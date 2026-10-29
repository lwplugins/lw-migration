<?php
/**
 * A refused upload chunk.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Carries why (one of the reason constants) and how many bytes the server
 * holds, so the browser can resume at the right offset. Pure: the REST layer
 * turns the reason into a translated message.
 */
final class UploadException extends \RuntimeException {

	public const OFFSET = 'offset';

	public const TOO_LARGE = 'too_large';

	public const NOT_ARCHIVE = 'not_archive';

	public const WRITE = 'write';

	public const EMPTY_CHUNK = 'empty_chunk';

	/**
	 * @param string $reason   One of the constants.
	 * @param int    $received Bytes the server holds.
	 */
	public function __construct( public string $reason, public int $received = 0 ) {
		parent::__construct( 'Upload chunk refused: ' . $reason );
	}
}
