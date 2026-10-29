<?php
/**
 * `wp lw-migration list`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Lists backups.
 */
final class ListCommand {

	/**
	 * Lists the backups (this plugin's folder and the folder of the other .wpress tool).
	 * The table shows sizes for reading; json and csv give them in bytes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array<int, string>   $args  Unused.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$format = (string) ( $assoc['format'] ?? 'table' );
		$labels = Labels::all();
		$items  = [];
		foreach ( Services::get()->backups()->all() as $backup ) {
			$items[] = [
				'name'   => $backup->name,
				'label'  => $labels[ $backup->name ] ?? '',
				'size'   => 'table' === $format ? size_format( $backup->size, 1 ) : $backup->size,
				'date'   => wp_date( 'Y-m-d H:i', $backup->mtime ),
				'source' => $backup->source,
			];
		}
		Console::items( $format, $items, [ 'name', 'label', 'size', 'date', 'source' ] );
	}
}
