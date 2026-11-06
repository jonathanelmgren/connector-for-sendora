<?php
/**
 * Tests for leaving suppressed recipients out of a send.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

use SendoraWP\Mail\Address;
use SendoraWP\Mail\Mailer;
use SendoraWP\Settings\Settings;
use SendoraWP\Suppression\SuppressionList;
use WP_Error;

/**
 * Sends through wp_mail() with addresses on the suppression list.
 */
final class SuppressionSendingTest extends TestCase {

	/** A broadcast body with the unsubscribe placeholder Sendora needs. */
	private const BROADCAST_BODY = 'News. Unsubscribe: {{ unsubscribe_url }}';

	/** The header that sends a message on the broadcast stream. */
	private const BROADCAST_HEADER = 'X-Sendora-Stream: broadcast';

	private SuppressionList $list;

	public function set_up(): void {
		parent::set_up();
		$this->configure( array( Settings::BROADCAST_STREAM => 'str_broadcast_1' ) );
		$this->list = new SuppressionList();
	}

	public function test_a_hard_bounced_address_gets_no_transactional_mail(): void {
		$this->list->add( 'bounced@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_MANUAL );
		$data = $this->record_success();

		$this->assertTrue( wp_mail( 'anna@example.com, Bounced@Example.com', 'Hello', 'Body' ) );

		$this->assertSame( array( 'anna@example.com' ), self::emails( $this->transport->sent[0]->to ) );
		$this->assertSame( array( 'bounced@example.com' => SuppressionList::BOUNCED ), $data->value['sendora_suppressed'] );
	}

	public function test_a_complaint_or_an_unsubscribe_still_gets_transactional_mail(): void {
		$this->list->add( 'complained@example.com', SuppressionList::COMPLAINED, SuppressionList::SOURCE_WEBHOOK );
		$this->list->add( 'gone@example.com', SuppressionList::UNSUBSCRIBED, SuppressionList::SOURCE_WEBHOOK );

		$this->assertTrue( wp_mail( 'complained@example.com, gone@example.com', 'Your password reset', 'Body' ) );

		$this->assertSame( array( 'complained@example.com', 'gone@example.com' ), self::emails( $this->transport->sent[0]->to ) );
	}

	public function test_a_complaint_or_an_unsubscribe_gets_no_broadcast(): void {
		$this->list->add( 'complained@example.com', SuppressionList::COMPLAINED, SuppressionList::SOURCE_WEBHOOK );
		$this->list->add( 'gone@example.com', SuppressionList::UNSUBSCRIBED, SuppressionList::SOURCE_WEBHOOK );

		$this->assertTrue( wp_mail( 'anna@example.com, complained@example.com, gone@example.com', 'News', self::BROADCAST_BODY, self::BROADCAST_HEADER ) );

		$this->assertCount( 1, $this->transport->sent );
		$this->assertSame( array( 'anna@example.com' ), self::emails( $this->transport->sent[0]->to ) );
	}

	public function test_a_suppressed_cc_is_left_out_and_the_rest_still_get_the_message(): void {
		$this->list->add( 'bounced@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_IMPORT );

		$this->assertTrue( wp_mail( 'bounced@example.com', 'Hello', 'Body', array( 'Cc: cc@example.com', 'Bcc: bounced@example.com' ) ) );

		$sent = $this->transport->sent[0];
		$this->assertSame( array( 'cc@example.com' ), self::emails( $sent->to ) );
		$this->assertSame( array(), $sent->bcc );
	}

	public function test_when_every_recipient_is_suppressed_wp_mail_fails_and_says_why(): void {
		$this->list->add( 'bounced@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK );
		$error = $this->record_failure();

		$this->assertFalse( wp_mail( 'bounced@example.com', 'Hello', 'Body' ) );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertInstanceOf( WP_Error::class, $error->value );
		$this->assertSame( Mailer::ERROR_CODE, $error->value->get_error_code() );
		$this->assertStringContainsString( 'bounced@example.com (hard bounce)', $error->value->get_error_message() );
		$data = $error->value->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( Mailer::ERROR_ALL_SUPPRESSED, $data['sendora_error_code'] );
		$this->assertSame( array( 'bounced@example.com' => SuppressionList::BOUNCED ), $data['sendora_suppressed'] );
	}

	public function test_a_broadcast_to_unsubscribed_recipients_only_fails(): void {
		$this->list->add( 'gone@example.com', SuppressionList::UNSUBSCRIBED, SuppressionList::SOURCE_WEBHOOK );
		$error = $this->record_failure();

		$this->assertFalse( wp_mail( 'gone@example.com', 'News', self::BROADCAST_BODY, self::BROADCAST_HEADER ) );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertInstanceOf( WP_Error::class, $error->value );
		$this->assertStringContainsString( 'gone@example.com (unsubscribed)', $error->value->get_error_message() );
	}

	public function test_the_filter_can_send_to_a_suppressed_address(): void {
		$this->list->add( 'bounced@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK );
		$seen = array();
		add_filter(
			SuppressionList::FILTER,
			static function ( bool $suppressed, string $email, string $stream, ?string $reason ) use ( &$seen ): bool {
				$seen[] = array( $suppressed, $email, $stream, $reason );
				return false;
			},
			10,
			4
		);

		$this->assertTrue( wp_mail( 'Bounced@example.com', 'Hello', 'Body' ) );

		$this->assertCount( 1, $this->transport->sent );
		$this->assertSame( array( array( true, 'bounced@example.com', 'transactional', SuppressionList::BOUNCED ) ), $seen );
	}

	public function test_the_filter_can_suppress_an_address_the_list_does_not_have(): void {
		add_filter( SuppressionList::FILTER, static fn ( bool $suppressed, string $email ): bool => 'anna@example.com' === $email, 10, 2 );

		$this->assertFalse( wp_mail( 'anna@example.com', 'Hello', 'Body' ) );

		$this->assertSame( array(), $this->transport->sent );
	}

	public function test_a_removed_address_gets_mail_again(): void {
		$this->list->add( 'bounced@example.com', SuppressionList::BOUNCED, SuppressionList::SOURCE_WEBHOOK );
		$this->list->remove( $this->list->find( 'bounced@example.com', 1, 0 )[0]['id'] );

		$this->assertTrue( wp_mail( 'bounced@example.com', 'Hello', 'Body' ) );

		$this->assertCount( 1, $this->transport->sent );
	}

	/**
	 * The addresses of a recipient list.
	 *
	 * @param list<Address> $addresses The recipients.
	 * @return list<string>
	 */
	private static function emails( array $addresses ): array {
		return array_map( static fn ( Address $a ): string => $a->email, $addresses );
	}

	/** Where the data of the next `wp_mail_succeeded` lands. */
	private function record_success(): \stdClass {
		$box        = new \stdClass();
		$box->value = null;
		add_action(
			'wp_mail_succeeded',
			static function ( array $data ) use ( $box ): void {
				$box->value = $data;
			}
		);
		return $box;
	}

	/** Where the error of the next `wp_mail_failed` lands. */
	private function record_failure(): \stdClass {
		$box        = new \stdClass();
		$box->value = null;
		add_action(
			'wp_mail_failed',
			static function ( WP_Error $error ) use ( $box ): void {
				$box->value = $error;
			}
		);
		return $box;
	}
}
