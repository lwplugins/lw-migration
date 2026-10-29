<?php
/**
 * Steps of an export job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Export\Steps\CleanStep;
use LightweightPlugins\Migration\Export\Steps\ConfigStep;
use LightweightPlugins\Migration\Export\Steps\ContentStep;
use LightweightPlugins\Migration\Export\Steps\DatabaseFileStep;
use LightweightPlugins\Migration\Export\Steps\DatabaseStep;
use LightweightPlugins\Migration\Export\Steps\EnumerateStep;
use LightweightPlugins\Migration\Export\Steps\FinalizeStep;
use LightweightPlugins\Migration\Export\Steps\InitStep;
use LightweightPlugins\Migration\Export\Steps\NetworkStep;
use LightweightPlugins\Migration\Export\Steps\StoreStep;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Schedules\Repository as Schedules;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * Step keys in order and their implementations. The network step is always
 * registered; only the step lists of a network job name it (stepsFor()).
 */
final class ExportPipeline {

	public const STEPS = [ 'init', 'config', 'enumerate', 'content', 'database', 'database_file', 'finalize', 'store', 'clean' ];

	/** The safety backup before an import: database only. */
	public const DATABASE_ONLY_STEPS = [ 'init', 'config', 'database', 'database_file', 'finalize', 'store', 'clean' ];

	/**
	 * A connection's archive: the archive stays in the job folder (no backup
	 * is stored); the other site downloads it (pull) or receives it (push).
	 */
	public const LINK_STEPS = [ 'init', 'config', 'enumerate', 'content', 'database', 'database_file', 'finalize' ];

	/**
	 * The step list of a job: STEPS / DATABASE_ONLY_STEPS, with the network
	 * snapshot after `init` on a multisite network.
	 *
	 * @param bool $network      A multisite network (adds the network snapshot step).
	 * @param bool $databaseOnly The safety backup before an import.
	 * @return array<int, string>
	 */
	public static function stepsFor( bool $network, bool $databaseOnly ): array {
		$steps = $databaseOnly ? self::DATABASE_ONLY_STEPS : self::STEPS;

		return $network ? array_merge( [ 'init', 'network' ], array_slice( $steps, 1 ) ) : $steps;
	}

	/**
	 * @param JobStore       $jobs           Job store.
	 * @param string         $storageRoot    Storage root.
	 * @param string         $backupsDir     Backups folder.
	 * @param Retention      $retention      Pruning rules.
	 * @param \Closure       $connect        fn(): Connection — called once per step run, so every tick gets a fresh link.
	 * @param \Closure       $freeSpace      fn( string $dir ): ?int.
	 * @param \Closure       $databaseBytes  fn(): int.
	 * @param \Closure       $now            fn(): int.
	 * @param \Closure|null  $scheduleExists fn( string $id ): bool — whether a schedule still exists;
	 *                                       null = a scheduled backup never prunes.
	 * @param \Closure|null  $ready          fn(): void — makes the backups folder ready or throws ExportException;
	 *                                      null = the store step creates the folder (tests).
	 * @param FileMover|null $mover          Moves the archive into the backups folder.
	 * @param string         $movingDir      Target of an unfinished move of the backups ('' = none).
	 * @return array<string, StepInterface>
	 */
	public static function build( JobStore $jobs, string $storageRoot, string $backupsDir, Retention $retention, \Closure $connect, \Closure $freeSpace, \Closure $databaseBytes, \Closure $now, ?\Closure $scheduleExists = null, ?\Closure $ready = null, ?FileMover $mover = null, string $movingDir = '' ): array {
		return [
			'init'          => new InitStep(),
			'network'       => new NetworkStep( $jobs, new NetworkReader() ),
			'config'        => new ConfigStep( $jobs, static fn(): string => $connect()->serverVersion(), $now ),
			'enumerate'     => new EnumerateStep( $jobs, $storageRoot, $freeSpace, $databaseBytes, $backupsDir ),
			'content'       => new ContentStep( $jobs ),
			'database'      => new DatabaseStep( $jobs, $connect ),
			'database_file' => new DatabaseFileStep( $jobs ),
			'finalize'      => new FinalizeStep( $jobs ),
			'store'         => new StoreStep( $jobs, $backupsDir, $retention, $now, $scheduleExists, $ready, $mover, null, $movingDir ),
			'clean'         => new CleanStep( $jobs ),
		];
	}

	/**
	 * A scheduled backup prunes only while its schedule is still stored (the
	 * lookup reads, it never creates the default schedule). The store step
	 * makes the backups folder ready on every call: the default one is created,
	 * a chosen one must still be there and ours (never created again).
	 *
	 * @param Services       $services  WordPress wiring.
	 * @param Retention|null $retention Pruning rules (default: the site's option).
	 * @return array<string, StepInterface>
	 */
	public static function steps( Services $services, ?Retention $retention = null ): array {
		$storage = $services->storage();

		return self::build(
			$services->jobs(),
			$storage->root(),
			$storage->backupsDir(),
			$retention ?? $services->retention(),
			static fn() => $services->connection(),
			static fn( string $dir ): ?int => DiskSpace::free( $dir ),
			static fn(): int => $services->databaseBytes(),
			static fn(): int => time(),
			static fn( string $id ): bool => null !== ( new Schedules() )->find( $id ),
			static function () use ( $services ): void {
				try {
					$services->storage()->ensureBackups();
				} catch ( FolderRefusedException $e ) {
					throw new ExportException( FolderMessages::of( $e->reason ) );
				}
			},
			$services->fileMover(),
			(string) $storage->movingDir()
		);
	}
}
