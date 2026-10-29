<?php
/**
 * Import step: downloads the archive into the job folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Http\DownloadException;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * Each run first resumes at the saved offset (bytes a dead request appended
 * after the last saved chunk are cut back and fetched again), then appends
 * whole chunks until the deadline. A server without range support, or one
 * that does not tell the size, sends the file in one request, capped at the
 * free disk space minus DISK_MARGIN. A range download is refused before its
 * next chunk when the bytes still missing do not fit. The download lives in
 * the job folder and belongs to the job (state.archiveOwned), so cleanup
 * deletes it.
 *
 * When the free disk space cannot be read, the whole download is capped at
 * the filter `lw_migration_max_download_bytes` (int bytes, default 50 GiB,
 * MAX_DOWNLOAD) instead.
 *
 * An optional failure hook sees a failed download before it becomes the
 * import's failure, and may throw its own exception instead (a connection
 * explains a refused part or waits for a passing one; review I2).
 */
final class UrlDownloadStep implements StepInterface {

	/** Disk space left free for the import itself. */
	public const DISK_MARGIN = 1073741824;

	/** Download ceiling when the free disk space is unknown (filter `lw_migration_max_download_bytes`). */
	public const MAX_DOWNLOAD = 53687091200;

	/**
	 * Free bytes in the job folder (null: unknown).
	 *
	 * @var \Closure(string): ?int
	 */
	private \Closure $freeSpace;

	/**
	 * @param JobStore        $store     Job store.
	 * @param RangeDownloader $http      Downloader.
	 * @param \Closure|null   $freeSpace Free bytes of a folder; DiskSpace::free() by default.
	 * @param \Closure|null   $onFailure fn( DownloadException $e ): void, called before the failure is wrapped; it may throw instead.
	 */
	public function __construct( private JobStore $store, private RangeDownloader $http, ?\Closure $freeSpace = null, private ?\Closure $onFailure = null ) {
		$this->freeSpace = $freeSpace ?? static fn( string $dir ): ?int => DiskSpace::free( $dir );
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$download = (array) ( $job->state['download'] ?? [] );
		$url      = (string) ( $download['url'] ?? '' );
		$size     = isset( $download['size'] ) ? (int) $download['size'] : null;
		$dir      = WorkDir::path( $this->store, $job );
		$target   = $dir . '/' . ImportFiles::DOWNLOAD;
		try {
			if ( empty( $download['ranges'] ) || null === $size ) {
				RangeDownloader::resumeFrom( $target, 0 );
				$offset = $this->http->fetchAll( $url, $target, $size, $this->room( $dir, 0 ) );
			} else {
				$offset                = RangeDownloader::resumeFrom( $target, (int) ( $job->cursor['offset'] ?? 0 ) );
				$job->cursor['offset'] = $offset;
				if ( $size - $offset > $this->room( $dir, $offset ) ) {
					throw new ImportException( __( 'The download is larger than the free disk space allows.', 'lw-migration' ) );
				}
				$offset = $this->chunks( $job, $url, $target, $offset, $size, $deadline );
			}
		} catch ( DownloadException $e ) {
			if ( null !== $this->onFailure ) {
				( $this->onFailure )( $e );
			}
			/* translators: 1: where the download comes from (an address, or a connected site's name), 2: technical reason */
			throw new ImportException( sprintf( __( 'The download from %1$s stopped: %2$s', 'lw-migration' ), AddressRedactor::display( $url ), UrlResolveStep::reason( $e ) ) );
		}
		if ( null !== $size && $offset < $size ) {
			/* translators: 1: downloaded size, 2: total size */
			return StepResult::more( (int) ( 100 * $offset / max( 1, $size ) ), sprintf( __( 'Downloading the archive: %1$s of %2$s...', 'lw-migration' ), size_format( $offset ), size_format( $size ) ) );
		}
		$job->state['archive']      = $target;
		$job->state['archiveOwned'] = true;

		return StepResult::done( __( 'Archive downloaded.', 'lw-migration' ) );
	}

	/**
	 * Appends whole chunks until the file is complete or the deadline is
	 * reached; the cursor follows every appended chunk.
	 *
	 * @param Job      $job      Job (cursor.offset).
	 * @param string   $url      Address.
	 * @param string   $target   Download file.
	 * @param int      $offset   Bytes already in the file.
	 * @param int      $size     File size from the probe.
	 * @param Deadline $deadline Request time budget.
	 * @throws DownloadException When a chunk cannot be fetched.
	 */
	private function chunks( Job $job, string $url, string $target, int $offset, int $size, Deadline $deadline ): int {
		while ( $offset < $size ) {
			$to                    = min( $offset + $this->http->chunk() - 1, $size - 1 );
			$offset               += $this->http->fetchRange( $url, $target, $offset, $to, $size );
			$job->cursor['offset'] = $offset;
			if ( $deadline->reached() ) {
				break;
			}
		}

		return $offset;
	}

	/**
	 * Most bytes the download may still add: the free space minus the margin,
	 * or, when that is unknown, the ceiling minus the bytes already downloaded.
	 *
	 * @param string $dir  Job folder.
	 * @param int    $have Bytes already downloaded.
	 */
	private function room( string $dir, int $have ): int {
		$free = ( $this->freeSpace )( $dir );
		if ( null !== $free ) {
			return max( 0, $free - self::DISK_MARGIN );
		}

		return max( 0, (int) apply_filters( 'lw_migration_max_download_bytes', self::MAX_DOWNLOAD ) - $have );
	}
}
