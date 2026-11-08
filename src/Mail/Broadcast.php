<?php
/**
 * Sendora's rules for mail on a broadcast stream.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Mail;

/**
 * Makes a broadcast fit Sendora's rules, or refuses it. Sendora puts each
 * recipient's own unsubscribe link where `{{ unsubscribe_url }}` stands,
 * and sets the `List-Unsubscribe` headers itself. So a broadcast:
 *
 * - is first passed through the `sendora_wp_broadcast_message`
 *   filter, which can rewrite it, for instance to put the placeholder in
 *   place of an unsubscribe link of its own;
 * - gets a text part written from its HTML when it has HTML only;
 * - is refused when a part it has lacks the placeholder;
 * - loses any `List-Unsubscribe` and `List-Unsubscribe-Post` header;
 * - goes to each of its To, Cc and Bcc recipients alone, so each gets a
 *   link of their own.
 */
final class Broadcast {

	/** The filter that can rewrite a broadcast before its checks. */
	public const MESSAGE_FILTER = 'sendora_wp_broadcast_message';

	/** Where Sendora puts a recipient's unsubscribe link. */
	public const UNSUBSCRIBE_PLACEHOLDER = '{{ unsubscribe_url }}';

	/** The error code for a broadcast without the placeholder. */
	public const ERROR_NO_UNSUBSCRIBE_URL = 'unsubscribe_url_missing';

	/** The placeholder, however its inner spacing is written, as Sendora reads it. */
	private const UNSUBSCRIBE_PATTERN = '/\{\{\s*unsubscribe_url\s*\}\}/';

	/** Headers Sendora sets on a broadcast itself, in lower case. */
	private const SENDORA_HEADERS = array( 'list-unsubscribe', 'list-unsubscribe-post' );

	/**
	 * The messages to send: one per recipient, each fit for the broadcast
	 * stream.
	 *
	 * @param Message              $message The broadcast.
	 * @param array<string, mixed> $atts    wp_mail()'s arguments, for the filter.
	 * @return list<Message>
	 * @throws SendFailed A part of the message lacks the placeholder.
	 */
	public function deliveries( Message $message, array $atts ): array {
		/**
		 * Filters a message on the broadcast stream before Sendora's rules are
		 * checked: return the message as it should be sent, for instance with
		 * `{{ unsubscribe_url }}` in place of an unsubscribe link of your own.
		 * Anything other than a `SendoraWP\Mail\Message` keeps the
		 * message as it was. The message stays on the broadcast stream
		 * whatever stream the returned one names.
		 *
		 * @param Message              $message The message.
		 * @param array<string, mixed> $atts    wp_mail()'s arguments: to, subject, message, headers, attachments.
		 */
		/**
		 * What the filter returned, which can be anything.
		 *
		 * @var mixed $filtered
		 */
		$filtered = apply_filters( self::MESSAGE_FILTER, $message, $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is sendora_wp_broadcast_message.
		if ( $filtered instanceof Message ) {
			// The filter rewrites the content; the stream stays the one chosen, so a broadcast never leaves as transactional mail.
			$message = $filtered->on_stream_of( $message );
		}

		if ( null === $message->text && null !== $message->html ) {
			$message = $message->with_text( HtmlToText::convert( $message->html ) );
		}

		if ( null !== $message->text && ! self::has_placeholder( $message->text ) ) {
			throw self::missing_placeholder( __( 'text', 'connector-for-sendora' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}
		if ( null !== $message->html && ! self::has_placeholder( $message->html ) ) {
			throw self::missing_placeholder( __( 'HTML', 'connector-for-sendora' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}

		$message = $message->with_headers(
			array_filter(
				$message->headers,
				static fn ( string $name ): bool => ! in_array( strtolower( $name ), self::SENDORA_HEADERS, true ),
				ARRAY_FILTER_USE_KEY
			)
		);

		return array_map( $message->to_alone( ... ), self::recipients( $message ) );
	}

	/**
	 * Whether the text has the placeholder.
	 *
	 * @param string $text A part of the message.
	 */
	private static function has_placeholder( string $text ): bool {
		return 1 === preg_match( self::UNSUBSCRIBE_PATTERN, $text );
	}

	/**
	 * The refusal of a part without the placeholder.
	 *
	 * @param string $part The part, as the refusal names it.
	 */
	private static function missing_placeholder( string $part ): SendFailed {
		$failure = sprintf(
			/* translators: 1: a part of the message, "text" or "HTML"; 2: the placeholder, {{ unsubscribe_url }}. */
			__( 'The message is a broadcast, and its %1$s part does not contain %2$s, which Sendora needs on every broadcast for the recipient\'s unsubscribe link.', 'connector-for-sendora' ),
			$part,
			self::UNSUBSCRIBE_PLACEHOLDER
		);
		return new SendFailed( $failure, self::ERROR_NO_UNSUBSCRIBE_URL );
	}

	/**
	 * Every recipient of the message, To, Cc and Bcc, each address once.
	 *
	 * @param Message $message The message.
	 * @return list<Address>
	 */
	private static function recipients( Message $message ): array {
		$recipients = array();
		foreach ( array_merge( $message->to, $message->cc, $message->bcc ) as $recipient ) {
			$recipients[ strtolower( $recipient->email ) ] ??= $recipient;
		}
		return array_values( $recipients );
	}
}
