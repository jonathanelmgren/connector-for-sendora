<?php
/**
 * Plugin Name:       Connector for Sendora
 * Description:       Sends the site's email through Sendora's API instead of PHP mail() or SMTP.
 * Version:           0.3.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Jonathan Elmgren
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       connector-for-sendora
 * Domain Path:       /languages
 *
 * @package SendoraWP
 */

/*
 * This file is read by PHP versions older than the plugin needs, so it holds
 * nothing newer than PHP 5.6 syntax: it checks the versions, and loads the
 * plugin's code only when they are met.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SENDORA_WP_FILE', __FILE__ );
define( 'SENDORA_WP_VERSION', '0.3.0' );

require_once __DIR__ . '/requirements.php';

/**
 * What keeps the plugin from running here, as sentences for an admin, or an
 * empty array when nothing does.
 *
 * @param string $php_version PHP's version.
 * @param string $wp_version  WordPress's version.
 * @return string[]
 */
function sendora_wp_requirement_errors( $php_version, $wp_version ) {
	$errors = array();
	if ( version_compare( $php_version, SENDORA_WP_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required PHP version, 2: the site's PHP version. */
			__( 'Connector for Sendora needs PHP %1$s or newer. This site runs PHP %2$s.', 'connector-for-sendora' ),
			SENDORA_WP_MIN_PHP,
			$php_version
		);
	}
	if ( version_compare( $wp_version, SENDORA_WP_MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required WordPress version, 2: the site's WordPress version. */
			__( 'Connector for Sendora needs WordPress %1$s or newer. This site runs WordPress %2$s.', 'connector-for-sendora' ),
			SENDORA_WP_MIN_WP,
			$wp_version
		);
	}
	return $errors;
}

/**
 * The requirement errors for this site.
 *
 * @return string[]
 */
function sendora_wp_site_requirement_errors() {
	return sendora_wp_requirement_errors( PHP_VERSION, get_bloginfo( 'version' ) );
}

/**
 * Refuses activation on a site that misses a requirement, with a page that
 * says which, instead of a fatal error.
 *
 * @return void
 */
function sendora_wp_activate() {
	$errors = sendora_wp_site_requirement_errors();
	if ( array() === $errors ) {
		return;
	}
	deactivate_plugins( plugin_basename( SENDORA_WP_FILE ) );
	wp_die(
		'<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p>',
		esc_html__( 'Connector for Sendora cannot be activated', 'connector-for-sendora' ),
		array( 'back_link' => true )
	);
}
register_activation_hook( __FILE__, 'sendora_wp_activate' );

/**
 * Shows the requirement errors to an admin, when the site no longer meets them.
 *
 * @return void
 */
function sendora_wp_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	foreach ( sendora_wp_site_requirement_errors() as $error ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $error ) . ' ' . esc_html__( 'The plugin is not running, and WordPress sends email as it would without it.', 'connector-for-sendora' ) . '</p></div>';
	}
}

if ( array() !== sendora_wp_site_requirement_errors() ) {
	add_action( 'admin_notices', 'sendora_wp_requirements_notice' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\SendoraWP\Plugin::boot();
