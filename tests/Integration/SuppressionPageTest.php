<?php
/**
 * Tests for the suppression list's screen.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Admin\SuppressionPage;
use ConnectorForSendora\Suppression\SuppressionList;

/**
 * Renders the screen as an administrator.
 */
final class SuppressionPageTest extends TestCase {

	private SuppressionList $list;

	public function set_up(): void {
		parent::set_up();
		$this->log_in( 'administrator' );
		$this->list = new SuppressionList();
		$this->list->add( 'anna@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK, 'str_1' );
		$this->list->add( 'bo@example.org', SuppressionList::UNSUBSCRIBED, SuppressionList::SOURCE_MANUAL );
	}

	public function tear_down(): void {
		unset( $_GET[ SuppressionPage::SEARCH_ARG ], $_GET[ SuppressionPage::REMOVED_ARG ] );
		parent::tear_down();
	}

	public function test_the_screen_lists_every_suppressed_address(): void {
		$page = $this->render();

		$this->assertStringContainsString( 'anna@example.com', $page );
		$this->assertStringContainsString( 'hard bounce', $page );
		$this->assertStringContainsString( 'str_1', $page );
		$this->assertStringContainsString( 'bo@example.org', $page );
		$this->assertStringContainsString( 'unsubscribed', $page );
		$this->assertStringContainsString( '<td>—</td>', $page );
		$this->assertStringContainsString( 'name="action" value="' . SuppressionPage::REMOVE_ACTION . '"', $page );
	}

	public function test_the_screen_says_sendora_keeps_its_own_list(): void {
		$this->assertStringContainsString( 'Only Sendora&#039;s support can take an address off it', $this->render() );
	}

	public function test_a_search_shows_the_matching_addresses_only(): void {
		$_GET[ SuppressionPage::SEARCH_ARG ] = 'EXAMPLE.ORG';

		$page = $this->render();

		$this->assertStringContainsString( 'bo@example.org', $page );
		$this->assertStringNotContainsString( 'anna@example.com', $page );
	}

	public function test_the_screen_is_empty_for_a_user_who_cannot_manage_options(): void {
		$this->log_in( 'editor' );

		$this->assertSame( '', $this->render() );
	}

	public function test_a_row_can_be_removed(): void {
		$row = $this->list->find( 'anna@example.com', 1, 0 )[0];

		$this->assertTrue( $this->list->remove( $row['id'] ) );
		$this->assertFalse( $this->list->remove( $row['id'] ) );
		$this->assertSame( 1, $this->list->count( '' ) );
	}

	/** The screen's HTML. */
	private function render(): string {
		ob_start();
		( new SuppressionPage( $this->list ) )->render();
		return (string) ob_get_clean();
	}
}
