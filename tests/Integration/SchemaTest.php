<?php
/**
 * Tests for the plugin's tables.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

use SendoraWP\Database\Schema;
use SendoraWP\Suppression\SuppressionList;

/**
 * The suppression table's key and the schema's version.
 */
final class SchemaTest extends TestCase {

	/** The length of each long domain label below, under DNS's 63. */
	private const LABEL_LENGTH = 61;

	/** The end of the deliveries table's name, which esc_like() leaves alone. */
	private const DELIVERIES_TABLE_SUFFIX = 'deliveries';

	/** A table lookup that finds nothing. */
	private const FIND_NO_TABLE = "SHOW TABLES LIKE 'sendora_missing'";

	public function test_addresses_alike_past_the_index_prefix_are_kept_apart(): void {
		$list   = new SuppressionList();
		$common = 'anna@' . implode( '.', array( str_repeat( 'a', self::LABEL_LENGTH ), str_repeat( 'b', self::LABEL_LENGTH ), str_repeat( 'c', self::LABEL_LENGTH ) ) );
		$first  = $common . '.example.com';
		$second = $common . '.example.net';

		$list->add( $first, SuppressionList::BOUNCED, SuppressionList::SOURCE_MANUAL );
		$list->add( $second, SuppressionList::BOUNCED, SuppressionList::SOURCE_MANUAL );

		$this->assertSame( 2, $list->count( $common ) );
		$this->assertSame( SuppressionList::BOUNCED, $list->reason( $first, 'transactional' ) );
		$this->assertSame( SuppressionList::BOUNCED, $list->reason( $second, 'transactional' ) );
	}

	public function test_an_install_that_leaves_a_table_missing_saves_no_version(): void {
		delete_option( Schema::VERSION_OPTION );
		// The tables are there already; running dbDelta() on them would change nothing.
		add_filter( 'dbdelta_queries', '__return_empty_array' );
		$hide_deliveries = static fn ( string $query ): string => str_starts_with( $query, 'SHOW TABLES LIKE' ) && str_contains( $query, self::DELIVERIES_TABLE_SUFFIX ) ? self::FIND_NO_TABLE : $query;
		add_filter( 'query', $hide_deliveries );

		Schema::maybe_upgrade();
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );

		remove_filter( 'query', $hide_deliveries );
		Schema::maybe_upgrade();
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}
}
