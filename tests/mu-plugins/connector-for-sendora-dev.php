<?php
/**
 * Plugin Name: Connector for Sendora — local fake
 * Description: On a wp-env site, sends through an in-memory fake instead of Sendora, and refuses every request to Sendora's hosts.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

// The PHPUnit bootstrap sets up its own guards.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

/** Where the tests directory is mapped in wp-env (see .wp-env.json). */
const CONNECTOR_FOR_SENDORA_DEV_TESTS_DIR = WP_CONTENT_DIR . '/connector-for-sendora-tests';

/** The option the fake keeps the last messages in, for a look with WP-CLI. */
const CONNECTOR_FOR_SENDORA_DEV_OUTBOX = 'connector_for_sendora_dev_outbox';

/** How many messages the outbox keeps. */
const CONNECTOR_FOR_SENDORA_DEV_OUTBOX_SIZE = 20;

add_filter(
	'connector_for_sendora_transport',
	static function () {
		require_once CONNECTOR_FOR_SENDORA_DEV_TESTS_DIR . '/Support/OutboxTransport.php';
		return new \ConnectorForSendora\Tests\Support\OutboxTransport( CONNECTOR_FOR_SENDORA_DEV_OUTBOX, CONNECTOR_FOR_SENDORA_DEV_OUTBOX_SIZE );
	},
	PHP_INT_MAX
);

add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( 'sendora.se' === $host || str_ends_with( $host, '.sendora.se' ) ) {
			return new WP_Error( 'connector_for_sendora_dev_blocked', 'A local site never reaches Sendora.' );
		}
		return $response;
	},
	PHP_INT_MAX,
	3
);
