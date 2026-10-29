<?php
/**
 * Extracts a whole archive into a folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\ControlChars;
use LightweightPlugins\Migration\Support\NoDeadline;

defined( 'ABSPATH' ) || exit;

/**
 * For the extract command: every entry under one folder. A name that would
 * land outside the folder is skipped with a warning, and so is a file that is
 * already there unless overwriting is asked for (--force). A damaged entry stops the
 * extraction; its partial data only ever lived in a temporary file, which
 * Reader::extractTo removes, so a file of the same name already in the
 * folder is left as it was. Every byte written counts against one WriteBudget (a replaced file is refunded after the rename),
 * whose limit stops the extraction the same way.
 */
final class Extractor {

	/**
	 * @param string      $archive   Archive path.
	 * @param ChunkCodec  $codec     Codec from package.json.
	 * @param string      $target    Target folder (created when missing).
	 * @param WriteBudget $budget    Limit on the total bytes written.
	 * @param callable    $warn      fn( string $message ): void.
	 * @param bool        $overwrite Replace files that already exist in the folder.
	 * @return int Files written.
	 * @throws ArchiveException On unreadable or damaged data, or (WriteLimitException) past the budget's limit.
	 */
	public static function extractAll( string $archive, ChunkCodec $codec, string $target, WriteBudget $budget, callable $warn, bool $overwrite = false ): int {
		Folder::ensure( $target );
		$reader = new Reader( $archive, $codec );
		$count  = 0;
		try {
			$reader->validate();
			foreach ( $reader->entries() as $entry ) {
				try {
					$path = PathGuard::resolve( $target, $entry->name );
				} catch ( UnsafePathException $e ) {
					$warn( $e->getMessage() );
					continue;
				}
				if ( ! $overwrite && ( file_exists( $path ) || is_link( $path ) ) ) {
					$warn( sprintf( 'Kept existing file %s (use --force to overwrite)', ControlChars::escape( $entry->name ) ) );
					continue;
				}
				$reader->extractTo( $entry, $path, new ExtractCursor(), new NoDeadline(), $budget );
				++$count;
			}
		} finally {
			$reader->close();
		}

		return $count;
	}
}
