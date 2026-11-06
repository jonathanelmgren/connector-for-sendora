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
 *
 * The recipient lists may be given in any combination but all empty:
 * {@see deliveries()} turns the message into what Sendora can send, which
 * always has a To.
 */
final class Message {

	/**
	 * A message.
	 *
	 * @param Address              $from        The sender.
	 * @param list<Address>        $to          The recipients; empty when the message has only Cc or Bcc.
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

	/**
	 * The messages to send so that every recipient gets this one, each
	 * with a To, as Sendora needs. A message with a To is sent as it is.
	 * Without one, Cc recipients become the To: they see each other either
	 * way. With only Bcc recipients, each gets a message of their own, To
	 * them alone, so no blind recipient sees another.
	 *
	 * @return list<Message>
	 */
	public function deliveries(): array {
		if ( array() !== $this->to ) {
			return array( $this );
		}
		if ( array() !== $this->cc ) {
			return array( $this->with_recipients( $this->cc, array(), $this->bcc ) );
		}
		return array_map( fn ( Address $recipient ): Message => $this->with_recipients( array( $recipient ), array(), array() ), $this->bcc );
	}

	/**
	 * This message to other recipients.
	 *
	 * @param list<Address> $to  The recipients.
	 * @param list<Address> $cc  Copied recipients.
	 * @param list<Address> $bcc Blind-copied recipients.
	 */
	private function with_recipients( array $to, array $cc, array $bcc ): self {
		return new self(
			from: $this->from,
			to: $to,
			subject: $this->subject,
			text: $this->text,
			html: $this->html,
			cc: $cc,
			bcc: $bcc,
			reply_to: $this->reply_to,
			headers: $this->headers,
			attachments: $this->attachments,
			stream_id: $this->stream_id,
		);
	}
}
