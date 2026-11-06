<?php
/**
 * Which kind of Sendora stream a message goes on.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Mail;

/**
 * Every message is transactional unless something asks for the broadcast
 * stream: the `X-Sendora-Stream: broadcast` header, for plugins that can
 * only add headers, or the `sendora_wp_stream` filter, for one
 * that recognises its own messages from wp_mail()'s arguments.
 */
final class Stream {

	public const TRANSACTIONAL = 'transactional';

	public const BROADCAST = 'broadcast';

	/** The filter that chooses the stream. */
	public const FILTER = 'sendora_wp_stream';

	/** The header that asks for a stream. It is never sent. */
	public const HEADER = 'X-Sendora-Stream';

	/**
	 * The stream the message goes on: {@see self::BROADCAST} when the header
	 * or the filter asks for it, {@see self::TRANSACTIONAL} otherwise.
	 *
	 * @param string|null          $header The `X-Sendora-Stream` header's value; null when the message has none.
	 * @param array<string, mixed> $atts   wp_mail()'s arguments.
	 */
	public static function choose( ?string $header, array $atts ): string {
		$stream = self::is_broadcast( $header ) ? self::BROADCAST : self::TRANSACTIONAL;

		/**
		 * Filters the stream a message goes on. Return `'broadcast'` to send
		 * it on the broadcast stream, under Sendora's rules for broadcasts;
		 * any other value sends it on the transactional stream.
		 *
		 * @param string               $stream `'transactional'`, or `'broadcast'` when the message's `X-Sendora-Stream` header asks for it.
		 * @param array<string, mixed> $atts   wp_mail()'s arguments: to, subject, message, headers, attachments.
		 */
		$chosen = apply_filters( self::FILTER, $stream, $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is sendora_wp_stream.

		return self::is_broadcast( $chosen ) ? self::BROADCAST : self::TRANSACTIONAL;
	}

	/**
	 * Whether a header's or the filter's value names the broadcast stream.
	 *
	 * @param mixed $value The value.
	 */
	private static function is_broadcast( mixed $value ): bool {
		return is_string( $value ) && 0 === strcasecmp( trim( $value ), self::BROADCAST );
	}
}
