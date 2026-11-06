<?php
/**
 * Removes everything the plugin keeps, when it is deleted.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora;

use ConnectorForSendora\Admin\SettingsPage;
use ConnectorForSendora\Admin\SuppressionImport;
use ConnectorForSendora\Database\Schema;
use ConnectorForSendora\Settings\Settings;
use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Webhook\DeliveryLog;

/**
 * Drops the plugin's tables and deletes its options and transients, on
 * every site of a network. Called from uninstall.php, which WordPress runs
 * when the plugin is deleted, not when it is deactivated.
 */
final class Uninstaller {

	/** The options the plugin saves. */
	private const OPTIONS = array(
		Settings::OPTION,
		Schema::VERSION_OPTION,
	);

	/**
	 * The transients the plugin saves for one user at a time, each followed
	 * by the user's id.
	 */
	private const USER_TRANSIENT_PREFIXES = array(
		SettingsPage::TEST_RESULT_PREFIX,
		SuppressionImport::TRANSIENT_PREFIX,
	);

	/** What the options table puts before a transient's name, for its value and for its expiry. */
	private const TRANSIENT_OPTION_PREFIXES = array( '_transient_', '_transient_timeout_' );

	/** Cleans up every site, or this one on a single site. */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/** Cleans up the current site. */
	public static function uninstall_site(): void {
		global $wpdb;

		foreach ( array( SuppressionList::table(), DeliveryLog::table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- drops the plugin's own tables on uninstall; a table name cannot be a placeholder.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::TRANSIENT_OPTION_PREFIXES as $storage ) {
			foreach ( self::USER_TRANSIENT_PREFIXES as $prefix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- per-user transients have no list to find them by.
				$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $storage . $prefix ) . '%' ) );
				// delete_option() rather than one DELETE, so the object cache forgets them too.
				array_map( 'delete_option', $names );
			}
		}
	}
}
