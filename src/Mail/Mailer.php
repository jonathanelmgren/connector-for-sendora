<?php
/**
 * Sends wp_mail() through Sendora.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Mail;

use ConnectorForSendora\Settings\Settings;
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
 */
final class Mailer {

	/** The WP_Error code wp_mail() itself uses for a failed send. */
	public const ERROR_CODE = 'wp_mail_failed';

	/**
	 * A mailer.
	 *
	 * @param Settings              $settings   Whether the plugin is configured, and its defaults.
	 * @param callable(): Transport $transports Makes the transport for a send, from the settings as they are then.
	 */
	public function __construct(
		private readonly Settings $settings,
		private $transports,
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

		$ids     = array();
		$sent_to = array();
		try {
			$message    = ( new MessageBuilder( $this->settings ) )->build( $atts );
			$transport  = ( $this->transports )();
			$deliveries = $message->broadcast ? ( new Broadcast() )->deliveries( $message, $atts ) : $message->deliveries();
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
			/** This action is documented in wp-includes/pluggable.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.
			do_action( 'wp_mail_failed', new WP_Error( self::ERROR_CODE, $failure->getMessage(), $mail_data ) );
			return false;
		}

		$mail_data['sendora_message_ids'] = $ids;
		/** This action is documented in wp-includes/pluggable.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.
		do_action( 'wp_mail_succeeded', $mail_data );
		return true;
	}
}
