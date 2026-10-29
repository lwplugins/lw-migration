<?php
/**
 * `wp lw-migration browse`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the files inside a backup.
 */
final class BrowseCommand {

	/**
	 * Lists the files inside a backup (no password needed: names are not encrypted).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup name (see `wp lw-migration list`) or path to a .wpress file.
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
	 * @param array<int, string>   $args  Backup.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$arg  = (string) $args[0];
		$path = Archives::resolve( $arg, Services::get()->backups() );
		if ( null === $path ) {
			/* translators: %s: backup name or path */
			Console::error( sprintf( __( 'No backup or .wpress file found: %s', 'lw-migration' ), $arg ) );
			return;
		}
		$items = [];
		try {
			$reader = new Reader( $path );
			try {
				$reader->validate();
				foreach ( $reader->entries() as $entry ) {
					$items[] = [
						'name' => $entry->name,
						'size' => $entry->size,
						'date' => wp_date( 'Y-m-d H:i', $entry->mtime ),
					];
				}
			} finally {
				$reader->close();
			}
		} catch ( ArchiveException $e ) {
			Console::error( Archives::redact( $e->getMessage(), [ $path => $arg ] ) );
			return;
		}
		Console::items( (string) ( $assoc['format'] ?? 'table' ), $items, [ 'name', 'size', 'date' ] );
	}
}
