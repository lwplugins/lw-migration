<?php
/**
 * Which temporary import tables still belong to an import.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Maintenance;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\OrphanTables;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * A live import or database reset owns all of its tables (lwt/lwx/lwo/lwy),
 * and so does one whose swap may have happened; any other one whose folder
 * still exists owns its replaced tables (lwo/lwy, the only undo path after a
 * swap). The
 * swap marker goes with the work files of an ended job, so "folder exists" is
 * the only safe owner test for lwo/lwy. The site's own prefix is never touched,
 * and neither is a run this site did not register (TmpRunRegistry): another
 * site sharing the database may own it.
 */
final class TableOwners {

	/** Site-lock holder id prefix of the table sweep (never a job id: those are 16 hex digits). */
	public const LOCK_ID = 'table-sweep-';

	/**
	 * Runs under the site lock (taken with a sweep id, so no import or export
	 * can start or go on meanwhile); when the lock is busy nothing is done.
	 * Only runs this site registered are dropped, and a run's owners are
	 * checked again right before its drops. A registered run with no table
	 * left and no job is forgotten once older than the lock TTL; its RunMarker
	 * goes last, after the registry entry.
	 *
	 * @param Connection     $db         Database.
	 * @param JobStore       $store      Job store.
	 * @param SiteLock       $lock       Site lock.
	 * @param TmpRunRegistry $registry   Runs this site created.
	 * @param int            $now        Unix time.
	 * @param string         $sitePrefix The site's (base) table prefix.
	 * @return array{dropped: array<int, string>, failed: array<string, string>, unregistered: array<int, string>, cleared: array<int, string>}|null Null when the site lock is busy.
	 */
	public static function sweep( Connection $db, JobStore $store, SiteLock $lock, TmpRunRegistry $registry, int $now, string $sitePrefix ): ?array {
		$sweepId = self::LOCK_ID . bin2hex( random_bytes( 6 ) );
		if ( ! $lock->acquire( $sweepId, $now ) ) {
			return null;
		}
		try {
			$owned  = static fn( string $run ): array => self::keptFor( $store, $now, $run );
			$result = OrphanTables::sweep( $db, $registry->ids(), $owned, $sitePrefix );
			$since  = $registry->all();
			foreach ( $result['cleared'] as $run ) {
				if ( ( $since[ $run ] ?? $now ) < $now - SiteLock::TTL && [] === $owned( $run ) ) {
					RunMarker::releaseIfUnused( $db, 'lwt' . $run . '_', static fn( string $id ): bool => $registry->forget( $id ) );
				}
			}

			return $result;
		} finally {
			$lock->release( $sweepId );
		}
	}

	/**
	 * Uninstall: every table of every run this site registered goes, the
	 * replaced (undo) tables included, then each emptied run is forgotten and
	 * its RunMarker dropped. Nothing happens while a job holds the site lock
	 * (an import still running in a process started before the uninstall).
	 *
	 * @param Connection     $db         Database.
	 * @param SiteLock       $lock       Site lock.
	 * @param TmpRunRegistry $registry   Runs this site created.
	 * @param string         $sitePrefix The site's (base) table prefix.
	 * @return array{dropped: array<int, string>, failed: array<string, string>, unregistered: array<int, string>, cleared: array<int, string>}|null Null when the site lock is busy.
	 */
	public static function dropForUninstall( Connection $db, SiteLock $lock, TmpRunRegistry $registry, string $sitePrefix ): ?array {
		$sweepId = self::LOCK_ID . bin2hex( random_bytes( 6 ) );
		if ( ! $lock->acquire( $sweepId, time() ) ) {
			return null;
		}
		try {
			$result = OrphanTables::sweep( $db, $registry->ids(), static fn(): array => [], $sitePrefix ); // Keeps nothing.
			foreach ( $result['cleared'] as $run ) {
				RunMarker::releaseIfUnused( $db, 'lwt' . $run . '_', static fn( string $id ): bool => $registry->forget( $id ) );
			}

			return $result;
		} finally {
			$lock->release( $sweepId );
		}
	}

	/**
	 * @param array{failed: array<string, string>, unregistered: array<int, string>}|null $result Sweep result.
	 * @return array<int, string> Log lines (table names only).
	 */
	public static function logLines( ?array $result ): array {
		if ( null === $result ) {
			return [];
		}
		$lines = [];
		foreach ( $result['failed'] as $table => $error ) {
			$lines[] = sprintf( 'could not drop the leftover table %s: %s', $table, $error );
		}
		if ( [] !== $result['unregistered'] ) {
			$lines[] = sprintf(
				'left %d temporary-looking tables alone because this site did not create them (another site may share the database): %s',
				count( $result['unregistered'] ),
				implode( ', ', array_slice( $result['unregistered'], 0, 10 ) )
			);
		}

		return $lines;
	}

	/**
	 * Temporary prefixes of the imports that may still be running: unfinished
	 * and touched within Cleanup::MAX_AGE, or holding the site lock.
	 *
	 * @param JobStore      $store  Job store.
	 * @param int           $now    Unix time.
	 * @param \Closure|null $holder fn(): ?string, the job holding the site lock.
	 * @return array<int, string> lwtXXXX_ prefixes.
	 */
	public static function activeTmpPrefixes( JobStore $store, int $now, ?\Closure $holder = null ): array {
		$owner  = null === $holder ? null : $holder();
		$active = [];
		foreach ( self::imports( $store ) as $job ) {
			if ( $job->id === $owner || ( ! $job->isFinished() && $job->updatedAt >= $now - Cleanup::MAX_AGE ) ) {
				$active[] = (string) $job->state['tmpPrefix'];
			}
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * @param JobStore      $store  Job store.
	 * @param int           $now    Unix time.
	 * @param \Closure|null $holder fn(): ?string, the job holding the site lock.
	 * @return array<int, string> Table prefixes the orphan sweep keeps.
	 */
	public static function keptPrefixes( JobStore $store, int $now, ?\Closure $holder = null ): array {
		$active = self::activeTmpPrefixes( $store, $now, $holder );
		$keep   = [];
		foreach ( self::imports( $store ) as $job ) {
			$tmp     = (string) $job->state['tmpPrefix'];
			$swapped = ! empty( $job->state['swapped'] ) || is_file( $store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
			$all     = $swapped || in_array( $tmp, $active, true );
			$keep    = array_merge( $keep, $all ? OrphanTables::runPrefixes( $tmp ) : OrphanTables::undoPrefixes( $tmp ) );
		}

		return array_values( array_unique( $keep ) );
	}

	/**
	 * @param JobStore $store Job store.
	 * @param int      $now   Unix time.
	 * @param string   $run   Run id.
	 * @return array<int, string> The prefixes of that run still owned by a job.
	 */
	private static function keptFor( JobStore $store, int $now, string $run ): array {
		return array_values( array_filter( self::keptPrefixes( $store, $now ), static fn( string $p ): bool => OrphanTables::runId( $p ) === $run ) );
	}

	/**
	 * @param JobStore $store Job store.
	 * @return array<int, Job> Readable imports and resets with a valid temporary prefix (state.tmpPrefix).
	 */
	private static function imports( JobStore $store ): array {
		$imports = [];
		foreach ( $store->ids() as $id ) {
			try {
				$job = $store->load( $id );
			} catch ( JobException $e ) {
				continue;
			}
			$tmp = $job->state['tmpPrefix'] ?? null;
			if ( in_array( $job->type, [ 'import', ResetJobs::TYPE ], true ) && is_string( $tmp ) && PrefixRules::isValidTempPrefix( $tmp ) ) {
				$imports[] = $job;
			}
		}

		return $imports;
	}
}
