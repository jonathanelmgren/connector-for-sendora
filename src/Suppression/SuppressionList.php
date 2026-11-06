<?php
/**
 * The addresses the site no longer sends to.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Suppression;

use SendoraWP\Database\QueryFailed;
use SendoraWP\Database\Schema;
use SendoraWP\Mail\Stream;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The suppression list, in its own table: one row per address and reason,
 * the address in lower case and keyed on its hash, which covers the whole
 * address however long it is.
 *
 * A hard bounce stops all mail to the address. A complaint or an
 * unsubscribe stops broadcasts only, so a password reset still arrives.
 * The filter `sendora_wp_is_suppressed` has the last word.
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
	public const FILTER = 'sendora_wp_is_suppressed';

	/** The table's name without the site's prefix. */
	private const TABLE = 'sendora_wp_suppressions';

	/** How many addresses one query looks up or adds. */
	private const CHUNK = 500;

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
	 * already, records the new source, stream and time. An unknown reason or
	 * source, or an empty or too long address, is refused with an
	 * \InvalidArgumentException.
	 *
	 * @param string                 $email     The address.
	 * @param string                 $reason    One of {@see self::REASONS}.
	 * @param string                 $source    One of {@see self::SOURCES}.
	 * @param string|null            $stream_id The Sendora stream the event came from; null when not known.
	 * @param DateTimeImmutable|null $when      When it happened; now when not given.
	 * @throws QueryFailed The database refused the write.
	 */
	public function add( string $email, string $reason, string $source, ?string $stream_id = null, ?DateTimeImmutable $when = null ): void {
		global $wpdb;
		$email = self::normalise( $email );
		self::check( $email, $reason, $source );
		$when = ( $when ?? new DateTimeImmutable() )->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DATE_FORMAT );

		// prepare() writes null as '', which NULLIF() turns back into NULL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$written = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (email, email_hash, reason, source, stream_id, suppressed_at) VALUES (%s, %s, %s, %s, NULLIF(%s, \'\'), %s)
				ON DUPLICATE KEY UPDATE source = VALUES(source), stream_id = VALUES(stream_id), suppressed_at = VALUES(suppressed_at)',
				self::table(),
				$email,
				Schema::hash( $email ),
				$reason,
				$source,
				$stream_id ?? '',
				$when
			)
		);
		if ( false === $written ) {
			throw QueryFailed::last( 'Adding to the suppression list' );
		}
	}

	/**
	 * Of these addresses, the ones on the list for the reason.
	 *
	 * @param list<string> $emails The addresses, normalised.
	 * @param string       $reason One of {@see self::REASONS}.
	 * @return list<string>
	 * @throws QueryFailed The database refused the lookup.
	 */
	public function listed( array $emails, string $reason ): array {
		global $wpdb;
		$listed = array();
		foreach ( array_chunk( array_values( array_unique( $emails ) ), self::CHUNK ) as $chunk ) {
			$hashes = array_map( array( Schema::class, 'hash' ), $chunk );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
			$found = $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the hashes are spread in.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %s per hash, filled in below.
					'SELECT email FROM %i WHERE reason = %s AND email_hash IN (' . implode( ', ', array_fill( 0, count( $hashes ), '%s' ) ) . ')',
					self::table(),
					$reason,
					...$hashes
				)
			);
			if ( '' !== $wpdb->last_error ) {
				throw QueryFailed::last( 'Looking up the suppression list' );
			}
			array_push( $listed, ...array_map( 'strval', $found ) );
		}
		return array_values( array_intersect( $emails, $listed ) );
	}

	/**
	 * Adds the addresses that are not on the list for the reason yet, and
	 * leaves the rows that are as they are. Refuses what {@see self::add()}
	 * refuses, before writing anything.
	 *
	 * @param list<string> $emails The addresses.
	 * @param string       $reason One of {@see self::REASONS}.
	 * @param string       $source One of {@see self::SOURCES}.
	 * @return list<string> The addresses added, normalised.
	 * @throws QueryFailed The database refused the write.
	 */
	public function add_new( array $emails, string $reason, string $source ): array {
		global $wpdb;
		$emails = array_values( array_unique( array_map( array( self::class, 'normalise' ), $emails ) ) );
		foreach ( $emails as $email ) {
			self::check( $email, $reason, $source );
		}
		$when  = gmdate( self::DATE_FORMAT );
		$added = array();
		foreach ( array_chunk( $emails, self::CHUNK ) as $chunk ) {
			$new = array_values( array_diff( $chunk, $this->listed( $chunk, $reason ) ) );
			if ( array() === $new ) {
				continue;
			}
			$values = array();
			foreach ( $new as $email ) {
				array_push( $values, $email, Schema::hash( $email ), $reason, $source, $when );
			}
			// A row added since the lookup above keeps its own source and time.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
			$written = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are spread in.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- five placeholders per address, filled in below.
					'INSERT INTO %i (email, email_hash, reason, source, suppressed_at) VALUES ' . implode( ', ', array_fill( 0, count( $new ), '(%s, %s, %s, %s, %s)' ) ) . ' ON DUPLICATE KEY UPDATE id = id',
					self::table(),
					...$values
				)
			);
			if ( false === $written ) {
				throw QueryFailed::last( 'Adding to the suppression list' );
			}
			// An existing row counts as no row written, so fewer rows mean some were added meanwhile.
			if ( count( $new ) !== $written ) {
				$new = $this->written( $new, $reason, $source, $when );
			}
			array_push( $added, ...$new );
		}
		return $added;
	}

	/**
	 * Of these addresses, the ones whose row for the reason has this source
	 * and time: the rows one {@see self::add_new()} wrote.
	 *
	 * @param list<string> $emails The addresses, normalised.
	 * @param string       $reason One of {@see self::REASONS}.
	 * @param string       $source One of {@see self::SOURCES}.
	 * @param string       $when   The time written, as the table has it.
	 * @return list<string>
	 * @throws QueryFailed The database refused the lookup.
	 */
	private function written( array $emails, string $reason, string $source, string $when ): array {
		global $wpdb;
		$hashes = array_map( array( Schema::class, 'hash' ), $emails );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; nothing caches it.
		$found = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the hashes are spread in.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %s per hash, filled in below.
				'SELECT email FROM %i WHERE reason = %s AND source = %s AND suppressed_at = %s AND email_hash IN (' . implode( ', ', array_fill( 0, count( $hashes ), '%s' ) ) . ')',
				self::table(),
				$reason,
				$source,
				$when,
				...$hashes
			)
		);
		if ( '' !== $wpdb->last_error ) {
			throw QueryFailed::last( 'Looking up the suppression list' );
		}
		return array_values( array_intersect( $emails, array_map( 'strval', $found ) ) );
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
		$suppressed = apply_filters( self::FILTER, null !== $reason, $email, $stream, $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is sendora_wp_is_suppressed.

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
			self::BOUNCED      => __( 'hard bounce', 'sendora-for-wp' ),
			self::COMPLAINED   => __( 'spam complaint', 'sendora-for-wp' ),
			self::UNSUBSCRIBED => __( 'unsubscribed', 'sendora-for-wp' ),
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
	 * Refuses a row the list cannot keep.
	 *
	 * @param string $email  The address, normalised.
	 * @param string $reason The reason.
	 * @param string $source The source.
	 * @throws \InvalidArgumentException The reason or the source is not one of the known ones, or the address is empty or too long.
	 */
	private static function check( string $email, string $reason, string $source ): void {
		if ( '' === $email || strlen( $email ) > Schema::EMAIL_LENGTH ) {
			throw new \InvalidArgumentException( 'The address is empty or too long for the suppression list.' );
		}
		if ( ! in_array( $reason, self::REASONS, true ) || ! in_array( $source, self::SOURCES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown suppression reason or source.' );
		}
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
