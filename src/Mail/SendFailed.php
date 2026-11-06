<?php
/**
 * A message that was not accepted for delivery.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Mail;

use RuntimeException;

/**
 * Sendora refused the message, or could not be reached. The code is
 * Sendora's error code (`invalid_request`, `connection_failed`, …), and the
 * status its HTTP status when it answered.
 */
final class SendFailed extends RuntimeException {

	/**
	 * A failure.
	 *
	 * @param string   $message    What went wrong, for an admin.
	 * @param string   $error_code Sendora's error code.
	 * @param int|null $status     The HTTP status; null when Sendora did not answer.
	 */
	public function __construct(
		string $message,
		public readonly string $error_code,
		public readonly ?int $status = null,
	) {
		parent::__construct( $message );
	}
}
