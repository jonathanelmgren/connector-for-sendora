<?php
/**
 * Queries the database refuses, for tests of failures.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Support;

/**
 * Turns every query matching a pattern into one the database refuses, with
 * its errors kept quiet, until {@see self::restore()}.
 */
final class BrokenQueries {

	/** What a refused query is replaced with. */
	private const NOT_SQL = 'NOT SQL';

	/**
	 * The `query` filter in place, if any.
	 *
	 * @var \Closure(string): string|null
	 */
	private ?\Closure $filter = null;

	/**
	 * Whether the database's errors were suppressed before.
	 *
	 * @var bool
	 */
	private bool $suppressed = false;

	/**
	 * A pattern matching every insert into, or update of, the table.
	 *
	 * @param string $table The table.
	 */
	public static function writes_to( string $table ): string {
		return '/^(INSERT|UPDATE)\b.*`' . preg_quote( $table, '/' ) . '`/s';
	}

	/**
	 * A pattern matching every read of the table.
	 *
	 * @param string $table The table.
	 */
	public static function reads_of( string $table ): string {
		return '/^SELECT\b.*`' . preg_quote( $table, '/' ) . '`/s';
	}

	/**
	 * Makes the database refuse the queries that match.
	 *
	 * @param string $pattern A regular expression a query is matched with.
	 */
	public function refuse( string $pattern ): void {
		global $wpdb;
		$this->restore();
		$this->filter     = static fn ( string $query ): string => 1 === preg_match( $pattern, $query ) ? self::NOT_SQL : $query;
		$this->suppressed = $wpdb->suppress_errors();
		add_filter( 'query', $this->filter );
	}

	/** Lets every query through again. */
	public function restore(): void {
		global $wpdb;
		if ( null === $this->filter ) {
			return;
		}
		remove_filter( 'query', $this->filter );
		$this->filter = null;
		$wpdb->suppress_errors( $this->suppressed );
	}
}
