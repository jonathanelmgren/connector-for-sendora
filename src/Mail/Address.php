<?php
/**
 * An email address with an optional display name.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Mail;

/**
 * One mailbox: `anna@example.com`, or `Anna Svensson <anna@example.com>`.
 */
final class Address {

	/**
	 * An address.
	 *
	 * @param string      $email The address itself.
	 * @param string|null $name  The display name; null for none.
	 */
	public function __construct(
		public readonly string $email,
		public readonly ?string $name = null,
	) {
	}

	/**
	 * Reads `Name <email>`, `"Name" <email>` or a bare address, as
	 * wp_mail() takes them.
	 *
	 * @param string $mailbox One mailbox as written in a header.
	 */
	public static function parse( string $mailbox ): self {
		$mailbox = trim( $mailbox );
		if ( 1 === preg_match( '/^(.*)<\s*([^<>]+?)\s*>$/', $mailbox, $matches ) ) {
			$name = trim( trim( $matches[1] ), '"' );
			return new self( $matches[2], '' === $name ? null : $name );
		}
		return new self( $mailbox );
	}

	/** The address as a header writes it. */
	public function to_header(): string {
		if ( null === $this->name ) {
			return $this->email;
		}
		return sprintf( '"%s" <%s>', addcslashes( $this->name, '"\\' ), $this->email );
	}
}
