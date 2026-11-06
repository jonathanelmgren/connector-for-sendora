<?php
/**
 * Tests for the settings page.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Admin\SettingsPage;
use ConnectorForSendora\Mail\SendFailed;
use ConnectorForSendora\Settings\Settings;

/**
 * Renders the page and runs its handlers as an administrator.
 */
final class SettingsPageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->log_in( 'administrator' );
	}

	public function test_a_saved_key_is_never_written_into_the_page(): void {
		$this->configure();

		$page = $this->render( new Settings() );

		$this->assertStringNotContainsString( self::LIVE_KEY, $page );
		$this->assertStringContainsString( 'A key is saved', $page );
		$this->assertStringContainsString( 'Remove the saved key', $page );
	}

	public function test_a_key_from_a_constant_is_never_written_into_the_page(): void {
		$page = $this->render( self::with_constants( array( 'CONNECTOR_FOR_SENDORA_API_KEY' => self::LIVE_KEY ) ) );

		$this->assertStringNotContainsString( self::LIVE_KEY, $page );
		$this->assertStringContainsString( 'Set by CONNECTOR_FOR_SENDORA_API_KEY in wp-config.php.', $page );
	}

	public function test_a_field_set_by_a_constant_is_read_only_and_not_submitted(): void {
		$page = $this->render( self::with_constants( array( 'CONNECTOR_FOR_SENDORA_FROM_NAME' => 'From Config' ) ) );

		$this->assertMatchesRegularExpression( '/<input type="text" id="connector-for-sendora-from_name"\s+class="regular-text" value="From Config" readonly disabled \/>/', $page );
		$this->assertStringNotContainsString( 'name="connector_for_sendora_settings[from_name]"', $page );
		$this->assertStringContainsString( 'name="connector_for_sendora_settings[from_email]"', $page );
	}

	public function test_a_test_key_is_labelled_test_mode(): void {
		$this->configure( array( Settings::API_KEY => self::TEST_KEY ) );

		$page = $this->render( new Settings() );

		$this->assertStringContainsString( '(test mode)', $page );
		$this->assertStringContainsString( 'Test mode.', $page );
		$this->assertStringNotContainsString( self::TEST_KEY, $page );
	}

	public function test_without_a_key_the_page_says_wordpress_sends_the_mail(): void {
		$page = $this->render( new Settings() );

		$this->assertStringContainsString( 'Not sending', $page );
		$this->assertStringContainsString( 'disabled', $page );
	}

	public function test_the_test_mode_notice_shows_to_administrators_only(): void {
		$this->configure( array( Settings::API_KEY => self::TEST_KEY ) );
		$page = new SettingsPage( new Settings() );

		$this->assertStringContainsString( 'Sendora is in test mode', self::output( array( $page, 'test_mode_notice' ) ) );

		$this->log_in( 'subscriber' );
		$this->assertSame( '', self::output( array( $page, 'test_mode_notice' ) ) );
	}

	public function test_the_settings_page_shows_no_second_test_mode_notice(): void {
		$this->configure( array( Settings::API_KEY => self::TEST_KEY ) );
		set_current_screen( 'settings_page_' . SettingsPage::SLUG );

		$this->assertSame( '', self::output( array( new SettingsPage( new Settings() ), 'test_mode_notice' ) ) );
	}

	public function test_no_test_mode_notice_for_a_live_key(): void {
		$this->configure();

		$this->assertSame( '', self::output( array( new SettingsPage( new Settings() ), 'test_mode_notice' ) ) );
	}

	public function test_saving_an_empty_key_field_keeps_the_saved_key(): void {
		$this->configure();

		$saved = ( new SettingsPage( new Settings() ) )->sanitize( array( Settings::API_KEY => '' ) );

		$this->assertSame( self::LIVE_KEY, $saved[ Settings::API_KEY ] );
	}

	public function test_saving_a_new_key_replaces_the_saved_one(): void {
		$this->configure();

		$saved = ( new SettingsPage( new Settings() ) )->sanitize( array( Settings::API_KEY => ' ' . self::TEST_KEY . ' ' ) );

		$this->assertSame( self::TEST_KEY, $saved[ Settings::API_KEY ] );
	}

	public function test_the_saved_key_can_be_removed(): void {
		$this->configure();

		$saved = ( new SettingsPage( new Settings() ) )->sanitize( array( SettingsPage::REMOVE_KEY_FIELD => '1' ) );

		$this->assertSame( '', $saved[ Settings::API_KEY ] );
	}

	public function test_a_field_set_by_a_constant_keeps_its_saved_value(): void {
		$this->configure( array( Settings::FROM_NAME => 'Saved' ) );
		$page = new SettingsPage( self::with_constants( array( 'CONNECTOR_FOR_SENDORA_FROM_NAME' => 'Constant' ) ) );

		$saved = $page->sanitize( array( Settings::FROM_NAME => 'Posted' ) );

		$this->assertSame( 'Saved', $saved[ Settings::FROM_NAME ] );
	}

	public function test_an_invalid_from_address_is_not_saved(): void {
		$saved = ( new SettingsPage( new Settings() ) )->sanitize(
			array(
				Settings::FROM_EMAIL           => 'not an address',
				Settings::TRANSACTIONAL_STREAM => ' str_1 ',
			)
		);

		$this->assertSame( '', $saved[ Settings::FROM_EMAIL ] );
		$this->assertSame( 'str_1', $saved[ Settings::TRANSACTIONAL_STREAM ] );
		$this->assertNotEmpty( get_settings_errors( Settings::OPTION ) );
	}

	public function test_the_test_email_goes_through_sendora(): void {
		$this->configure();

		$result = ( new SettingsPage( new Settings() ) )->test_email( 'admin@example.com' );

		$this->assertTrue( $result['sent'] );
		$this->assertSame( 'admin@example.com', $this->transport->sent[0]->to[0]->email );
	}

	public function test_a_test_email_with_a_test_key_says_it_is_not_delivered(): void {
		$this->configure( array( Settings::API_KEY => self::TEST_KEY ) );

		$result = ( new SettingsPage( new Settings() ) )->test_email( 'admin@example.com' );

		$this->assertTrue( $result['sent'] );
		$this->assertStringContainsString( 'will not be delivered', $result['message'] );
	}

	public function test_a_failed_test_email_shows_sendora_s_error(): void {
		$this->configure();
		$this->transport->failure = new SendFailed( 'The key was revoked.', 'unauthorized', 401 );

		$result = ( new SettingsPage( new Settings() ) )->test_email( 'admin@example.com' );

		$this->assertFalse( $result['sent'] );
		$this->assertStringContainsString( 'The key was revoked.', $result['message'] );
	}

	public function test_no_test_email_without_a_key(): void {
		$result = ( new SettingsPage( new Settings() ) )->test_email( 'admin@example.com' );

		$this->assertFalse( $result['sent'] );
		$this->assertSame( array(), $this->transport->sent );
	}

	/**
	 * The settings page's HTML.
	 *
	 * @param Settings $settings The settings it shows.
	 */
	private function render( Settings $settings ): string {
		return self::output( array( new SettingsPage( $settings ), 'render' ) );
	}

	/**
	 * What a callback prints.
	 *
	 * @param callable $callback The callback.
	 */
	private static function output( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}
}
