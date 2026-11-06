<?php
/**
 * Webhook deliveries as Sendora sends them, signed with a test secret.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Support;

use ConnectorForSendora\Webhook\Webhook;
use WP_REST_Request;

/**
 * Builds event payloads in Sendora's shape and the requests that carry
 * them, signed as Sendora signs them: `t=<seconds>,v1=<HMAC-SHA256 of
 * "<seconds>.<body>">`.
 */
final class WebhookDeliveries {

	/** A made-up signing secret, which no Sendora webhook has. */
	public const SECRET = 'whsec_not_a_real_secret';

	/** The stream id the events name. */
	public const STREAM_ID = 'str_broadcast_1';

	/** The SMTP status a receiver refuses an unknown mailbox with. */
	private const SMTP_MAILBOX_UNKNOWN = 550;

	/** The SMTP status a receiver answers "try again later" with. */
	private const SMTP_TRY_LATER = 421;

	/** The SMTP status a receiver accepts a message with. */
	private const SMTP_ACCEPTED = 250;

	/** The hash Sendora signs with. */
	private const SIGNATURE_HASH = 'sha256';

	/**
	 * An event's payload.
	 *
	 * @param string               $event     The event's name, such as `bounced`.
	 * @param string               $recipient The recipient it is about.
	 * @param array<string, mixed> $details   Its details.
	 * @param string|null          $id        The delivery's id; a fresh one when null.
	 * @return array<string, mixed>
	 */
	public static function payload( string $event, string $recipient, array $details, ?string $id = null ): array {
		return array(
			'id'          => $id ?? uniqid( 'whd_', true ),
			'attempt'     => 1,
			'event'       => $event,
			'messageId'   => 'msg_1',
			'recipient'   => $recipient,
			'occurredAt'  => '2026-11-01T10:00:00Z',
			'serverId'    => 'srv_1',
			'streamId'    => self::STREAM_ID,
			'broadcastId' => null,
			'tag'         => null,
			'metadata'    => array( 'ref' => '7' ),
			'test'        => false,
			'details'     => $details,
		);
	}

	/**
	 * A bounce's payload.
	 *
	 * @param string      $recipient The recipient.
	 * @param bool        $hard      Whether it is a hard bounce.
	 * @param string|null $id        The delivery's id; a fresh one when null.
	 * @return array<string, mixed>
	 */
	public static function bounced( string $recipient, bool $hard = true, ?string $id = null ): array {
		return self::payload(
			'bounced',
			$recipient,
			array(
				'code'           => $hard ? self::SMTP_MAILBOX_UNKNOWN : self::SMTP_TRY_LATER,
				'response'       => $hard ? 'No such user' : 'Try again later',
				'classification' => $hard ? 'InvalidRecipient' : 'Transient',
				'hard'           => $hard,
				'expired'        => false,
			),
			$id
		);
	}

	/**
	 * A spam complaint's payload.
	 *
	 * @param string $recipient The recipient.
	 * @return array<string, mixed>
	 */
	public static function complained( string $recipient ): array {
		return self::payload( 'spam_complaint', $recipient, array( 'feedbackType' => 'abuse' ) );
	}

	/**
	 * An unsubscribe's payload.
	 *
	 * @param string $recipient The recipient.
	 * @return array<string, mixed>
	 */
	public static function unsubscribed( string $recipient ): array {
		return self::payload( 'unsubscribed', $recipient, array( 'source' => 'one_click' ) );
	}

	/**
	 * A delivery's payload.
	 *
	 * @param string $recipient The recipient.
	 * @return array<string, mixed>
	 */
	public static function delivered( string $recipient ): array {
		return self::payload(
			'delivered',
			$recipient,
			array(
				'code'     => self::SMTP_ACCEPTED,
				'response' => 'OK',
			)
		);
	}

	/**
	 * The request that delivers the payload, signed now with the secret.
	 *
	 * @param array<string, mixed> $payload The event.
	 * @param string               $secret  The secret it is signed with.
	 * @param int|null             $time    When it is signed; now when null.
	 */
	public static function request( array $payload, string $secret = self::SECRET, ?int $time = null ): WP_REST_Request {
		$body    = (string) wp_json_encode( $payload );
		$time    = $time ?? time();
		$request = self::unsigned( $body );
		$request->set_header( Webhook::SIGNATURE_HEADER, 't=' . $time . ',v1=' . hash_hmac( self::SIGNATURE_HASH, $time . '.' . $body, $secret ) );
		return $request;
	}

	/**
	 * The request with the body and no signature.
	 *
	 * @param string $body The body.
	 */
	public static function unsigned( string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Webhook::NAMESPACE . Webhook::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		return $request;
	}
}
