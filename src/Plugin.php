<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP;

use SendoraWP\Admin\SettingsPage;
use SendoraWP\Admin\SuppressionImport;
use SendoraWP\Admin\SuppressionPage;
use SendoraWP\Cli\ImportCommand;
use SendoraWP\Database\Schema;
use SendoraWP\Import\Importer;
use SendoraWP\Mail\Mailer;
use SendoraWP\Mail\Transport;
use SendoraWP\Sendora\SendoraTransport;
use SendoraWP\Settings\Settings;
use SendoraWP\Suppression\SuppressionList;
use SendoraWP\Webhook\DeliveryLog;
use SendoraWP\Webhook\Webhook;

/**
 * The plugin's entry point, called once from the main plugin file.
 */
final class Plugin {

	/** The filter that can replace the transport, for tests and local sites. */
	public const TRANSPORT_FILTER = 'sendora_wp_transport';

	/** Hooks everything in. */
	public static function boot(): void {
		Schema::maybe_upgrade();

		$settings    = new Settings();
		$suppression = new SuppressionList();
		$importer    = new Importer( $suppression );

		( new Mailer( $settings, static fn (): Transport => self::transport( $settings ), $suppression ) )->register();
		( new Webhook( $settings, $suppression, new DeliveryLog() ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $settings ) )->register();
			( new SuppressionPage( $suppression, new SuppressionImport( $importer ) ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new ImportCommand( $importer ) )->register();
		}
	}

	/**
	 * The transport for a send: Sendora's API with the configured key,
	 * unless the `sendora_wp_transport` filter gives another.
	 *
	 * @param Settings $settings The settings, for the key.
	 */
	public static function transport( Settings $settings ): Transport {
		/**
		 * Filters the transport that sends the site's email. Return a
		 * `SendoraWP\Mail\Transport` to send through something
		 * other than Sendora's API, such as an in-memory fake in tests;
		 * anything else keeps Sendora's API.
		 *
		 * @param Transport|null $transport Null, for Sendora's API.
		 * @param Settings       $settings  The plugin's settings.
		 */
		$transport = apply_filters( self::TRANSPORT_FILTER, null, $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is sendora_wp_transport.
		return $transport instanceof Transport ? $transport : new SendoraTransport( $settings->get( Settings::API_KEY ) );
	}
}
