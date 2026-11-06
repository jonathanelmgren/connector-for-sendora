<?php
/**
 * Run by WordPress when the plugin is deleted: drops its tables and deletes
 * its options, on every site of a network.
 *
 * @package ConnectorForSendora
 */

/*
 * Like the main plugin file, this file may be read by a PHP older than the
 * plugin needs, so it holds nothing newer than PHP 5.6 syntax. The plugin's
 * code never ran on such a PHP and left nothing behind there to remove,
 * unless the site ran a newer PHP before; its data then stays.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/requirements.php';

if ( version_compare( PHP_VERSION, CONNECTOR_FOR_SENDORA_MIN_PHP, '<' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\ConnectorForSendora\Uninstaller::uninstall();
