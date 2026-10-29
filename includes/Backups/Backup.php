<?php
/**
 * One backup file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Where it is and whether it is ours or in the other .wpress tool's folder.
 */
final class Backup {

	public const OWN = 'own';

	public const EXTERNAL = 'external';

	public function __construct(
		public string $name,
		public string $path,
		public int $size,
		public int $mtime,
		public string $source
	) {
	}
}
