<?php
/**
 * A file attached to a message.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Mail;

/**
 * A file by its path on disk, with the name and type the recipient sees.
 */
final class Attachment {

	/**
	 * An attachment.
	 *
	 * @param string $path         The file on disk.
	 * @param string $name         The file name the recipient sees.
	 * @param string $content_type The file's MIME type.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $name,
		public readonly string $content_type,
	) {
	}
}
