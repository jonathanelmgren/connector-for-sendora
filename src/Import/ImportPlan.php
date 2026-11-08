<?php
/**
 * What an import would do.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Import;

/**
 * The outcome of reading a verifier's export against the suppression list:
 * the addresses it would add, those already on the list, and how many rows
 * it skips for each reason, with the rows themselves when asked for.
 */
final class ImportPlan {

	/** The row has no address. */
	public const SKIP_NO_ADDRESS = 'no_address';

	/** The row's address is not a valid address. */
	public const SKIP_INVALID_ADDRESS = 'invalid_address';

	/** The row's address is on an earlier row too. */
	public const SKIP_REPEATED = 'repeated';

	/** The row's status does not suppress. */
	public const SKIP_STATUS = 'status';

	/** Every reason a row is skipped, in the order a report lists them. */
	public const SKIP_REASONS = array( self::SKIP_STATUS, self::SKIP_NO_ADDRESS, self::SKIP_INVALID_ADDRESS, self::SKIP_REPEATED );

	/**
	 * A plan.
	 *
	 * @param int                                                 $rows_read    How many rows the file has under its header, blank rows left out.
	 * @param list<string>                                        $suppress     The addresses to add, normalised.
	 * @param list<string>                                        $already      The addresses already on the list, normalised.
	 * @param array<string, int>                                  $skipped      How many rows were skipped, by reason.
	 * @param array<string, list<array{row: int, email: string}>> $skipped_rows The rows skipped, by reason, when asked for; a row's number is its CSV record's, the header being 1.
	 */
	public function __construct(
		public readonly int $rows_read,
		public readonly array $suppress,
		public readonly array $already,
		public readonly array $skipped,
		public readonly array $skipped_rows = array(),
	) {
	}

	/**
	 * How many rows were skipped for each reason, every reason present.
	 *
	 * @return array<string, int>
	 */
	public function skipped_counts(): array {
		$counts = array();
		foreach ( self::SKIP_REASONS as $reason ) {
			$counts[ $reason ] = (int) ( $this->skipped[ $reason ] ?? 0 );
		}
		return $counts;
	}

	/**
	 * The counts, one sentence each: rows read, added or to add, already on
	 * the list, and the rows skipped for each reason. No address.
	 *
	 * @param array{suppressed: list<string>, already: list<string>}|null $applied What {@see Importer::apply()} did; null for a dry run.
	 * @return list<string>
	 */
	public function summary( ?array $applied = null ): array {
		$lines = array(
			/* translators: %d: how many rows. */
			sprintf( _n( '%d row read.', '%d rows read.', $this->rows_read, 'connector-for-sendora' ), $this->rows_read ),
		);
		if ( null === $applied ) {
			$suppress = count( $this->suppress );
			$already  = count( $this->already );
			/* translators: %d: how many addresses. */
			$lines[] = sprintf( _n( '%d address would be suppressed.', '%d addresses would be suppressed.', $suppress, 'connector-for-sendora' ), $suppress );
		} else {
			$suppress = count( $applied['suppressed'] );
			$already  = count( $this->already ) + count( $applied['already'] );
			/* translators: %d: how many addresses. */
			$lines[] = sprintf( _n( '%d address suppressed.', '%d addresses suppressed.', $suppress, 'connector-for-sendora' ), $suppress );
		}
		/* translators: %d: how many addresses. */
		$lines[] = sprintf( _n( '%d address already on the list.', '%d addresses already on the list.', $already, 'connector-for-sendora' ), $already );
		foreach ( $this->skipped_counts() as $reason => $count ) {
			if ( 0 === $count ) {
				continue;
			}
			/* translators: 1: how many rows, 2: why they were skipped. */
			$lines[] = sprintf( _n( '%1$d row skipped: %2$s.', '%1$d rows skipped: %2$s.', $count, 'connector-for-sendora' ), $count, self::skip_label( $reason ) );
		}
		return $lines;
	}

	/**
	 * Why a row was skipped, as the person importing reads it.
	 *
	 * @param string $reason One of {@see self::SKIP_REASONS}.
	 */
	public static function skip_label( string $reason ): string {
		return match ( $reason ) {
			self::SKIP_STATUS          => __( 'status does not suppress', 'connector-for-sendora' ),
			self::SKIP_NO_ADDRESS      => __( 'no address', 'connector-for-sendora' ),
			self::SKIP_INVALID_ADDRESS => __( 'not a valid address', 'connector-for-sendora' ),
			self::SKIP_REPEATED        => __( 'address repeated in the file', 'connector-for-sendora' ),
			default                    => $reason,
		};
	}
}
