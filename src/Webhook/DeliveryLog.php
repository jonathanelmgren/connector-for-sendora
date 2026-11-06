<?php
/**
 * The webhook deliveries being handled or already handled.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Webhook;

use ConnectorForSendora\Database\QueryFailed;
use ConnectorForSendora\Database\Schema;

/**
 * Sendora delivers each event at least once, so a delivery's id is
 * claimed before it is handled and marked done after, and a second
 * delivery with the same id changes nothing. A claim whose handling never
 * finished, because PHP died on the way, is taken over by a retry once it
 * is {@see self::PROCESSING_LEASE_SECONDS} old. Ids are kept for
 * {@see self::RETENTION_DAYS} days, well past the day for which Sendora
 * retries a delivery.
 */
final class DeliveryLog {

	/** How long a delivery's id is kept, in days. */
	public const RETENTION_DAYS = 30;

	/**
	 * How long a claim holds while its delivery is handled, in seconds:
	 * well past the time a request is given to run.
	 */
	public const PROCESSING_LEASE_SECONDS = 300;

	/** The delivery is this request's to handle. */
	public const CLAIMED = 'claimed';

	/** The delivery was handled before. */
	public const DONE = 'done';

	/** Another request is handling the delivery now. */
	public const IN_PROGRESS = 'in_progress';

	/** A row's status while its delivery is handled. */
	private const STATUS_PROCESSING = 'processing';

	/** A row's status once its delivery is handled. */
	private const STATUS_DONE = 'done';

	/** The table's name without the site's prefix. */
	private const TABLE = 'connector_for_sendora_webhook_deliveries';

	/** How a time is written in the table, always in UTC. */
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/** The table's name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Claims the delivery for this request, unless it was handled before or
	 * another request is handling it.
	 *
	 * @param string $delivery_id The delivery's id.
	 * @return self::CLAIMED|self::DONE|self::IN_PROGRESS
	 * @throws QueryFailed The database refused the claim.
	 */
	public function claim( string $delivery_id ): string {
		global $wpdb;
		$key = self::key( $delivery_id );
		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', self::table(), self::date( $now - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );

		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (delivery_hash, status, received_at) VALUES (%s, %s, %s)', self::table(), $key, self::STATUS_PROCESSING, self::date( $now ) ) );
		if ( false === $inserted ) {
			throw QueryFailed::last( 'Claiming a webhook delivery' );
		}
		if ( 1 === $inserted ) {
			return self::CLAIMED;
		}

		$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET received_at = %s WHERE delivery_hash = %s AND status = %s AND received_at < %s', self::table(), self::date( $now ), $key, self::STATUS_PROCESSING, self::date( $now - self::PROCESSING_LEASE_SECONDS ) ) );
		if ( false === $taken ) {
			throw QueryFailed::last( 'Taking over a webhook delivery' );
		}
		if ( 1 === $taken ) {
			return self::CLAIMED;
		}

		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE delivery_hash = %s', self::table(), $key ) );
		// phpcs:enable

		// A row released since the insert is in another request's hands too: Sendora retries.
		return self::STATUS_DONE === $status ? self::DONE : self::IN_PROGRESS;
	}

	/**
	 * Marks the claimed delivery as handled.
	 *
	 * @param string $delivery_id The delivery's id.
	 * @throws QueryFailed The database refused the update.
	 */
	public function complete( string $delivery_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$updated = $wpdb->update( self::table(), array( 'status' => self::STATUS_DONE ), array( 'delivery_hash' => self::key( $delivery_id ) ), array( '%s' ), array( '%s' ) );
		if ( false === $updated ) {
			throw QueryFailed::last( 'Completing a webhook delivery' );
		}
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
	 * The key a delivery is kept under, which fits the table's key whatever
	 * the id's length.
	 *
	 * @param string $delivery_id The delivery's id.
	 */
	public static function key( string $delivery_id ): string {
		return Schema::hash( $delivery_id );
	}

	/**
	 * A time as the table writes it.
	 *
	 * @param int $timestamp The time.
	 */
	private static function date( int $timestamp ): string {
		return gmdate( self::DATE_FORMAT, $timestamp );
	}
}
