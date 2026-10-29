<?php
/**
 * Downloads a backup from a destination into the backups folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud\Steps;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\Steps\UrlDownloadStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobException;
use LightweightPlugins\Migration\Storage\Cloud\CloudRetry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\BackupFileMode;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.1 "Visszatöltés": the remote file comes into the backups folder in
 * ranged chunks (one per call, so the Runner saves cursor.offset between
 * them) into a hidden temp (TempName: never listed; cursor.temp, which the
 * daily cleanup keeps while the job runs and removes when it is abandoned),
 * created in 'x' mode in the call that names it, its identity in cursor.id;
 * every later call checks by lstat that the name still holds that regular
 * file with the saved bytes, else drops the name and starts over (the
 * backups folder may be one others can write to), and writes only through a
 * handle verified to be that file; then it
 * is published under its own name without ever replacing a file
 * (BackupPublisher; cursor.reserving where hard links do not work). A local
 * backup of that name and size is taken as it is; one of another size stops
 * the job before anything is written (plan D8). Then state.archive names
 * the backup for the import that follows (not owned: the import's cleanup
 * keeps it, it stays in the backups list) and state.backup names it for a
 * fetch job's result. A passing failure waits like the upload (CloudRetry);
 * a lasting one, spent patience, too little disk space or an unwritable temp
 * stops the job.
 */
final class CloudDownloadStep implements StepInterface {

	public const KEY = 'cloud_download';

	/** Disk space left free for the import (like UrlDownloadStep). */
	public const DISK_MARGIN = 1073741824;

	/**
	 * Free bytes of a folder: fn( string $dir ): ?int.
	 *
	 * @var \Closure
	 */
	private \Closure $freeSpace;

	/**
	 * Pause: fn( float $seconds ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $pause;

	/**
	 * @param Destinations  $destinations Profiles, secrets and types.
	 * @param \Closure      $backupsDir   fn(): string — the ready backups folder (throws CloudJobException).
	 * @param \Closure      $now          fn(): int.
	 * @param \Closure|null $freeSpace    fn( string $dir ): ?int (default DiskSpace::free()).
	 * @param \Closure|null $pause        fn( float $seconds ): void (default usleep).
	 * @param int           $chunk        Bytes per ranged read.
	 * @param \Closure|null $link         fn( string $from, string $to ): bool for BackupPublisher (tests: no hard links); default link().
	 */
	public function __construct( private Destinations $destinations, private \Closure $backupsDir, private \Closure $now, ?\Closure $freeSpace = null, ?\Closure $pause = null, private int $chunk = RangeDownloader::CHUNK, private ?\Closure $link = null ) {
		$this->freeSpace = $freeSpace ?? static fn( string $dir ): ?int => DiskSpace::free( $dir );
		$this->pause     = $pause ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
		};
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		[ $id, $file ] = self::source( $job );
		$dir           = ( $this->backupsDir )();
		$final         = $dir . '/' . $file->name;
		$temp          = (string) ( $job->cursor['temp'] ?? '' );
		if ( ! TempName::is( $temp ) ) {
			if ( self::exists( $final ) ) {
				self::assertSame( $final, $file );
				$job->addWarning( __( 'A backup of the same name and size was already in the backups folder; that copy is used.', 'lw-migration' ) );
				return $this->finish( $job, $final, $file );
			}
			self::create( $job, $dir );
			return StepResult::more( 0, __( 'Downloading the backup…', 'lw-migration' ) );
		}
		if ( (int) ( $job->cursor['offset'] ?? 0 ) >= $file->size && ! self::exists( $dir . '/' . $temp ) && self::exists( $final ) ) {
			// A request died after publishing: the temp holds the backup's name now.
			self::assertSame( $final, $file );
			return $this->finish( $job, $final, $file );
		}
		$offset = self::resume( $job, $dir );
		$temp   = (string) $job->cursor['temp'];
		if ( $offset >= $file->size ) {
			return $this->publish( $job, $dir, $temp, $file );
		}
		if ( CloudRetry::waitIfHeld( $job, $deadline, $this->pause, $this->now() ) ) {
			return StepResult::more( self::percent( $offset, $file->size ), __( 'Waiting for the destination to answer again…', 'lw-migration' ) );
		}
		$this->checkRoom( $dir, $file->size - $offset, $offset );
		$this->fetch( $job, $id, $file, $dir . '/' . $temp, $offset );
		$done = (int) $job->cursor['offset'];

		/* translators: 1: bytes downloaded, 2: backup size */
		return StepResult::more( self::percent( $done, $file->size ), sprintf( __( 'Downloading the backup: %1$s of %2$s…', 'lw-migration' ), size_format( $done ), size_format( $file->size ) ) );
	}

	/**
	 * The destination and the remote file of the job (state.cloudSource).
	 *
	 * @param Job $job Job.
	 * @return array{0: string, 1: RemoteFile}
	 * @throws CloudJobException On a damaged source, a name that is not a plain backup name, or a cloud import whose name is another.
	 */
	public static function source( Job $job ): array {
		$source = (array) ( $job->state['cloudSource'] ?? [] );
		$id     = (string) ( $source['id'] ?? '' );
		$file   = RemoteFile::fromArray( (array) ( $source['file'] ?? [] ) );
		$named  = ImportOptions::CLOUD !== ( $job->options['source'] ?? null ) || ( $job->options['location'] ?? null ) === ( null === $file ? null : $file->name );
		if ( 1 !== preg_match( Profile::ID_PATTERN, $id ) || null === $file || $file->size < 1 || ! BackupName::isValid( $file->name ) || ! $named ) {
			throw new CloudJobException( __( 'This job does not say which cloud backup to download.', 'lw-migration' ) );
		}

		return [ $id, $file ];
	}

	/**
	 * Reads one chunk and appends it; a passing failure holds the next read back.
	 *
	 * @param Job        $job    Job.
	 * @param string     $id     Profile id.
	 * @param RemoteFile $file   Remote file.
	 * @param string     $path   Temp path.
	 * @param int        $offset Bytes in the temp.
	 * @throws CloudJobException When the destination fails for good, the file changed there or the temp cannot be written.
	 */
	private function fetch( Job $job, string $id, RemoteFile $file, string $path, int $offset ): void {
		$profile = $this->destinations->find( $id );
		if ( null === $profile ) {
			throw new CloudJobException( __( 'The destination of this backup was deleted.', 'lw-migration' ) );
		}
		$length = min( $this->chunk, $file->size - $offset );
		try {
			$bytes = $this->destinations->open( $profile )->readRange( $file, $offset, $length );
		} catch ( TransientFailure $e ) {
			try {
				CloudRetry::defer( $job, $e, $this->now() );
				return;
			} catch ( TransientFailure $spent ) {
				throw new CloudJobException( $spent->getMessage(), 0, $spent );
			}
		} catch ( PermanentFailure $e ) {
			throw new CloudJobException( $e->getMessage(), 0, $e );
		}
		if ( strlen( $bytes ) !== $length ) {
			throw new CloudJobException( __( 'The backup at the destination changed while it was downloaded.', 'lw-migration' ) );
		}
		self::write( $path, (string) $job->cursor['id'], $offset, $bytes );
		CloudRetry::clear( $job );
		$job->cursor['offset'] = $offset + $length;
	}

	/**
	 * @param Job        $job  Job.
	 * @param string     $dir  Backups folder.
	 * @param string     $temp Temp name.
	 * @param RemoteFile $file Remote file.
	 * @throws CloudJobException When the name was taken meanwhile.
	 */
	private function publish( Job $job, string $dir, string $temp, RemoteFile $file ): StepResult {
		$publisher = new BackupPublisher( $dir, $this->link );
		$reserving = (string) ( $job->cursor['reserving'] ?? '' );
		$result    = '' === $reserving ? $publisher->publish( $temp, $file->name ) : $publisher->publishReserved( $temp, $file->name );
		if ( BackupPublisher::RESERVE === $result ) {
			$job->cursor['reserving'] = $file->name;
			return StepResult::more( 99 );
		}
		if ( BackupPublisher::TAKEN === $result ) {
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'Another backup named %s appeared in the backups folder meanwhile; nothing was replaced.', 'lw-migration' ), $file->name ) );
		}

		return $this->finish( $job, $dir . '/' . $file->name, $file );
	}

	/**
	 * @param string     $final Path under the backup's name.
	 * @param RemoteFile $file  Remote file.
	 * @throws CloudJobException When a different file holds the name.
	 */
	private static function assertSame( string $final, RemoteFile $file ): void {
		clearstatcache( true, $final );
		if ( is_link( $final ) || ! is_file( $final ) || (int) filesize( $final ) !== $file->size ) {
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'A different backup named %s is already in the backups folder. Rename or delete it first.', 'lw-migration' ), $file->name ) );
		}
	}

	private function finish( Job $job, string $path, RemoteFile $file ): StepResult {
		$job->state['archive']      = $path;
		$job->state['archiveOwned'] = false;
		$job->state['backup']       = [
			'name' => $file->name,
			'path' => $path,
			'size' => $file->size,
		];
		$job->cursor                = [];

		/* translators: %s: backup file name */
		return StepResult::done( sprintf( __( 'Downloaded %s.', 'lw-migration' ), $file->name ) );
	}

	/**
	 * The free space minus DISK_MARGIN, or, when that is unknown, the ceiling
	 * of UrlDownloadStep (filter `lw_migration_max_download_bytes`) minus the
	 * bytes already downloaded.
	 *
	 * @param string $dir     Backups folder.
	 * @param int    $missing Bytes still to come.
	 * @param int    $done    Bytes already downloaded.
	 * @throws CloudJobException When they do not fit.
	 */
	private function checkRoom( string $dir, int $missing, int $done ): void {
		$free = ( $this->freeSpace )( $dir );
		$room = null === $free ? (int) apply_filters( 'lw_migration_max_download_bytes', UrlDownloadStep::MAX_DOWNLOAD ) - $done : $free - self::DISK_MARGIN;
		if ( $missing > $room ) {
			throw new CloudJobException( __( 'The backup is larger than the free disk space allows.', 'lw-migration' ) );
		}
	}

	/**
	 * Creates the temp under a new name in 'x' mode (never replacing or
	 * following anything) and keeps its identity in the cursor.
	 *
	 * @param Job    $job Job.
	 * @param string $dir Backups folder.
	 * @throws CloudJobException When it cannot be created.
	 */
	private static function create( Job $job, string $dir ): void {
		$name = TempName::name();
		$path = $dir . '/' . $name;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' never follows a name; a failure is reported without the path.
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			throw self::unwritable();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		BackupFileMode::apply( $path );
		$id = FileIdentity::of( $path )['id'] ?? null;
		if ( null === $id ) {
			throw self::unwritable();
		}
		$job->cursor['temp']   = $name;
		$job->cursor['id']     = $id;
		$job->cursor['offset'] = 0;
	}

	/**
	 * The temp may sit in a folder others can write to: unless its name still
	 * holds our regular file (not followed if it is a link) with at least the
	 * saved bytes, the name is dropped (a link is removed, never followed) and
	 * the download starts over in a new temp. Bytes a dead request appended
	 * past the saved offset are cut, through a handle on the verified file.
	 *
	 * @param Job    $job Job.
	 * @param string $dir Backups folder.
	 * @return int The offset to go on from.
	 * @throws CloudJobException When the temp cannot be prepared.
	 */
	private static function resume( Job $job, string $dir ): int {
		$path   = $dir . '/' . (string) $job->cursor['temp'];
		$offset = (int) ( $job->cursor['offset'] ?? 0 );
		$have   = FileIdentity::of( $path );
		if ( null === $have || ( $job->cursor['id'] ?? null ) !== $have['id'] || $have['size'] < $offset || $offset < 0 ) {
			Files::removeFile( $path );
			self::create( $job, $dir );
			return 0;
		}
		if ( $have['size'] > $offset ) {
			self::write( $path, $have['id'], $offset, '' );
		}

		return $offset;
	}

	/**
	 * Cuts the temp to $offset and appends $bytes, only when the opened handle
	 * is the file of identity $id (a name swapped meanwhile is never written).
	 *
	 * @param string $path   Temp path.
	 * @param string $id     Its identity (FileIdentity id).
	 * @param int    $offset Bytes kept.
	 * @param string $bytes  Bytes appended ('' = only cut).
	 * @throws CloudJobException When it is not our file or cannot be written whole.
	 */
	private static function write( string $path, string $id, int $offset, string $bytes ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- checked by identity below; a failure is reported without the path.
		$handle = @fopen( $path, 'r+b' );
		if ( false === $handle ) {
			throw self::unwritable();
		}
		try {
			$stat  = fstat( $handle );
			$whole = false !== $stat && $stat['dev'] . ':' . $stat['ino'] === $id && 0100000 === ( $stat['mode'] & 0170000 )
				&& ftruncate( $handle, $offset ) && 0 === fseek( $handle, $offset )
				&& ( '' === $bytes || strlen( $bytes ) === fwrite( $handle, $bytes ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- our verified temp.
				&& fflush( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( ! $whole ) {
			throw self::unwritable();
		}
	}

	private static function unwritable( ?\Throwable $previous = null ): CloudJobException {
		return new CloudJobException( __( 'The backups folder cannot take the downloaded backup (it may be full or not writable).', 'lw-migration' ), 0, $previous );
	}

	private static function exists( string $path ): bool {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		return @file_exists( $path ) || @is_link( $path );
	}

	private static function percent( int $offset, int $size ): int {
		return (int) floor( 100 * $offset / max( 1, $size ) );
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
