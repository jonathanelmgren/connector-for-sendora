<?php
/**
 * Tests for sending wp_mail() through Sendora.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

use SendoraWP\Mail\Address;
use SendoraWP\Mail\Mailer;
use SendoraWP\Mail\MessageBuilder;
use SendoraWP\Mail\SendFailed;
use SendoraWP\Settings\Settings;
use SendoraWP\Tests\Support\RecordingStreamWrapper;
use WP_Error;

/**
 * Sends through wp_mail() itself, so the plugin's hook is what is tested.
 */
final class MailerTest extends TestCase {

	/** An HTTP status Sendora answers a refused body with. */
	private const STATUS_UNPROCESSABLE = 422;

	public function test_without_an_api_key_wordpress_sends_the_message(): void {
		reset_phpmailer_instance();

		// A From header, since PHPMailer refuses the test site's own default, wordpress@localhost.
		$this->assertTrue( wp_mail( 'anna@example.com', 'Hello', 'Body', 'From: shop@example.com' ) );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertSame( 'anna@example.com', self::phpmailer()->getToAddresses()[0][0] );
	}

	public function test_with_an_api_key_the_message_goes_to_sendora_and_not_to_phpmailer(): void {
		$this->configure();
		reset_phpmailer_instance();

		$this->assertTrue( wp_mail( 'anna@example.com', 'Hello', 'Body' ) );

		$this->assertCount( 1, $this->transport->sent );
		$this->assertFalse( self::phpmailer()->get_sent() );
	}

	public function test_every_part_of_the_message_is_kept(): void {
		$this->configure();
		$file = wp_tempnam( 'invoice.pdf' );
		file_put_contents( $file, '%PDF-1.4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$sent = wp_mail(
			'anna@example.com, Bo Ek <bo@example.com>',
			'Your invoice',
			'<p>Hello</p>',
			array(
				'From: Shop <shop@example.com>',
				'Cc: cc1@example.com, "Cee Two" <cc2@example.com>',
				'Bcc: bcc@example.com',
				'Reply-To: Support <support@example.com>',
				'Content-Type: text/html; charset=UTF-8',
				'X-Order: 1042',
			),
			array( 'invoice-1042.pdf' => $file )
		);

		$this->assertTrue( $sent );
		$message = $this->transport->sent[0];
		$this->assertEquals( new Address( 'shop@example.com', 'Shop' ), $message->from );
		$this->assertEquals( array( new Address( 'anna@example.com' ), new Address( 'bo@example.com', 'Bo Ek' ) ), $message->to );
		$this->assertEquals( array( new Address( 'cc1@example.com' ), new Address( 'cc2@example.com', 'Cee Two' ) ), $message->cc );
		$this->assertEquals( array( new Address( 'bcc@example.com' ) ), $message->bcc );
		$this->assertEquals( array( new Address( 'support@example.com', 'Support' ) ), $message->reply_to );
		$this->assertSame( 'Your invoice', $message->subject );
		$this->assertSame( '<p>Hello</p>', $message->html );
		$this->assertNull( $message->text );
		$this->assertSame( array( 'X-Order' => '1042' ), $message->headers );
		$this->assertCount( 1, $message->attachments );
		$this->assertSame( 'invoice-1042.pdf', $message->attachments[0]->name );
		$this->assertSame( 'application/pdf', $message->attachments[0]->content_type );
		$this->assertSame( $file, $message->attachments[0]->path );
	}

	public function test_headers_and_recipients_given_as_strings_are_read_like_wp_mail_reads_them(): void {
		$this->configure();

		wp_mail( array( 'anna@example.com', 'bo@example.com' ), 'Hi', 'Body', "Cc: cc@example.com\r\nX-One: 1\r\nnot a header" );

		$message = $this->transport->sent[0];
		$this->assertCount( 2, $message->to );
		$this->assertEquals( array( new Address( 'cc@example.com' ) ), $message->cc );
		$this->assertSame( array( 'X-One' => '1' ), $message->headers );
	}

	public function test_plain_text_is_the_default(): void {
		$this->configure();

		wp_mail( 'anna@example.com', 'Hi', 'Plain body' );

		$this->assertSame( 'Plain body', $this->transport->sent[0]->text );
		$this->assertNull( $this->transport->sent[0]->html );
	}

	public function test_the_content_type_filter_makes_the_message_html(): void {
		$this->configure();
		add_filter( 'wp_mail_content_type', static fn (): string => 'text/html' );

		wp_mail( 'anna@example.com', 'Hi', '<b>Hi</b>' );

		$this->assertSame( '<b>Hi</b>', $this->transport->sent[0]->html );
	}

	public function test_the_default_sender_comes_from_the_settings(): void {
		$this->configure(
			array(
				Settings::FROM_EMAIL => 'news@example.com',
				Settings::FROM_NAME  => 'Example News',
			)
		);

		wp_mail( 'anna@example.com', 'Hi', 'Body' );

		$this->assertEquals( new Address( 'news@example.com', 'Example News' ), $this->transport->sent[0]->from );
	}

	public function test_without_a_default_sender_wordpress_s_default_is_used(): void {
		$this->configure();

		wp_mail( 'anna@example.com', 'Hi', 'Body' );

		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$this->assertEquals( new Address( 'wordpress@' . $host, 'WordPress' ), $this->transport->sent[0]->from );
	}

	public function test_the_from_filters_apply(): void {
		$this->configure();
		add_filter( 'wp_mail_from', static fn (): string => 'filtered@example.com' );
		add_filter( 'wp_mail_from_name', static fn (): string => 'Filtered' );

		wp_mail( 'anna@example.com', 'Hi', 'Body', 'From: Shop <shop@example.com>' );

		$this->assertEquals( new Address( 'filtered@example.com', 'Filtered' ), $this->transport->sent[0]->from );
	}

	public function test_the_message_goes_on_the_configured_transactional_stream(): void {
		$this->configure( array( Settings::TRANSACTIONAL_STREAM => 'str_transactional' ) );

		wp_mail( 'anna@example.com', 'Hi', 'Body' );

		$this->assertSame( 'str_transactional', $this->transport->sent[0]->stream_id );
	}

	public function test_without_a_stream_the_server_s_default_stream_is_used(): void {
		$this->configure();

		wp_mail( 'anna@example.com', 'Hi', 'Body' );

		$this->assertNull( $this->transport->sent[0]->stream_id );
	}

	public function test_an_unreadable_attachment_is_skipped_as_wp_mail_skips_it(): void {
		$this->configure();

		wp_mail( 'anna@example.com', 'Hi', 'Body', '', array( '/no/such/file.pdf' ) );

		$this->assertSame( array(), $this->transport->sent[0]->attachments );
	}

	public function test_text_in_another_charset_reaches_sendora_as_utf8(): void {
		$this->configure();

		$sent = wp_mail(
			"Bj\xF6rn <bjorn@example.com>",
			"Caf\xE9 order",
			"Your caf\xE9 order",
			array(
				'Content-Type: text/plain; charset=ISO-8859-1',
				"From: Andr\xE9 <shop@example.com>",
				"Cc: Ren\xE9e <renee@example.com>",
				"Reply-To: S\xF8ren <soren@example.com>",
				"X-Note: na\xEFve",
			)
		);

		$this->assertTrue( $sent );
		$message = $this->transport->sent[0];
		$this->assertEquals( new Address( 'shop@example.com', 'André' ), $message->from );
		$this->assertEquals( array( new Address( 'bjorn@example.com', 'Björn' ) ), $message->to );
		$this->assertEquals( array( new Address( 'renee@example.com', 'Renée' ) ), $message->cc );
		$this->assertEquals( array( new Address( 'soren@example.com', 'Søren' ) ), $message->reply_to );
		$this->assertSame( 'Café order', $message->subject );
		$this->assertSame( 'Your café order', $message->text );
		$this->assertSame( array( 'X-Note' => 'naïve' ), $message->headers );
	}

	public function test_the_charset_filter_sets_the_charset_text_is_converted_from(): void {
		$this->configure();
		add_filter( 'wp_mail_charset', static fn (): string => 'ISO-8859-1' );

		wp_mail( 'anna@example.com', "Caf\xE9", 'Body' );

		$this->assertSame( 'Café', $this->transport->sent[0]->subject );
	}

	public function test_a_charset_that_cannot_be_converted_fails_the_send(): void {
		$this->configure();
		$errors = array();
		add_action(
			'wp_mail_failed',
			static function ( WP_Error $error ) use ( &$errors ): void {
				$errors[] = $error;
			}
		);

		$this->assertFalse( wp_mail( 'anna@example.com', 'Hi', 'Body', 'Content-Type: text/plain; charset=x-no-such-charset' ) );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertCount( 1, $errors );
		$this->assertSame( MessageBuilder::ERROR_UNSUPPORTED_CHARSET, $errors[0]->get_error_data()['sendora_error_code'] );
	}

	public function test_an_attachment_path_with_a_scheme_is_skipped_without_being_looked_at(): void {
		$this->configure();
		RecordingStreamWrapper::register();

		try {
			wp_mail( 'anna@example.com', 'Hi', 'Body', '', array( RecordingStreamWrapper::SCHEME . '://invoice.pdf', 'ftp://example.com/invoice.pdf' ) );
		} finally {
			RecordingStreamWrapper::unregister();
		}

		$this->assertSame( array(), $this->transport->sent[0]->attachments );
		$this->assertSame( array(), RecordingStreamWrapper::$stats );
	}

	public function test_a_failed_send_returns_false_and_fires_wp_mail_failed_with_sendora_s_error(): void {
		$this->configure();
		$this->transport->failure = new SendFailed( 'The domain example.com is not verified.', 'domain_not_verified', self::STATUS_UNPROCESSABLE );
		$errors                   = array();
		add_action(
			'wp_mail_failed',
			static function ( WP_Error $error ) use ( &$errors ): void {
				$errors[] = $error;
			}
		);

		$this->assertFalse( wp_mail( 'anna@example.com', 'Hi', 'Body' ) );

		$this->assertCount( 1, $errors );
		$this->assertSame( Mailer::ERROR_CODE, $errors[0]->get_error_code() );
		$this->assertSame( 'The domain example.com is not verified.', $errors[0]->get_error_message() );
		$data = $errors[0]->get_error_data();
		$this->assertSame( 'domain_not_verified', $data['sendora_error_code'] );
		$this->assertSame( self::STATUS_UNPROCESSABLE, $data['sendora_status'] );
		$this->assertSame( 'anna@example.com', $data['to'] );
	}

	public function test_a_message_without_a_recipient_fails(): void {
		$this->configure();
		$failed = 0;
		add_action(
			'wp_mail_failed',
			static function () use ( &$failed ): void {
				++$failed;
			}
		);

		$this->assertFalse( wp_mail( '', 'Hi', 'Body' ) );

		$this->assertSame( 1, $failed );
		$this->assertSame( array(), $this->transport->sent );
	}

	public function test_a_message_with_only_cc_goes_to_the_cc_recipients(): void {
		$this->configure();

		$this->assertTrue( wp_mail( '', 'Hi', 'Body', array( 'Cc: cc1@example.com, cc2@example.com', 'Bcc: bcc@example.com' ) ) );

		$this->assertCount( 1, $this->transport->sent );
		$message = $this->transport->sent[0];
		$this->assertEquals( array( new Address( 'cc1@example.com' ), new Address( 'cc2@example.com' ) ), $message->to );
		$this->assertSame( array(), $message->cc );
		$this->assertEquals( array( new Address( 'bcc@example.com' ) ), $message->bcc );
	}

	public function test_a_message_with_only_bcc_goes_to_each_recipient_alone(): void {
		$this->configure();

		$this->assertTrue( wp_mail( '', 'Hi', 'Body', 'Bcc: one@example.com, Two <two@example.com>' ) );

		$this->assertCount( 2, $this->transport->sent );
		$this->assertEquals( array( new Address( 'one@example.com' ) ), $this->transport->sent[0]->to );
		$this->assertEquals( array( new Address( 'two@example.com', 'Two' ) ), $this->transport->sent[1]->to );
		foreach ( $this->transport->sent as $message ) {
			$this->assertSame( array(), $message->cc );
			$this->assertSame( array(), $message->bcc );
			$this->assertSame( 'Hi', $message->subject );
		}
	}

	public function test_a_message_sent_as_several_stops_at_the_first_failure(): void {
		$this->configure();
		$this->transport->failure = new SendFailed( 'Refused.', 'invalid_request', self::STATUS_UNPROCESSABLE );
		$failed                   = 0;
		add_action(
			'wp_mail_failed',
			static function () use ( &$failed ): void {
				++$failed;
			}
		);

		$this->assertFalse( wp_mail( '', 'Hi', 'Body', 'Bcc: one@example.com, two@example.com' ) );

		$this->assertSame( 1, $failed );
		$this->assertSame( 1, $this->transport->attempts );
	}

	public function test_a_sent_message_fires_wp_mail_succeeded(): void {
		$this->configure();
		$succeeded = array();
		add_action(
			'wp_mail_succeeded',
			static function ( array $data ) use ( &$succeeded ): void {
				$succeeded[] = $data;
			}
		);

		wp_mail( 'anna@example.com', 'Hi', 'Body' );

		$this->assertCount( 1, $succeeded );
		$this->assertSame( 'Hi', $succeeded[0]['subject'] );
		$this->assertSame( array( 'msg_fake_1' ), $succeeded[0]['sendora_message_ids'] );
	}

	public function test_an_earlier_pre_wp_mail_answer_is_kept(): void {
		$this->configure();
		add_filter( 'pre_wp_mail', '__return_false', 5 );

		$this->assertFalse( wp_mail( 'anna@example.com', 'Hi', 'Body' ) );

		$this->assertSame( array(), $this->transport->sent );
	}

	/** The test suite's PHPMailer, which records instead of sending. */
	private static function phpmailer(): \MockPHPMailer {
		$mailer = tests_retrieve_phpmailer_instance();
		self::assertInstanceOf( \MockPHPMailer::class, $mailer );
		return $mailer;
	}
}
