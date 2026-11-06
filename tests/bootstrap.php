<?php
/**
 * PHPUnit bootstrap: the WordPress test suite with the plugin loaded, no
 * Sendora key anywhere, and every request or redirect to a host other than
 * this machine refused.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

use SendoraWP\Tests\Support\SendoraGuard;

$sendora_root = dirname( __DIR__ );

require_once $sendora_root . '/vendor/autoload.php';

// Before WordPress loads, so a key in the environment never meets the plugin.
SendoraGuard::refuse( SendoraGuard::violations( 'getenv', 'defined' ) );

$sendora_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $sendora_tests_dir || '' === $sendora_tests_dir ) {
	$sendora_tests_dir = '/wordpress-phpunit';
}

/*
 * The WordPress test suite drops and reinstalls every table under its
 * prefix, and wp-env's tests site, which Plugin Check runs in, lives in the
 * same database under `wp_`. wp-env's wp-tests-config.php reads the prefix
 * from this variable.
 */
const SENDORA_TEST_TABLE_PREFIX = 'wptests_';
$sendora_prefix                 = getenv( 'WORDPRESS_TABLE_PREFIX' );
if ( false !== $sendora_prefix && '' !== $sendora_prefix && SENDORA_TEST_TABLE_PREFIX !== $sendora_prefix ) {
	fwrite( STDERR, "Refusing to run: WORDPRESS_TABLE_PREFIX must be unset or '" . SENDORA_TEST_TABLE_PREFIX . "', not '$sendora_prefix'.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- no WordPress yet.
	exit( 1 );
}
putenv( 'WORDPRESS_TABLE_PREFIX=' . SENDORA_TEST_TABLE_PREFIX ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- read by wp-tests-config.php.

require_once $sendora_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $sendora_root ): void {
		// wp-tests-config.php has run by now: check it defined no key.
		SendoraGuard::refuse( SendoraGuard::violations( 'getenv', 'defined' ) );
		require_once $sendora_root . '/sendora.php';
	}
);

// The tests-cli user cannot write to wp-content, so uploads go to a temporary directory.
tests_add_filter(
	'upload_dir',
	static function ( array $dirs ): array {
		$base = sys_get_temp_dir() . '/sendora-uploads';
		wp_mkdir_p( $base . $dirs['subdir'] );
		$dirs['basedir'] = $base;
		$dirs['path']    = $base . $dirs['subdir'];
		return $dirs;
	}
);

tests_add_filter( 'pre_http_request', array( SendoraGuard::class, 'block_request' ), PHP_INT_MAX, 3 );
tests_add_filter( SendoraGuard::REDIRECT_HOOK, array( SendoraGuard::class, 'block_redirect' ) );

require_once $sendora_tests_dir . '/includes/bootstrap.php';
