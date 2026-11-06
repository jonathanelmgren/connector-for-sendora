<?php
/**
 * The plugin's own database tables.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Database;

use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Webhook\DeliveryLog;

/**
 * Creates and updates the suppression list's table and the table of
 * webhook deliveries already handled. The schema's version is kept in an
 * option, so the tables are brought up to date on the first request after
 * an update as well as on activation.
 */
final class Schema {

	/** The option that holds the version of the schema the tables have. */
	public const VERSION_OPTION = 'connector_for_sendora_db_version';

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

	/** The length of a delivery id's SHA-256, in hex. */
	private const DELIVERY_HASH_LENGTH = 64;

	/** Brings the tables up to date when the schema has changed. */
	public static function maybe_upgrade(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		self::install();
	}

	/** Creates or updates the tables. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset     = $wpdb->get_charset_collate();
		$suppression = SuppressionList::table();
		$deliveries  = DeliveryLog::table();
		$email       = self::EMAIL_LENGTH;
		$prefix      = self::INDEX_PREFIX;
		$hash        = self::DELIVERY_HASH_LENGTH;

		// dbDelta() wants each column on its own line and two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$suppression} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar({$email}) NOT NULL,
  reason varchar(20) NOT NULL,
  source varchar(20) NOT NULL,
  stream_id varchar({$prefix}) DEFAULT NULL,
  suppressed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_reason (email({$prefix}),reason)
) {$charset};
CREATE TABLE {$deliveries} (
  delivery_hash char({$hash}) NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (delivery_hash),
  KEY received_at (received_at)
) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
