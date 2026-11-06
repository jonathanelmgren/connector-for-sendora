<?php
/**
 * Tests for reading the settings.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Settings\Settings;

/**
 * Constants are looked up through the lookup the settings take, since a
 * real constant could not be undefined again after its test.
 */
final class SettingsTest extends TestCase {

	public function test_a_saved_value_is_read_trimmed(): void {
		$this->configure( array( Settings::FROM_NAME => '  Shop  ' ) );

		$this->assertSame( 'Shop', ( new Settings() )->get( Settings::FROM_NAME ) );
	}

	public function test_a_constant_wins_over_the_saved_value(): void {
		$this->configure( array( Settings::FROM_EMAIL => 'saved@example.com' ) );
		$settings = self::with_constants( array( 'CONNECTOR_FOR_SENDORA_FROM_EMAIL' => 'constant@example.com' ) );

		$this->assertSame( 'constant@example.com', $settings->get( Settings::FROM_EMAIL ) );
		$this->assertTrue( $settings->is_constant( Settings::FROM_EMAIL ) );
		$this->assertFalse( $settings->is_constant( Settings::FROM_NAME ) );
	}

	public function test_every_setting_has_its_constant(): void {
		$this->assertSame(
			array(
				Settings::API_KEY              => 'CONNECTOR_FOR_SENDORA_API_KEY',
				Settings::TRANSACTIONAL_STREAM => 'CONNECTOR_FOR_SENDORA_TRANSACTIONAL_STREAM',
				Settings::BROADCAST_STREAM     => 'CONNECTOR_FOR_SENDORA_BROADCAST_STREAM',
				Settings::FROM_EMAIL           => 'CONNECTOR_FOR_SENDORA_FROM_EMAIL',
				Settings::FROM_NAME            => 'CONNECTOR_FOR_SENDORA_FROM_NAME',
				Settings::WEBHOOK_SECRET       => 'CONNECTOR_FOR_SENDORA_WEBHOOK_SECRET',
			),
			Settings::CONSTANTS
		);
	}

	public function test_without_a_key_the_plugin_is_not_configured(): void {
		$this->assertFalse( ( new Settings() )->is_configured() );
		$this->assertFalse( ( new Settings() )->is_test_mode() );
	}

	public function test_a_key_from_a_constant_configures_the_plugin(): void {
		$settings = self::with_constants( array( 'CONNECTOR_FOR_SENDORA_API_KEY' => self::LIVE_KEY ) );

		$this->assertTrue( $settings->is_configured() );
		$this->assertFalse( $settings->is_test_mode() );
	}

	public function test_a_test_server_s_key_is_test_mode(): void {
		$this->configure( array( Settings::API_KEY => self::TEST_KEY ) );

		$this->assertTrue( ( new Settings() )->is_test_mode() );
	}
}
