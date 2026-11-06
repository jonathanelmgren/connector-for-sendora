<?php
/**
 * A redirect, caught before the handler exits.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Support;

/**
 * Thrown from the `wp_redirect` filter, so a test sees where an admin-post
 * handler sends the browser instead of PHP exiting.
 */
final class Redirected extends \RuntimeException {

	/** The `wp_redirect` filter that throws. */
	public static function catch(): void {
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new self( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output.
			}
		);
	}
}
