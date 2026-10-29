<?php
/**
 * Decoded end-of-archive block.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Version 1 carries nothing; version 2 carries the archive size and CRC.
 */
final class EofInfo {

	public function __construct(
		public int $version,
		public ?int $size,
		public ?string $crc
	) {
	}
}
