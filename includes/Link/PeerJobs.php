<?php
/**
 * The jobs a connected site runs on this site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportJobs;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportJobs;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Rest\JobsApi;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\WebLimits;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Behind Link\Guard. A push becomes an upload import, a pull an export
 * without its store step, each marked with state.link {id, ref, name,
 * address}; the other site then drives only the jobs it started (own())
 * through JobsApi. The link acts for the code-maker ({id, login}): every
 * start and job request is refused once that admin is gone, renamed or lost
 * the capability, and the link's unfinished job is cancelled (I3, I8) —
 * except past its point of no return: the link then drives it to its end
 * (the swap gives the kept admin a new id; SwapStep re-points the link). A
 * repeated start finds its job by ref, a busy site is told before any work,
 * and a push start ticks the new import once, so it already waits for the
 * upload (C2). This site drives these jobs only once the link no longer can
 * (I5: drivenElsewhere()).
 */
final class PeerJobs {

	/**
	 * @param JobStore $jobs        Job store.
	 * @param SiteLock $lock        Site lock.
	 * @param \Closure $startImport fn( array $link, array{id: int, login: string} $owner, array $input, int $now ): Job.
	 * @param \Closure $startExport fn( array $link, array $input, int $now ): Job.
	 * @param \Closure $owner       fn( array{id: int, login: string} $owner ): ?array{id: int, login: string} — null unless that user still has that login and the capability.
	 * @param \Closure $tick        fn( string $id ): Job (JobsApi::tick).
	 * @param \Closure $cancel      fn( string $id ): void (JobsApi::cancel; best effort).
	 * @param \Closure $pastSwap    fn( Job $job ): bool — past its point of no return (swapped, or its cancel deferred).
	 * @param \Closure $now         fn(): int.
	 */
	public function __construct(
		private JobStore $jobs,
		private SiteLock $lock,
		private \Closure $startImport,
		private \Closure $startExport,
		private \Closure $owner,
		private \Closure $tick,
		private \Closure $cancel,
		private \Closure $pastSwap,
		private \Closure $now
	) {
	}

	/**
	 * Wired to WordPress: the starts through ImportJobs/ExportJobs, the owner
	 * through Permission::owner(), tick and cancel through JobsApi.
	 *
	 * @param Services $services Services.
	 */
	public static function fromServices( Services $services ): self {
		$api = static fn(): JobsApi => JobsApi::fromServices( $services );

		return new self(
			$services->jobs(),
			$services->lock(),
			self::importStarter( $services ),
			static function ( array $link, array $input, int $now ) use ( $services ): Job {
				$services->storage()->ensure();

				return ExportJobs::createForLink( $services->jobs(), $services->lock(), SiteReader::read(), $now, self::mark( $link, (string) $input['ref'] ) );
			},
			static fn( array $owner ): ?array => Permission::owner( $owner ),
			static fn( string $id ): Job => $api()->tick( $id ),
			static function ( string $id ) use ( $api ): void {
				try {
					$api()->cancel( $id );
				} catch ( \Throwable $e ) {
					unset( $e ); // Best effort: the job's lock expires on its own.
				}
			},
			static fn( Job $job ): bool => Runner::cancelDeferred( $job ) || ( 'import' === $job->type && ImportCleanup::maySwapped( $job, $services->jobs()->dir( $job->id ) ) ),
			static fn(): int => time()
		);
	}

	/**
	 * @param Services $services Services.
	 * @return \Closure The start of a pushed upload import (review m15).
	 */
	private static function importStarter( Services $services ): \Closure {
		return static function ( array $link, array $owner, array $input, int $now ) use ( $services ): Job {
			$storage = $services->storage();
			$storage->ensure();
			if ( $input['size'] > WebLimits::uploadRoom( DiskSpace::free( $storage->jobsDir() ) ) ) {
				/* translators: %s: archive size */
				throw new RestException( 'lw_migration_no_room', sprintf( __( 'There is not enough free disk space here for this %s archive.', 'lw-migration' ), size_format( $input['size'] ) ), 400 );
			}
			if ( $input['safetyBackup'] ) {
				try {
					$storage->ensureBackups();
				} catch ( FolderRefusedException $e ) {
					throw RestException::backupDirUnusable( $e );
				}
			}
			$state  = [
				'upload' => [ 'size' => $input['size'] ],
				'link'   => self::mark( $link, $input['ref'] ),
			];
			$pretty = '' !== (string) get_option( 'permalink_structure' );

			return ImportJobs::create( $services->jobs(), $services->lock(), SiteReader::read(), new ImportOptions( ImportOptions::UPLOAD, $input['name'], $input['safetyBackup'] ), $owner, $pretty, null, $services->webSecrets(), $now, $state );
		};
	}

	/**
	 * @param array<string, mixed> $link Verified link.
	 * @param array<string, mixed> $body {ref, name, size, safetyBackup}.
	 * @throws RestException 400, 403.
	 * @throws SiteBusyException When another job holds the site (before any work).
	 */
	public function startPush( array $link, array $body ): Job {
		self::allowed( $link, Links::PUSH );
		$owner = $this->ownerOf( $link );
		$input = PeerInput::push( $body );
		$job   = $this->startOrAgain( $link, $input['ref'], fn( int $now ): Job => ( $this->startImport )( $link, $owner, $input, $now ) );

		// A new upload import is pending until its first tick; only then does it wait for the bytes (review C2).
		return Job::PENDING === $job->status ? ( $this->tick )( $job->id ) : $job;
	}

	/**
	 * @param array<string, mixed> $link Verified link.
	 * @param array<string, mixed> $body {ref}.
	 * @throws RestException 400, 403.
	 * @throws SiteBusyException When another job holds the site (before any work).
	 */
	public function startPull( array $link, array $body ): Job {
		self::allowed( $link, Links::PULL );
		$this->ownerOf( $link );
		$ref = PeerInput::pull( $body );

		return $this->startOrAgain( $link, $ref, fn( int $now ): Job => ( $this->startExport )( $link, [ 'ref' => $ref ], $now ) );
	}

	/**
	 * A job this link started here. The link's admin is checked on every call
	 * (I8); when that fails, an unfinished job is cancelled. Past its point
	 * of no return a job is never stranded: its link drives it to the end.
	 *
	 * @param array<string, mixed> $link Verified link.
	 * @param string               $id   Job id.
	 * @throws RestException 404 for an unknown job or one this link did not start, 403 (owner).
	 */
	public function own( array $link, string $id ): Job {
		$job = $this->load( $id );
		if ( null === $job || ( self::startedBy( $job )['id'] ?? null ) !== $link['id'] ) {
			throw new RestException( 'lw_migration_no_job', __( 'This job does not exist (any more).', 'lw-migration' ), 404 );
		}
		if ( ( $this->pastSwap )( $job ) ) {
			return $job;
		}
		try {
			$this->ownerOf( $link );
		} catch ( RestException $e ) {
			if ( ! $job->isFinished() ) {
				( $this->cancel )( $job->id );
			}
			throw $e;
		}

		return $job;
	}

	/**
	 * The other site's name while its link drives this job here, so this
	 * site's screens and cookie routes leave it alone (I5); null for other
	 * jobs, and once the link is gone or its admin may no longer act, the
	 * link released it past its point of no return (a deferred cancel), or it
	 * has not been saved for SiteLock::TTL (the other site stopped driving
	 * it): then this site's admin can finish the job (final review I1; two
	 * drivers stay serialised by the site lock and job_busy).
	 *
	 * @param Job      $job   Job.
	 * @param Links    $links Links.
	 * @param \Closure $admin fn( array{id: int, login: string} $owner ): ?array (Permission::owner()).
	 */
	public static function drivenElsewhere( Job $job, Links $links, \Closure $admin ): ?string {
		$mark = self::startedBy( $job );
		try {
			$row = null === $mark ? null : $links->find( (string) $mark['id'], time() );
		} catch ( \Throwable $e ) {
			$row = null; // Links cannot be read (no sodium): nothing can drive it but this site.
		}
		if ( null === $mark || null === $row || Links::IN !== $row['direction'] || null === $admin( $row['createdBy'] ) || Runner::cancelDeferred( $job ) || time() - $job->updatedAt >= SiteLock::TTL ) {
			return null;
		}
		$name = (string) ( $mark['name'] ?? '' );

		return '' !== $name ? $name : AddressRedactor::display( (string) ( $mark['address'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $link Verified link.
	 * @param string               $id   Job id.
	 * @throws RestException 404 (not this link's), 409 (not ready).
	 */
	public function archive( array $link, string $id ): Backup {
		$job  = $this->own( $link, $id );
		$path = $this->archivePath( $job );
		if ( null === $path ) {
			throw new RestException( 'lw_migration_archive_not_ready', __( 'The archive of this job is not ready (any more).', 'lw-migration' ), 409 );
		}
		$home = Site::fromArray( (array) ( $job->state['site'] ?? [] ) )->homeUrl;

		return new Backup( BackupName::forSite( $home, $job->createdAt, 0 ), $path, (int) filesize( $path ), (int) filemtime( $path ), Backup::OWN ); // Named as StoreStep names a backup.
	}

	/**
	 * @param JobsApi $api Jobs API.
	 * @param Job     $job Job.
	 * @return array<string, mixed> JobsApi::view(), archiveBytes and archiveSha256 (null until the archive is ready; issue #1).
	 */
	public function view( JobsApi $api, Job $job ): array {
		$path = $this->archivePath( $job );

		return $api->view( $job ) + [
			'archiveBytes'  => null === $path ? null : (int) filesize( $path ),
			'archiveSha256' => null === $path ? null : ( $job->state['archiveSha256'] ?? null ), // FinalizeStep's resumable hash.
		];
	}

	/**
	 * The other site is done with the job: an unfinished one is cancelled (an
	 * import past its swap defers it), a ready archive goes; job.json stays.
	 *
	 * @param array<string, mixed> $link   Verified link.
	 * @param string               $id     Job id.
	 * @param \Closure             $cancel fn( string $id ): array{job: Job, cancelDeferred: bool} (JobsApi::cancel).
	 * @return array{released: bool, cancelDeferred: bool}
	 */
	public function release( array $link, string $id, \Closure $cancel ): array {
		$job      = $this->own( $link, $id );
		$deferred = false;
		if ( ! $job->isFinished() ) {
			$deferred = (bool) $cancel( $job->id )['cancelDeferred'];
		} elseif ( null !== $this->archivePath( $job ) ) {
			ExportFiles::remove( $this->jobs->dir( $job->id ) );
		}

		return [
			'released'       => true,
			'cancelDeferred' => $deferred,
		];
	}

	/**
	 * The link is being removed: its unfinished jobs here stop (own push/pull jobs carry no ref).
	 *
	 * @param string   $linkId Link id.
	 * @param \Closure $cancel fn( string $id ): mixed.
	 * @return int How many were asked to stop.
	 */
	public function cancelFor( string $linkId, \Closure $cancel ): int {
		$count = 0;
		foreach ( $this->jobs->ids() as $id ) {
			try {
				$job = $this->jobs->load( $id );
				if ( ! $job->isFinished() && ( self::startedBy( $job )['id'] ?? null ) === $linkId ) {
					++$count;
					$cancel( $id );
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Best effort: the job's lock expires on its own.
			}
		}

		return $count;
	}

	/**
	 * Starts a job when the site is free. When a job holds the site, a start
	 * the other site repeats (its answer got lost) gets that job back if it
	 * is unfinished and has this link and ref; any other holder makes the
	 * site busy, before the start does any work (review m5).
	 *
	 * @param array<string, mixed> $link  Verified link.
	 * @param string               $ref   The other site's reference of its job.
	 * @param \Closure             $start fn( int $now ): Job.
	 * @throws SiteBusyException When another job holds the site.
	 */
	private function startOrAgain( array $link, string $ref, \Closure $start ): Job {
		$now    = $this->now();
		$holder = $this->lock->holder( $now );
		if ( null === $holder ) {
			return $start( $now ); // JobStarter still refuses when another start took the site meanwhile.
		}
		$job  = $this->load( $holder );
		$mark = null === $job ? null : self::startedBy( $job );
		if ( null !== $job && null !== $mark && ! $job->isFinished() && $mark['id'] === $link['id'] && $mark['ref'] === $ref ) {
			return $job;
		}
		throw new SiteBusyException( $this->lock->expiresAt( $now ) );
	}

	private function load( string $id ): ?Job {
		try {
			return $this->jobs->load( $id );
		} catch ( JobException $e ) {
			return null;
		}
	}

	/**
	 * The state.link {id, ref, …} of a job a connection started here, else null.
	 *
	 * @param Job $job Job.
	 * @return array<string, mixed>|null
	 */
	private static function startedBy( Job $job ): ?array {
		$link = $job->state['link'] ?? null;

		return is_array( $link ) && is_string( $link['id'] ?? null ) && is_string( $link['ref'] ?? null ) ? $link : null;
	}

	private function archivePath( Job $job ): ?string {
		if ( Job::DONE !== $job->status || 'export' !== $job->type || ExportPipeline::LINK_STEPS !== $job->steps ) {
			return null;
		}
		$path = $this->jobs->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;

		return is_file( $path ) ? $path : null;
	}

	/**
	 * @param array<string, mixed> $link  Verified link.
	 * @param string               $right Links::PUSH or Links::PULL.
	 * @throws RestException 403.
	 */
	private static function allowed( array $link, string $right ): void {
		if ( ! in_array( $right, (array) ( $link['allow'] ?? [] ), true ) ) {
			throw new RestException( 'lw_migration_link_not_allowed', __( 'This connection does not allow that on this site.', 'lw-migration' ), 403 );
		}
	}

	/**
	 * @param array<string, mixed> $link Verified link.
	 * @return array{id: int, login: string}
	 * @throws RestException 403 when the code-maker is gone, renamed or lost the capability.
	 */
	private function ownerOf( array $link ): array {
		$owner = ( $this->owner )( (array) ( $link['createdBy'] ?? [] ) );
		if ( ! is_array( $owner ) ) {
			throw new RestException( 'lw_migration_link_owner', __( 'The admin who allowed this connection can no longer use the plugin on this site. Pair the sites again.', 'lw-migration' ), 403 );
		}

		return $owner;
	}

	private static function mark( array $link, string $ref ): array {
		return [
			'id'      => (string) $link['id'],
			'ref'     => $ref,
			'name'    => (string) ( $link['name'] ?? '' ),
			'address' => (string) ( $link['address'] ?? '' ),
		];
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
