<?php
/**
 * Constants the main plugin file defines, for PHPStan.
 *
 * @package SendoraWP
 */

define( 'SENDORA_WP_FILE', dirname( __DIR__ ) . '/sendora-for-wp.php' );
define( 'SENDORA_WP_VERSION', '0.3.0' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own, which requirements.php checks for.
}
require_once dirname( __DIR__ ) . '/requirements.php';
