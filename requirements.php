<?php
/**
 * The PHP and WordPress versions the plugin needs, read by the main plugin
 * file and by uninstall.php.
 *
 * @package SendoraWP
 */

// Read by PHP versions older than the plugin needs: PHP 5.6 syntax only.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The PHP version the plugin and Sendora's SDK need. */
define( 'SENDORA_MIN_PHP', '8.2' );

/** The oldest WordPress the plugin supports. */
define( 'SENDORA_MIN_WP', '6.7' );
