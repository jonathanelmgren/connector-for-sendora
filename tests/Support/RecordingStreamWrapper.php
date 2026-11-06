<?php
/**
 * A stream wrapper that records every path it is asked about.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Support;

/**
 * Registered for a scheme, it answers every stat as a readable file and
 * keeps the path, so a test can tell whether code looked at a path at all.
 */
final class RecordingStreamWrapper {

	/** The scheme the wrapper is registered for. */
	public const SCHEME = 'sendora-recording';

	/** The mode of a regular file anyone can read: S_IFREG | 0644. */
	private const READABLE_FILE_MODE = 0100644;

	/**
	 * The paths stat was asked about, oldest first.
	 *
	 * @var list<string>
	 */
	public static array $stats = array();

	/**
	 * The stream context PHP sets on every wrapper instance.
	 *
	 * @var resource|null
	 */
	public $context;

	/** Registers the wrapper and forgets earlier stats. */
	public static function register(): void {
		self::$stats = array();
		stream_wrapper_register( self::SCHEME, self::class );
	}

	/** Unregisters the wrapper. */
	public static function unregister(): void {
		stream_wrapper_unregister( self::SCHEME );
	}

	/**
	 * Records the path, and answers that it is a readable file.
	 *
	 * @param string $path  The path asked about.
	 * @param int    $flags How to stat it.
	 * @return array<string, int>
	 */
	public function url_stat( string $path, int $flags ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- PHP's signature.
		self::$stats[] = $path;
		return array( 'mode' => self::READABLE_FILE_MODE );
	}
}
