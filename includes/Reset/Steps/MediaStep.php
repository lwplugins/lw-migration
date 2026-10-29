<?php
/**
 * Reset step: empties the media library through core, then the uploads folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\CoreFolders;
use LightweightPlugins\Migration\Reset\NetworkSites;
use LightweightPlugins\Migration\Reset\Preflight;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\TreeEraser;
use LightweightPlugins\Migration\Reset\UploadsOnly;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Phases in cursor.phase: attachments (every attachment through core's
 * wp_delete_attachment(), BATCH ids at a time by ascending id from
 * cursor.after; on a network every site of the main network in turn, each in
 * its own context, so core deletes the files where that site keeps them;
 * UploadsOnly keeps core's deletions inside that uploads folder and out of
 * our storage, the backups folders and the site's own folders),
 * then files (the main site's uploads folder emptied through TreeEraser:
 * links go as links, our storage and backups folders are never entered, by
 * identity, the uploads folder's own protection files stay). The files phase
 * is a scan pass resumed from cursor.position, so a tree of files that
 * cannot be removed still ends every request on time and moves on.
 *
 * Refused on every request when the uploads folder became unsafe (it holds
 * a core folder) or unwritable while the job waited; NotAsRoot refuses root.
 * The folders kept are read from the site as it is now, not from the job's
 * snapshot. The job is saved with cursor.inflight before core deletes each
 * attachment, so one that ends the request (a fatal error, a time limit) is
 * passed with a warning by the next request, never tried again; cursor.after
 * moves after every id. The counts in state.removed.media are saved with the
 * cursor, so a request that dies recounts at most what it did since the last
 * save (a display count).
 *
 * Residual: a network site with an old custom upload_path keeps the files
 * there that are not attachments (only the main site's uploads folder is
 * emptied).
 */
final class MediaStep implements StepInterface {

	public const KEY = ResetType::MEDIA_STEP;

	public const BATCH = 200;

	/** @var array<string, string>|null Folders whose files core never deletes, by id, for this request. */
	private ?array $kept = null;

	/** @var Site|null The site as it is now, for this request. */
	private ?Site $live = null;

	/** @var \Closure fn( Job $job ): void */
	private \Closure $save;

	/** The name the warnings give the uploads folder. */
	private const NAME = 'uploads';

	/**
	 * @param \Closure      $nextIds    fn( int $after, int $limit ): array<int, int>, attachment ids above $after, ascending.
	 * @param \Closure      $deleteOne  fn( int $id, array $kept ): bool; $kept = folders whose files never go, by id.
	 * @param \Closure      $onSite     fn( int $siteId, \Closure $work ): mixed, runs $work switched to that site.
	 * @param \Closure      $sites      fn( int $after ): array<int, int>, site ids of the main network above $after (empty on a single site).
	 * @param \Closure      $sealed     fn( Site $site ): array<string, string> folders never entered, by id.
	 * @param \Closure      $uploadsDir fn(): string, the main site's uploads folder.
	 * @param \Closure      $unsafe     fn(): ?string, a ResetRefused reason, checked on every request.
	 * @param \Closure|null $site       fn(): Site, the site as it is now; null = the job's snapshot.
	 * @param \Closure|null $save       fn( Job $job ): void, saves the job; null = not saved.
	 */
	public function __construct(
		private \Closure $nextIds,
		private \Closure $deleteOne,
		private \Closure $onSite,
		private \Closure $sites,
		private \Closure $sealed,
		private \Closure $uploadsDir,
		private \Closure $unsafe,
		private ?\Closure $site = null,
		?\Closure $save = null
	) {
		$this->save = $save ?? static function ( Job $job ): void {
		};
	}

	/**
	 * @param Services $services Services.
	 */
	public static function fromWordPress( Services $services ): self {
		return new self(
			static function ( int $after, int $limit ): array {
				global $wpdb;
				return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the attachment ids in pages; no API pages by id.
			},
			static fn( int $id, array $kept ): bool => UploadsOnly::delete( $id, $kept ),
			static function ( int $site, \Closure $work ) {
				switch_to_blog( $site );
				try {
					return $work();
				} finally {
					restore_current_blog();
				}
			},
			static fn( int $after ): array => is_multisite() ? NetworkSites::page( $after ) : [],
			static fn( Site $site ): array => FolderIdentity::ids( ExportRoots::sealedFolders( $site, $services->storage()->root() ) ),
			static fn(): string => untrailingslashit( (string) wp_upload_dir( null, false )['basedir'] ), // The job runs in the main site's context.
			static fn(): ?string => Preflight::fromWordPress( SiteReader::read() )->reason( ResetType::MEDIA ),
			static fn(): Site => SiteReader::read(),
			static function ( Job $job ) use ( $services ): void {
				$services->jobs()->save( $job );
			}
		);
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$this->kept = null;
		$this->live = null;
		$reason     = ( $this->unsafe )(); // Every request: the folder setting may change while the job waits.
		if ( null !== $reason ) {
			throw new JobException( ResetTexts::refusal( $reason ) );
		}
		if ( 'files' !== ( $job->cursor['phase'] ?? 'attachments' ) ) {
			if ( ! $this->attachments( $job, $deadline ) ) {
				/* translators: %d: media items deleted so far */
				return StepResult::more( 10, sprintf( __( 'Deleting the media library: %d items so far...', 'lw-migration' ), self::count( $job, 'attachments' ) ) );
			}
			$refused = (int) ( $job->cursor['refused'] ?? 0 );
			if ( $refused > 0 ) {
				/* translators: %d: number of media items */
				$job->addWarning( sprintf( _n( '%d media item could not be deleted from the media library.', '%d media items could not be deleted from the media library.', $refused, 'lw-migration' ), $refused ) );
			}
			$job->cursor = [ 'phase' => 'files' ];
		}

		return $this->files( $job, $deadline );
	}

	/**
	 * @param Job      $job      Reset job.
	 * @param Deadline $deadline Time budget.
	 * @return bool True once every site's media library is empty.
	 */
	private function attachments( Job $job, Deadline $deadline ): bool {
		if ( ! is_multisite() ) {
			return $this->batch( $job, $deadline );
		}
		$perSite = function ( int $id ) use ( $job, $deadline ): bool {
			if ( (int) ( $job->cursor['site'] ?? 0 ) !== $id ) {
				if ( isset( $job->cursor['inflight'] ) ) { // It ended a request on a site that is gone: passed, never another site's id.
					$job->cursor['refused'] = (int) ( $job->cursor['refused'] ?? 0 ) + 1;
					unset( $job->cursor['inflight'] );
				}
				$job->cursor['site']  = $id;
				$job->cursor['after'] = 0;
			}

			return (bool) ( $this->onSite )( $id, fn(): bool => $this->batch( $job, $deadline ) );
		};

		return NetworkSites::advance( $job, 'sites', $perSite, $deadline, $this->sites );
	}

	/**
	 * Deletes the current site's attachments from cursor.after on.
	 *
	 * @param Job      $job      Reset job.
	 * @param Deadline $deadline Time budget.
	 * @return bool True once no attachment is left, false when the deadline cut it.
	 */
	private function batch( Job $job, Deadline $deadline ): bool {
		$died = (int) ( $job->cursor['inflight'] ?? 0 );
		if ( $died > 0 ) { // It ended the request inside core: passed, never tried again.
			unset( $job->cursor['inflight'] );
			$job->cursor['refused'] = (int) ( $job->cursor['refused'] ?? 0 ) + 1;
			$job->cursor['after']   = max( $died, (int) ( $job->cursor['after'] ?? 0 ) );
		}
		while ( true ) {
			$after = (int) ( $job->cursor['after'] ?? 0 );
			$ids   = array_values( array_filter( array_map( 'intval', ( $this->nextIds )( $after, self::BATCH ) ), static fn( int $id ): bool => $id > $after ) );
			if ( [] === $ids ) {
				return true;
			}
			foreach ( $ids as $id ) {
				$job->cursor['inflight'] = $id;
				( $this->save )( $job );
				$deleted = ( $this->deleteOne )( $id, $this->kept( $job ) );
				unset( $job->cursor['inflight'] );
				if ( $deleted ) {
					self::add( $job, 'attachments', 1 );
				} else {
					$job->cursor['refused'] = (int) ( $job->cursor['refused'] ?? 0 ) + 1;
				}
				$job->cursor['after'] = $id;
				if ( $deadline->reached() ) {
					return false;
				}
			}
		}
	}

	/**
	 * One scan pass over the uploads folder, from cursor.position.
	 *
	 * @param Job      $job      Reset job.
	 * @param Deadline $deadline Time budget.
	 */
	private function files( Job $job, Deadline $deadline ): StepResult {
		$site   = $this->live( $job );
		$eraser = TreeEraser::forSite( $this->sealedIds( $job ), $site, TreeEraser::PROTECTION_FILES );
		$result = $eraser->erase( ( $this->uploadsDir )(), false, $deadline, (string) ( $job->cursor['position'] ?? '' ) );
		self::add( $job, 'files', $result['removed'] );
		self::add( $job, 'failed', $result['failed'] );
		self::add( $job, 'kept', $result['skipped'] );
		$left = $eraser->skippedFor();
		foreach ( $left as $why => $n ) {
			$left[ $why ] = $n + (int) ( $job->cursor['left'][ $why ] ?? 0 );
		}
		if ( ! $result['done'] ) {
			$job->cursor['position'] = $eraser->position(); // Progress the attempt guard sees, also for a pass that removed nothing.
			$job->cursor['left']     = $left;
			return StepResult::more( 60, __( 'Removing the uploaded files...', 'lw-migration' ) );
		}
		foreach ( ResetTexts::leftFolders( self::NAME, $left ) as $warning ) {
			$job->addWarning( $warning );
		}
		$failed = self::count( $job, 'failed' );
		if ( $failed > 0 ) {
			/* translators: %d: number of files */
			$job->addWarning( sprintf( _n( '%d uploaded file could not be removed (permissions).', '%d uploaded files could not be removed (permissions).', $failed, 'lw-migration' ), $failed ) );
		}

		/* translators: 1: number of media items, 2: number of files */
		return StepResult::done( sprintf( __( 'Media library emptied: %1$d items, %2$d files.', 'lw-migration' ), self::count( $job, 'attachments' ), self::count( $job, 'files' ) ) );
	}

	/**
	 * Our storage, the backups folders and the site's own folders.
	 *
	 * @param Job $job Reset job.
	 * @return array<string, string>
	 */
	private function kept( Job $job ): array {
		$site = $this->live( $job );

		return $this->kept ??= $this->sealedIds( $job ) + CoreFolders::ids( $site );
	}

	/**
	 * Our storage and the backups folders, of the job's snapshot and of the
	 * site as it is now (a folder setting changed while the job waited).
	 *
	 * @param Job $job Reset job.
	 * @return array<string, string>
	 */
	private function sealedIds( Job $job ): array {
		return ( $this->sealed )( $this->live( $job ) ) + ( $this->sealed )( Site::fromArray( (array) ( $job->state['site'] ?? [] ) ) );
	}

	/**
	 * @param Job $job Reset job.
	 * @return Site The site as it is now (read once per request).
	 */
	private function live( Job $job ): Site {
		return $this->live ??= null === $this->site ? Site::fromArray( (array) ( $job->state['site'] ?? [] ) ) : ( $this->site )();
	}

	/**
	 * @param Job    $job Reset job.
	 * @param string $key attachments, files, failed or kept.
	 * @param int    $n   How many more.
	 */
	private static function add( Job $job, string $key, int $n ): void {
		$job->state['removed']['media'][ $key ] = self::count( $job, $key ) + $n;
	}

	/**
	 * @param Job    $job Reset job.
	 * @param string $key attachments, files, failed or kept.
	 */
	private static function count( Job $job, string $key ): int {
		return (int) ( $job->state['removed']['media'][ $key ] ?? 0 );
	}
}
