<?php
/**
 * Plugin Name: Connector for Sendora — local fake
 * Description: On a wp-env site, sends through an in-memory fake instead of Sendora, and refuses every request and redirect to Sendora's hosts.
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

/** Sendora's domain; it and every host under it are refused. */
const CONNECTOR_FOR_SENDORA_DEV_SENDORA_DOMAIN = 'sendora.se';

/** Why a request to Sendora was refused. */
const CONNECTOR_FOR_SENDORA_DEV_BLOCKED_MESSAGE = 'A local site never reaches Sendora.';

/** The error code of a refused request. */
const CONNECTOR_FOR_SENDORA_DEV_BLOCKED = 'connector_for_sendora_dev_blocked';

/**
 * Whether the address is one of Sendora's hosts.
 *
 * @param string $url An address.
 */
function connector_for_sendora_dev_is_sendora( string $url ): bool {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	return CONNECTOR_FOR_SENDORA_DEV_SENDORA_DOMAIN === $host || str_ends_with( $host, '.' . CONNECTOR_FOR_SENDORA_DEV_SENDORA_DOMAIN );
}

add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		if ( connector_for_sendora_dev_is_sendora( (string) $url ) ) {
			return new WP_Error( CONNECTOR_FOR_SENDORA_DEV_BLOCKED, CONNECTOR_FOR_SENDORA_DEV_BLOCKED_MESSAGE );
		}
		return $response;
	},
	PHP_INT_MAX,
	3
);

// WordPress follows a redirect without passing through pre_http_request again.
add_action(
	'requests-requests.before_redirect', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Requests' hook, as WordPress passes it on.
	static function ( $location ): void {
		if ( connector_for_sendora_dev_is_sendora( (string) $location ) ) {
			throw new \WpOrg\Requests\Exception( CONNECTOR_FOR_SENDORA_DEV_BLOCKED_MESSAGE, CONNECTOR_FOR_SENDORA_DEV_BLOCKED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is.
		}
	}
);
