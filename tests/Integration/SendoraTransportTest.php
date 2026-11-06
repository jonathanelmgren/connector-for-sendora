<?php
/**
 * Tests for sending with Sendora's SDK.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Integration;

use ConnectorForSendora\Mail\Address;
use ConnectorForSendora\Mail\Attachment;
use ConnectorForSendora\Mail\Message;
use ConnectorForSendora\Mail\SendFailed;
use ConnectorForSendora\Plugin;
use ConnectorForSendora\Sendora\SendoraTransport;
use ConnectorForSendora\Settings\Settings;

/**
 * Drives the SDK through WordPress's HTTP API, with a `pre_http_request`
 * filter answering in Sendora's place, so the request is checked as it
 * would leave and nothing does.
 */
final class SendoraTransportTest extends TestCase {

	private const STATUS_ACCEPTED = 202;

	private const STATUS_UNPROCESSABLE = 422;

	/**
	 * The requests the fake answered.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The fake's next answer.
	 *
	 * @var array{status: int, body: string}
	 */
	private array $answer = array(
		'status' => self::STATUS_ACCEPTED,
		'body'   => '{"messageId":"msg_123","status":"accepted","submittedAt":"2026-11-06T10:00:00Z","test":false}',
	);

	public function test_a_message_is_posted_to_sendora_s_email_endpoint(): void {
		$this->answer_requests();
		$file = wp_tempnam( 'a.txt' );
		file_put_contents( $file, 'attached' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$id = ( new SendoraTransport( self::LIVE_KEY ) )->send(
			new Message(
				from: new Address( 'shop@example.com', 'Shop' ),
				to: array( new Address( 'anna@example.com' ) ),
				subject: 'Hi',
				text: 'Body',
				html: '<p>Body</p>',
				cc: array( new Address( 'cc@example.com', 'Cee' ) ),
				bcc: array( new Address( 'bcc@example.com' ) ),
				reply_to: array( new Address( 'support@example.com', 'Support' ) ),
				headers: array( 'X-Order' => '1042' ),
				attachments: array( new Attachment( $file, 'note.txt', 'text/plain' ) ),
				stream_id: 'str_transactional',
			)
		);

		$this->assertSame( 'msg_123', $id );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( SendoraTransport::API_URL . '/v1/email', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'Bearer ' . self::LIVE_KEY, $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertNotEmpty( $this->requests[0]['args']['headers']['Idempotency-Key'] );
		$this->assertSame(
			array(
				'from'        => array(
					'email' => 'shop@example.com',
					'name'  => 'Shop',
				),
				'to'          => array( array( 'email' => 'anna@example.com' ) ),
				'subject'     => 'Hi',
				'streamId'    => 'str_transactional',
				'cc'          => array(
					array(
						'email' => 'cc@example.com',
						'name'  => 'Cee',
					),
				),
				'bcc'         => array( array( 'email' => 'bcc@example.com' ) ),
				'text'        => 'Body',
				'html'        => '<p>Body</p>',
				'headers'     => array(
					'X-Order'  => '1042',
					'Reply-To' => '"Support" <support@example.com>',
				),
				'attachments' => array(
					array(
						'name'        => 'note.txt',
						'content'     => base64_encode( 'attached' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API's own encoding.
						'contentType' => 'text/plain',
					),
				),
			),
			json_decode( (string) $this->requests[0]['args']['body'], true )
		);
	}

	public function test_a_refusal_carries_sendora_s_error(): void {
		$this->answer_requests();
		$this->answer = array(
			'status' => self::STATUS_UNPROCESSABLE,
			'body'   => '{"error":"invalid_request","message":"The request is invalid.","issues":[{"path":"from.email","message":"is not a verified sending domain"}]}',
		);

		try {
			( new SendoraTransport( self::LIVE_KEY ) )->send( self::message() );
			$this->fail( 'The send should have failed.' );
		} catch ( SendFailed $failure ) {
			$this->assertSame( 'invalid_request', $failure->error_code );
			$this->assertSame( self::STATUS_UNPROCESSABLE, $failure->status );
			$this->assertStringContainsString( 'from.email: is not a verified sending domain', $failure->getMessage() );
		}
	}

	public function test_a_request_nobody_answers_is_refused_before_it_leaves(): void {
		try {
			( new SendoraTransport( self::LIVE_KEY ) )->send( self::message() );
			$this->fail( 'The send should have failed.' );
		} catch ( SendFailed $failure ) {
			$this->assertSame( 'connection_failed', $failure->error_code );
			$this->assertNull( $failure->status );
			$this->assertStringContainsString( 'Tests may not reach', $failure->getMessage() );
		}
	}

	public function test_without_a_transport_filter_the_plugin_sends_through_sendora(): void {
		remove_all_filters( Plugin::TRANSPORT_FILTER );

		$this->assertInstanceOf( SendoraTransport::class, Plugin::transport( self::with_constants( array( 'CONNECTOR_FOR_SENDORA_API_KEY' => self::LIVE_KEY ) ) ) );
	}

	public function test_wp_mail_reaches_sendora_s_api_through_the_plugin(): void {
		remove_all_filters( Plugin::TRANSPORT_FILTER );
		$this->answer_requests();
		$this->configure( array( Settings::FROM_EMAIL => 'shop@example.com' ) );

		$this->assertTrue( wp_mail( 'anna@example.com', 'Hi', 'Body' ) );

		$this->assertCount( 1, $this->requests );
	}

	/** Answers every request in Sendora's place, and keeps it. */
	private function answer_requests(): void {
		add_filter(
			'pre_http_request',
			function ( $response, array $args, string $url ): array {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => $this->answer['body'],
					'response' => array(
						'code'    => $this->answer['status'],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/** A message with only what a send needs. */
	private static function message(): Message {
		return new Message(
			from: new Address( 'shop@example.com' ),
			to: array( new Address( 'anna@example.com' ) ),
			subject: 'Hi',
			text: 'Body',
			html: null,
		);
	}
}
