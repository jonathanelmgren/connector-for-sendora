<?php
/**
 * The plugin's own database tables.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Database;

use SendoraWP\Suppression\SuppressionList;
use SendoraWP\Webhook\DeliveryLog;

/**
 * Creates and updates the suppression list's table and the table of
 * webhook deliveries already handled. The schema's version is kept in an
 * option, so the tables are brought up to date on the first request after
 * an update as well as on activation.
 */
final class Schema {

	/** The option that holds the version of the schema the tables have. */
	public const VERSION_OPTION = 'sendora_wp_db_version';

	/** The schema's version; raise it with every change to a table below. */
	public const VERSION = '1';

	/**
	 * The longest address the list keeps: RFC 5321's limit on a path,
	 * less its angle brackets.
	 */
	public const EMAIL_LENGTH = 254;

	/**
	 * How much of a column an index covers: 191 characters of utf8mb4 fit
	 * the 767 bytes an InnoDB index without large prefixes takes.
	 */
	private const INDEX_PREFIX = 191;

	/**
	 * The hash a value is keyed on when the value itself may be too long
	 * for a key: an address, a delivery's id.
	 */
	private const HASH_ALGO = 'sha256';

	/** The length of a {@see self::HASH_ALGO} hash, in hex. */
	private const HASH_LENGTH = 64;

	/**
	 * The key a value is kept under, in a column of {@see self::HASH_LENGTH}
	 * characters.
	 *
	 * @param string $value The value.
	 */
	public static function hash( string $value ): string {
		return hash( self::HASH_ALGO, $value );
	}

	/** Brings the tables up to date when the schema has changed. */
	public static function maybe_upgrade(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Creates or updates the tables. The version is saved only once both
	 * tables are there, so a failed install is tried again on the next
	 * request.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset     = $wpdb->get_charset_collate();
		$suppression = SuppressionList::table();
		$deliveries  = DeliveryLog::table();
		$email       = self::EMAIL_LENGTH;
		$prefix      = self::INDEX_PREFIX;
		$hash        = self::HASH_LENGTH;

		// dbDelta() wants each column on its own line and two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$suppression} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar({$email}) NOT NULL,
  email_hash char({$hash}) NOT NULL,
  reason varchar(20) NOT NULL,
  source varchar(20) NOT NULL,
  stream_id varchar({$prefix}) DEFAULT NULL,
  suppressed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_reason (email_hash,reason),
  KEY email (email({$prefix}))
) {$charset};
CREATE TABLE {$deliveries} (
  delivery_hash char({$hash}) NOT NULL,
  status varchar(20) NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (delivery_hash),
  KEY received_at (received_at)
) {$charset};"
		);

		if ( self::exists( $suppression ) && self::exists( $deliveries ) ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Whether the table is there.
	 *
	 * @param string $table The table's name.
	 */
	private static function exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks the schema once, after an install.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
