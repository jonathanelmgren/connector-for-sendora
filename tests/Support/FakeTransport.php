<?php
/**
 * An in-memory stand-in for Sendora.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Tests\Support;

use ConnectorForSendora\Mail\Message;
use ConnectorForSendora\Mail\SendFailed;
use ConnectorForSendora\Mail\Transport;

/**
 * Keeps every message it is given, and answers with an id, or with the
 * failure it was told to give.
 */
final class FakeTransport implements Transport {

	/**
	 * The messages sent, oldest first.
	 *
	 * @var list<Message>
	 */
	public array $sent = array();

	/**
	 * The failure the next sends give; null to accept them.
	 *
	 * @var SendFailed|null
	 */
	public ?SendFailed $failure = null;

	/**
	 * How many sends were tried, failed ones included.
	 *
	 * @var int
	 */
	public int $attempts = 0;

	/**
	 * Keeps the message, or throws the failure it was given.
	 *
	 * @param Message $message The message.
	 * @throws SendFailed When told to fail.
	 */
	public function send( Message $message ): string {
		++$this->attempts;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		$this->sent[] = $message;
		return 'msg_fake_' . count( $this->sent );
	}
}
