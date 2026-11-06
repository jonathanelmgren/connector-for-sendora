<?php
/**
 * Tests for the test suite's own guards.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Tests\Support\SendoraGuard;
use WP_Error;

/**
 * The guards that keep a Sendora key and Sendora's hosts out of every run.
 */
final class SendoraGuardTest extends TestCase {

	public function test_this_run_has_no_sendora_key(): void {
		$this->assertSame( array(), SendoraGuard::violations( 'getenv', 'defined' ) );
	}

	public function test_each_sendora_variable_makes_a_run_unsafe(): void {
		foreach ( SendoraGuard::VARIABLES as $name ) {
			$violations = SendoraGuard::violations(
				static fn ( string $variable ): string|false => $variable === $name ? 'sk_live_x' : false,
				static fn (): bool => false
			);
			$this->assertSame( array( "The environment variable $name is set." ), $violations );
		}
	}

	public function test_an_empty_variable_is_unset(): void {
		$this->assertSame( array(), SendoraGuard::violations( static fn (): string => '', static fn (): bool => false ) );
	}

	public function test_the_key_constant_makes_a_run_unsafe(): void {
		$violations = SendoraGuard::violations(
			static fn (): bool => false,
			static fn ( string $name ): bool => SendoraGuard::KEY_CONSTANT === $name
		);

		$this->assertSame( array( 'The constant CONNECTOR_FOR_SENDORA_API_KEY is defined.' ), $violations );
	}

	public function test_a_request_to_sendora_is_refused(): void {
		$response = wp_remote_post( 'https://api.sendora.se/v1/email' );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( SendoraGuard::BLOCKED, $response->get_error_code() );
	}

	public function test_a_request_to_any_other_remote_host_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, SendoraGuard::block_request( false, array(), 'https://example.com/' ) );
	}

	public function test_a_request_to_this_machine_is_let_through(): void {
		$this->assertFalse( SendoraGuard::block_request( false, array(), 'http://localhost:8080/' ) );
		$this->assertFalse( SendoraGuard::block_request( false, array(), 'http://[::1]/' ) );
	}

	public function test_an_answer_a_test_gave_is_kept(): void {
		$answer = array( 'body' => 'fake' );

		$this->assertSame( $answer, SendoraGuard::block_request( $answer, array(), 'https://api.sendora.se/' ) );
	}
}
