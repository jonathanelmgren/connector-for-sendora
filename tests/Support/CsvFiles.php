<?php
/**
 * Temporary CSV files for import tests.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Support;

/**
 * Writes text to a temporary file and deletes what it wrote when asked.
 * Every address in a fixture is made up, on a reserved example domain.
 */
final class CsvFiles {

	/** The byte order mark a UTF-8 file may start with. */
	public const BOM = "\xEF\xBB\xBF";

	/**
	 * The files written.
	 *
	 * @var list<string>
	 */
	private array $paths = array();

	/**
	 * A file holding the text.
	 *
	 * @param string $contents The file's contents.
	 */
	public function write( string $contents ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'cfs-import-' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$this->paths[] = $path;
		return $path;
	}

	/**
	 * A file with the rows, each joined with commas, one per line.
	 *
	 * @param list<string> $lines The lines.
	 */
	public function lines( array $lines ): string {
		return $this->write( implode( "\n", $lines ) . "\n" );
	}

	/** Deletes every file written. */
	public function delete_all(): void {
		foreach ( $this->paths as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->paths = array();
	}
}
