<?php
/**
 * Starts the job that moves the backups into another folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;
use LightweightPlugins\Migration\Storage\Steps\FinishStep;
use LightweightPlugins\Migration\Storage\Steps\MoveStep;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * A move goes from the current folder to a new one, finishes an unfinished
 * move (to its target), or undoes it (back to the current folder). Folders
 * are compared by identity too, so another spelling of a folder is that
 * folder. A new folder passes the rules; the target of a finish or an undo
 * must still be ours and is never created again (an unmounted disk fails
 * loudly). The target is prepared and the setting marks the move only after
 * the site lock is taken (JobStarter), and only while the stored setting is
 * still the one the plan was made from, so a refused or busy start changes
 * nothing; while the job runs, no other job (and no other move) can start.
 */
final class MoveJobs {

	public const TYPE = 'move';

	public const STEPS = [ 'move', 'finish' ];

	/**
	 * @param StorageDir     $storage   Storage root with the current setting.
	 * @param BackupDirRules $rules     Folder rules (FolderContext::rules()).
	 * @param string         $target    New folder ('' = the default folder).
	 * @param bool|null      $supported Whether a folder of one's own works here (tests); null = FolderContext::supported().
	 * @return array{from: string, to: string, fromDir: string, toDir: string, current: BackupDir, during: BackupDir, after: BackupDir}
	 * @throws Refused When the folder or the moment is refused.
	 */
	public static function plan( StorageDir $storage, BackupDirRules $rules, string $target, ?bool $supported = null ): array {
		$current = $storage->setting();
		$to      = '' === trim( $target, ' ' ) ? '' : BackupDirRules::normalise( $target );
		if ( '' !== $to && ! ( $supported ?? FolderContext::supported() ) ) {
			throw new Refused( Refused::UNSUPPORTED );
		}
		if ( null !== $current->moving ) {
			$to   = self::pendingTarget( $storage, $rules, $to );
			$from = $to === $current->moving ? $current->path : $current->moving;
		} else {
			if ( self::sameFolder( $storage, $rules, $to, $current->path ) ) {
				throw new Refused( Refused::SAME );
			}
			$to   = '' === $to ? '' : $rules->check( $to );
			$from = $current->path;
		}
		$marked = $current->withMarker();
		$during = null === $current->moving ? new BackupDir( $current->path, $to, $marked->marker ) : $marked;

		return [
			'from'    => $from,
			'to'      => $to,
			'fromDir' => $storage->resolve( $from ),
			'toDir'   => $storage->resolve( $to ),
			'current' => $current,
			'during'  => $during,
			'after'   => new BackupDir( $to, null, $marked->marker ),
		];
	}

	/**
	 * @param JobStore       $jobs    Job store.
	 * @param SiteLock       $lock    Site lock.
	 * @param StorageDir     $storage Storage root with the current setting.
	 * @param BackupDirRules $rules   Folder rules (FolderContext::rules()).
	 * @param string         $target  New folder ('' = the default folder).
	 * @param int            $now     Unix time.
	 * @param object|null    $db      Database object (tests).
	 * @throws Refused When the folder or the moment is refused.
	 * @throws \LightweightPlugins\Migration\WordPress\SiteBusyException When another job holds the site.
	 */
	public static function create( JobStore $jobs, SiteLock $lock, StorageDir $storage, BackupDirRules $rules, string $target, int $now, ?object $db = null ): Job {
		$plan    = self::plan( $storage, $rules, $target );
		$options = [
			'from'        => $plan['from'],
			'to'          => $plan['to'],
			'fromDir'     => $plan['fromDir'],
			'toDir'       => $plan['toDir'],
			'fromDefault' => '' === $plan['from'],
			'during'      => $plan['during']->toJson(),
			'after'       => $plan['after']->toJson(),
		];

		return JobStarter::start(
			$jobs,
			$lock,
			self::TYPE,
			self::STEPS,
			$options,
			$now,
			static function ( Job $job ) use ( $jobs, $storage, $plan, $db ): void {
				if ( BackupDir::read( $db )->toJson() !== $plan['current']->toJson() ) {
					throw new Refused( Refused::PENDING ); // The plan was made from a setting read before the lock.
				}
				self::discardAbandoned( $jobs, $job->id );
				foreach ( self::prepareTarget( $storage, $plan ) as $name ) {
					/* translators: %s: file name, for example .htaccess */
					$job->addWarning( sprintf( __( 'The new backups folder already holds another file named %s, so it was left as it is. Check that the folder is not served on the web.', 'lw-migration' ), $name ) );
				}
				if ( $plan['during']->toJson() !== $plan['current']->toJson() && ! BackupDir::replace( $plan['current'], $plan['during'], $db ) ) {
					throw new Refused( Refused::PENDING ); // Another request changed the setting meanwhile.
				}
				$jobs->save( $job );
			}
		);
	}

	/**
	 * @param Services $services Services.
	 * @return array<string, StepInterface>
	 */
	public static function steps( Services $services ): array {
		return [
			'move'   => new MoveStep(
				$services->fileMover(),
				static function ( Job $job ) use ( $services ): void {
					self::targetReady( $job, $services->storage() );
				}
			),
			'finish' => new FinishStep( null, static fn(): array => $services->storage()->owners() ),
		];
	}

	/**
	 * Before every write into the target: the default folder is created and
	 * protected; a chosen one must still be ours (never created here).
	 *
	 * @param Job        $job     Move job.
	 * @param StorageDir $storage Storage root.
	 * @throws JobException When the target may not be written.
	 */
	public static function targetReady( Job $job, StorageDir $storage ): void {
		$to = (string) ( $job->options['to'] ?? '' );
		if ( '' === $to ) {
			$storage->ensure();
			return;
		}
		$problem = BackupDirRules::inUse( $to, BackupDir::parse( (string) ( $job->options['after'] ?? '' ) )->marker, $storage->owners() );
		if ( null !== $problem ) {
			throw new JobException( FolderMessages::of( $problem ) );
		}
	}

	/**
	 * Whether backups are being moved now (an unfinished move saved within the lock's lifetime).
	 *
	 * @param JobStore $jobs Job store.
	 * @param int      $now  Unix time.
	 */
	public static function active( JobStore $jobs, int $now ): bool {
		foreach ( $jobs->ids() as $id ) {
			try {
				$job = $jobs->load( $id );
			} catch ( JobException $e ) {
				continue;
			}
			if ( self::TYPE === $job->type && ! $job->isFinished() && $job->updatedAt >= $now - SiteLock::TTL ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * After a failed or cancelled move: removes the temp of the file in flight
	 * and an empty name reservation (originals and published backups stay).
	 *
	 * @param Job $job Move job.
	 */
	public static function discard( Job $job ): void {
		$to = (string) ( $job->options['toDir'] ?? '' );
		if ( '' !== $to ) {
			( new BackupPublisher( $to ) )->discard( (string) ( $job->cursor['temp'] ?? '' ), (string) ( $job->cursor['reserving'] ?? '' ) );
		}
	}

	/**
	 * Under the lock no other move runs: an unfinished one was abandoned. Its
	 * empty name reservation would block the next move's name; a reservation
	 * that holds bytes is a backup and stays.
	 *
	 * @param JobStore $jobs Job store.
	 * @param string   $keep The new job.
	 */
	private static function discardAbandoned( JobStore $jobs, string $keep ): void {
		foreach ( $jobs->ids() as $id ) {
			try {
				$other = $jobs->load( $id );
			} catch ( JobException $e ) {
				continue;
			}
			$to = (string) ( $other->options['toDir'] ?? '' );
			if ( $id !== $keep && self::TYPE === $other->type && ! $other->isFinished() && '' !== $to ) {
				( new BackupPublisher( $to ) )->discard( '', (string) ( $other->cursor['reserving'] ?? '' ) );
			}
		}
	}

	/**
	 * During an unfinished move only its target (finish) or the current
	 * folder (undo) may be asked for; a chosen one must still be ours.
	 *
	 * @param StorageDir     $storage Storage root with the current setting.
	 * @param BackupDirRules $rules   Folder rules.
	 * @param string         $to      Normalised target ('' = the default folder).
	 * @return string The stored spelling of the folder asked for.
	 * @throws Refused PENDING, or why the folder cannot be used.
	 */
	private static function pendingTarget( StorageDir $storage, BackupDirRules $rules, string $to ): string {
		$current = $storage->setting();
		foreach ( [ (string) $current->moving, $current->path ] as $stored ) {
			if ( ! self::sameFolder( $storage, $rules, $to, $stored ) ) {
				continue;
			}
			$problem = '' === $stored ? null : BackupDirRules::inUse( $stored, $current->marker, $storage->owners() );
			if ( null !== $problem ) {
				throw new Refused( $problem );
			}

			return $stored;
		}
		throw new Refused( Refused::PENDING );
	}

	/**
	 * The same folder by name, or by identity when both exist. An existing
	 * random-named folder is also the one meant when its parent is typed and
	 * typing that parent makes a random folder (a web root): that is what was
	 * typed to make it, so typing it again never starts a second folder.
	 * Elsewhere the parent is a folder of its own.
	 *
	 * @param StorageDir     $storage Storage root.
	 * @param BackupDirRules $rules   Folder rules.
	 * @param string         $typed   Normalised folder ('' = the default one).
	 * @param string         $stored  A stored folder ('' = the default one).
	 */
	private static function sameFolder( StorageDir $storage, BackupDirRules $rules, string $typed, string $stored ): bool {
		if ( $typed === $stored ) {
			return true;
		}
		if ( '' === $typed || '' === $stored ) {
			return false;
		}
		$a = FolderIdentity::of( $typed );
		if ( null === $a ) {
			return false;
		}
		$dir  = $storage->resolve( $stored );
		$self = FolderIdentity::of( $dir );
		if ( $self === $a ) {
			return true;
		}

		return null !== $self && 1 === preg_match( BackupDirRules::SUFFIX_PATTERN, basename( $dir ) )
			&& FolderIdentity::of( dirname( $dir ) ) === $a && $rules->makesRandomChild( dirname( $dir ) );
	}

	/**
	 * Under the lock: the default folder is created; a new chosen folder is
	 * prepared (which checks again that it is new, empty or ours); the target
	 * of a finish or an undo must still be ours.
	 *
	 * @param StorageDir                                                                                                               $storage Storage root.
	 * @param array{from: string, to: string, fromDir: string, toDir: string, current: BackupDir, during: BackupDir, after: BackupDir} $plan    The plan.
	 * @return array<int, string> Deny files left alone in the new folder.
	 * @throws Refused When the folder cannot be used.
	 */
	private static function prepareTarget( StorageDir $storage, array $plan ): array {
		if ( '' === $plan['to'] ) {
			$storage->ensure();
			return [];
		}
		if ( null !== $plan['current']->moving ) {
			$problem = BackupDirRules::inUse( $plan['to'], $plan['after']->marker, $storage->owners() );
			if ( null !== $problem ) {
				throw new Refused( $problem );
			}
			return [];
		}

		return BackupDirRules::prepare( $plan['toDir'], $plan['after']->marker, $storage->owners() );
	}
}
