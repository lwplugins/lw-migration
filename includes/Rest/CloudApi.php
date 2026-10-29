<?php
/**
 * Backups at a destination, and moving one there or back.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Import\ImportJobs;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.6 "Felhőben lévő mentések" (restore, download here, delete) and
 * "Feltöltés…". The remote listing shows .wpress files only, newest first,
 * each marked when this site wrote it; any valid .wpress name in the
 * destination's folder can be deleted, not only this site's (plan P-M2). A
 * restore is an import with the source `cloud` (its first step downloads
 * into the backups folder); a fetch is a `cloud` job; an upload of an
 * existing backup is a `cloud` job that never prunes. Every request field
 * is checked before the destination is asked anything; a local backup of the
 * same name but another size refuses up front (plan D8). Remote failures
 * answer 422 with our own text and reason (RestException::destinationFailed).
 */
final class CloudApi {

	private const RESTORE_KEYS = [ 'name', 'safetyBackup', 'password' ];

	private const FETCH_KEYS = [ 'name' ];

	private const UPLOAD_KEYS = [ 'folder', 'name', 'destinations' ];

	/**
	 * Checks the backups folder before a download: fn(): void (null: not checked).
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $backupsReady;

	/**
	 * @param Destinations  $destinations Profiles.
	 * @param JobStore      $jobs         Job store.
	 * @param SiteLock      $lock         Site lock.
	 * @param Repository    $backups      Local backups.
	 * @param SecretStore   $secrets      Where a restore's archive password is kept.
	 * @param \Closure      $context      JobContext::closure().
	 * @param \Closure      $now          fn(): int.
	 * @param \Closure      $home         fn(): string — this site's address.
	 * @param \Closure|null $backupsReady fn(): void — the backups folder can take a file now (FolderRefusedException).
	 */
	public function __construct( private Destinations $destinations, private JobStore $jobs, private SiteLock $lock, private Repository $backups, private SecretStore $secrets, private \Closure $context, private \Closure $now, private \Closure $home, ?\Closure $backupsReady = null ) {
		$this->backupsReady = $backupsReady;
	}

	public static function fromServices( Services $services ): self {
		return new self(
			Destinations::fromWordPress(),
			$services->jobs(),
			$services->lock(),
			$services->backups(),
			$services->webSecrets(),
			JobContext::closure( $services ),
			static fn(): int => time(),
			static fn(): string => SiteReader::read()->homeUrl,
			static fn() => $services->storage()->ensureBackups()
		);
	}

	/**
	 * @param string $id Profile id.
	 * @return array{files: array<int, array{name: string, size: int, mtime: int, ours: bool}>, insecure: bool, copiesChanged: bool}
	 * @throws RestException 404 or 422.
	 */
	public function files( string $id ): array {
		$profile     = $this->profile( $id );
		$destination = $this->open( $profile );
		$home        = (string) ( $this->home )();
		$changed     = false;
		$files       = array_values( array_filter( $this->listed( $id, $destination, $changed ), static fn( RemoteFile $f ): bool => BackupName::isValid( $f->name ) ) );
		usort( $files, static fn( RemoteFile $a, RemoteFile $b ): int => [ $b->mtime, $a->name ] <=> [ $a->mtime, $b->name ] );
		$type = $this->destinations->registry()->get( $profile->type );

		return [
			'files'         => array_map(
				static fn( RemoteFile $f ): array => [
					'name'  => $f->name,
					'size'  => $f->size,
					'mtime' => $f->mtime,
					'ours'  => BackupName::isOfSite( $f->name, $home ),
				],
				$files
			),
			'insecure'      => null !== $type && $type->insecure( $profile ),
			'copiesChanged' => $changed,
		];
	}

	/**
	 * Deletes one backup at the destination (only a .wpress name, P-M2) and
	 * forgets its copy record there.
	 *
	 * @param string $id   Profile id.
	 * @param string $name Remote backup name.
	 * @return array{deleted: bool}
	 * @throws RestException 400 on a name that is not a backup's, 404 or 422.
	 */
	public function deleteFile( string $id, string $name ): array {
		$profile = $this->profile( $id );
		if ( ! BackupName::isValid( $name ) ) {
			throw RestException::invalidField( 'name' );
		}
		$destination = $this->open( $profile );
		$file        = $this->remote( $id, $destination, $name );
		$this->call( static fn() => $destination->delete( $file ) );
		CloudCopies::forget( $name, $id );

		return [ 'deleted' => true ];
	}

	/**
	 * @param string               $id   Profile id.
	 * @param array<string, mixed> $body {name, safetyBackup?, password?}.
	 * @throws RestException On a refused request.
	 */
	public function restore( string $id, #[\SensitiveParameter] array $body ): Job {
		self::only( $body, self::RESTORE_KEYS );
		$safety = $body['safetyBackup'] ?? true;
		if ( ! is_bool( $safety ) ) {
			throw RestException::invalidField( 'safetyBackup' );
		}
		$password = JobInput::password( $body['password'] ?? null );
		$file     = $this->prepare( $id, $body );
		$context  = ( $this->context )();

		return JobInput::checked( fn(): Job => ImportJobs::create( $this->jobs, $this->lock, $context['site'], new ImportOptions( ImportOptions::CLOUD, $file->name, $safety ), $context['admin'], $context['permalinks'], $password, $this->secrets, (int) ( $this->now )(), [ 'cloudSource' => CloudJobs::source( $id, $file ) ] ) );
	}

	/**
	 * @param string               $id   Profile id.
	 * @param array<string, mixed> $body {name}.
	 * @throws RestException On a refused request.
	 */
	public function fetch( string $id, array $body ): Job {
		self::only( $body, self::FETCH_KEYS );
		$file    = $this->prepare( $id, $body );
		$context = ( $this->context )();

		return CloudJobs::fetch( $this->jobs, $this->lock, $context['site'], $id, $file, (int) ( $this->now )() );
	}

	/**
	 * @param array<string, mixed> $body {folder, name, destinations}.
	 * @throws RestException On a refused request.
	 */
	public function upload( array $body ): Job {
		self::only( $body, self::UPLOAD_KEYS );
		$plan = JobInput::checked( static fn(): CloudPlan => CloudPlan::upload( DestinationIds::checked( $body['destinations'] ?? null ) ), 'destinations' );
		foreach ( $plan->ids() as $target ) {
			$this->profile( $target );
		}
		$backup = $this->backups->findIn( is_string( $body['name'] ?? null ) ? $body['name'] : '', is_string( $body['folder'] ?? null ) ? $body['folder'] : '' );
		if ( null === $backup ) {
			throw RestException::backupMissing();
		}
		$context = ( $this->context )();

		return CloudJobs::upload( $this->jobs, $this->lock, $context['site'], $backup, $plan, (int) ( $this->now )() );
	}

	/**
	 * The remote backup of this name, checked against the backups folder (plan D8).
	 *
	 * @param string $id   Profile id.
	 * @param string $name Remote backup name.
	 * @throws RestException On a bad name, a missing file, a clash with a local backup or an unusable folder.
	 */
	public function remoteFile( string $id, string $name ): RemoteFile {
		return $this->prepare( $id, [ 'name' => $name ] );
	}

	/**
	 * The remote backup a restore or fetch names, checked against the backups
	 * folder. The job's location and its cloudSource carry this one name
	 * (CloudDownloadStep refuses them otherwise).
	 *
	 * @param string               $id   Profile id.
	 * @param array<string, mixed> $body {name}.
	 * @throws RestException On a bad name, a missing file, a clash with a local backup or an unusable folder.
	 */
	private function prepare( string $id, array $body ): RemoteFile {
		$name = is_string( $body['name'] ?? null ) ? $body['name'] : '';
		if ( ! BackupName::isValid( $name ) ) {
			throw RestException::invalidField( 'name' );
		}
		$file  = $this->remote( $id, $this->open( $this->profile( $id ) ), $name );
		$local = $this->backups->findIn( $name, Backup::OWN ); // Where CloudDownloadStep publishes it.
		if ( null !== $local && $local->size !== $file->size ) {
			/* translators: %s: backup file name */
			throw new RestException( 'lw_migration_local_exists', sprintf( __( 'A different backup named %s is already in the backups folder. Rename or delete it first.', 'lw-migration' ), $name ), 409 );
		}
		try {
			if ( null !== $this->backupsReady ) {
				( $this->backupsReady )();
			}
		} catch ( FolderRefusedException $e ) {
			throw RestException::backupDirUnusable( $e );
		}

		return $file;
	}

	/**
	 * @param string               $id          Profile id.
	 * @param DestinationInterface $destination Destination.
	 * @param string               $name        Remote backup name.
	 * @throws RestException 404 lw_migration_remote_missing or 422.
	 */
	private function remote( string $id, DestinationInterface $destination, string $name ): RemoteFile {
		foreach ( $this->listed( $id, $destination ) as $file ) {
			if ( $file->name === $name ) {
				return $file;
			}
		}
		throw new RestException( 'lw_migration_remote_missing', __( 'This backup is not at the destination (any more).', 'lw-migration' ), 404 );
	}

	/**
	 * The destination's whole listing (list() throws rather than answer a cut
	 * one), which also corrects its copy records: a backup removed there by
	 * hand stops showing as held by it, and one it holds byte for byte as a
	 * backup here shows as held by it.
	 *
	 * @param string               $id          Profile id.
	 * @param DestinationInterface $destination Destination.
	 * @param bool                 $changed     Set to whether a copy record changed.
	 * @return array<int, RemoteFile>
	 * @throws RestException 422.
	 */
	private function listed( string $id, DestinationInterface $destination, bool &$changed = false ): array {
		$listedAt = (int) ( $this->now )();
		$files    = $this->call( static fn(): array => $destination->list() );
		$forgot   = CloudCopies::reconcile( $id, array_map( static fn( RemoteFile $f ): string => $f->name, $files ), $listedAt );
		$adopted  = CloudCopies::adopt( $id, $this->heldHere( $files ), $listedAt );
		$changed  = $forgot || $adopted;

		return $files;
	}

	/**
	 * @param array<int, RemoteFile> $files A destination's listing.
	 * @return array<int, string> Names of the files a backup here matches by name and size.
	 */
	private function heldHere( array $files ): array {
		$here = [];
		foreach ( $this->backups->all() as $backup ) {
			$here[ $backup->name ][ $backup->size ] = true;
		}

		return array_values(
			array_map(
				static fn( RemoteFile $f ): string => $f->name,
				array_filter( $files, static fn( RemoteFile $f ): bool => isset( $here[ $f->name ][ $f->size ] ) )
			)
		);
	}

	/**
	 * @param string $id Profile id (checked against Profile::ID_PATTERN before any lookup).
	 * @throws RestException 404 lw_migration_destination_missing.
	 */
	private function profile( string $id ): Profile {
		return ( new DestinationsApi( $this->destinations, static fn(): array => [] ) )->find( $id );
	}

	/**
	 * @param Profile $profile Profile.
	 * @throws RestException 422 when it cannot be opened.
	 */
	private function open( Profile $profile ): DestinationInterface {
		return $this->call( fn(): DestinationInterface => $this->destinations->open( $profile ) );
	}

	/**
	 * Runs a destination call; its failure becomes a 422.
	 *
	 * @template T
	 * @param \Closure $work fn(): T.
	 * @phpstan-param \Closure(): T $work
	 * @return T
	 * @throws RestException 422 lw_migration_destination_failed.
	 */
	private function call( \Closure $work ) {
		try {
			return $work();
		} catch ( CloudFailure $e ) {
			throw RestException::destinationFailed( $e );
		}
	}

	/**
	 * @param array<string, mixed> $body    Request.
	 * @param array<int, string>   $allowed Accepted keys.
	 * @throws RestException 400 on any other key.
	 */
	private static function only( array $body, array $allowed ): void {
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw RestException::invalidField( (string) $key );
			}
		}
	}
}
