<?php
/**
 * Hands a message to Sendora, or to a stand-in for it.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Mail;

/**
 * Sends one message. The plugin's own implementation talks to Sendora's
 * API; tests and local sites use an in-memory one, set with the
 * `connector_for_sendora_transport` filter.
 */
interface Transport {

	/**
	 * Sends the message.
	 *
	 * @param Message $message The message.
	 * @return string The id Sendora gave the message.
	 * @throws SendFailed The message was not accepted.
	 */
	public function send( Message $message ): string;
}
