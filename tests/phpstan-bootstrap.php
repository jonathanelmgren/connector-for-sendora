<?php
/**
 * Constants the main plugin file defines, for PHPStan.
 *
 * @package SendoraWP
 */

define( 'SENDORA_FILE', dirname( __DIR__ ) . '/sendora.php' );
define( 'SENDORA_VERSION', '0.1.0' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own, which requirements.php checks for.
}
require_once dirname( __DIR__ ) . '/requirements.php';
