<?php
/**
 * A file that cannot be imported.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Import;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a message is escaped where it is shown.

/**
 * The file cannot be read, has no header row, lacks a column the import
 * needs, is not an upload the screen takes, gives a dry run too large to
 * keep, or meets a database that refuses the import. Its message is a
 * translated sentence for the person importing.
 */
final class ImportFailed extends \RuntimeException {

	/**
	 * The file is not there, or cannot be opened.
	 *
	 * @param \Throwable|null $previous What caused it.
	 */
	public static function unreadable( ?\Throwable $previous = null ): self {
		return new self( __( 'The file cannot be read.', 'sendora' ), 0, $previous );
	}

	/** The file has no header row. */
	public static function empty_file(): self {
		return new self( __( 'The file is empty: it needs a header row naming its columns.', 'sendora' ) );
	}

	/**
	 * The header row does not name the column.
	 *
	 * @param string       $column The column looked for.
	 * @param array<mixed> $header The file's header row.
	 */
	public static function missing_column( string $column, array $header ): self {
		return new self(
			sprintf(
				/* translators: 1: a column name, 2: the file's column names, comma-separated. */
				__( 'The file has no column named "%1$s". Its columns are: %2$s.', 'sendora' ),
				$column,
				implode( ', ', array_map( static fn ( $name ): string => trim( (string) $name ), $header ) )
			)
		);
	}

	/** No file came with the request. */
	public static function no_upload(): self {
		return new self( __( 'No file was uploaded. Choose a CSV file and try again.', 'sendora' ) );
	}

	/**
	 * The upload is over the limit.
	 *
	 * @param int $limit The largest file taken, in bytes.
	 */
	public static function too_large( int $limit ): self {
		return new self(
			sprintf(
				/* translators: %s: the largest file size, such as "10 MB". */
				__( 'The file is too large. The largest file the import takes is %s; import a larger one with WP-CLI.', 'sendora' ),
				size_format( $limit )
			)
		);
	}

	/** The dry run is too large for the site to keep until it is confirmed. */
	public static function not_kept(): self {
		return new self( __( 'The dry run could not be kept for you to confirm: the file is too large for this site\'s storage. Import it with WP-CLI.', 'sendora' ) );
	}

	/**
	 * The database refused a lookup or a write. The message names no query.
	 *
	 * @param \Throwable|null $previous What caused it.
	 */
	public static function database( ?\Throwable $previous = null ): self {
		return new self( __( 'The database refused the import. Some addresses may be on the list already; importing the file again is safe, as it leaves those as they are.', 'sendora' ), 0, $previous );
	}

	/** The upload is not a CSV file. */
	public static function not_csv(): self {
		return new self( __( 'That is not a CSV file. Export the verifier\'s results as CSV and try again.', 'sendora' ) );
	}
}
