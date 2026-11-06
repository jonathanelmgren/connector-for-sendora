<?php
/**
 * The webhook deliveries already handled.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Webhook;

/**
 * Sendora delivers each event at least once, so a delivery's id is
 * recorded when it is handled, and a second delivery with the same id
 * changes nothing. Ids are kept for {@see self::RETENTION_DAYS} days,
 * well past the day for which Sendora retries a delivery.
 */
final class DeliveryLog {

	/** How long a handled delivery's id is kept, in days. */
	public const RETENTION_DAYS = 30;

	/** The table's name without the site's prefix. */
	private const TABLE = 'connector_for_sendora_webhook_deliveries';

	/** The hash a delivery's id is kept as, which fits the table's key whatever the id's length. */
	private const HASH = 'sha256';

	/** How a time is written in the table, always in UTC. */
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/** The table's name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Records the delivery as handled, unless it was already.
	 *
	 * @param string $delivery_id The delivery's id.
	 * @return bool True when this is its first arrival; false when it was handled before.
	 */
	public function claim( string $delivery_id ): bool {
		global $wpdb;
		$now = gmdate( self::DATE_FORMAT );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', self::table(), gmdate( self::DATE_FORMAT, time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (delivery_hash, received_at) VALUES (%s, %s)', self::table(), self::key( $delivery_id ), $now ) );
		return 1 === $inserted;
	}

	/**
	 * Forgets the delivery, so that Sendora's retry of it is handled.
	 *
	 * @param string $delivery_id The delivery's id.
	 */
	public function release( string $delivery_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$wpdb->delete( self::table(), array( 'delivery_hash' => self::key( $delivery_id ) ), array( '%s' ) );
	}

	/**
	 * The key a delivery is kept under.
	 *
	 * @param string $delivery_id The delivery's id.
	 */
	private static function key( string $delivery_id ): string {
		return hash( self::HASH, $delivery_id );
	}
}
