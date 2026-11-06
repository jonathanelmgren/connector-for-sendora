<?php
/**
 * Sends a message with Sendora's API.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Sendora;

use SendoraWP\Mail\Address;
use SendoraWP\Mail\Attachment;
use SendoraWP\Mail\Message;
use SendoraWP\Mail\SendFailed;
use SendoraWP\Mail\Transport;
use SendoraWP\Vendor\Sendora\Attachment as SendoraAttachment;
use SendoraWP\Vendor\Sendora\Exception\ExceptionInterface;
use SendoraWP\Vendor\Sendora\Exception\SendoraException;
use SendoraWP\Vendor\Sendora\Sendora;
use SendoraWP\Vendor\Sendora\Types\ValidationIssue;

/**
 * Sends each message as one `POST /v1/email` through Sendora's PHP SDK,
 * over WordPress's HTTP API.
 */
final class SendoraTransport implements Transport {

	/**
	 * Sendora's API. Passed even though it is the SDK's default too: left to
	 * itself the SDK would read the address from SENDORA_WP_BASE_URL.
	 */
	public const API_URL = 'https://api.sendora.se';

	/**
	 * Retries after a failed attempt, which the SDK makes only when it is
	 * safe: every send goes under an idempotency key. One, since a send
	 * holds up the page that sent it.
	 */
	private const MAX_RETRIES = 1;

	/** The error code for a message the SDK refused before sending anything. */
	private const ERROR_SDK_REFUSED = 'invalid_argument';

	/** Sendora's error code for a body that does not match its schema. */
	private const ERROR_INVALID_REQUEST = 'invalid_request';

	/**
	 * Sendora's SDK, for this key.
	 *
	 * @var Sendora
	 */
	private readonly Sendora $client;

	/**
	 * A transport for one server key.
	 *
	 * @param string $api_key The server key.
	 * @throws SendFailed The SDK refuses the key.
	 */
	public function __construct( #[\SensitiveParameter] string $api_key ) {
		try {
			$this->client = new Sendora(
				token: $api_key,
				baseUrl: self::API_URL,
				maxRetries: self::MAX_RETRIES,
				httpClient: new WordPressHttpClient(),
			);
		} catch ( ExceptionInterface $e ) {
			throw self::failure( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}
	}

	/**
	 * Sends the message.
	 *
	 * @param Message $message The message.
	 * @throws SendFailed Sendora refused it, or could not be reached.
	 */
	public function send( Message $message ): string {
		$headers = $message->headers;
		if ( array() !== $message->reply_to ) {
			$headers['Reply-To'] = implode( ', ', array_map( static fn ( Address $a ): string => $a->to_header(), $message->reply_to ) );
		}

		try {
			return $this->client->email->send(
				from: self::address( $message->from ),
				to: array_map( self::address( ... ), $message->to ),
				subject: $message->subject,
				streamId: $message->stream_id,
				cc: array() === $message->cc ? null : array_map( self::address( ... ), $message->cc ),
				bcc: array() === $message->bcc ? null : array_map( self::address( ... ), $message->bcc ),
				text: $message->text,
				html: $message->html,
				headers: array() === $headers ? null : $headers,
				attachments: array() === $message->attachments ? null : array_map( self::attachment( ... ), $message->attachments ),
			)->messageId;
		} catch ( ExceptionInterface $e ) {
			throw self::failure( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}
	}

	/**
	 * An address as the SDK takes it.
	 *
	 * @param Address $address The address.
	 * @return array{email: string, name?: string}
	 */
	private static function address( Address $address ): array {
		return null === $address->name ? array( 'email' => $address->email ) : array(
			'email' => $address->email,
			'name'  => $address->name,
		);
	}

	/**
	 * An attachment as the SDK takes it.
	 *
	 * @param Attachment $attachment The file.
	 */
	private static function attachment( Attachment $attachment ): SendoraAttachment {
		return SendoraAttachment::fromFile( $attachment->path, $attachment->content_type, $attachment->name );
	}

	/**
	 * The SDK's exception as a failed send. Sendora's message is kept: it
	 * says what to fix, such as a sending domain that is not verified.
	 *
	 * @param ExceptionInterface $e The SDK's exception.
	 */
	private static function failure( ExceptionInterface $e ): SendFailed {
		if ( ! $e instanceof SendoraException ) {
			return new SendFailed( $e->getMessage(), self::ERROR_SDK_REFUSED );
		}
		$message = $e->getMessage();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the SDK's property.
		if ( self::ERROR_INVALID_REQUEST === $e->errorCode && array() !== $e->issues ) {
			$issues = implode( '; ', array_map( static fn ( ValidationIssue $issue ): string => $issue->path . ': ' . $issue->message, $e->issues ) );
			// Without a message of Sendora's own, the SDK writes one from the issues already.
			if ( ! str_contains( $message, $issues ) ) {
				$message .= ' ' . $issues;
			}
		}
		return new SendFailed( $message, $e->errorCode, $e->status ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the SDK's property.
	}
}
