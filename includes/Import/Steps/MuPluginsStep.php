<?php
/**
 * Import step: moves the staged must-use plugins into place.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Archive\UnsafePathException;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\ImportTemps;
use LightweightPlugins\Migration\Import\Ownership;
use LightweightPlugins\Migration\Import\OwnershipNotice;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Runs after the swap: a must-use plugin starts running on the next request,
 * so each file appears complete (a rename, or a copy to a temporary name that
 * is renamed into place). Every folder a temporary copy can appear in is
 * journaled first (base key BASE) and swept at the end and after a failure.
 * Our temporary files left in the staging folder by an interrupted extraction
 * and links are never moved. A file that cannot be moved is skipped with a
 * warning; one already moved is gone from the staging folder, so a replay
 * moves only the rest. The step stops at the deadline between files;
 * cursor.handled lists every file dealt with (moved, copied with its source
 * left behind, or skipped with a warning), so each request moves the cursor
 * and no file is copied, counted or warned about twice; cursor.replaced
 * collects the replaced must-use plugins, named in one warning at the end.
 */
final class MuPluginsStep implements StepInterface {

	public const KEY = 'mu_plugins';

	/** ImportTemps journal base key of the must-use plugins folder. */
	public const BASE = 'mu-plugins';

	/** Names listed in one warning at most. */
	private const MAX_NAMES = 10;

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$work    = WorkDir::path( $this->store, $job );
		$staging = $work . '/' . ImportFiles::STAGING;
		if ( ! is_dir( $staging ) || is_link( $staging ) ) {
			return StepResult::done();
		}
		$target = ImportState::site( $job )->muPluginsDir;
		if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) && ! is_dir( $target ) ) {
			throw new ImportException( __( 'Cannot create the must-use plugins folder.', 'lw-migration' ) );
		}
		$real     = (string) realpath( $target );
		$owner    = Ownership::forBase( $real );
		$replaced = array_values( array_map( 'strval', (array) ( $job->cursor['replaced'] ?? [] ) ) );
		$handled  = array_values( array_map( 'strval', (array) ( $job->cursor['handled'] ?? [] ) ) );
		$staged   = array_values( array_diff( self::staged( $staging ), $handled ) );
		foreach ( $staged as $i => $rel ) {
			$dest      = $this->place( $job, $work, $real, $staging, $rel );
			$handled[] = $rel; // Moved, copied, or skipped with its warning: never again.
			if ( null !== $dest && '' !== $dest[1] ) {
				$replaced[] = $rel;
			}
			if ( null !== $dest && null !== $owner && ! $owner->apply( $real, $dest[0] ) ) {
				OwnershipNotice::once( $job );
			}
			if ( $i < count( $staged ) - 1 && $deadline->reached() ) {
				$job->cursor = [
					'handled'  => $handled,
					'replaced' => $replaced,
				];
				return StepResult::more( (int) ( 100 * count( $handled ) / ( count( $handled ) + count( $staged ) - $i - 1 ) ), __( 'Restoring must-use plugins...', 'lw-migration' ) );
			}
		}
		ImportTemps::sweep( $work, [ self::BASE => $real ], $job->createdAt );
		Files::removeTree( $staging );
		if ( [] !== $replaced ) {
			/* translators: %s: list of file names below the must-use plugins folder */
			$job->addWarning( sprintf( __( 'Replaced these must-use plugins of this site with the archive\'s copies: %s', 'lw-migration' ), self::nameList( $replaced ) ) );
		}

		return StepResult::done( __( 'Must-use plugins restored.', 'lw-migration' ) );
	}

	/**
	 * Regular files waiting in the staging folder, relative to it, without our
	 * temporary files and links.
	 *
	 * @param string $root Staging folder.
	 * @return array<int, string>
	 */
	public static function staged( string $root ): array {
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return [];
		}
		$out      = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && ! $file->isLink() && $file->isFile() && ! TempName::is( $file->getFilename() ) ) {
				$out[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * Up to MAX_NAMES names, then how many more.
	 *
	 * @param array<int, string> $names File names.
	 */
	public static function nameList( array $names ): string {
		$list = implode( ', ', array_slice( $names, 0, self::MAX_NAMES ) );
		$more = count( $names ) - self::MAX_NAMES;
		if ( $more <= 0 ) {
			return $list;
		}

		/* translators: 1: list of names, 2: number of further names */
		return sprintf( _n( '%1$s and %2$d more', '%1$s and %2$d more', $more, 'lw-migration' ), $list, $more );
	}

	/**
	 * Moves one staged file into place.
	 *
	 * @param Job    $job     Import job.
	 * @param string $work    Work folder (journal).
	 * @param string $real    Real path of the must-use plugins folder.
	 * @param string $staging Staging folder.
	 * @param string $rel     File below both.
	 * @return array{0: string, 1: string}|null [ target, '' or 'replaced' ]; null when it was skipped.
	 */
	private function place( Job $job, string $work, string $real, string $staging, string $rel ): ?array {
		try {
			$dest = PathGuard::resolve( $real, $rel );
		} catch ( UnsafePathException $e ) {
			/* translators: %s: path inside the archive */
			$job->addWarning( sprintf( __( 'Skipped an unsafe path in the archive: %s', 'lw-migration' ), 'mu-plugins/' . $rel ) );
			return null;
		}
		$existed = file_exists( $dest ) ? 'replaced' : '';
		$dir     = dirname( $rel );
		ImportTemps::record( $work, self::BASE, '.' === $dir ? '' : $dir );
		if ( ! self::move( $staging . '/' . $rel, $dest ) ) {
			/* translators: %s: file name below the must-use plugins folder */
			$job->addWarning( sprintf( __( 'Could not restore the must-use plugin %s: it could not be moved into place. Copy it from the archive by hand if you need it.', 'lw-migration' ), $rel ) );
			return null;
		}

		return [ $dest, $existed ];
	}

	private static function move( string $from, string $dest ): bool {
		$dir = dirname( $dest );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is reported as a warning without server paths.
		if ( ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) || is_dir( $dest ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a rename across file systems fails; the copy below takes over.
		if ( @rename( $from, $dest ) ) {
			return true;
		}
		$temp = TempName::generate( $dir );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is reported as a warning without server paths.
		if ( @copy( $from, $temp ) && @rename( $temp, $dest ) ) {
			Files::removeFile( $from ); // A source left in the staging folder goes with it at the end.
			return true;
		}
		Files::removeFile( $temp ); // One left behind is swept (journaled folder).

		return false;
	}
}
