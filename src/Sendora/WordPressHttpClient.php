<?php
/**
 * A PSR-18 client for Sendora's SDK over WordPress's HTTP API.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Sendora;

use SendoraWP\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use SendoraWP\Vendor\Psr\Http\Client\ClientInterface;
use SendoraWP\Vendor\Psr\Http\Message\RequestFactoryInterface;
use SendoraWP\Vendor\Psr\Http\Message\RequestInterface;
use SendoraWP\Vendor\Psr\Http\Message\ResponseInterface;
use SendoraWP\Vendor\Psr\Http\Message\StreamFactoryInterface;
use SendoraWP\Vendor\Psr\Http\Message\StreamInterface;

/**
 * Sends the SDK's requests with wp_remote_request() rather than the SDK's
 * own curl transport, so the site's proxy and certificate settings apply,
 * and `pre_http_request` sees every request: that is how the test suite
 * keeps every request away from Sendora.
 *
 * The SDK builds each request itself and retries on its own; this client
 * sends one attempt, never follows a redirect and never throws on an
 * error status, which the SDK reads.
 */
final class WordPressHttpClient implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface {

	/** Seconds one attempt may take before WordPress gives up on it. */
	private const TIMEOUT_SECONDS = 15;

	/**
	 * Makes the messages the client answers with.
	 *
	 * @var Psr17Factory
	 */
	private readonly Psr17Factory $factory;

	/** A client. */
	public function __construct() {
		$this->factory = new Psr17Factory();
	}

	/**
	 * Sends one request.
	 *
	 * @param RequestInterface $request The SDK's request.
	 * @throws NetworkFailed WordPress could not complete the request.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( array_keys( $request->getHeaders() ) as $name ) {
			$headers[ (string) $name ] = $request->getHeaderLine( (string) $name );
		}

		$reply = wp_remote_request(
			(string) $request->getUri(),
			array(
				'method'      => $request->getMethod(),
				'headers'     => $headers,
				'body'        => (string) $request->getBody(),
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $reply ) ) {
			throw new NetworkFailed( $reply->get_error_message(), $request ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output as is; whoever shows it escapes it.
		}

		$response = $this->factory->createResponse( (int) wp_remote_retrieve_response_code( $reply ) );
		foreach ( wp_remote_retrieve_headers( $reply ) as $name => $value ) {
			$response = $response->withHeader( (string) $name, $value );
		}
		return $response->withBody( $this->factory->createStream( wp_remote_retrieve_body( $reply ) ) );
	}

	/**
	 * A request, for the SDK to fill in.
	 *
	 * @param string $method The HTTP method.
	 * @param mixed  $uri    The address.
	 */
	public function createRequest( string $method, $uri ): RequestInterface {
		return $this->factory->createRequest( $method, $uri );
	}

	/**
	 * A body.
	 *
	 * @param string $content The body's bytes.
	 */
	public function createStream( string $content = '' ): StreamInterface {
		return $this->factory->createStream( $content );
	}

	/**
	 * A body from a file.
	 *
	 * @param string $filename The file.
	 * @param string $mode     The mode to open it in.
	 */
	public function createStreamFromFile( string $filename, string $mode = 'r' ): StreamInterface {
		return $this->factory->createStreamFromFile( $filename, $mode );
	}

	/**
	 * A body from an open resource.
	 *
	 * @param resource $handle The resource.
	 */
	public function createStreamFromResource( $handle ): StreamInterface {
		return $this->factory->createStreamFromResource( $handle );
	}
}
