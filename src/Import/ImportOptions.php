<?php
/**
 * How to read an address verifier's export.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Import;

/**
 * The columns that hold the address and the verifier's status, and the
 * statuses that put an address on the suppression list. Column names and
 * statuses are matched without regard to case or surrounding spaces.
 */
final class ImportOptions {

	/** The address column, unless named otherwise. */
	public const DEFAULT_EMAIL_COLUMN = 'email';

	/** The status column, unless named otherwise. */
	public const DEFAULT_STATUS_COLUMN = 'status';

	/** The statuses that suppress, unless named otherwise. */
	public const DEFAULT_STATUSES = array( 'undeliverable', 'invalid' );

	/**
	 * The column holding the address, as matched.
	 *
	 * @var string
	 */
	public readonly string $email_column;

	/**
	 * The column holding the status, as matched.
	 *
	 * @var string
	 */
	public readonly string $status_column;

	/**
	 * The statuses that suppress, as matched.
	 *
	 * @var list<string>
	 */
	public readonly array $statuses;

	/**
	 * Options.
	 *
	 * @param string       $email_column  The address column; empty for the default.
	 * @param string       $status_column The status column; empty for the default.
	 * @param list<string> $statuses      The statuses that suppress; none for the default.
	 */
	public function __construct( string $email_column = '', string $status_column = '', array $statuses = array() ) {
		$email_column        = self::fold( $email_column );
		$status_column       = self::fold( $status_column );
		$statuses            = array_values( array_unique( array_filter( array_map( array( self::class, 'fold' ), $statuses ), static fn ( string $status ): bool => '' !== $status ) ) );
		$this->email_column  = '' === $email_column ? self::DEFAULT_EMAIL_COLUMN : $email_column;
		$this->status_column = '' === $status_column ? self::DEFAULT_STATUS_COLUMN : $status_column;
		$this->statuses      = array() === $statuses ? self::DEFAULT_STATUSES : $statuses;
	}

	/**
	 * Options as a form or a command line gives them, the statuses comma-separated.
	 *
	 * @param string $email_column  The address column.
	 * @param string $status_column The status column.
	 * @param string $statuses      The statuses that suppress, comma-separated.
	 */
	public static function from_text( string $email_column, string $status_column, string $statuses ): self {
		return new self( $email_column, $status_column, explode( ',', $statuses ) );
	}

	/**
	 * A column name or a status as it is compared.
	 *
	 * @param string $text The name or status.
	 */
	public static function fold( string $text ): string {
		return strtolower( trim( $text ) );
	}

	/**
	 * Whether the status puts an address on the list.
	 *
	 * @param string $status The status as the file has it.
	 */
	public function suppresses( string $status ): bool {
		return in_array( self::fold( $status ), $this->statuses, true );
	}
}
