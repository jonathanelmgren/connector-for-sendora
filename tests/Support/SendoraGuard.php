<?php
/**
 * Keeps every test run away from a Sendora key and Sendora's hosts.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Support;

use WP_Error;
use WpOrg\Requests\Exception as RequestsException;

/**
 * The test suite's two guards. The bootstrap refuses to start when a
 * variable or constant could hand a test a Sendora key or address, and a
 * `pre_http_request` filter refuses every request to a host that is not
 * this machine before it leaves. A test may still answer a request itself
 * with its own filter, which runs first: nothing leaves either way.
 *
 * WordPress follows redirects without passing through `pre_http_request`
 * again, so a redirect from this machine to another host is refused on
 * {@see REDIRECT_HOOK} as well.
 */
final class SendoraGuard {

	/**
	 * Variables that would hand a run a Sendora key or address: the plugin's
	 * constant's name, the SDK's own two, and the names other projects on
	 * the same machine use. Keep in step with bin/env.mjs.
	 */
	public const VARIABLES = array(
		'CONNECTOR_FOR_SENDORA_API_KEY',
		'SENDORA_API_TOKEN',
		'SENDORA_BASE_URL',
		'SENDORA_API_KEY',
		'SENDORA_API_URL',
	);

	/** The constant wp-config.php would set the key with. */
	public const KEY_CONSTANT = 'CONNECTOR_FOR_SENDORA_API_KEY';

	/** The only hosts a test request may go to. */
	public const ALLOWED_HOSTS = array( 'localhost', '127.0.0.1', '::1' );

	/** The error code of a refused request. */
	public const BLOCKED = 'connector_for_sendora_test_host_blocked';

	/**
	 * The action WordPress fires before it follows a redirect: Requests'
	 * `requests.before_redirect` hook, as WP_Requests_Hooks passes it on.
	 */
	public const REDIRECT_HOOK = 'requests-requests.before_redirect';

	/**
	 * What makes this run unsafe, one sentence each; empty when nothing
	 * does.
	 *
	 * @param callable(string): (string|false) $variable Reads an environment variable, false when unset.
	 * @param callable(string): bool           $defined  Whether a constant is defined.
	 * @return list<string>
	 */
	public static function violations( callable $variable, callable $defined ): array {
		$violations = array();
		foreach ( self::VARIABLES as $name ) {
			$value = $variable( $name );
			if ( false !== $value && '' !== $value ) {
				$violations[] = sprintf( 'The environment variable %s is set.', $name );
			}
		}
		if ( $defined( self::KEY_CONSTANT ) ) {
			$violations[] = sprintf( 'The constant %s is defined.', self::KEY_CONSTANT );
		}
		return $violations;
	}

	/**
	 * Stops the run when it is unsafe.
	 *
	 * @param list<string> $violations What {@see violations()} found.
	 */
	public static function refuse( array $violations ): void {
		if ( array() === $violations ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- no WordPress yet.
		fwrite( STDERR, 'Refusing to run: tests never use a Sendora key or Sendora\'s API. ' . implode( ' ', $violations ) . "\n" );
		exit( 1 );
	}

	/**
	 * The `pre_http_request` filter: refuses a request to any host but this
	 * machine, unless a filter before it already answered.
	 *
	 * @param false|array<string, mixed>|WP_Error $response An earlier filter's answer; false when none.
	 * @param array<string, mixed>                $args     The request's arguments.
	 * @param string                              $url      The request's address.
	 * @return false|array<string, mixed>|WP_Error
	 */
	public static function block_request( $response, $args, $url ) {
		if ( false !== $response || self::allows( (string) $url ) ) {
			return $response;
		}
		return new WP_Error( self::BLOCKED, sprintf( 'Tests may not reach %s.', $url ) );
	}

	/**
	 * The {@see REDIRECT_HOOK} action: refuses a redirect to any host but
	 * this machine. WordPress answers the request with a WP_Error holding
	 * the exception's message.
	 *
	 * @param mixed $location Where the redirect goes.
	 * @throws RequestsException The redirect leaves this machine.
	 */
	public static function block_redirect( $location ): void {
		if ( ! self::allows( (string) $location ) ) {
			throw new RequestsException( sprintf( 'Tests may not be redirected to %s.', $location ), self::BLOCKED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is.
		}
	}

	/**
	 * Whether a test may reach the address: only when it is this machine.
	 *
	 * @param string $url An address.
	 */
	private static function allows( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && in_array( trim( $host, '[]' ), self::ALLOWED_HOSTS, true );
	}
}
