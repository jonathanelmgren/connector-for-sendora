<?php
/**
 * Turns wp_mail()'s arguments into a message.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Mail;

use ConnectorForSendora\Settings\Settings;

/**
 * Reads wp_mail()'s arguments the way wp_mail() itself does: the same
 * header parsing, the same defaults and the same filters
 * (`wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type`,
 * `wp_mail_charset`), so a site behaves alike with and without the plugin.
 */
final class MessageBuilder {

	/** The default sender name of wp_mail(). */
	private const DEFAULT_FROM_NAME = 'WordPress';

	private const CONTENT_TYPE_HTML = 'text/html';

	private const CONTENT_TYPE_TEXT = 'text/plain';

	/** The charset Sendora's API takes text in. */
	private const UTF8 = 'UTF-8';

	/**
	 * Headers the message carries in its own fields, or that the message
	 * format sets, so none of them goes on as a custom header.
	 */
	private const STRUCTURAL_HEADERS = array(
		'from',
		'to',
		'cc',
		'bcc',
		'reply-to',
		'subject',
		'content-type',
		'content-transfer-encoding',
		'mime-version',
	);

	/**
	 * A builder.
	 *
	 * @param Settings $settings The default From name and address, and the stream.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * The message wp_mail() was asked to send.
	 *
	 * @param array<string, mixed> $atts wp_mail()'s arguments: to, subject, message, headers, attachments.
	 * @throws SendFailed The message has no recipient: no To, Cc or Bcc.
	 */
	public function build( array $atts ): Message {
		$to = self::addresses( self::list_of( $atts['to'] ?? array(), ',' ) );

		$from         = null;
		$content_type = null;
		$charset      = null;
		$cc           = array();
		$bcc          = array();
		$reply_to     = array();
		$headers      = array();
		foreach ( self::list_of( $atts['headers'] ?? array(), "\n" ) as $header ) {
			if ( ! str_contains( $header, ':' ) ) {
				continue;
			}
			[ $name, $content ] = array_map( 'trim', explode( ':', trim( $header ), 2 ) );
			switch ( strtolower( $name ) ) {
				case 'from':
					$from = Address::parse( $content );
					break;
				case 'content-type':
					[ $content_type, $charset ] = self::content_type( $content, $content_type, $charset );
					break;
				case 'cc':
					$cc = array_merge( $cc, explode( ',', $content ) );
					break;
				case 'bcc':
					$bcc = array_merge( $bcc, explode( ',', $content ) );
					break;
				case 'reply-to':
					$reply_to = array_merge( $reply_to, explode( ',', $content ) );
					break;
				default:
					if ( ! in_array( strtolower( $name ), self::STRUCTURAL_HEADERS, true ) && '' !== $name ) {
						$headers[ $name ] = $content;
					}
			}
		}

		$cc  = self::addresses( $cc );
		$bcc = self::addresses( $bcc );
		if ( array() === $to && array() === $cc && array() === $bcc ) {
			throw new SendFailed( __( 'The message has no recipient.', 'connector-for-sendora' ), 'no_recipient' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.		/** This filter is documented in wp-includes/pluggable.php. */
		$content_type = (string) apply_filters( 'wp_mail_content_type', $content_type ?? self::CONTENT_TYPE_TEXT );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.		/** This filter is documented in wp-includes/pluggable.php. */
		$charset = (string) apply_filters( 'wp_mail_charset', $charset ?? get_bloginfo( 'charset' ) );

		$body = self::to_utf8( (string) ( $atts['message'] ?? '' ), $charset );
		$html = self::CONTENT_TYPE_HTML === strtolower( $content_type );

		return new Message(
			from: $this->from( $from ),
			to: $to,
			subject: self::to_utf8( (string) ( $atts['subject'] ?? '' ), $charset ),
			text: $html ? null : $body,
			html: $html ? $body : null,
			cc: $cc,
			bcc: $bcc,
			reply_to: self::addresses( $reply_to ),
			headers: $headers,
			attachments: self::attachments( $atts['attachments'] ?? array() ),
			stream_id: self::non_empty( $this->settings->get( Settings::TRANSACTIONAL_STREAM ) ),
		);
	}

	/**
	 * The sender: the message's From header, else the plugin's default,
	 * else wp_mail()'s, each passed through wp_mail()'s filters.
	 *
	 * @param Address|null $header The From header's address, when the message has one.
	 */
	private function from( ?Address $header ): Address {
		$email = $header->email ?? self::non_empty( $this->settings->get( Settings::FROM_EMAIL ) ) ?? self::default_from_email();
		$name  = $header->name ?? self::non_empty( $this->settings->get( Settings::FROM_NAME ) ) ?? self::DEFAULT_FROM_NAME;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.		/** This filter is documented in wp-includes/pluggable.php. */
		$email = (string) apply_filters( 'wp_mail_from', $email );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, as wp_mail() uses it.		/** This filter is documented in wp-includes/pluggable.php. */
		$name = (string) apply_filters( 'wp_mail_from_name', $name );

		return new Address( $email, self::non_empty( $name ) );
	}

	/** The default sender of wp_mail(): `wordpress@` the site's domain, without `www.`. */
	private static function default_from_email(): string {
		$sitename = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( str_starts_with( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, strlen( 'www.' ) );
		}
		return 'wordpress@' . $sitename;
	}

	/**
	 * A Content-Type header's type and charset, as wp_mail() reads them.
	 *
	 * @param string      $content The header's value.
	 * @param string|null $type    The type so far.
	 * @param string|null $charset The charset so far.
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function content_type( string $content, ?string $type, ?string $charset ): array {
		if ( ! str_contains( $content, ';' ) ) {
			return array( '' === $content ? $type : $content, $charset );
		}
		[ $type, $parameters ] = array_map( 'trim', explode( ';', $content, 2 ) );
		if ( false !== stripos( $parameters, 'charset=' ) ) {
			$charset = trim( str_ireplace( array( 'charset=', '"' ), '', $parameters ) );
		}
		return array( $type, $charset );
	}

	/**
	 * A wp_mail() argument that is a list or a string of items, as a list
	 * of non-empty trimmed strings.
	 *
	 * @param mixed            $value     The argument.
	 * @param non-empty-string $separator What separates items in a string.
	 * @return array<array-key, string>
	 */
	private static function list_of( mixed $value, string $separator ): array {
		if ( is_string( $value ) ) {
			$value = explode( $separator, str_replace( "\r\n", "\n", $value ) );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$items = array();
		foreach ( $value as $key => $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$items[ $key ] = trim( (string) $item );
			}
		}
		return $items;
	}

	/**
	 * Each non-empty mailbox, parsed.
	 *
	 * @param array<array-key, string> $mailboxes Mailboxes as headers write them.
	 * @return list<Address>
	 */
	private static function addresses( array $mailboxes ): array {
		$addresses = array();
		foreach ( $mailboxes as $mailbox ) {
			if ( '' !== trim( $mailbox ) ) {
				$addresses[] = Address::parse( $mailbox );
			}
		}
		return $addresses;
	}

	/**
	 * The files to attach. A string key names the file, as wp_mail() takes
	 * it; a file that cannot be read is skipped, as wp_mail() skips it.
	 *
	 * @param mixed $attachments wp_mail()'s attachments argument.
	 * @return list<Attachment>
	 */
	private static function attachments( mixed $attachments ): array {
		$files = array();
		foreach ( self::list_of( $attachments, "\n" ) as $name => $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$name    = is_string( $name ) && '' !== $name ? $name : wp_basename( $path );
			$type    = wp_check_filetype( $name )['type'];
			$files[] = new Attachment( $path, $name, is_string( $type ) ? $type : 'application/octet-stream' );
		}
		return $files;
	}

	/**
	 * Text in UTF-8, which Sendora's API takes, from the message's charset.
	 *
	 * @param string $text    The text.
	 * @param string $charset Its charset.
	 */
	private static function to_utf8( string $text, string $charset ): string {
		if ( '' === $charset || 0 === strcasecmp( $charset, self::UTF8 ) || ! function_exists( 'mb_convert_encoding' ) ) {
			return $text;
		}
		try {
			$converted = mb_convert_encoding( $text, self::UTF8, $charset );
		} catch ( \ValueError ) {
			return $text;
		}
		return is_string( $converted ) ? $converted : $text;
	}

	/**
	 * The string, or null when it is empty.
	 *
	 * @param string $value A value.
	 */
	private static function non_empty( string $value ): ?string {
		return '' === $value ? null : $value;
	}
}
