<?php
/**
 * Export step: lists the files to back up.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * One folder per unit of work (a network backup walks the folders its sites
 * need: NetworkRoots); then compares the expected size with the free
 * disk space. The cursor goes into the job only after the enumerator returned
 * normally: a failed run resumes from the last saved cursor, whose lengths the
 * list and its side file are cut back to.
 */
final class EnumerateStep implements StepInterface {

	/**
	 * @param JobStore      $store         Job store.
	 * @param string        $storageRoot   Our storage root (never exported, disk checked there).
	 * @param \Closure      $freeSpace     fn( string $dir ): ?int.
	 * @param \Closure      $databaseBytes fn(): int — size of the site's tables.
	 * @param string        $backupsDir    Backups folder ('' = not checked): on another disk it needs room for a copy.
	 * @param \Closure|null $device        fn( string $dir ): ?int, the device of a folder (tests); null = stat().
	 */
	public function __construct( private JobStore $store, private string $storageRoot, private \Closure $freeSpace, private \Closure $databaseBytes, private string $backupsDir = '', private ?\Closure $device = null ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$options     = ExportOptions::fromArray( $job->options );
		$network     = isset( $job->state['network'] ) ? (array) $job->state['network'] : null;
		$site        = self::site( (array) $job->state['site'], $network );
		$cursor      = EnumCursor::fromArray( $job->cursor );
		$roots       = ExportRoots::build( $site, $options, $this->storageRoot, $network );
		$enumerator  = new FileEnumerator( $roots, WorkDir::path( $this->store, $job ) . '/' . ExportFiles::LIST, ExportRoots::territories( $site, $network ) );
		$done        = $enumerator->run(
			$cursor,
			$deadline,
			static function ( string $code, string $entry ) use ( $job ): void {
				$job->addWarning( self::warning( $code, $entry ) );
			}
		);
		$job->cursor = $cursor->toArray();
		if ( ! $done ) {
			/* translators: %d: number of files found so far */
			return StepResult::more( 0, sprintf( __( 'Listing files: %d found so far...', 'lw-migration' ), $cursor->files ) );
		}
		$job->state['files'] = [
			'count' => $cursor->files,
			'bytes' => $cursor->bytes,
		];
		$this->checkSpace( $job, $cursor->bytes + ( $options->noDatabase ? 0 : (int) ( $this->databaseBytes )() ) );

		/* translators: %d: number of files */
		return StepResult::done( sprintf( __( '%d files to back up.', 'lw-migration' ), $cursor->files ) );
	}

	/**
	 * The snapshot; on a network its active plugins are those of every exported
	 * site and the network-active ones (state.network.activePlugins).
	 *
	 * @param array<string, mixed>      $snapshot state.site.
	 * @param array<string, mixed>|null $network  state.network.
	 * @return Site
	 */
	private static function site( array $snapshot, ?array $network ): Site {
		if ( null === $network ) {
			return Site::fromArray( $snapshot );
		}

		return Site::fromArray( array_merge( $snapshot, [ 'activePlugins' => array_values( array_map( 'strval', (array) ( $network['activePlugins'] ?? [] ) ) ) ] ) );
	}

	/**
	 * The archive is built in the storage folder; a backups folder on another
	 * disk then takes a full copy of it (on the same disk it is a link).
	 *
	 * @param Job $job    Job.
	 * @param int $needed Estimated archive size.
	 */
	private function checkSpace( Job $job, int $needed ): void {
		$free = ( $this->freeSpace )( $this->storageRoot );
		if ( null !== $free && $free < $needed ) {
			/* translators: 1: estimated backup size, 2: free disk space */
			$job->addWarning( sprintf( __( 'The backup may need about %1$s, but only %2$s is free on the disk. It may fail when the disk fills up.', 'lw-migration' ), (string) size_format( $needed ), (string) size_format( $free ) ) );
		}
		if ( '' === $this->backupsDir || ! $this->otherDisk() ) {
			return;
		}
		$free = ( $this->freeSpace )( $this->backupsDir );
		if ( null !== $free && $free < $needed ) {
			/* translators: 1: estimated backup size, 2: free disk space */
			$job->addWarning( sprintf( __( 'The backups folder is on another disk. The backup may need about %1$s there, but only %2$s is free there. It may fail when that disk fills up.', 'lw-migration' ), (string) size_format( $needed ), (string) size_format( $free ) ) );
		}
	}

	/** Whether the backups folder is known to be on another device than the storage folder. */
	private function otherDisk(): bool {
		$device  = $this->device ?? static function ( string $dir ): ?int {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing folder is unknown, never a warning with the path.
			$stat = @stat( $dir );

			return false === $stat ? null : (int) $stat['dev'];
		};
		$storage = $device( $this->storageRoot );
		$backups = $device( $this->backupsDir );

		return null !== $storage && null !== $backups && $storage !== $backups;
	}

	/**
	 * @param string $code  FileEnumerator warning code.
	 * @param string $entry Archive entry name (relative, never a server path).
	 * @return string
	 */
	private static function warning( string $code, string $entry ): string {
		switch ( $code ) {
			case 'symlink':
				/* translators: %s: path inside the backup */
				return sprintf( __( 'Skipped a broken symbolic link (its target does not exist): %s', 'lw-migration' ), $entry );
			case 'unreadable_dir':
				/* translators: %s: folder inside the backup */
				return sprintf( __( 'Skipped a folder that cannot be read: %s', 'lw-migration' ), $entry );
			case 'name_too_long':
				/* translators: %s: path inside the backup */
				return sprintf( __( 'Skipped a file whose name is too long for the archive format: %s', 'lw-migration' ), $entry );
			default:
				/* translators: %s: path inside the backup */
				return sprintf( __( 'Skipped a file that cannot be read: %s', 'lw-migration' ), $entry );
		}
	}
}
