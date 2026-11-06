<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora;

use ConnectorForSendora\Admin\SettingsPage;
use ConnectorForSendora\Admin\SuppressionPage;
use ConnectorForSendora\Database\Schema;
use ConnectorForSendora\Mail\Mailer;
use ConnectorForSendora\Mail\Transport;
use ConnectorForSendora\Sendora\SendoraTransport;
use ConnectorForSendora\Settings\Settings;
use ConnectorForSendora\Suppression\SuppressionList;
use ConnectorForSendora\Webhook\DeliveryLog;
use ConnectorForSendora\Webhook\Webhook;

/**
 * The plugin's entry point, called once from the main plugin file.
 */
final class Plugin {

	/** The filter that can replace the transport, for tests and local sites. */
	public const TRANSPORT_FILTER = 'connector_for_sendora_transport';

	/** Hooks everything in. */
	public static function boot(): void {
		Schema::maybe_upgrade();

		$settings    = new Settings();
		$suppression = new SuppressionList();

		( new Mailer( $settings, static fn (): Transport => self::transport( $settings ), $suppression ) )->register();
		( new Webhook( $settings, $suppression, new DeliveryLog() ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $settings ) )->register();
			( new SuppressionPage( $suppression ) )->register();
		}
	}

	/**
	 * The transport for a send: Sendora's API with the configured key,
	 * unless the `connector_for_sendora_transport` filter gives another.
	 *
	 * @param Settings $settings The settings, for the key.
	 */
	public static function transport( Settings $settings ): Transport {
		/**
		 * Filters the transport that sends the site's email. Return a
		 * `ConnectorForSendora\Mail\Transport` to send through something
		 * other than Sendora's API, such as an in-memory fake in tests;
		 * anything else keeps Sendora's API.
		 *
		 * @param Transport|null $transport Null, for Sendora's API.
		 * @param Settings       $settings  The plugin's settings.
		 */
		$transport = apply_filters( self::TRANSPORT_FILTER, null, $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is connector_for_sendora_transport.
		return $transport instanceof Transport ? $transport : new SendoraTransport( $settings->get( Settings::API_KEY ) );
	}
}
