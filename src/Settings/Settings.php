<?php
/**
 * The plugin's settings, from the options table or wp-config.php constants.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Settings;

use ConnectorForSendora\Vendor\Sendora\Keys;

/**
 * Reads each setting from its constant when one is defined, and from the
 * `connector_for_sendora_settings` option otherwise. A constant always wins,
 * so a value kept out of the database stays there.
 */
final class Settings {

	/** The option that holds every setting saved on the settings page. */
	public const OPTION = 'connector_for_sendora_settings';

	public const API_KEY              = 'api_key';
	public const TRANSACTIONAL_STREAM = 'transactional_stream';
	public const BROADCAST_STREAM     = 'broadcast_stream';
	public const FROM_EMAIL           = 'from_email';
	public const FROM_NAME            = 'from_name';
	public const WEBHOOK_SECRET       = 'webhook_secret';

	/** Each setting and the wp-config.php constant that can set it instead. */
	public const CONSTANTS = array(
		self::API_KEY              => 'CONNECTOR_FOR_SENDORA_API_KEY',
		self::TRANSACTIONAL_STREAM => 'CONNECTOR_FOR_SENDORA_TRANSACTIONAL_STREAM',
		self::BROADCAST_STREAM     => 'CONNECTOR_FOR_SENDORA_BROADCAST_STREAM',
		self::FROM_EMAIL           => 'CONNECTOR_FOR_SENDORA_FROM_EMAIL',
		self::FROM_NAME            => 'CONNECTOR_FOR_SENDORA_FROM_NAME',
		self::WEBHOOK_SECRET       => 'CONNECTOR_FOR_SENDORA_WEBHOOK_SECRET',
	);

	/**
	 * Looks a constant up by name: its value, or null when it is not defined.
	 *
	 * @var callable(string): mixed
	 */
	private $constant;

	/**
	 * Settings as this site has them.
	 *
	 * @param (callable(string): mixed)|null $constant Looks a constant up by name, null when undefined; PHP's own constants when not given.
	 */
	public function __construct( ?callable $constant = null ) {
		$this->constant = $constant ?? static fn ( string $name ): mixed => defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * A setting's value, trimmed; an empty string when it is not set.
	 *
	 * @param string $field One of the field constants of this class.
	 */
	public function get( string $field ): string {
		$from_constant = $this->from_constant( $field );
		if ( null !== $from_constant ) {
			return $from_constant;
		}
		$saved = self::saved();
		return isset( $saved[ $field ] ) && is_string( $saved[ $field ] ) ? trim( $saved[ $field ] ) : '';
	}

	/**
	 * Whether a constant sets this field, so the settings page shows it
	 * read-only.
	 *
	 * @param string $field One of the field constants of this class.
	 */
	public function is_constant( string $field ): bool {
		return null !== $this->from_constant( $field );
	}

	/** Whether the plugin sends the site's email: it has an API key. */
	public function is_configured(): bool {
		return '' !== $this->get( self::API_KEY );
	}

	/** Whether the key belongs to a Sendora test server, which delivers nothing. */
	public function is_test_mode(): bool {
		return $this->is_configured() && Keys::isTestKey( $this->get( self::API_KEY ) );
	}

	/**
	 * The option's saved values.
	 *
	 * @return array<string, mixed>
	 */
	public static function saved(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * The constant's value as a trimmed string, or null when no constant
	 * sets the field.
	 *
	 * @param string $field One of the field constants of this class.
	 */
	private function from_constant( string $field ): ?string {
		$name = self::CONSTANTS[ $field ] ?? null;
		if ( null === $name ) {
			return null;
		}
		$value = ( $this->constant )( $name );
		return is_scalar( $value ) ? trim( (string) $value ) : null;
	}
}
