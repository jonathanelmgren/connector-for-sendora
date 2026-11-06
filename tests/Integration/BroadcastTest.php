<?php
/**
 * Tests for the choice of stream and Sendora's rules for broadcasts.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

use SendoraWP\Mail\Address;
use SendoraWP\Mail\Broadcast;
use SendoraWP\Mail\Message;
use SendoraWP\Mail\MessageBuilder;
use SendoraWP\Mail\SendFailed;
use SendoraWP\Mail\Stream;
use SendoraWP\Settings\Settings;
use WP_Error;

/**
 * Sends through wp_mail() itself, with both streams configured.
 */
final class BroadcastTest extends TestCase {

	private const TRANSACTIONAL_STREAM = 'str_transactional';

	private const BROADCAST_STREAM = 'str_broadcast';

	/** An HTTP status Sendora answers a refused body with. */
	private const STATUS_UNPROCESSABLE = 422;

	private const HEADER_BROADCAST = 'X-Sendora-Stream: broadcast';

	private const TEXT_WITH_LINK = "News.\n\nUnsubscribe: {{ unsubscribe_url }}";

	private const HTML_WITH_LINK = '<p>News.</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';

	private const HTML_TYPE = 'Content-Type: text/html; charset=UTF-8';

	/**
	 * The errors wp_mail_failed was fired with.
	 *
	 * @var list<WP_Error>
	 */
	private array $errors = array();

	public function set_up(): void {
		parent::set_up();
		$this->configure(
			array(
				Settings::TRANSACTIONAL_STREAM => self::TRANSACTIONAL_STREAM,
				Settings::BROADCAST_STREAM     => self::BROADCAST_STREAM,
			)
		);
		$this->errors = array();
		add_action(
			'wp_mail_failed',
			function ( WP_Error $error ): void {
				$this->errors[] = $error;
			}
		);
	}

	public function test_a_message_is_transactional_by_default(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body' ) );

		$message = $this->transport->sent[0];
		$this->assertSame( self::TRANSACTIONAL_STREAM, $message->stream_id );
		$this->assertFalse( $message->broadcast );
	}

	public function test_the_stream_header_puts_the_message_on_the_broadcast_stream_and_is_not_sent(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'News', self::TEXT_WITH_LINK, array( self::HEADER_BROADCAST, 'X-Campaign: 7' ) ) );

		$message = $this->transport->sent[0];
		$this->assertSame( self::BROADCAST_STREAM, $message->stream_id );
		$this->assertTrue( $message->broadcast );
		$this->assertSame( array( 'X-Campaign' => '7' ), $message->headers );
	}

	public function test_the_stream_header_is_read_whatever_its_case(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'News', self::TEXT_WITH_LINK, 'x-sendora-stream:  Broadcast ' ) );

		$this->assertSame( self::BROADCAST_STREAM, $this->transport->sent[0]->stream_id );
	}

	public function test_any_other_stream_header_value_is_transactional_and_is_not_sent(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body', 'X-Sendora-Stream: marketing' ) );

		$message = $this->transport->sent[0];
		$this->assertSame( self::TRANSACTIONAL_STREAM, $message->stream_id );
		$this->assertSame( array(), $message->headers );
	}

	public function test_the_stream_filter_puts_the_message_on_the_broadcast_stream(): void {
		$calls = array();
		add_filter(
			Stream::FILTER,
			static function ( string $stream, array $atts ) use ( &$calls ): string {
				$calls[] = array( $stream, $atts );
				return 'Newsletter' === $atts['subject'] ? Stream::BROADCAST : $stream;
			},
			10,
			2
		);

		$this->assertTrue( wp_mail( 'anna@example.com', 'Newsletter', self::TEXT_WITH_LINK ) );
		$this->assertTrue( wp_mail( 'anna@example.com', 'Your order', 'Body' ) );

		$this->assertSame( self::BROADCAST_STREAM, $this->transport->sent[0]->stream_id );
		$this->assertSame( self::TRANSACTIONAL_STREAM, $this->transport->sent[1]->stream_id );
		$this->assertSame( Stream::TRANSACTIONAL, $calls[0][0] );
		$this->assertSame( 'anna@example.com', $calls[0][1]['to'] );
		$this->assertSame( self::TEXT_WITH_LINK, $calls[0][1]['message'] );
	}

	public function test_the_stream_filter_sees_the_header_s_choice_and_can_overrule_it(): void {
		$seen = null;
		add_filter(
			Stream::FILTER,
			static function ( string $stream ) use ( &$seen ): string {
				$seen = $stream;
				return Stream::TRANSACTIONAL;
			}
		);

		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body', self::HEADER_BROADCAST ) );

		$this->assertSame( Stream::BROADCAST, $seen );
		$this->assertSame( self::TRANSACTIONAL_STREAM, $this->transport->sent[0]->stream_id );
	}

	public function test_any_other_stream_filter_value_is_transactional(): void {
		add_filter( Stream::FILTER, static fn (): array => array( Stream::BROADCAST ) );

		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body' ) );

		$this->assertSame( self::TRANSACTIONAL_STREAM, $this->transport->sent[0]->stream_id );
	}

	public function test_a_broadcast_without_a_broadcast_stream_is_refused(): void {
		$this->configure( array( Settings::TRANSACTIONAL_STREAM => self::TRANSACTIONAL_STREAM ) );

		$this->assertFalse( wp_mail( 'anna@example.com', 'News', self::TEXT_WITH_LINK, self::HEADER_BROADCAST ) );

		$this->assertRefused( MessageBuilder::ERROR_NO_BROADCAST_STREAM, 'broadcast stream' );
	}

	public function test_a_text_broadcast_without_the_unsubscribe_placeholder_is_refused(): void {
		$this->assertFalse( wp_mail( 'anna@example.com', 'News', 'News without a link.', self::HEADER_BROADCAST ) );

		$this->assertRefused( Broadcast::ERROR_NO_UNSUBSCRIBE_URL, 'text part' );
	}

	public function test_an_html_broadcast_without_the_unsubscribe_placeholder_is_refused(): void {
		$this->assertFalse( wp_mail( 'anna@example.com', 'News', '<p>News, <a href="https://example.com/unsubscribe">unsubscribe</a>.</p>', array( self::HEADER_BROADCAST, self::HTML_TYPE ) ) );

		$this->assertRefused( Broadcast::ERROR_NO_UNSUBSCRIBE_URL, 'part' );
	}

	public function test_a_broadcast_whose_text_part_lacks_the_placeholder_is_refused_though_its_html_has_it(): void {
		add_filter( Broadcast::MESSAGE_FILTER, static fn ( Message $message ): Message => $message->with_text( 'News.' ) );

		$this->assertFalse( wp_mail( 'anna@example.com', 'News', self::HTML_WITH_LINK, array( self::HEADER_BROADCAST, self::HTML_TYPE ) ) );

		$this->assertRefused( Broadcast::ERROR_NO_UNSUBSCRIBE_URL, 'text part' );
	}

	public function test_a_broadcast_whose_html_part_lacks_the_placeholder_is_refused_though_its_text_has_it(): void {
		add_filter( Broadcast::MESSAGE_FILTER, static fn ( Message $message ): Message => $message->with_text( self::TEXT_WITH_LINK ) );

		$this->assertFalse( wp_mail( 'anna@example.com', 'News', '<p>News.</p>', array( self::HEADER_BROADCAST, self::HTML_TYPE ) ) );

		$this->assertRefused( Broadcast::ERROR_NO_UNSUBSCRIBE_URL, 'HTML part' );
	}

	public function test_the_placeholder_is_found_however_its_inner_spacing_is_written(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'News', 'Unsubscribe: {{unsubscribe_url}}', self::HEADER_BROADCAST ) );

		$this->assertCount( 1, $this->transport->sent );
	}

	public function test_an_html_only_broadcast_gets_a_text_part_that_keeps_the_placeholder(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'News', self::HTML_WITH_LINK, array( self::HEADER_BROADCAST, self::HTML_TYPE ) ) );

		$message = $this->transport->sent[0];
		$this->assertSame( self::HTML_WITH_LINK, $message->html );
		$this->assertSame( "News.\n\nUnsubscribe ({{ unsubscribe_url }})", $message->text );
	}

	public function test_a_broadcast_loses_its_list_unsubscribe_headers(): void {
		$this->assertTrue(
			wp_mail(
				'anna@example.com',
				'News',
				self::TEXT_WITH_LINK,
				array(
					self::HEADER_BROADCAST,
					'List-Unsubscribe: <https://example.com/unsubscribe>',
					'list-unsubscribe-post: List-Unsubscribe=One-Click',
					'X-Campaign: 7',
				)
			)
		);

		$this->assertSame( array( 'X-Campaign' => '7' ), $this->transport->sent[0]->headers );
	}

	public function test_a_transactional_message_keeps_its_list_unsubscribe_header(): void {
		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body', 'List-Unsubscribe: <https://example.com/unsubscribe>' ) );

		$this->assertSame( array( 'List-Unsubscribe' => '<https://example.com/unsubscribe>' ), $this->transport->sent[0]->headers );
	}

	public function test_a_broadcast_goes_to_each_recipient_alone(): void {
		$succeeded = array();
		add_action(
			'wp_mail_succeeded',
			static function ( array $data ) use ( &$succeeded ): void {
				$succeeded[] = $data;
			}
		);

		$this->assertTrue(
			wp_mail(
				'anna@example.com, Bo Ek <bo@example.com>',
				'News',
				self::TEXT_WITH_LINK,
				array( self::HEADER_BROADCAST, 'Cc: cc@example.com, ANNA@example.com', 'Bcc: bcc@example.com' )
			)
		);

		$this->assertCount( 4, $this->transport->sent );
		$this->assertEquals(
			array(
				array( new Address( 'anna@example.com' ) ),
				array( new Address( 'bo@example.com', 'Bo Ek' ) ),
				array( new Address( 'cc@example.com' ) ),
				array( new Address( 'bcc@example.com' ) ),
			),
			array_map( static fn ( Message $message ): array => $message->to, $this->transport->sent )
		);
		foreach ( $this->transport->sent as $message ) {
			$this->assertSame( array(), $message->cc );
			$this->assertSame( array(), $message->bcc );
			$this->assertSame( self::BROADCAST_STREAM, $message->stream_id );
		}
		$this->assertSame( array( 'msg_fake_1', 'msg_fake_2', 'msg_fake_3', 'msg_fake_4' ), $succeeded[0]['sendora_message_ids'] );
	}

	public function test_the_broadcast_filter_can_put_the_placeholder_in_place_of_its_own_link(): void {
		$seen = array();
		add_filter(
			Broadcast::MESSAGE_FILTER,
			static function ( Message $message, array $atts ) use ( &$seen ): Message {
				$seen[] = $atts['subject'];
				return $message->with_text( str_replace( 'https://example.com/unsubscribe', Broadcast::UNSUBSCRIBE_PLACEHOLDER, (string) $message->text ) );
			},
			10,
			2
		);

		$this->assertTrue( wp_mail( 'anna@example.com', 'News', 'Unsubscribe: https://example.com/unsubscribe', self::HEADER_BROADCAST ) );

		$this->assertSame( array( 'News' ), $seen );
		$this->assertSame( 'Unsubscribe: {{ unsubscribe_url }}', $this->transport->sent[0]->text );
	}

	public function test_a_message_the_broadcast_filter_returns_stays_on_the_broadcast_stream(): void {
		add_filter(
			Broadcast::MESSAGE_FILTER,
			static fn ( Message $message ): Message => new Message(
				from: $message->from,
				to: $message->to,
				subject: $message->subject,
				text: self::TEXT_WITH_LINK,
				html: self::HTML_WITH_LINK,
			)
		);

		$this->assertTrue( wp_mail( 'anna@example.com', 'News', self::TEXT_WITH_LINK, self::HEADER_BROADCAST ) );

		$message = $this->transport->sent[0];
		$this->assertSame( self::BROADCAST_STREAM, $message->stream_id );
		$this->assertTrue( $message->broadcast );
		$this->assertSame( self::HTML_WITH_LINK, $message->html );
	}

	public function test_the_broadcast_filter_can_rewrite_the_html_part(): void {
		add_filter( Broadcast::MESSAGE_FILTER, static fn ( Message $message ): Message => $message->with_html( str_replace( 'https://example.com/unsubscribe', Broadcast::UNSUBSCRIBE_PLACEHOLDER, (string) $message->html ) ) );

		$this->assertTrue( wp_mail( 'anna@example.com', 'News', '<p><a href="https://example.com/unsubscribe">Unsubscribe</a></p>', array( self::HEADER_BROADCAST, self::HTML_TYPE ) ) );

		$this->assertSame( '<p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>', $this->transport->sent[0]->html );
	}

	public function test_a_broadcast_that_fails_part_way_says_who_has_it_already(): void {
		$this->transport->failure      = new SendFailed( 'Refused.', 'invalid_request', self::STATUS_UNPROCESSABLE );
		$this->transport->accept_first = 1;

		$this->assertFalse( wp_mail( 'anna@example.com, bo@example.com, cy@example.com', 'News', self::TEXT_WITH_LINK, self::HEADER_BROADCAST ) );

		$this->assertSame( 2, $this->transport->attempts );
		$this->assertCount( 1, $this->errors );
		$data = $this->errors[0]->get_error_data();
		$this->assertSame( array( 'msg_fake_1' ), $data['sendora_message_ids'] );
		$this->assertSame( array( 'anna@example.com' ), $data['sendora_sent_to'] );
	}

	public function test_a_broadcast_filter_that_returns_no_message_keeps_the_message(): void {
		add_filter( Broadcast::MESSAGE_FILTER, '__return_null' );

		$this->assertTrue( wp_mail( 'anna@example.com', 'News', self::TEXT_WITH_LINK, self::HEADER_BROADCAST ) );

		$this->assertSame( self::TEXT_WITH_LINK, $this->transport->sent[0]->text );
	}

	public function test_the_broadcast_filter_is_not_applied_to_transactional_mail(): void {
		$filtered = 0;
		add_filter(
			Broadcast::MESSAGE_FILTER,
			static function ( Message $message ) use ( &$filtered ): Message {
				++$filtered;
				return $message;
			}
		);

		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body' ) );

		$this->assertSame( 0, $filtered );
	}

	/**
	 * Asserts that nothing was sent, on either stream, and that wp_mail_failed
	 * was fired once with the code, and a message that names the rule.
	 *
	 * @param string $code The error code.
	 * @param string $rule Words the error message holds.
	 */
	private function assertRefused( string $code, string $rule ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- named like PHPUnit's own assertions.
		$this->assertSame( array(), $this->transport->sent );
		$this->assertSame( 0, $this->transport->attempts );
		$this->assertCount( 1, $this->errors );
		$this->assertSame( $code, $this->errors[0]->get_error_data()['sendora_error_code'] );
		$this->assertStringContainsString( $rule, $this->errors[0]->get_error_message() );
	}
}
