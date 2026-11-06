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
	 * by the user's id, and the capability a user needs to have one.
	 */
	private const USER_TRANSIENTS = array(
		SettingsPage::TEST_RESULT_PREFIX    => SettingsPage::CAPABILITY,
		SuppressionImport::TRANSIENT_PREFIX => SuppressionImport::CAPABILITY,
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- drops the plugin's own tables on uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::USER_TRANSIENTS as $prefix => $capability ) {
			// With a persistent object cache, transients are kept there and not in the options table.
			foreach ( self::users_with( $capability ) as $user_id ) {
				delete_transient( $prefix . $user_id );
			}
			// Without one, they are rows in the options table, found whoever's they are.
			foreach ( self::TRANSIENT_OPTION_PREFIXES as $storage ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- per-user transients have no list to find them by.
				$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $storage . $prefix ) . '%' ) );
				// delete_option() rather than one DELETE, so the object cache forgets them too.
				array_map( 'delete_option', $names );
			}
		}
	}

	/**
	 * The ids of the users on the current site with the capability, and of
	 * a network's super admins, who have every capability.
	 *
	 * @param string $capability The capability.
	 * @return list<int>
	 */
	private static function users_with( string $capability ): array {
		$ids = array_map(
			'intval',
			get_users(
				array(
					'capability' => $capability,
					'fields'     => 'ID',
				)
			)
		);
		foreach ( is_multisite() ? get_super_admins() : array() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( false !== $user ) {
				$ids[] = $user->ID;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
