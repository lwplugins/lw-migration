<?php
/**
 * Daily sweep of leftover jobs and store temps.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Maintenance;

use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Export\NestedExport;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Nonces;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Job folders untouched for a day are deleted (spec 8), finished or not. A job
 * that may still be running is never touched: the one holding the site lock, one
 * whose lock file someone holds, and one marked running that was touched within
 * the site-lock TTL. Store temps left in a backups folder (the default one,
 * the chosen one and the target of an unfinished move) by a request that
 * died are deleted once older than the site-lock TTL, unless a job still names them.
 * Before a folder goes, an unfinished import's temporary files go
 * (AbandonedImport); temporary import tables nobody owns go last (TableOwners).
 * On a network the sweep is planned and runs on the main site only, where the
 * storage folder and the run registry live (no event in any subsite's cron).
 */
final class Cleanup {

	public const HOOK = 'lw_migration_cleanup';

	public const MAX_AGE = 86400;

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
	}

	public static function schedule(): void {
		if ( MainSite::isContext() && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run(): void {
		if ( MainSite::isContext() ) {
			self::clean( Services::get(), time() );
		}
	}

	/**
	 * Each sweep runs on its own: a failure is logged (without the server's
	 * folders) and never stops the other sweeps or the cron event. The table
	 * sweep runs last, so tables of a folder deleted now are orphans already.
	 *
	 * @param Services $services Wired services.
	 * @param int      $now      Unix time.
	 * @return void
	 */
	public static function clean( Services $services, int $now ): void {
		self::pruneConnections( $now ); // Option rows only: they need no storage folder.
		try {
			$jobs    = $services->jobs();
			$storage = $services->storage();
			$root    = $storage->root();
			$backups = $storage->backupsDir();
			$folders = array_values( array_unique( array_merge( [ $storage->defaultBackupsDir() ], $storage->backupDirs() ) ) );
		} catch ( StorageException $e ) {
			return; // No storage folder yet: nothing to clean.
		}
		$paths  = array_merge( $folders, [ $root, WP_CONTENT_DIR, ABSPATH ] );
		$holder = static fn(): ?string => $services->lock()->holder( $now );
		try {
			$discard = static function ( string $id ) use ( $jobs, $backups, $folders, $root, $paths ): void {
				self::discardReservations( $jobs, $id, $folders );
				if ( ! AbandonedImport::discard( $jobs, $id, $backups, $root, static fn( string $line ) => self::write( $line, $paths ) ) ) {
					self::write( 'some temporary files of an abandoned import could not be removed', $paths );
				}
			};
			self::sweep( $jobs, $now, $holder, $discard );
		} catch ( \Throwable $e ) {
			self::log( 'job', $e, $paths );
		}
		$named = null;
		foreach ( $folders as $folder ) {
			try {
				$named = $named ?? self::namedTemps( $jobs );
				self::sweepTemps( $folder, $now, $named );
			} catch ( \Throwable $e ) {
				self::log( 'temp', $e, $paths );
			}
		}
		try {
			( new Shares() )->prune( $now );
		} catch ( \Throwable $e ) {
			self::log( 'transfer link', $e, $paths );
		}
		try {
			$result = TableOwners::sweep( $services->connection(), $jobs, $services->lock(), new TmpRunRegistry(), $now, (string) $GLOBALS['wpdb']->base_prefix );
			foreach ( TableOwners::logLines( $result ) as $line ) {
				self::write( $line, $paths );
			}
		} catch ( \Throwable $e ) {
			self::log( 'table', $e, $paths );
		}
	}

	/**
	 * Each of the three rows on its own: a failure is logged and never stops the others.
	 *
	 * @param int $now Unix time.
	 */
	private static function pruneConnections( int $now ): void {
		$paths = [ WP_CONTENT_DIR, ABSPATH ];
		try {
			( new Links() )->prune( $now );
		} catch ( \Throwable $e ) {
			self::log( 'connection', $e, $paths );
		}
		try {
			( new Codes() )->prune( $now );
		} catch ( \Throwable $e ) {
			self::log( 'pairing code', $e, $paths );
		}
		try {
			( new Nonces() )->prune( $now );
		} catch ( \Throwable $e ) {
			self::log( 'replay cache', $e, $paths );
		}
	}

	/**
	 * Every check is repeated once the job lock is held, so a job that was
	 * picked up in between is kept.
	 *
	 * @param JobStore      $store  Job store.
	 * @param int           $now    Unix time.
	 * @param \Closure|null $holder  fn(): ?string, the job holding the site lock (never deleted).
	 * @param \Closure|null $discard fn( string $id ): void, run before a folder goes (AbandonedImport).
	 * @return array<int, string> Deleted job ids.
	 */
	public static function sweep( JobStore $store, int $now, ?\Closure $holder = null, ?\Closure $discard = null ): array {
		$deleted = [];
		foreach ( $store->folders() as $id ) {
			if ( ! self::deletable( $store, $id, $now, $holder, false ) ) {
				continue;
			}
			try {
				$lock = $store->acquire( $id );
			} catch ( JobException $e ) {
				continue; // Gone meanwhile.
			}
			if ( null === $lock ) {
				continue;
			}
			try {
				if ( self::deletable( $store, $id, $now, $holder, true ) ) {
					if ( null !== $discard ) {
						$discard( $id );
					}
					$store->delete( $id );
					$deleted[] = $id;
				}
			} finally {
				$store->release( $lock );
			}
		}

		return $deleted;
	}

	/**
	 * @param string             $dir  Backups folder.
	 * @param int                $now  Unix time.
	 * @param array<int, string> $keep Temp names a job still refers to.
	 * @return array<int, string> Deleted temp names.
	 */
	public static function sweepTemps( string $dir, int $now, array $keep ): array {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a folder outside open_basedir or without permissions is skipped, never a warning with its path.
		$items = @is_dir( $dir ) ? @scandir( $dir ) : false;
		if ( false === $items ) {
			return [];
		}
		$deleted = [];
		foreach ( $items as $name ) {
			$path = rtrim( $dir, '/' ) . '/' . $name;
			if ( ! TempName::is( $name ) || in_array( $name, $keep, true ) || @is_link( $path ) || ! @is_file( $path ) ) {
				continue;
			}
			$mtime = @filemtime( $path );
			if ( false !== $mtime && $mtime < $now - SiteLock::TTL && @unlink( $path ) ) {
				$deleted[] = $name;
			}
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		return $deleted;
	}

	/**
	 * @param JobStore      $store  Job store.
	 * @param string        $id     Job id.
	 * @param int           $now    Unix time.
	 * @param \Closure|null $holder fn(): ?string.
	 * @param bool          $locked Whether we hold the job lock (taking it touched the folder).
	 */
	private static function deletable( JobStore $store, string $id, int $now, ?\Closure $holder, bool $locked ): bool {
		if ( null !== $holder && $id === $holder() ) {
			return false;
		}
		$dir  = $store->dir( $id );
		$file = $dir . '/job.json';
		if ( ! is_file( $file ) ) {
			if ( $locked ) {
				return true; // Still no job.json: the age was checked before the lock.
			}
			$mtime = is_dir( $dir ) ? filemtime( $dir ) : false;
			return false !== $mtime && $mtime < $now - self::MAX_AGE;
		}
		$mtime = filemtime( $file );
		try {
			$job = $store->load( $id );
		} catch ( JobException $e ) {
			return true; // Unreadable job.json.
		}
		if ( Job::RUNNING === $job->status ) {
			if ( max( $job->updatedAt, false === $mtime ? 0 : $mtime ) >= $now - SiteLock::TTL ) {
				return false;
			}
		}

		return $job->updatedAt < $now - self::MAX_AGE;
	}

	/**
	 * @param string             $sweep Which sweep failed.
	 * @param \Throwable         $e     Failure.
	 * @param array<int, string> $paths Absolute folders shown relative in the log.
	 */
	private static function log( string $sweep, \Throwable $e, array $paths ): void {
		self::write( sprintf( 'the %s sweep failed: %s: %s', $sweep, get_class( $e ), $e->getMessage() ), $paths );
	}

	/**
	 * @param string             $text  Log line (paths are made relative).
	 * @param array<int, string> $paths Absolute folders shown relative in the log.
	 */
	private static function write( string $text, array $paths ): void {
		$map = [];
		foreach ( $paths as $path ) {
			$map[ rtrim( $path, '/' ) . '/' ] = '';
			$map[ rtrim( $path, '/' ) ]       = '.';
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a cron sweep has no other place to report a failure.
		error_log( 'LW Migration cleanup: ' . strtr( $text, $map ) );
	}

	/**
	 * An empty name reservation a dead store of this job left (its own store,
	 * an import's nested safety backup or a reset's nested backup) in any of
	 * our backups folders; a name holding bytes stays.
	 *
	 * @param JobStore           $store   Job store.
	 * @param string             $id      Job id.
	 * @param array<int, string> $folders Backups folders.
	 */
	private static function discardReservations( JobStore $store, string $id, array $folders ): void {
		try {
			$cursor = $store->load( $id )->cursor;
		} catch ( JobException $e ) {
			return;
		}
		foreach ( $folders as $backups ) {
			$publisher = new BackupPublisher( $backups );
			foreach ( array_merge( [ (string) ( $cursor['reserving'] ?? '' ) ], NestedExport::namedTemps( $cursor )['reserving'] ) as $name ) {
				$publisher->discard( '', $name );
			}
		}
	}

	/**
	 * @param JobStore $store Job store.
	 * @return array<int, string> Temp names in the cursors of the remaining jobs (also cursor.safety|backup.cursor.temp).
	 */
	private static function namedTemps( JobStore $store ): array {
		$names = [];
		foreach ( $store->ids() as $id ) {
			try {
				$cursor = $store->load( $id )->cursor;
			} catch ( JobException $e ) {
				continue;
			}
			foreach ( array_merge( [ $cursor['temp'] ?? null ], NestedExport::namedTemps( $cursor )['temps'] ) as $temp ) {
				if ( is_string( $temp ) ) {
					$names[] = $temp; // An export's store temp, or a nested backup's (an import's or a reset's).
				}
			}
		}

		return $names;
	}
}
