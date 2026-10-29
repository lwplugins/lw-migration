<?php
/**
 * Move step: moves the backups into the new folder one by one.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Steps;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * After the checks (MoveCheck), per backup, sorted by name, each phase saved
 * before the next one acts: a hidden temp name is picked; FileMover copies
 * (or hard-links) the backup into it (cursor.copy), and the identities of the
 * finished copy and of the original are saved (cursor.moved) before anything
 * is published; BackupPublisher gives the temp the backup's name without ever
 * replacing a file (cursor.reserving where hard links are missing); then the
 * published file and the original are compared byte for byte in slices
 * (FileIdentity::same, cursor.same); the backup's live transfer links are
 * bound to the copy, and only then the original goes
 * (FileIdentity::removeOriginal: only while both still have the saved
 * identities). A name already in the new folder is no collision when it holds
 * the same backup (an interrupted move left it): only the original goes. A
 * request that dies at any point repeats safely, and a backup is counted once.
 * Nothing moves unless the stored setting is the one this move wrote. Known
 * limit: an original deleted outside the plugin after its copy was published
 * stays in the new folder (it is counted as moved).
 */
final class MoveStep implements StepInterface {

	/**
	 * The checks before the first backup moves.
	 *
	 * @var MoveCheck
	 */
	private MoveCheck $check;

	/**
	 * @param FileMover     $mover      Link or copy.
	 * @param \Closure      $ready      fn( Job $job ): void — the target folder may be written, or a JobException.
	 * @param \Closure|null $free       fn( string $dir ): ?int; default DiskSpace::free().
	 * @param \Closure|null $sameDevice fn( string $a, string $b ): bool (tests).
	 * @param \Closure|null $link       fn( string $from, string $to ): bool — the hard link of the publish (tests); null = link().
	 */
	public function __construct( private FileMover $mover, private \Closure $ready, ?\Closure $free = null, ?\Closure $sameDevice = null, private ?\Closure $link = null ) {
		$this->check = new MoveCheck(
			$free ?? static fn( string $dir ): ?int => DiskSpace::free( $dir ),
			$sameDevice ?? static fn( string $a, string $b ): bool => MoveCheck::sameDevice( $a, $b )
		);
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$from = (string) ( $job->options['fromDir'] ?? '' );
		$to   = (string) ( $job->options['toDir'] ?? '' );
		( $this->ready )( $job );
		$this->guardSource( $job, $from );
		if ( empty( $job->cursor['checked'] ) ) {
			if ( BackupDir::read()->toJson() !== (string) ( $job->options['during'] ?? '' ) ) {
				throw new JobException( FinishStep::settingChanged() ); // Nothing moves unless the setting marks this very move.
			}
			return $this->check->run( $job, $from, $to, $deadline )
				? StepResult::more( 2, __( 'Moving the backups...', 'lw-migration' ) )
				: StepResult::more( 1, __( 'Checking the backups...', 'lw-migration' ) );
		}
		if ( ! BackupName::isValid( (string) ( $job->cursor['name'] ?? '' ) ) ) {
			$next = self::listed( $from )[0] ?? null;
			if ( null === $next ) {
				return StepResult::done( __( 'Every backup is in the new folder.', 'lw-migration' ) );
			}
			$job->cursor = [
				'checked' => true,
				'name'    => $next,
				'temp'    => TempName::name(),
				'phase'   => 'copy',
				'copy'    => [],
			];
			return $this->more( $job );
		}
		$phase = (string) ( $job->cursor['phase'] ?? '' );
		if ( 'publish' === $phase ) {
			return $this->publish( $job, $from, $to );
		}

		return 'verify' === $phase ? $this->verify( $job, $from, $to, $deadline ) : $this->copy( $job, $from, $to, $deadline );
	}

	/**
	 * Backups in a folder: valid names of regular, non-empty files (links and
	 * our temps are not backups), sorted. A missing or unreadable folder has none.
	 *
	 * @param string $dir Folder.
	 * @return array<int, string>
	 */
	public static function names( string $dir ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$items = @is_dir( $dir ) && @is_readable( $dir ) ? @scandir( $dir ) : false;
		if ( false === $items ) {
			return [];
		}
		$names = array_values( array_filter( $items, static fn( string $item ): bool => BackupName::isValid( $item ) && ( FileIdentity::of( $dir . '/' . $item )['size'] ?? 0 ) > 0 ) );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * The backups of the old folder: a missing folder has none, but one that is
	 * there and cannot be listed is never taken for empty (the setting would
	 * switch and its backups leave the list).
	 *
	 * @param string $dir Old folder.
	 * @return array<int, string>
	 * @throws JobException When the folder is there but cannot be listed.
	 */
	public static function listed( string $dir ): array {
		clearstatcache( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( ! @file_exists( $dir ) && ! @is_link( $dir ) ) {
			return [];
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( ! @is_dir( $dir ) || ! @is_readable( $dir ) || ! @is_executable( $dir ) || false === @scandir( $dir ) ) {
			throw new JobException( __( 'The old backups folder cannot be read, so its backups cannot be moved. Check the permissions of that folder.', 'lw-migration' ) );
		}

		return self::names( $dir );
	}

	/**
	 * @param string $dir Folder.
	 * @return array{count: int, bytes: int}
	 */
	public static function count( string $dir ): array {
		$bytes = 0;
		$names = self::names( $dir );
		foreach ( $names as $name ) {
			$bytes += (int) ( FileIdentity::of( $dir . '/' . $name )['size'] ?? 0 );
		}

		return [
			'count' => count( $names ),
			'bytes' => $bytes,
		];
	}

	/**
	 * The old folder is read and emptied only while it is still the folder the
	 * move started from: a chosen one keeps its name, is no link and carries
	 * our marker; once found, it may not vanish (its backups would leave the
	 * list when the setting switches).
	 *
	 * @param Job    $job  Move job.
	 * @param string $from Old folder.
	 * @throws JobException When the old folder is not itself any more.
	 */
	private function guardSource( Job $job, string $from ): void {
		clearstatcache( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$there = @file_exists( $from ) || @is_link( $from );
		if ( ! $there ) {
			if ( ! empty( $job->state['fromFound'] ) ) {
				throw new JobException( FolderMessages::of( Refused::MISSING ) );
			}
			return;
		}
		if ( ! empty( $job->options['fromDefault'] ) ) {
			return;
		}
		$marker = BackupDir::parse( (string) ( $job->options['after'] ?? '' ) )->marker;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( @is_link( $from ) || @realpath( $from ) !== $from || BackupDirRules::markerOf( $from ) !== $marker ) {
			throw new JobException( FolderMessages::of( Refused::CHANGED ) );
		}
	}

	/**
	 * Copies the backup into the temp (one slice per call); when the copy is
	 * verified its identity and the original's are saved before anything is
	 * published. A name the new folder already holds goes to the byte compare.
	 *
	 * @param Job      $job      Move job.
	 * @param string   $from     Old folder.
	 * @param string   $to       New folder.
	 * @param Deadline $deadline Time budget.
	 * @throws JobException When the copy fails or the name holds another file.
	 */
	private function copy( Job $job, string $from, string $to, Deadline $deadline ): StepResult {
		$name      = (string) $job->cursor['name'];
		$temp      = (string) ( $job->cursor['temp'] ?? '' );
		$source    = FileIdentity::of( $from . '/' . $name );
		$publisher = new BackupPublisher( $to );
		if ( null === $source || ! TempName::is( $temp ) ) {
			$publisher->discard( $temp ); // Gone meanwhile (or a broken cursor): the next pick decides.
			$job->cursor = [ 'checked' => true ];
			return $this->more( $job );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( @file_exists( $to . '/' . $name ) || @is_link( $to . '/' . $name ) ) {
			return $this->twin( $job, $publisher, $temp, $to . '/' . $name, $source );
		}
		$copy = (array) ( $job->cursor['copy'] ?? [] );
		try {
			$done = $this->mover->copy( $from . '/' . $name, $to . '/' . $temp, $copy, $deadline );
		} catch ( StorageException $e ) {
			throw new JobException( FolderMessages::copyFailed( (int) $e->getCode(), $name ) );
		} finally {
			$job->cursor['copy'] = $copy;
		}
		if ( $done ) {
			$job->cursor['moved'] = [
				'copy'     => $copy['id'],
				'original' => $copy['source'],
			];
			$job->cursor['phase'] = 'publish';
			unset( $job->cursor['copy'] );
		}

		return $this->more( $job );
	}

	/**
	 * A name in both folders: it counts as moved only when it holds the same
	 * backup, which the verify phase decides byte for byte.
	 *
	 * @param Job                                      $job       Move job.
	 * @param BackupPublisher                          $publisher The new folder.
	 * @param string                                   $temp      Temp name (removed: nothing is copied).
	 * @param string                                   $there     The name in the new folder.
	 * @param array{id: string, size: int, mtime: int} $source    The original's identity.
	 * @throws JobException When the name holds no regular file.
	 */
	private function twin( Job $job, BackupPublisher $publisher, string $temp, string $there, array $source ): StepResult {
		$twin = FileIdentity::of( $there );
		if ( null === $twin ) {
			throw new JobException( MoveCheck::taken( basename( $there ) ) );
		}
		$publisher->discard( $temp );
		$job->cursor['moved'] = [
			'copy'     => $twin,
			'original' => $source,
		];
		$job->cursor['twin']  = true;
		$job->cursor['phase'] = 'verify';
		$job->cursor['same']  = [];
		unset( $job->cursor['copy'] );

		return $this->more( $job );
	}

	/**
	 * Gives the finished temp the backup's name (never over another file).
	 * A backup deleted meanwhile (a delete removes the name from both folders,
	 * e.g. while this move sat past the lock lifetime) is never published
	 * again from the temp: the temp and a reserved name are discarded.
	 *
	 * @param Job    $job  Move job.
	 * @param string $from Old folder.
	 * @param string $to   New folder.
	 * @throws JobException When the name is taken or the published file is not the copy.
	 */
	private function publish( Job $job, string $from, string $to ): StepResult {
		$name      = (string) $job->cursor['name'];
		$temp      = (string) ( $job->cursor['temp'] ?? '' );
		$reserving = (string) ( $job->cursor['reserving'] ?? '' );
		$copy      = (array) ( $job->cursor['moved']['copy'] ?? [] );
		$publisher = new BackupPublisher( $to, $this->link );
		if ( null === FileIdentity::of( $from . '/' . $name ) && FileIdentity::of( $to . '/' . $name ) !== $copy ) {
			$publisher->discard( $temp, $reserving );
			$job->cursor = [ 'checked' => true ];
			return $this->more( $job );
		}
		if ( TempName::is( $temp ) && $publisher->isStaged( $temp ) ) {
			try {
				$result = $reserving === $name ? $publisher->publishReserved( $temp, $name ) : $publisher->publish( $temp, $name );
			} catch ( ExportException $e ) {
				throw new JobException( FolderMessages::copyFailed( FileMover::IO, $name ) );
			}
			if ( BackupPublisher::RESERVE === $result ) {
				$job->cursor['reserving'] = $name;
				return $this->more( $job );
			}
			if ( BackupPublisher::TAKEN === $result ) {
				$publisher->discard( $temp, $reserving );
				throw new JobException( MoveCheck::taken( $name ) );
			}
		} elseif ( FileIdentity::of( $to . '/' . $name ) !== $copy ) {
			$publisher->discard( '', $reserving ); // Neither the temp nor our copy is there: copy this one again.
			$job->cursor = [ 'checked' => true ];
			return $this->more( $job );
		}
		if ( FileIdentity::of( $to . '/' . $name ) !== $copy ) {
			throw new JobException( FolderMessages::copyFailed( FileMover::MISMATCH, $name ) );
		}
		unset( $job->cursor['reserving'] );
		$job->cursor['phase'] = 'verify';
		$job->cursor['same']  = [];

		return $this->more( $job );
	}

	/**
	 * Compares the published backup with the original byte for byte, then
	 * removes the original and counts the backup, once.
	 *
	 * @param Job      $job      Move job.
	 * @param string   $from     Old folder.
	 * @param string   $to       New folder.
	 * @param Deadline $deadline Time budget.
	 * @throws JobException When the files differ or the original cannot be removed (nothing is removed then).
	 */
	private function verify( Job $job, string $from, string $to, Deadline $deadline ): StepResult {
		$name     = (string) $job->cursor['name'];
		$moved    = (array) ( $job->cursor['moved'] ?? [] );
		$original = $from . '/' . $name;
		$there    = $to . '/' . $name;
		$differs  = empty( $job->cursor['twin'] ) ? FolderMessages::copyFailed( FileMover::MISMATCH, $name ) : MoveCheck::taken( $name );
		if ( ! is_array( $moved['copy'] ?? null ) || ! is_array( $moved['original'] ?? null ) || FileIdentity::of( $there ) !== $moved['copy'] ) {
			throw new JobException( $differs );
		}
		if ( null !== FileIdentity::of( $original ) ) {
			$same = (array) ( $job->cursor['same'] ?? [] );
			try {
				$result = FileIdentity::same( $there, $original, $same, $deadline );
			} catch ( StorageException $e ) {
				throw new JobException( FolderMessages::copyFailed( (int) $e->getCode(), $name ) );
			} finally {
				$job->cursor['same'] = $same;
			}
			if ( null === $result ) {
				return $this->more( $job );
			}
			if ( false === $result ) {
				throw new JobException( $differs );
			}
			( new Shares() )->rebind( Backup::OWN, $name, Fingerprint::of( $original ), Fingerprint::of( $there ) ); // The same bytes, proven: a live link follows them.
			$this->removeOriginal( $original, $moved, $there, $differs );
		}
		$job->state['moved']['count'] = (int) ( $job->state['moved']['count'] ?? 0 ) + 1;
		$job->state['moved']['bytes'] = (int) ( $job->state['moved']['bytes'] ?? 0 ) + (int) $moved['copy']['size'];
		$job->cursor                  = [ 'checked' => true ];

		return $this->more( $job );
	}

	/**
	 * @param string                                                                                                    $original The original.
	 * @param array{copy: array{id: string, size: int, mtime: int}, original: array{id: string, size: int, mtime: int}} $moved    Saved identities.
	 * @param string                                                                                                    $there    The backup in the new folder.
	 * @param string                                                                                                    $differs  The text when a file is not the one compared.
	 * @throws JobException When either file changed, or the original cannot be removed.
	 */
	private function removeOriginal( string $original, array $moved, string $there, string $differs ): void {
		try {
			FileIdentity::removeOriginal( $original, $moved['original'], $there, $moved['copy'] );
		} catch ( StorageException $e ) {
			throw new JobException( FileMover::MISMATCH === (int) $e->getCode() ? $differs : __( 'A backup was copied, but it could not be removed from the old folder. Check the permissions of that folder.', 'lw-migration' ) );
		}
	}

	private function more( Job $job ): StepResult {
		$moved = (array) ( $job->state['moved'] ?? [] );
		$total = (array) ( $job->state['total'] ?? [] );
		$done  = (int) ( $moved['count'] ?? 0 );

		return StepResult::more(
			min( 95, 2 + (int) floor( 93 * (int) ( $moved['bytes'] ?? 0 ) / max( 1, (int) ( $total['bytes'] ?? 0 ) ) ) ),
			/* translators: 1: backups moved so far, 2: all backups */
			sprintf( __( 'Moving the backups: %1$d of %2$d...', 'lw-migration' ), $done, max( $done, (int) ( $total['count'] ?? 0 ) ) )
		);
	}
}
