<?php
/**
 * One message, as wp_mail() was asked to send it.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Mail;

/**
 * A message ready for a transport: every address parsed, the body split
 * into its text or HTML part, and the headers that are not addresses kept
 * by name.
 */
final class Message {

	/**
	 * A message.
	 *
	 * @param Address              $from        The sender.
	 * @param list<Address>        $to          The recipients.
	 * @param string               $subject     The subject line.
	 * @param string|null          $text        The plain-text part; null for none.
	 * @param string|null          $html        The HTML part; null for none.
	 * @param list<Address>        $cc          Copied recipients.
	 * @param list<Address>        $bcc         Blind-copied recipients.
	 * @param list<Address>        $reply_to    Where replies go.
	 * @param array<string,string> $headers     Other headers by name, sent as given.
	 * @param list<Attachment>     $attachments Attached files.
	 * @param string|null          $stream_id   The Sendora stream; null for the server's default transactional stream.
	 */
	public function __construct(
		public readonly Address $from,
		public readonly array $to,
		public readonly string $subject,
		public readonly ?string $text,
		public readonly ?string $html,
		public readonly array $cc = array(),
		public readonly array $bcc = array(),
		public readonly array $reply_to = array(),
		public readonly array $headers = array(),
		public readonly array $attachments = array(),
		public readonly ?string $stream_id = null,
	) {
	}
}
