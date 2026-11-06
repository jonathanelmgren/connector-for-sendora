<?php
/**
 * A query on the plugin's tables that the database refused.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Database;

use RuntimeException;

/**
 * The database answered a write with an error, so nothing was stored.
 */
final class QueryFailed extends RuntimeException {

	/**
	 * The failure of the last query.
	 *
	 * @param string $what What the query was for.
	 */
	public static function last( string $what ): self {
		global $wpdb;
		return new self( $what . ': ' . $wpdb->last_error );
	}
}
