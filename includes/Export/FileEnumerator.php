<?php
/**
 * Lists the files a backup will contain.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Depth-first, one folder at a time, alphabetical. The list file is cut back
 * to the cursor at every start, so a crashed request never duplicates lines.
 *
 * Symlinks are followed (Composer and managed hosts link active plugins and
 * themes in; the archive stores them as plain files and folders). A folder
 * whose real path was already walked, through another link, a loop or another
 * root, is skipped silently; a dangling link is skipped with a warning. An
 * excluded folder stays excluded when reached through its real path, and
 * a linked file whose target is excluded is never listed. A folder that
 * belongs to another root's territory (uploads, plugins, a theme root; also
 * one an option leaves out) is skipped silently: its content is listed under
 * that root or deliberately left out.
 *
 * Following links may archive targets outside the site, as the format's
 * reference tool does; our storage and the excluded set are never archived
 * through any link, and our backups folders are recognised by identity. Warnings name archive entries (relative), never server
 * paths.
 */
final class FileEnumerator {

	/** Suffix of the visited-folders side file next to the list (removed with the list). */
	public const VISITED_SUFFIX = '.dirs';

	/** @var Territories */
	private Territories $territories;

	/** @var string|null Territory that owns the root being walked (null: none). */
	private ?string $rootReal = null;

	/**
	 * @param array<int, array{dir: string, entry: string, filter: ExportFilter}> $roots    Roots in archive order.
	 * @param string                                                              $listPath List file.
	 * @param array<int, string>                                                  $claimed  Folders that are roots of their own, also those left out by options (ExportRoots::territories()).
	 */
	public function __construct( private array $roots, private string $listPath, array $claimed ) {
		foreach ( $roots as $root ) {
			if ( '' !== $root['entry'] ) {
				$claimed[] = $root['dir'];
			}
		}
		$this->territories = new Territories( $claimed );
	}

	/**
	 * @param EnumCursor $cursor   Progress.
	 * @param Deadline   $deadline Time budget.
	 * @param callable   $warn     fn( string $code, string $entry ): void.
	 * @return bool True when every root is listed.
	 * @throws ExportException When the list cannot be written or does not match the cursor.
	 */
	public function run( EnumCursor $cursor, Deadline $deadline, callable $warn ): bool {
		$out     = ListFile::open( $this->listPath, $cursor->listBytes );
		$visited = null;
		$total   = count( $this->roots );
		try {
			$visited = VisitedDirs::open( $this->listPath . self::VISITED_SUFFIX, $cursor->visitedBytes );
			while ( $cursor->root < $total ) {
				if ( ! $cursor->started ) {
					$cursor->started = true;
					$cursor->stack   = $this->startRoot( $visited, $cursor->root ) ? [ '' ] : [];
				}
				$this->rootReal = $this->rootOwner( $cursor->root );
				while ( [] !== $cursor->stack ) {
					$this->scan( $out, $visited, $cursor, (string) array_pop( $cursor->stack ), $warn );
					$cursor->listBytes    = ListFile::flush( $out );
					$cursor->visitedBytes = $visited->flush();
					if ( $deadline->reached() ) {
						return false;
					}
				}
				++$cursor->root;
				$cursor->started = false;
			}

			return true;
		} finally {
			fclose( $out );
			if ( null !== $visited ) {
				$visited->close();
			}
		}
	}

	/**
	 * @param string $line One list line.
	 * @return array{entry: string, path: string, size: int, mtime: int}|null
	 */
	public static function parseLine( string $line ): ?array {
		$parts = explode( ' ', rtrim( $line, "\n" ) );
		if ( 4 !== count( $parts ) || ! ctype_digit( $parts[2] ) || ! ctype_digit( $parts[3] ) ) {
			return null;
		}
		$entry = base64_decode( $parts[0], true );
		$path  = base64_decode( $parts[1], true );
		if ( false === $entry || false === $path || '' === $entry || '' === $path ) {
			return null;
		}

		return [
			'entry' => $entry,
			'path'  => $path,
			'size'  => (int) $parts[2],
			'mtime' => (int) $parts[3],
		];
	}

	/**
	 * @param int $index Root index.
	 * @return string|null Territory the root's folder belongs to (null for wp-content on a normal site).
	 */
	private function rootOwner( int $index ): ?string {
		$real = realpath( $this->roots[ $index ]['dir'] );

		return false === $real ? null : $this->territories->ownerOf( $real );
	}

	/**
	 * A root is walked when it is a folder whose real path was not walked yet.
	 *
	 * @param VisitedDirs $visited Walked folders.
	 * @param int         $index   Root index.
	 * @return bool
	 */
	private function startRoot( VisitedDirs $visited, int $index ): bool {
		$real = realpath( $this->roots[ $index ]['dir'] );

		return false !== $real && is_dir( $real ) && ! $this->roots[ $index ]['filter']->sealsTree( $real ) && $visited->add( $real );
	}

	/**
	 * @param resource    $out     List file.
	 * @param VisitedDirs $visited Walked folders.
	 * @param EnumCursor  $cursor  Progress.
	 * @param string      $rel     Folder relative to the current root.
	 * @param callable    $warn    Warning callback.
	 * @return void
	 */
	private function scan( $out, VisitedDirs $visited, EnumCursor $cursor, string $rel, callable $warn ): void {
		$root = $this->roots[ $cursor->root ];
		$dir  = '' === $rel ? $root['dir'] : $root['dir'] . '/' . $rel;
		if ( ! file_exists( $dir ) ) {
			return;
		}
		$items = is_readable( $dir ) && is_executable( $dir ) ? scandir( $dir ) : false;
		if ( false === $items ) {
			$warn( 'unreadable_dir', self::entry( $root['entry'], $rel ) );
			++$cursor->skipped;
			return;
		}
		$dirReal = (string) realpath( $dir );
		$folders = [];
		foreach ( $items as $item ) {
			$path  = $dir . '/' . $item;
			$child = '' === $rel ? $item : $rel . '/' . $item;
			if ( '.' === $item || '..' === $item || $root['filter']->excludes( $path ) ) {
				continue;
			}
			if ( ! file_exists( $path ) ) {
				if ( is_link( $path ) ) {
					$warn( 'symlink', self::entry( $root['entry'], $child ) );
					++$cursor->skipped;
				}
			} elseif ( is_dir( $path ) ) {
				$folders[ $child ] = is_link( $path );
			} elseif ( is_file( $path ) && ! TempName::is( $item ) && ! $this->excludedTarget( $root['filter'], $path, $dirReal . '/' . $item ) ) {
				$this->add( $out, $cursor, $path, self::entry( $root['entry'], $child ), $warn );
			}
		}
		$this->pushFolders( $visited, $cursor, $root, $folders );
	}

	/**
	 * A file reached through a link (its own, or a linked folder above it) is
	 * also checked by its resolved paths, so an excluded file never slips in;
	 * a file with several names is checked against the files of the sealed folders.
	 *
	 * @param ExportFilter $filter   Root filter.
	 * @param string       $path     Logical path.
	 * @param string       $resolved Path with its folder resolved.
	 * @return bool
	 */
	private function excludedTarget( ExportFilter $filter, string $path, string $resolved ): bool {
		if ( $filter->excludes( $resolved ) || $filter->sealsFile( $path ) ) {
			return true;
		}
		$real = is_link( $path ) ? (string) realpath( $path ) : '';

		return '' !== $real && ( $filter->excludes( $real ) || $filter->sealsTree( dirname( $real ) ) );
	}

	/**
	 * Marks and stacks the walkable subfolders. Real folders are marked before
	 * links, so a link next to a folder never takes that folder's name.
	 *
	 * @param VisitedDirs                                             $visited Walked folders.
	 * @param EnumCursor                                              $cursor  Progress.
	 * @param array{dir: string, entry: string, filter: ExportFilter} $root    Current root.
	 * @param array<string, bool>                                     $folders Relative folder => is a link, alphabetical.
	 * @return void
	 */
	private function pushFolders( VisitedDirs $visited, EnumCursor $cursor, array $root, array $folders ): void {
		$keep = [];
		foreach ( [ false, true ] as $links ) {
			foreach ( $folders as $child => $isLink ) {
				$real = $isLink === $links ? realpath( $root['dir'] . '/' . $child ) : false;
				if ( false !== $real && ! $root['filter']->excludes( $real ) && ! $this->sealed( $root['filter'], $real, $isLink ) && $this->territories->allows( $real, $this->rootReal ) && $visited->add( $real ) ) {
					$keep[ $child ] = true;
				}
			}
		}
		foreach ( array_reverse( array_keys( array_intersect_key( $folders, $keep ) ) ) as $subdir ) {
			$cursor->stack[] = (string) $subdir;
		}
	}

	/**
	 * A sealed folder (our backups folders) is never entered. A real subfolder
	 * of a walked folder can only be one itself; a link may point below one.
	 *
	 * @param ExportFilter $filter Root filter.
	 * @param string       $real   Resolved folder path.
	 * @param bool         $isLink Whether the folder was reached through a link.
	 */
	private function sealed( ExportFilter $filter, string $real, bool $isLink ): bool {
		return $isLink ? $filter->sealsTree( $real ) : $filter->seals( $real );
	}

	/**
	 * @param resource   $out    List file.
	 * @param EnumCursor $cursor Progress.
	 * @param string     $path   Absolute path.
	 * @param string     $entry  Archive entry name.
	 * @param callable   $warn   Warning callback.
	 * @return void
	 * @throws ExportException When the list cannot be written.
	 */
	private function add( $out, EnumCursor $cursor, string $path, string $entry, callable $warn ): void {
		[ $folder, $base ] = Header::split( $entry );
		if ( strlen( $base ) > Format::NAME_MAX || strlen( $folder ) > Format::PATH_MAX ) {
			$warn( 'name_too_long', $entry );
			++$cursor->skipped;
			return;
		}
		if ( ! is_readable( $path ) ) {
			$warn( 'unreadable_file', $entry );
			++$cursor->skipped;
			return;
		}
		$size = (int) filesize( $path );
		ListFile::write( $out, base64_encode( $entry ) . ' ' . base64_encode( $path ) . ' ' . $size . ' ' . (int) filemtime( $path ) . "\n" );
		++$cursor->files;
		$cursor->bytes += $size;
	}

	private static function entry( string $prefix, string $rel ): string {
		if ( '' === $prefix ) {
			return $rel;
		}

		return '' === $rel ? $prefix : $prefix . '/' . $rel;
	}
}
