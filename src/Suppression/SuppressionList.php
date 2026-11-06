<?php
/**
 * The addresses the site no longer sends to.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Suppression;

use ConnectorForSendora\Mail\Stream;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The suppression list, in its own table: one row per address and reason,
 * the address in lower case.
 *
 * A hard bounce stops all mail to the address. A complaint or an
 * unsubscribe stops broadcasts only, so a password reset still arrives.
 * The filter `connector_for_sendora_is_suppressed` has the last word.
 */
final class SuppressionList {

	/** The address bounced for good. */
	public const BOUNCED = 'bounced';

	/** The recipient reported a message as spam. */
	public const COMPLAINED = 'complained';

	/** The recipient unsubscribed. */
	public const UNSUBSCRIBED = 'unsubscribed';

	/** Every reason, in the order the list shows them. */
	public const REASONS = array( self::BOUNCED, self::COMPLAINED, self::UNSUBSCRIBED );

	/** Added from one of Sendora's webhook events. */
	public const SOURCE_WEBHOOK = 'webhook';

	/** Added by an import. */
	public const SOURCE_IMPORT = 'import';

	/** Added by hand. */
	public const SOURCE_MANUAL = 'manual';

	/** Every source. */
	public const SOURCES = array( self::SOURCE_WEBHOOK, self::SOURCE_IMPORT, self::SOURCE_MANUAL );

	/** The filter that can override whether a recipient is skipped. */
	public const FILTER = 'connector_for_sendora_is_suppressed';

	/** The table's name without the site's prefix. */
	private const TABLE = 'connector_for_sendora_suppressions';

	/** How a time is written in the table, always in UTC. */
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/** The table's name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * An address as the list keeps it.
	 *
	 * @param string $email The address.
	 */
	public static function normalise( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Adds the address for the reason, or, when it is there for that reason
	 * already, records the new source, stream and time.
	 *
	 * @param string                 $email     The address.
	 * @param string                 $reason    One of {@see self::REASONS}.
	 * @param string                 $source    One of {@see self::SOURCES}.
	 * @param string|null            $stream_id The Sendora stream the event came from; null when not known.
	 * @param DateTimeImmutable|null $when      When it happened; now when not given.
	 * @throws \InvalidArgumentException The reason or the source is not one of the known ones, or the address is empty or too long.
	 */
	public function add( string $email, string $reason, string $source, ?string $stream_id = null, ?DateTimeImmutable $when = null ): void {
		global $wpdb;
		$email = self::normalise( $email );
		if ( '' === $email || strlen( $email ) > \ConnectorForSendora\Database\Schema::EMAIL_LENGTH ) {
			throw new \InvalidArgumentException( 'The address is empty or too long for the suppression list.' );
		}
		if ( ! in_array( $reason, self::REASONS, true ) || ! in_array( $source, self::SOURCES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown suppression reason or source.' );
		}
		$when = ( $when ?? new DateTimeImmutable() )->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DATE_FORMAT );

		// prepare() writes null as '', which NULLIF() turns back into NULL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (email, reason, source, stream_id, suppressed_at) VALUES (%s, %s, %s, NULLIF(%s, \'\'), %s)
				ON DUPLICATE KEY UPDATE source = VALUES(source), stream_id = VALUES(stream_id), suppressed_at = VALUES(suppressed_at)',
				self::table(),
				$email,
				$reason,
				$source,
				$stream_id ?? '',
				$when
			)
		);
	}

	/**
	 * Removes one row of the list.
	 *
	 * @param int $id The row's id.
	 * @return bool Whether there was such a row.
	 */
	public function remove( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		return 1 === $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Why mail on this stream to the address is skipped, or null when it
	 * is sent. A bounce wins over the other reasons, since it stops every
	 * stream.
	 *
	 * @param string $email  The address.
	 * @param string $stream {@see Stream::TRANSACTIONAL} or {@see Stream::BROADCAST}.
	 */
	public function reason( string $email, string $stream ): ?string {
		$email   = self::normalise( $email );
		$reasons = $this->reasons_for( $email );
		$reason  = null;
		if ( in_array( self::BOUNCED, $reasons, true ) ) {
			$reason = self::BOUNCED;
		} elseif ( Stream::BROADCAST === $stream && array() !== $reasons ) {
			$reason = $reasons[0];
		}

		/**
		 * Filters whether mail to an address is skipped. Return true to skip
		 * it, false to send it.
		 *
		 * @param bool        $suppressed Whether the suppression list skips it.
		 * @param string      $email      The address, in lower case.
		 * @param string      $stream     `'transactional'` or `'broadcast'`.
		 * @param string|null $reason     `'bounced'`, `'complained'` or `'unsubscribed'` when the list skips it; null otherwise.
		 */
		$suppressed = apply_filters( self::FILTER, null !== $reason, $email, $stream, $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is connector_for_sendora_is_suppressed.

		if ( true !== $suppressed ) {
			return null;
		}
		// A filter that skips an address the list does not have still needs a reason to name.
		return $reason ?? ( $reasons[0] ?? self::BOUNCED );
	}

	/**
	 * Rows of the list, newest first.
	 *
	 * @param string $search   Part of an address; empty for every row.
	 * @param int    $limit    How many rows.
	 * @param int    $offset   How many rows to skip.
	 * @return list<array{id: int, email: string, reason: string, source: string, stream_id: string|null, suppressed_at: string}>
	 */
	public function find( string $search, int $limit, int $offset ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, email, reason, source, stream_id, suppressed_at FROM %i WHERE email LIKE %s ORDER BY suppressed_at DESC, id DESC LIMIT %d OFFSET %d',
				self::table(),
				self::like( $search ),
				$limit,
				$offset
			),
			ARRAY_A
		);
		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[] = array(
				'id'            => (int) $row['id'],
				'email'         => (string) $row['email'],
				'reason'        => (string) $row['reason'],
				'source'        => (string) $row['source'],
				'stream_id'     => null === $row['stream_id'] ? null : (string) $row['stream_id'],
				'suppressed_at' => (string) $row['suppressed_at'],
			);
		}
		return $found;
	}

	/**
	 * How many rows match the search.
	 *
	 * @param string $search Part of an address; empty for every row.
	 */
	public function count( string $search ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email LIKE %s', self::table(), self::like( $search ) ) );
	}

	/**
	 * A reason as an admin reads it.
	 *
	 * @param string $reason One of {@see self::REASONS}.
	 */
	public static function reason_label( string $reason ): string {
		return match ( $reason ) {
			self::BOUNCED      => __( 'hard bounce', 'connector-for-sendora' ),
			self::COMPLAINED   => __( 'spam complaint', 'connector-for-sendora' ),
			self::UNSUBSCRIBED => __( 'unsubscribed', 'connector-for-sendora' ),
			default            => $reason,
		};
	}

	/**
	 * The reasons the address is on the list for, in {@see self::REASONS}'
	 * order.
	 *
	 * @param string $email The address, normalised.
	 * @return list<string>
	 */
	private function reasons_for( string $email ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read once per recipient of a send.
		$found = $wpdb->get_col( $wpdb->prepare( 'SELECT reason FROM %i WHERE email = %s', self::table(), $email ) );
		return array_values( array_intersect( self::REASONS, array_map( 'strval', $found ) ) );
	}

	/**
	 * A LIKE pattern that finds the text anywhere in an address.
	 *
	 * @param string $search The text.
	 */
	private static function like( string $search ): string {
		global $wpdb;
		return '%' . $wpdb->esc_like( self::normalise( $search ) ) . '%';
	}
}
