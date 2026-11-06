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
	 * @param bool                 $broadcast   Whether the stream is a broadcast stream, whose rules {@see Broadcast} applies.
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
		public readonly bool $broadcast = false,
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
		return array_map( $this->to_alone( ... ), $this->bcc );
	}

	/**
	 * This message with another plain-text part.
	 *
	 * @param string $text The plain-text part.
	 */
	public function with_text( string $text ): self {
		return $this->copy( text: $text );
	}

	/**
	 * This message with another HTML part.
	 *
	 * @param string $html The HTML part.
	 */
	public function with_html( string $html ): self {
		return $this->copy( html: $html );
	}

	/**
	 * This message on the stream of another: its stream id, and whether it
	 * is a broadcast.
	 *
	 * @param Message $other The message whose stream this one takes.
	 */
	public function on_stream_of( Message $other ): self {
		return new self(
			from: $this->from,
			to: $this->to,
			subject: $this->subject,
			text: $this->text,
			html: $this->html,
			cc: $this->cc,
			bcc: $this->bcc,
			reply_to: $this->reply_to,
			headers: $this->headers,
			attachments: $this->attachments,
			stream_id: $other->stream_id,
			broadcast: $other->broadcast,
		);
	}

	/**
	 * This message with other headers.
	 *
	 * @param array<string,string> $headers Headers by name.
	 */
	public function with_headers( array $headers ): self {
		return $this->copy( headers: $headers );
	}

	/**
	 * This message to one recipient alone, with no Cc or Bcc.
	 *
	 * @param Address $recipient The recipient.
	 */
	public function to_alone( Address $recipient ): self {
		return $this->with_recipients( array( $recipient ), array(), array() );
	}

	/**
	 * This message to other recipients.
	 *
	 * @param list<Address> $to  The recipients.
	 * @param list<Address> $cc  Copied recipients.
	 * @param list<Address> $bcc Blind-copied recipients.
	 */
	private function with_recipients( array $to, array $cc, array $bcc ): self {
		return $this->copy( to: $to, cc: $cc, bcc: $bcc );
	}

	/**
	 * This message with the given parts replaced; a part left null is kept.
	 *
	 * @param list<Address>|null        $to      The recipients.
	 * @param list<Address>|null        $cc      Copied recipients.
	 * @param list<Address>|null        $bcc     Blind-copied recipients.
	 * @param string|null               $text    The plain-text part.
	 * @param string|null               $html    The HTML part.
	 * @param array<string,string>|null $headers Other headers by name.
	 */
	private function copy( ?array $to = null, ?array $cc = null, ?array $bcc = null, ?string $text = null, ?string $html = null, ?array $headers = null ): self {
		return new self(
			from: $this->from,
			to: $to ?? $this->to,
			subject: $this->subject,
			text: $text ?? $this->text,
			html: $html ?? $this->html,
			cc: $cc ?? $this->cc,
			bcc: $bcc ?? $this->bcc,
			reply_to: $this->reply_to,
			headers: $headers ?? $this->headers,
			attachments: $this->attachments,
			stream_id: $this->stream_id,
			broadcast: $this->broadcast,
		);
	}
}
