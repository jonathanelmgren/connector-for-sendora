<?php
/**
 * A request WordPress's HTTP API could not complete.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Sendora;

use SendoraWP\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use SendoraWP\Vendor\Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The request got no answer: no connection, a timeout, or a request that a
 * `pre_http_request` filter refused. The SDK reads it as a connection
 * failure.
 */
final class NetworkFailed extends RuntimeException implements NetworkExceptionInterface {

	/**
	 * A failure.
	 *
	 * @param string           $message WordPress's error message.
	 * @param RequestInterface $request The request that failed.
	 */
	public function __construct( string $message, private readonly RequestInterface $request ) {
		parent::__construct( $message );
	}

	/** The request that failed. */
	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
