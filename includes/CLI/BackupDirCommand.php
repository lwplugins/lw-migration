<?php
/**
 * `wp lw-migration backup-dir`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderContext;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Storage\Steps\MoveStep;
use LightweightPlugins\Migration\WordPress\SiteBusyException;

defined( 'ABSPATH' ) || exit;

/**
 * Shows or changes the folder the backups are kept in.
 */
final class BackupDirCommand {

	/**
	 * Shows the backups folder, a problem with it, and a move that has not finished.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration backup-dir show
	 *
	 * @return void
	 */
	public function show(): void {
		$storage = Services::get()->storage();
		$setting = $storage->setting();
		/* translators: %s: absolute path of the default backups folder */
		$folder = '' === $setting->path ? sprintf( __( 'default (%s)', 'lw-migration' ), $storage->defaultBackupsDir() ) : $setting->path;
		/* translators: %s: the backups folder */
		Console::log( sprintf( __( 'Backups folder: %s', 'lw-migration' ), $folder ) );
		$problem = '' === $setting->path ? null : BackupDirRules::inUse( $setting->path, $setting->marker, $storage->owners() );
		if ( null !== $problem ) {
			Console::warning( FolderMessages::of( $problem, $storage->ownerName() ) );
		}
		if ( null !== $setting->moving ) {
			$target = '' === $setting->moving ? __( 'the default folder', 'lw-migration' ) : $setting->moving;
			$left   = MoveStep::count( $storage->backupsDir() )['count'];
			/* translators: 1: target folder, 2: number of backups */
			Console::log( sprintf( _n( 'A move to %1$s has not finished: %2$d backup is still in the folder above. Run the move again to finish it, or move to the folder above to undo it.', 'A move to %1$s has not finished: %2$d backups are still in the folder above. Run the move again to finish it, or move to the folder above to undo it.', $left, 'lw-migration' ), $target, $left ) );
		}
	}

	/**
	 * Moves the backups into another folder, back to the default folder, or
	 * finishes or undoes a move that has not finished. Scheduled backups wait,
	 * and no backup or restore can start until it is done.
	 *
	 * A folder inside the site's web root (for example wp-content itself;
	 * folders below wp-content are refused) gets a subfolder with a random
	 * name; a folder outside it is used as given and must be new, empty, or
	 * already this site's backups folder.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : The new backups folder (a full path).
	 *
	 * [--default]
	 * : Move the backups back to the default folder.
	 *
	 * [--finish]
	 * : Finish the move that has not finished.
	 *
	 * [--undo]
	 * : Undo the move that has not finished (back to the folder it started from).
	 *
	 * [--yes]
	 * : Do not ask for confirmation (required when not run from a terminal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration backup-dir move /srv/backups/example
	 *     wp lw-migration backup-dir move --default --yes
	 *     wp lw-migration backup-dir move --finish
	 *
	 * @param array<int, string>   $args  The folder, if given.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function move( array $args, array $assoc ): void {
		$services = Services::get();
		$storage  = $services->storage();
		try {
			$target = self::target( $args, $assoc, $storage->setting() );
		} catch ( \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		if ( ! FolderContext::editable() ) {
			Console::error( FolderMessages::of( FolderRefusedException::LOCKED ) );
			return;
		}
		try {
			$storage->ensure();
			$plan = MoveJobs::plan( $storage, FolderContext::rules( $storage ), $target );
		} catch ( FolderRefusedException $e ) {
			Console::error( FolderMessages::of( $e->reason, $storage->ownerName() ) );
			return;
		} catch ( StorageException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		self::confirm( self::question( MoveStep::count( $plan['fromDir'] ), $plan['toDir'] ), $assoc );
		$job = self::start( $services, $storage, $target );
		if ( null === $job ) {
			return;
		}
		$secrets = new MemorySecretStore();
		try {
			$done = Drive::run( $services, $job, $secrets );
		} catch ( \RuntimeException $e ) { // Ctrl-C, lost lock, busy job.
			Console::error( Drive::stop( $services, $job, $secrets, $e ) );
			return;
		}
		Console::success( $done->message );
	}

	/**
	 * The folder asked for: exactly one of <path>, --default, --finish, --undo.
	 *
	 * @param array<int, string>   $args    Positional arguments.
	 * @param array<string, mixed> $assoc   Flags.
	 * @param BackupDir            $setting Current setting.
	 * @return string The folder ('' = the default one).
	 * @throws \InvalidArgumentException With the user's text.
	 */
	private static function target( array $args, array $assoc, BackupDir $setting ): string {
		$given = array_keys(
			array_filter(
				[
					'path'    => isset( $args[0] ),
					'default' => ! empty( $assoc['default'] ),
					'finish'  => ! empty( $assoc['finish'] ),
					'undo'    => ! empty( $assoc['undo'] ),
				]
			)
		);
		if ( 1 !== count( $given ) ) {
			throw new \InvalidArgumentException( __( 'Give the new folder, or one of --default, --finish, --undo.', 'lw-migration' ) );
		}
		if ( 'path' === $given[0] ) {
			return (string) $args[0];
		}
		if ( 'default' === $given[0] ) {
			return '';
		}
		if ( null === $setting->moving ) {
			throw new \InvalidArgumentException( FolderMessages::of( FolderRefusedException::NOTHING_PENDING ) );
		}

		return 'finish' === $given[0] ? $setting->moving : $setting->path;
	}

	/**
	 * The confirmation question. A random-named target is shown masked: the
	 * job draws its random segment again when it starts.
	 *
	 * @param array{count: int, bytes: int} $left Backups to move.
	 * @param string                        $to   Target folder.
	 */
	public static function question( array $left, string $to ): string {
		/* translators: 1: number of backups, 2: their size, 3: folder */
		return sprintf( _n( 'Move %1$d backup (%2$s) to %3$s? Scheduled backups wait, and no backup or restore can start until it is done. [y/n]', 'Move %1$d backups (%2$s) to %3$s? Scheduled backups wait, and no backup or restore can start until it is done. [y/n]', $left['count'], 'lw-migration' ), $left['count'], (string) size_format( $left['bytes'], 1 ), BackupDir::display( $to ) );
	}

	/**
	 * Returns only when the move is confirmed; otherwise exits with an error.
	 *
	 * @param string               $question The question (question()).
	 * @param array<string, mixed> $assoc    Flags.
	 * @return void
	 */
	private static function confirm( string $question, array $assoc ): void {
		$ask      = static fn(): ?string => Prompt::line( $question );
		$decision = Confirmation::decide( $assoc, Confirmation::stdinIsInteractive(), $ask );
		if ( Confirmation::NEEDS_YES === $decision ) {
			Console::error( __( 'Pass --yes to move without a prompt.', 'lw-migration' ) );
		}
		if ( Confirmation::DECLINED === $decision ) {
			Console::error( __( 'Nothing moved.', 'lw-migration' ) );
		}
	}

	/**
	 * Starts the move job under the site lock.
	 *
	 * @param Services   $services Services.
	 * @param StorageDir $storage  Storage root with the current setting.
	 * @param string     $target   The folder ('' = the default one).
	 * @return string|null The job id, or null after an error was printed.
	 */
	private static function start( Services $services, StorageDir $storage, string $target ): ?string {
		try {
			return MoveJobs::create( $services->jobs(), $services->lock(), $storage, FolderContext::rules( $storage ), $target, time() )->id;
		} catch ( FolderRefusedException $e ) {
			Console::error( FolderMessages::of( $e->reason, $storage->ownerName() ) );
		} catch ( SiteBusyException $e ) {
			Console::error( SiteBusyException::describe( $e->expiresAt ) );
		} catch ( \RuntimeException $e ) { // The job store or the setting row could not be written.
			Console::error( $e->getMessage() );
		}

		return null;
	}
}
