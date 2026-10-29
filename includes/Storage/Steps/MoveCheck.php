<?php
/**
 * What a move checks before the first backup moves.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses a move that could not finish, before anything is moved: an old
 * folder with backups that cannot be changed, a name in the new folder that
 * holds another file, too little room on another disk. A name in both
 * folders that holds the same backup (same file, or the same bytes, compared
 * in slices over as many calls as it takes; cursor.twins) is no collision:
 * an interrupted move left it, and the move removes only the old copy. A
 * missing old folder moves nothing, with a warning. Sets state.total and
 * state.moved, then cursor.checked.
 */
final class MoveCheck {

	/**
	 * @param \Closure $free       fn( string $dir ): ?int — free bytes (null = unknown).
	 * @param \Closure $sameDevice fn( string $a, string $b ): bool.
	 */
	public function __construct( private \Closure $free, private \Closure $sameDevice ) {
	}

	/**
	 * @param Job      $job      Move job.
	 * @param string   $from     Old folder.
	 * @param string   $to       New folder.
	 * @param Deadline $deadline Time budget.
	 * @return bool True when the check is complete (cursor.checked is set).
	 * @throws JobException When the move must not start.
	 */
	public function run( Job $job, string $from, string $to, Deadline $deadline ): bool {
		clearstatcache( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( ! @file_exists( $from ) && ! @is_link( $from ) ) {
			if ( empty( $job->options['fromDefault'] ) ) {
				$job->addWarning( __( 'The old backups folder was not found, so nothing was moved from it. Its backups are no longer listed.', 'lw-migration' ) );
			}
			self::start( $job, 0, 0, false );
			return true;
		}
		$names = MoveStep::listed( $from );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( [] !== $names && ! @is_writable( $from ) ) {
			throw new JobException( __( 'The old backups folder is read-only, so the backups cannot be moved out of it.', 'lw-migration' ) );
		}
		$twins = $this->twins( $names, $from, $to );
		if ( ! $this->compareTwins( $job, $twins, $from, $to, $deadline ) ) {
			return false;
		}
		$size  = MoveStep::count( $from );
		$saved = array_sum( array_map( static fn( string $name ): int => (int) ( FileIdentity::of( $from . '/' . $name )['size'] ?? 0 ), array_keys( $twins ) ) );
		$this->roomFor( $from, $to, $size['bytes'] - $saved );
		self::start( $job, $size['count'], $size['bytes'], true );

		return true;
	}

	/**
	 * @param Job  $job   Move job.
	 * @param int  $count Backups to move.
	 * @param int  $bytes Their size.
	 * @param bool $found Whether the old folder was there.
	 */
	private static function start( Job $job, int $count, int $bytes, bool $found ): void {
		$job->state['total']     = [
			'count' => $count,
			'bytes' => $bytes,
		];
		$job->state['moved']     = [
			'count' => 0,
			'bytes' => 0,
		];
		$job->state['fromFound'] = $found;
		$job->cursor             = [ 'checked' => true ];
	}

	/**
	 * Names that are taken in the new folder: refused at once unless the file
	 * there may be the same backup (a regular file of the same size and
	 * mtime, within FileIdentity::MTIME_SLACK).
	 *
	 * @param array<int, string> $names Backups in the old folder.
	 * @param string             $from  Old folder.
	 * @param string             $to    New folder.
	 * @return array<string, true> Names to compare byte for byte.
	 * @throws JobException Naming at most three taken names.
	 */
	private function twins( array $names, string $from, string $to ): array {
		$taken = [];
		$twins = [];
		foreach ( $names as $name ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			if ( ! @file_exists( $to . '/' . $name ) && ! @is_link( $to . '/' . $name ) ) {
				continue;
			}
			$there = FileIdentity::of( $to . '/' . $name );
			$here  = FileIdentity::of( $from . '/' . $name );
			if ( null === $there || null === $here || $there['size'] !== $here['size'] || abs( $there['mtime'] - $here['mtime'] ) > FileIdentity::MTIME_SLACK ) {
				$taken[] = $name;
			} else {
				$twins[ $name ] = true;
			}
		}
		if ( [] !== $taken ) {
			throw new JobException( self::collision( $taken ) );
		}

		return $twins;
	}

	/**
	 * @param Job                 $job      Move job.
	 * @param array<string, true> $twins    Names in both folders.
	 * @param string              $from     Old folder.
	 * @param string              $to       New folder.
	 * @param Deadline            $deadline Time budget.
	 * @return bool True when every twin holds the same bytes.
	 * @throws JobException When one does not, or a file cannot be read.
	 */
	private function compareTwins( Job $job, array $twins, string $from, string $to, Deadline $deadline ): bool {
		$done = array_values( array_map( 'strval', (array) ( $job->cursor['twins']['done'] ?? [] ) ) );
		$same = (array) ( $job->cursor['twins']['same'] ?? [] ); // same() starts over when it belongs to other files.
		foreach ( array_keys( $twins ) as $name ) {
			if ( in_array( $name, $done, true ) ) {
				continue;
			}
			try {
				$result = FileIdentity::same( $to . '/' . $name, $from . '/' . $name, $same, $deadline );
			} catch ( StorageException $e ) {
				throw new JobException( FolderMessages::copyFailed( (int) $e->getCode(), $name ) );
			}
			if ( false === $result ) {
				throw new JobException( self::collision( [ $name ] ) );
			}
			if ( true === $result ) {
				$done[] = $name;
				$same   = [];
			}
			$job->cursor['twins'] = [
				'done' => $done,
				'same' => $same,
			];
			if ( null === $result || $deadline->reached() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $from   Old folder.
	 * @param string $to     New folder.
	 * @param int    $needed Bytes to copy.
	 * @throws JobException When another disk lacks the room (a hard link needs none on the same one).
	 */
	private function roomFor( string $from, string $to, int $needed ): void {
		if ( $needed <= 0 || ( $this->sameDevice )( $from, $to ) ) {
			return;
		}
		$free = ( $this->free )( $to );
		if ( null !== $free && $free < $needed + FileMover::MARGIN ) {
			/* translators: 1: needed size, 2: free size */
			throw new JobException( sprintf( __( 'The new folder needs %1$s of free space for the backups; it has %2$s.', 'lw-migration' ), size_format( $needed + FileMover::MARGIN ), size_format( $free ) ) );
		}
	}

	/**
	 * @param array<int, string> $names Taken names.
	 */
	private static function collision( array $names ): string {
		/* translators: %s: backup file names */
		return sprintf( __( 'The new folder already holds a file named %s. Move or delete it there first.', 'lw-migration' ), implode( ', ', array_slice( $names, 0, 3 ) ) );
	}

	/**
	 * The text when a name in the new folder holds another file.
	 *
	 * @param string $name Backup name.
	 */
	public static function taken( string $name ): string {
		return self::collision( [ $name ] );
	}

	/**
	 * @param string $a Folder.
	 * @param string $b Folder.
	 */
	public static function sameDevice( string $a, string $b ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$x = @stat( $a );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$y = @stat( $b );

		return false !== $x && false !== $y && $x['dev'] === $y['dev'];
	}
}
