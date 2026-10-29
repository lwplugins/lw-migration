<?php
/**
 * Empties and removes a folder without ever leaving it through a link.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Child first. A link (to a file or a folder) inside the tree is removed as a
 * link, never followed (is_link() before is_dir() everywhere); a sealed folder
 * (our storage and the backups folders, compared by device:inode, so another
 * spelling or a mount finds it too), a folder of the site itself (forSite():
 * CoreFolders, so a bind mount of one is caught on the same device) and a
 * folder on another device than the root (a mount) are never entered. Each folder's identity (lstat
 * device:inode) is taken before it is entered and checked again after it is
 * listed and before every removal in it: a folder that became a link or
 * another folder meanwhile (an ancestor swapped for a link changes it too) is
 * left, so a swap never turns the walk into a delete outside the tree. A
 * folder's protection files (deny files, index pages) go last and only once
 * nothing else is left in it, so a folder that keeps a file keeps its
 * protection. A pass cut by the deadline returns done false; the next call
 * starts a fresh pass (what was removed stays removed). A scan pass (erase()
 * given a position) walks in byte order, ends at the deadline after any entry
 * (removed or not, so a tree of unremovable files still ends on time) and
 * position() names the last entry it finished; the next scan pass given that
 * position skips what lies up to it (protection files excepted: they are
 * always weighed for their folder). What cannot be
 * removed is counted, never hidden, and never raises a PHP warning (it would
 * carry the path); skippedFor() tells why folders were left. WordPress-free;
 * core's WP_Filesystem_Direct::delete() follows linked folders, so it is
 * never used for a reset.
 */
final class TreeEraser {

	/** Files that protect a folder from the web: they go last. */
	public const PROTECTION_FILES = [ '.htaccess', '.user.ini', 'web.config', 'index.php', 'index.html' ];

	/** Counts of a pass before it starts. */
	private const NO_COUNTS = [
		'removed' => 0,
		'failed'  => 0,
		'skipped' => 0,
	];

	/** Why folders were skipped, before a pass starts. */
	private const NO_REASONS = [
		'sealed'  => 0,
		'core'    => 0,
		'device'  => 0,
		'changed' => 0,
	];

	/** @var array{removed: int, failed: int, skipped: int} */
	private array $counts = self::NO_COUNTS;

	/** @var array{sealed: int, core: int, device: int, changed: int} Why folders were skipped in the last pass. */
	private array $reasons = self::NO_REASONS;

	/** @var array<int, string> Lower-cased names kept directly in the root. */
	private array $keepInRoot;

	/** @var \Closure fn( string $path, string $id ): string, the device of a folder */
	private \Closure $deviceOf;

	/** @var string The device of the root folder in this pass. */
	private string $device = '';

	/** @var array<string, true> Folders counted as changed in this pass. */
	private array $changed = [];

	/** @var ScanPosition|null The position of a scan pass (it ends after any entry); null = a plain pass. */
	private ?ScanPosition $scan = null;

	/**
	 * @param array<string, string> $sealed     "device:inode" => path, folders never entered.
	 * @param array<int, string>    $keepInRoot Names kept directly in the root (compared case-insensitively).
	 * @param \Closure|null         $deviceOf   fn( string $path, string $id ): string; null = the device part of the identity.
	 * @param array<string, string> $core       "device:inode" => path, folders of the site itself, never entered.
	 */
	public function __construct( private array $sealed, array $keepInRoot = [], ?\Closure $deviceOf = null, private array $core = [] ) {
		$this->keepInRoot = array_map( 'strtolower', $keepInRoot );
		$this->deviceOf   = $deviceOf ?? static fn( string $path, string $id ): string => explode( ':', $id )[0];
	}

	/**
	 * An eraser that never enters the sealed folders nor a folder of the site.
	 *
	 * @param array<string, string> $sealed     "device:inode" => path.
	 * @param Site                  $site       The site whose own folders stay.
	 * @param array<int, string>    $keepInRoot Names kept directly in the root.
	 */
	public static function forSite( array $sealed, Site $site, array $keepInRoot = [] ): self {
		return new self( $sealed, $keepInRoot, null, CoreFolders::ids( $site ) );
	}

	/**
	 * @param string   $root       Folder (or file, or link) to erase.
	 * @param bool     $removeRoot Whether the root itself goes too (a linked root: the link only).
	 * @param Deadline $deadline   Time budget.
	 * @param ?string  $after      Null: a plain pass. A string: a scan pass resuming after this position() ('' = from the start).
	 * @return array{done: bool, removed: int, failed: int, skipped: int}
	 */
	public function erase( string $root, bool $removeRoot, Deadline $deadline, ?string $after = null ): array {
		$this->counts  = self::NO_COUNTS;
		$this->reasons = self::NO_REASONS;
		$this->changed = [];
		$this->scan    = null === $after ? null : new ScanPosition();
		clearstatcache( true );
		$linked = is_link( $root );
		if ( $linked || is_file( $root ) ) {
			if ( $removeRoot ) {
				$this->removeOne( $root );
			}
			if ( ! $linked || $removeRoot ) {
				return [ 'done' => true ] + $this->counts;
			}
		}
		$id = $linked ? FolderIdentity::of( $root ) : FolderIdentity::entry( $root );
		if ( null === $id || ! is_dir( $root ) ) {
			return [ 'done' => true ] + $this->counts;
		}
		$kept = $this->kept( $root );
		if ( null !== $kept ) {
			$this->skip( $kept );
			return [ 'done' => true ] + $this->counts;
		}
		$this->device = ( $this->deviceOf )( $root, $id );
		$folder       = [ $root, $id, $linked ];
		$done         = $this->emptyFolder( $folder, true, $deadline, ScanPosition::names( (string) $after ) );
		if ( $done && $removeRoot ) { // A linked root left the method above.
			$this->removeFolder( $folder );
		}

		return [ 'done' => $done ] + $this->counts;
	}

	/**
	 * @return string The last entry the last scan pass finished, relative to the root ('' = none).
	 */
	public function position(): string {
		return null === $this->scan ? '' : $this->scan->last();
	}

	/**
	 * @return array{sealed: int, core: int, device: int, changed: int} Folders the last pass left: sealed, of the site itself, on another device, changed while it ran.
	 */
	public function skippedFor(): array {
		return $this->reasons;
	}

	/**
	 * Child first; never follows a link; protection files after the rest.
	 *
	 * @param array{0: string, 1: string, 2: bool} $folder   Path, identity, whether the path is a link (a kept linked root).
	 * @param bool                                 $isRoot   Whether it is the root (its kept names stay).
	 * @param Deadline                             $deadline Time budget.
	 * @param array<int, string>                   $resume   Scan position below this folder still to pass ([] = none).
	 * @return bool False when the deadline cut the pass.
	 */
	private function emptyFolder( array $folder, bool $isRoot, Deadline $deadline, array $resume = [] ): bool {
		$dir        = $folder[0];
		$protection = [];
		foreach ( $this->listing( $folder ) as $name ) {
			$path  = $dir . '/' . $name;
			$low   = strtolower( $name );
			$guard = in_array( $low, self::PROTECTION_FILES, true );
			if ( $isRoot && in_array( $low, $this->keepInRoot, true ) ) {
				continue;
			}
			$below = $guard ? [] : ScanPosition::resumeAt( $name, $resume ); // By name: a passed entry costs no stat.
			if ( null === $below ) {
				continue; // Finished by an earlier scan pass.
			}
			clearstatcache( true, $path );
			$plain = is_link( $path ) || ! is_dir( $path );
			if ( $guard && $plain ) {
				$protection[] = $name; // Weighed for its folder in every pass.
				continue;
			}
			$below = $guard ? ScanPosition::resumeAt( $name, $resume ) : $below;
			if ( null === $below ) {
				continue;
			}
			if ( ! $this->unchanged( $folder ) ) {
				return true; // Left: it changed under the walk (counted once).
			}
			$removed = $this->counts['removed'];
			if ( $plain ) {
				$this->removeOne( $path );
			} else {
				$this->scan?->enter( $name );
				$entered = $this->emptyChild( $path, $deadline, $below );
				$this->scan?->leave();
				if ( ! $entered ) {
					return false;
				}
			}
			if ( null !== $this->scan ) {
				$this->scan->finished( $name );
				if ( $deadline->reached() ) {
					return false; // Any entry uses up a scan pass: its position moved on.
				}
			} elseif ( $this->counts['removed'] > $removed && $deadline->reached() ) {
				return false; // Only a removal uses up the pass: a cut pass always made progress.
			}
		}
		$this->removeProtection( $folder, $protection );

		return true;
	}

	/**
	 * Enters a sub-folder (by its identity taken now) unless it is sealed or
	 * on another device, empties it and removes it.
	 *
	 * @param string             $path     Sub-folder (not a link).
	 * @param Deadline           $deadline Time budget.
	 * @param array<int, string> $resume   Scan position inside it ([] = none).
	 * @return bool False when the deadline cut the pass.
	 */
	private function emptyChild( string $path, Deadline $deadline, array $resume ): bool {
		$id = FolderIdentity::entry( $path );
		if ( null === $id ) {
			$this->skip( 'changed' );
			return true;
		}
		$kept = $this->kept( $path );
		if ( null !== $kept ) {
			$this->skip( $kept );
			return true;
		}
		if ( ( $this->deviceOf )( $path, $id ) !== $this->device ) {
			$this->skip( 'device' );
			return true;
		}
		$child = [ $path, $id, false ];
		if ( ! $this->emptyFolder( $child, false, $deadline, $resume ) ) {
			return false;
		}
		$this->removeFolder( $child );

		return true;
	}

	/**
	 * Whether the folder still is what was entered: the same identity, and
	 * a link only when it was entered as one. Counts a change once.
	 *
	 * @param array{0: string, 1: string, 2: bool} $folder Path, identity, whether it is a link.
	 */
	private function unchanged( array $folder ): bool {
		[ $dir, $id, $linked ] = $folder;
		clearstatcache( true, $dir );
		$same = $linked ? is_link( $dir ) && FolderIdentity::of( $dir ) === $id : ! is_link( $dir ) && is_dir( $dir ) && FolderIdentity::entry( $dir ) === $id;
		if ( ! $same && ! isset( $this->changed[ $dir ] ) ) {
			$this->changed[ $dir ] = true;
			$this->skip( 'changed' );
		}

		return $same;
	}

	/**
	 * Removes a folder's protection files only when nothing else is left in it
	 * (a file that stayed keeps its folder protected). Those left behind are
	 * neither removed nor failed.
	 *
	 * @param array{0: string, 1: string, 2: bool} $folder     Path, identity, whether it is a link.
	 * @param array<int, string>                   $protection Names of its protection files.
	 */
	private function removeProtection( array $folder, array $protection ): void {
		if ( [] === $protection ) {
			return;
		}
		$left = $this->entries( $folder[0] );
		if ( null === $left || [] !== array_diff( $left, $protection ) ) {
			return;
		}
		foreach ( $protection as $name ) {
			if ( ! $this->unchanged( $folder ) ) {
				return;
			}
			$this->removeOne( $folder[0] . '/' . $name );
		}
	}

	/**
	 * @param string $path File or link.
	 */
	private function removeOne( string $path ): void {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- any entry but a folder (a file, a link, a FIFO, a socket); a failure is counted, a warning would carry the path.
			@unlink( $path );
			clearstatcache( true, $path );
		}
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			++$this->counts['removed'];
		} else {
			++$this->counts['failed'];
		}
	}

	/**
	 * Removes an empty folder that still is the one entered; one holding
	 * something kept or unremovable is left quietly (its content was counted
	 * already).
	 *
	 * @param array{0: string, 1: string, 2: bool} $folder Path, identity, whether it is a link.
	 */
	private function removeFolder( array $folder ): void {
		$dir = $folder[0];
		if ( [] !== $this->entries( $dir ) || ! $this->unchanged( $folder ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a failure is counted; a warning would carry the path.
		@rmdir( $dir );
		clearstatcache( true, $dir );
		if ( is_dir( $dir ) || is_link( $dir ) ) {
			++$this->counts['failed'];
		} else {
			++$this->counts['removed'];
		}
	}

	/**
	 * Lists a folder that still is the one entered.
	 *
	 * @param array{0: string, 1: string, 2: bool} $folder Path, identity, whether it is a link.
	 * @return array<int, string> Its names; an unreadable folder counts one failure, a changed one is skipped; both list nothing.
	 */
	private function listing( array $folder ): array {
		$names = $this->entries( $folder[0] );
		if ( ! $this->unchanged( $folder ) ) {
			return [];
		}
		if ( null === $names ) {
			++$this->counts['failed'];
			return [];
		}
		return null === $this->scan ? $names : ScanPosition::order( $names );
	}

	/**
	 * @param string $dir Folder.
	 * @return array<int, string>|null Names without . and ..; null when it cannot be listed.
	 */
	private function entries( string $dir ): ?array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is counted; a warning would carry the path.
		$names = @scandir( $dir );

		return false === $names ? null : array_values( array_diff( $names, [ '.', '..' ] ) );
	}

	/**
	 * @param string $reason One of the skippedFor() keys.
	 */
	private function skip( string $reason ): void {
		++$this->counts['skipped'];
		++$this->reasons[ $reason ];
	}

	/**
	 * @param string $path Folder (not a link).
	 * @return string|null 'sealed' or 'core' when it is never entered.
	 */
	private function kept( string $path ): ?string {
		$id = FolderIdentity::of( $path );
		if ( null === $id ) {
			return null;
		}
		if ( isset( $this->sealed[ $id ] ) ) {
			return 'sealed';
		}

		return isset( $this->core[ $id ] ) ? 'core' : null;
	}
}
