<?php
/**
 * Tests for the version check.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

/**
 * The check the main plugin file runs before it loads anything else.
 */
final class RequirementsTest extends TestCase {

	public function test_supported_versions_pass(): void {
		$this->assertSame( array(), sendora_requirement_errors( '8.2.0', '6.7' ) );
	}

	public function test_an_old_php_is_named(): void {
		$errors = sendora_requirement_errors( '8.1.29', '6.8' );

		$this->assertSame( array( 'Sendora needs PHP 8.2 or newer. This site runs PHP 8.1.29.' ), $errors );
	}

	public function test_an_old_wordpress_is_named(): void {
		$errors = sendora_requirement_errors( '8.3.0', '6.6.2' );

		$this->assertSame( array( 'Sendora needs WordPress 6.7 or newer. This site runs WordPress 6.6.2.' ), $errors );
	}
}
