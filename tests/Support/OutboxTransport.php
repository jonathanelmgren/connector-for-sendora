<?php
/**
 * The local site's stand-in for Sendora.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Support;

use SendoraWP\Mail\Address;
use SendoraWP\Mail\Message;
use SendoraWP\Mail\Transport;

/**
 * Keeps the last messages in an option instead of sending them, so a local
 * site's mail can be read with `wp option get <option> --format=json`.
 */
final class OutboxTransport implements Transport {

	/**
	 * A transport.
	 *
	 * @param string $option The option the messages go in.
	 * @param int    $size   How many it keeps.
	 */
	public function __construct( private readonly string $option, private readonly int $size ) {
	}

	/**
	 * Keeps the message.
	 *
	 * @param Message $message The message.
	 */
	public function send( Message $message ): string {
		$id     = 'msg_local_' . wp_generate_uuid4();
		$outbox = get_option( $this->option, array() );
		$outbox = is_array( $outbox ) ? $outbox : array();

		$outbox[] = array(
			'id'      => $id,
			'from'    => $message->from->to_header(),
			'to'      => array_map( static fn ( Address $a ): string => $a->to_header(), $message->to ),
			'subject' => $message->subject,
			'stream'  => $message->stream_id,
			'text'    => $message->text,
			'html'    => $message->html,
		);
		update_option( $this->option, array_slice( $outbox, -$this->size ), false );
		return $id;
	}
}
