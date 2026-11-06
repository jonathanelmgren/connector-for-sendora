<?php
/**
 * A file that cannot be imported.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Import;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a message is escaped where it is shown.

/**
 * The file cannot be read, has no header row, lacks a column the import
 * needs, or is not an upload the screen takes. Its message is a translated
 * sentence for the person importing.
 */
final class ImportFailed extends \RuntimeException {

	/**
	 * The file is not there, or cannot be opened.
	 *
	 * @param \Throwable|null $previous What caused it.
	 */
	public static function unreadable( ?\Throwable $previous = null ): self {
		return new self( __( 'The file cannot be read.', 'connector-for-sendora' ), 0, $previous );
	}

	/** The file has no header row. */
	public static function empty_file(): self {
		return new self( __( 'The file is empty: it needs a header row naming its columns.', 'connector-for-sendora' ) );
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
				__( 'The file has no column named "%1$s". Its columns are: %2$s.', 'connector-for-sendora' ),
				$column,
				implode( ', ', array_map( static fn ( $name ): string => trim( (string) $name ), $header ) )
			)
		);
	}

	/** No file came with the request. */
	public static function no_upload(): self {
		return new self( __( 'No file was uploaded. Choose a CSV file and try again.', 'connector-for-sendora' ) );
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
				__( 'The file is too large. The largest file the import takes is %s; import a larger one with WP-CLI.', 'connector-for-sendora' ),
				size_format( $limit )
			)
		);
	}

	/** The upload is not a CSV file. */
	public static function not_csv(): self {
		return new self( __( 'That is not a CSV file. Export the verifier\'s results as CSV and try again.', 'connector-for-sendora' ) );
	}
}
