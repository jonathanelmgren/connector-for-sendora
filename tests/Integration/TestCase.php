<?php
/**
 * The base of the integration tests.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

use SendoraWP\Plugin;
use SendoraWP\Settings\Settings;
use SendoraWP\Tests\Support\FakeTransport;
use WP_UnitTestCase;

/**
 * Every test sends through an in-memory fake. Tests that configure the
 * plugin use a made-up key, which no Sendora server knows.
 */
abstract class TestCase extends WP_UnitTestCase {

	/** A made-up live key: the plugin is configured, and in live mode. */
	protected const LIVE_KEY = 'sk_live_not_a_real_key';

	/** A made-up test key: the plugin is configured, and in test mode. */
	protected const TEST_KEY = 'sk_test_not_a_real_key';

	protected FakeTransport $transport;

	public function set_up(): void {
		parent::set_up();
		$this->transport = new FakeTransport();
		add_filter( Plugin::TRANSPORT_FILTER, fn (): FakeTransport => $this->transport );
	}

	/**
	 * Saves settings as the settings page would.
	 *
	 * @param array<string, string> $values Settings by field.
	 */
	protected function configure( array $values = array() ): void {
		update_option( Settings::OPTION, $values + array( Settings::API_KEY => self::LIVE_KEY ) );
	}

	/**
	 * Logs a new user with the role in.
	 *
	 * @param string $role The role.
	 */
	protected function log_in( string $role ): void {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
	}

	/**
	 * Settings that see these constants, and no others; a real constant
	 * could not be undefined again after its test.
	 *
	 * @param array<string, string> $constants Constants by name.
	 */
	protected static function with_constants( array $constants ): Settings {
		return new Settings( static fn ( string $name ): ?string => $constants[ $name ] ?? null );
	}
}
