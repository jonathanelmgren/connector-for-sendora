<?php
/**
 * Tests for what deleting the plugin removes.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Admin\SettingsPage;
use ConnectorForSendora\Admin\SuppressionImport;
use ConnectorForSendora\Database\Schema;
use ConnectorForSendora\Settings\Settings;
use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Uninstaller;
use ConnectorForSendora\Webhook\DeliveryLog;

/**
 * Uninstalling drops the tables and deletes the options and transients,
 * and leaves everything else alone.
 *
 * Dropping a real table commits the test's transaction, so the test runs
 * without the suite's temporary tables, puts the tables back itself and
 * commits that, leaving the database as the other tests expect it.
 */
final class UninstallerTest extends TestCase {

	/** A user id for the per-user transients. */
	private const USER_ID = 7;

	/** Another plugin's option, which uninstalling leaves alone. */
	private const OTHER_OPTION = 'another_plugin_settings';

	/** Another plugin's transient, which uninstalling leaves alone. */
	private const OTHER_TRANSIENT = 'another_plugin_cache';

	public function test_uninstalling_removes_the_tables_options_and_transients_only(): void {
		$this->configure();
		( new SuppressionList() )->add( 'anna@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_MANUAL );
		set_transient( SettingsPage::TEST_RESULT_PREFIX . self::USER_ID, array( 'sent' => true ), HOUR_IN_SECONDS );
		set_transient( SuppressionImport::TRANSIENT_PREFIX . self::USER_ID, array( 'state' => 'preview' ), HOUR_IN_SECONDS );
		update_option( self::OTHER_OPTION, 'kept' );
		set_transient( self::OTHER_TRANSIENT, 'kept', HOUR_IN_SECONDS );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Uninstaller::uninstall();

		$this->assertFalse( self::table_exists( SuppressionList::table() ) );
		$this->assertFalse( self::table_exists( DeliveryLog::table() ) );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_transient( SettingsPage::TEST_RESULT_PREFIX . self::USER_ID ) );
		$this->assertFalse( get_transient( SuppressionImport::TRANSIENT_PREFIX . self::USER_ID ) );
		$this->assertSame( 'kept', get_option( self::OTHER_OPTION ) );
		$this->assertSame( 'kept', get_transient( self::OTHER_TRANSIENT ) );

		delete_option( self::OTHER_OPTION );
		delete_transient( self::OTHER_TRANSIENT );
		Schema::install();
		self::commit_transaction();

		$this->assertTrue( self::table_exists( SuppressionList::table() ) );
		$this->assertTrue( self::table_exists( DeliveryLog::table() ) );
	}

	/**
	 * Whether the table is there.
	 *
	 * @param string $table The table's name.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks the schema.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
