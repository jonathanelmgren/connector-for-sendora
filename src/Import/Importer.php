<?php
/**
 * Imports an address verifier's results into the suppression list.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Import;

use ConnectorForSendora\Database\Schema;
use ConnectorForSendora\Suppression\SuppressionList;
use SplFileObject;

/**
 * Reads a verifier's CSV export, with a header row, and puts each address
 * whose status suppresses on the list as a hard bounce from an import. An
 * address already on the list as a hard bounce is left as it is.
 *
 * Reading never writes: {@see self::plan()} says what an import would do,
 * and {@see self::apply()} does it.
 */
final class Importer {

	/** The reason an imported address is on the list for. */
	public const REASON = SuppressionList::BOUNCED;

	/** The byte order mark a UTF-8 file may start with. */
	private const UTF8_BOM = "\xEF\xBB\xBF";

	/** The separators a header row is tried with; the first wins a tie. */
	private const SEPARATORS = array( ',', ';', "\t" );

	/** The quote around a field. */
	private const ENCLOSURE = '"';

	/** No escape character: a quote inside a field is doubled, as RFC 4180 has it. */
	private const NO_ESCAPE = '';

	/**
	 * An importer.
	 *
	 * @param SuppressionList $suppression The list it adds to.
	 */
	public function __construct( private readonly SuppressionList $suppression ) {
	}

	/**
	 * What importing the file would do. Writes nothing.
	 *
	 * @param string        $path    The CSV file.
	 * @param ImportOptions $options Which columns and statuses to read.
	 * @throws ImportFailed The file cannot be read, has no header row, or lacks a column.
	 */
	public function plan( string $path, ImportOptions $options ): ImportPlan {
		$file                     = $this->open( $path );
		[ $email_at, $status_at ] = $this->columns( $file, $options );

		$rows_read  = 0;
		$row_number = 1;
		$candidates = array();
		$skipped    = array();
		while ( ! $file->eof() ) {
			$row = $file->fgetcsv();
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			++$row_number;
			++$rows_read;
			$email  = SuppressionList::normalise( (string) ( $row[ $email_at ] ?? '' ) );
			$status = (string) ( $row[ $status_at ] ?? '' );
			$reason = match ( true ) {
				'' === $email                          => ImportPlan::SKIP_NO_ADDRESS,
				! self::valid( $email )                => ImportPlan::SKIP_INVALID_ADDRESS,
				! $options->suppresses( $status )      => ImportPlan::SKIP_STATUS,
				isset( $candidates[ $email ] )         => ImportPlan::SKIP_REPEATED,
				default                                => null,
			};
			if ( null === $reason ) {
				$candidates[ $email ] = true;
				continue;
			}
			$skipped[ $reason ][] = array(
				'row'   => $row_number,
				'email' => $email,
			);
		}

		$candidates = array_map( 'strval', array_keys( $candidates ) );
		$already    = $this->suppression->listed( $candidates, self::REASON );
		return new ImportPlan( $rows_read, array_values( array_diff( $candidates, $already ) ), $already, $skipped );
	}

	/**
	 * Puts the addresses on the list, leaving those already there as they are.
	 *
	 * @param list<string> $emails The addresses a plan would add.
	 * @return array{suppressed: list<string>, already: list<string>} The addresses added, and those that were on the list by now.
	 * @throws \ConnectorForSendora\Database\QueryFailed The database refused the write.
	 */
	public function apply( array $emails ): array {
		$emails = array_values( array_filter( array_map( array( SuppressionList::class, 'normalise' ), $emails ), array( self::class, 'valid' ) ) );
		$added  = $this->suppression->add_new( $emails, self::REASON, SuppressionList::SOURCE_IMPORT );
		return array(
			'suppressed' => $added,
			'already'    => array_values( array_diff( array_unique( $emails ), $added ) ),
		);
	}

	/**
	 * Whether the list can keep the address.
	 *
	 * @param string $email The address, normalised.
	 */
	private static function valid( string $email ): bool {
		return strlen( $email ) <= Schema::EMAIL_LENGTH && false !== is_email( $email );
	}

	/**
	 * The file, past its byte order mark, read as CSV with the separator its
	 * header row uses.
	 *
	 * @param string $path The file.
	 * @throws ImportFailed The file cannot be read.
	 */
	private function open( string $path ): SplFileObject {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw ImportFailed::unreadable();
		}
		try {
			$file = new SplFileObject( $path, 'rb' );
		} catch ( \RuntimeException $e ) {
			throw ImportFailed::unreadable( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the cause is not shown.
		}

		$start = self::UTF8_BOM === $file->fread( strlen( self::UTF8_BOM ) ) ? strlen( self::UTF8_BOM ) : 0;
		$file->fseek( $start );
		$header = (string) $file->fgets();
		$file->fseek( $start );

		$separator = self::SEPARATORS[0];
		$most      = 0;
		foreach ( self::SEPARATORS as $candidate ) {
			$found = substr_count( $header, $candidate );
			if ( $found > $most ) {
				$separator = $candidate;
				$most      = $found;
			}
		}
		$file->setCsvControl( $separator, self::ENCLOSURE, self::NO_ESCAPE );
		return $file;
	}

	/**
	 * Reads the header row, and finds the address and status columns in it.
	 *
	 * @param SplFileObject $file    The file, at its header row.
	 * @param ImportOptions $options The columns to find.
	 * @return array{0: int, 1: int} The positions of the address column and the status column.
	 * @throws ImportFailed The file has no header row, or lacks a column.
	 */
	private function columns( SplFileObject $file, ImportOptions $options ): array {
		$header = $file->eof() ? false : $file->fgetcsv();
		if ( ! is_array( $header ) || array( null ) === $header ) {
			throw ImportFailed::empty_file();
		}
		$names = array_map( static fn ( $name ): string => ImportOptions::fold( (string) $name ), $header );

		$positions = array();
		foreach ( array( $options->email_column, $options->status_column ) as $column ) {
			$at = array_search( $column, $names, true );
			if ( ! is_int( $at ) ) {
				throw ImportFailed::missing_column( $column, $header ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where it is shown.
			}
			$positions[] = $at;
		}
		return array( $positions[0], $positions[1] );
	}
}
