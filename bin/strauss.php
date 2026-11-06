<?php
/**
 * Runs Strauss on the Composer project in the working directory, keeping
 * Composer's own `vendor/composer/InstalledVersions.php` unprefixed.
 *
 *   php bin/strauss.php
 *
 * Strauss's last step prefixes the Composer runtime files in its target
 * directory, which here is `vendor/` itself. The `composer dump-autoload` that
 * follows rewrites `ClassLoader.php` and the `autoload_*.php` files, but not
 * `InstalledVersions.php`, so `Composer\InstalledVersions` would stay declared
 * as `SendoraWP\Vendor\Composer\InstalledVersions` and fail to load.
 * Strauss has no setting to skip that step, so the file is kept and put back.
 *
 * Strauss comes from this checkout's dev install, so the release build can run
 * it on a staging directory that holds production packages only.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

$strauss            = dirname( __DIR__ ) . '/vendor/brianhenryie/strauss/bin/strauss';
$installed_versions = getcwd() . '/vendor/composer/InstalledVersions.php';

$original = file_get_contents( $installed_versions );
if ( false === $original ) {
	fwrite( STDERR, "strauss.php: cannot read {$installed_versions}\n" );
	exit( 1 );
}

passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $strauss ), $status );

if ( false === file_put_contents( $installed_versions, $original ) ) {
	fwrite( STDERR, "strauss.php: cannot restore {$installed_versions}\n" );
	exit( 1 );
}

exit( $status );
