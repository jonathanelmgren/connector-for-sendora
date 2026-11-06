<?php
/**
 * Sends wp_mail() through Sendora.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Mail;

use ConnectorForSendora\Settings\Settings;
use ConnectorForSendora\Suppression\SuppressionList;
use WP_Error;

/**
 * Takes over wp_mail() on `pre_wp_mail` once the plugin has an API key.
 * Without one it steps aside, and WordPress's own mailer runs untouched.
 *
 * As wp_mail() does, a send fires `wp_mail_succeeded` with the message's
 * data, to which it adds the ids Sendora gave the message's sends, and a
 * failure fires `wp_mail_failed` with a WP_Error whose data adds Sendora's
 * error code and HTTP status to the message's data, and the ids and
 * recipients of the sends that went out before the failure. A message
 * that goes out as several sends (see {@see Message::deliveries()} and
 * {@see Broadcast::deliveries()}) stops at the first that fails. A
 * broadcast that breaks Sendora's rules fails, and is never sent on the
 * transactional stream instead.
 *
 * A recipient on the suppression list for the message's stream is left
 * out, and named with its reason in the data's `sendora_suppressed`. A
 * message with every recipient left out fails.
 */
final class Mailer {

	/** The WP_Error code wp_mail() itself uses for a failed send. */
	public const ERROR_CODE = 'wp_mail_failed';

	/** The error code for a message whose every recipient is suppressed, as Sendora names the same refusal. */
	public const ERROR_ALL_SUPPRESSED = 'recipient_suppressed';

	/**
	 * A mailer.
	 *
	 * @param Settings              $settings    Whether the plugin is configured, and its defaults.
	 * @param callable(): Transport $transports  Makes the transport for a send, from the settings as they are then.
	 * @param SuppressionList       $suppression The addresses not to send to.
	 */
	public function __construct(
		private readonly Settings $settings,
		private $transports,
		private readonly SuppressionList $suppression = new SuppressionList(),
	) {
	}

	/** Hooks the mailer into wp_mail(). */
	public function register(): void {
		add_filter( 'pre_wp_mail', array( $this, 'pre_wp_mail' ), 10, 2 );
	}

	/**
	 * The `pre_wp_mail` filter: sends the message and answers wp_mail()'s
	 * result, or null to let WordPress send it.
	 *
	 * @param null|bool $result A result another filter already gave; null when none did.
	 * @param mixed     $atts   wp_mail()'s arguments, an array.
	 */
	public function pre_wp_mail( $result, $atts ): ?bool {
		if ( null !== $result || ! $this->settings->is_configured() || ! is_array( $atts ) ) {
			return $result;
		}

		$mail_data = array(
			'to'          => $atts['to'] ?? array(),
			'subject'     => $atts['subject'] ?? '',
			'message'     => $atts['message'] ?? '',
			'headers'     => $atts['headers'] ?? array(),
			'attachments' => $atts['attachments'] ?? array(),
		);

		$ids        = array();
		$sent_to    = array();
		$suppressed = array();
		try {
			$message    = ( new MessageBuilder( $this->settings ) )->build( $atts );
			$deliveries = $this->unsuppressed( $message, $atts, $suppressed );
			$transport  = ( $this->transports )();
			foreach ( $deliveries as $delivery ) {
				$ids[]   = $transport->send( $delivery );
				$sent_to = array_merge( $sent_to, array_map( static fn ( Address $a ): string => $a->email, array_merge( $delivery->to, $delivery->cc, $delivery->bcc ) ) );
			}
		} catch ( SendFailed $failure ) {
			$mail_data['sendora_error_code'] = $failure->error_code;
			$mail_data['sendora_status']     = $failure->status;
			// A message sent as several can fail part way: say who has it already, so a retry can leave them out.
			$mail_data['sendora_message_ids'] = $ids;
			$mail_data['sendora_sent_to']     = $sent_to;
			$mail_data['sendora_suppressed']  = $suppressed;
			/** This action is documented in wp-includes/pluggable.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.
			do_action( 'wp_mail_failed', new WP_Error( self::ERROR_CODE, $failure->getMessage(), $mail_data ) );
			return false;
		}

		$mail_data['sendora_message_ids'] = $ids;
		$mail_data['sendora_suppressed']  = $suppressed;
		/** This action is documented in wp-includes/pluggable.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.
		do_action( 'wp_mail_succeeded', $mail_data );
		return true;
	}

	/**
	 * The sends that carry the message to every recipient not on the
	 * suppression list for its stream.
	 *
	 * A transactional message loses its suppressed recipients before it is
	 * split into sends, so that a Cc can still become the To. A broadcast
	 * goes to each recipient alone already, so its sends are dropped.
	 *
	 * @param Message               $message    The message.
	 * @param array<string, mixed>  $atts       wp_mail()'s arguments, for the broadcast filter.
	 * @param array<string, string> $suppressed Filled with each address left out and its reason.
	 * @return list<Message>
	 * @throws SendFailed Every recipient is suppressed, or the broadcast breaks Sendora's rules.
	 */
	private function unsuppressed( Message $message, array $atts, array &$suppressed ): array {
		$stream = $message->broadcast ? Stream::BROADCAST : Stream::TRANSACTIONAL;
		if ( $message->broadcast ) {
			$deliveries = array();
			foreach ( ( new Broadcast() )->deliveries( $message, $atts ) as $delivery ) {
				$kept = $this->without_suppressed( $delivery, $stream, $suppressed );
				if ( null !== $kept ) {
					$deliveries[] = $kept;
				}
			}
		} else {
			$kept       = $this->without_suppressed( $message, $stream, $suppressed );
			$deliveries = null === $kept ? array() : $kept->deliveries();
		}

		if ( array() === $deliveries && array() !== $suppressed ) {
			$reasons = array();
			foreach ( $suppressed as $email => $reason ) {
				$reasons[] = $email . ' (' . SuppressionList::reason_label( $reason ) . ')';
			}
			$failure = sprintf(
				/* translators: %s: the recipients and why each is suppressed, such as "anna@example.com (hard bounce)". */
				__( 'Not sent: every recipient is on the suppression list: %s.', 'connector-for-sendora' ),
				implode( ', ', $reasons )
			);
			throw new SendFailed( $failure, self::ERROR_ALL_SUPPRESSED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}
		return $deliveries;
	}

	/**
	 * The message without its suppressed recipients, or null when none is
	 * left.
	 *
	 * @param Message               $message    The message.
	 * @param string                $stream     The stream it goes on.
	 * @param array<string, string> $suppressed Filled with each address left out and its reason.
	 */
	private function without_suppressed( Message $message, string $stream, array &$suppressed ): ?Message {
		$keep = function ( array $addresses ) use ( $stream, &$suppressed ): array {
			$kept = array();
			foreach ( $addresses as $address ) {
				$reason = $this->suppression->reason( $address->email, $stream );
				if ( null === $reason ) {
					$kept[] = $address;
				} else {
					$suppressed[ SuppressionList::normalise( $address->email ) ] = $reason;
				}
			}
			return $kept;
		};
		$to   = $keep( $message->to );
		$cc   = $keep( $message->cc );
		$bcc  = $keep( $message->bcc );
		if ( array() === $to && array() === $cc && array() === $bcc ) {
			return null;
		}
		return $message->with_recipients( $to, $cc, $bcc );
	}
}
