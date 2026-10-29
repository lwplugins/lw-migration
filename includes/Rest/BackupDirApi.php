<?php
/**
 * The backups folder over REST.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderContext;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\Steps\MoveStep;

defined( 'ABSPATH' ) || exit;

/**
 * A random folder name is a protection, so it is never sent: the default
 * folder is not shown at all, a folder inside a web root only as
 * <parent>/lw-migration-backups-…; a folder outside the web roots is shown as
 * the admin typed it. Finishing or undoing a move is asked for by action, so
 * the client never sends a masked path back. A change is a move job, driven
 * like every job (JobView, tick, cancel).
 */
final class BackupDirApi {

	private const ACTIONS = [ 'finish', 'undo' ];

	/**
	 * @param Services $services Services.
	 */
	public function __construct( private Services $services ) {
	}

	public static function fromServices( Services $services ): self {
		return new self( $services );
	}

	/**
	 * @return array{custom: string, hidden: bool, moving: array{to: string, toHidden: bool, left: int, leftBytes: int}|null, problem: string|null, problemText: string, editable: bool, supported: bool, running: bool}
	 */
	public function view(): array {
		return QuietOutput::run(
			function (): array {
				$storage = $this->services->storage();
				$setting = $storage->setting();
				$left    = null === $setting->moving ? null : MoveStep::count( $storage->backupsDir() );
				$problem = '' === $setting->path ? null : BackupDirRules::inUse( $setting->path, $setting->marker, $storage->owners() );

				return [
					'custom'      => self::display( $setting->path ),
					'hidden'      => BackupDir::hidden( $setting->path ),
					'moving'      => null === $left ? null : [
						'to'        => self::display( (string) $setting->moving ),
						'toHidden'  => BackupDir::hidden( (string) $setting->moving ),
						'left'      => $left['count'],
						'leftBytes' => $left['bytes'],
					],
					'problem'     => $problem,
					'problemText' => null === $problem ? '' : FolderMessages::of( $problem, $storage->ownerName() ),
					'editable'    => FolderContext::editable(),
					'supported'   => FolderContext::supported(),
					'running'     => MoveJobs::active( $this->services->jobs(), time() ),
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $body {path: string} ('' = the default folder) or {action: finish|undo}.
	 * @throws RestException 400 (refused body or folder) / 403 (locked); SiteBusyException → 409 and StorageException → 500 through ErrorMap.
	 */
	public function move( array $body ): Job {
		return QuietOutput::run(
			function () use ( $body ): Job {
				if ( ! FolderContext::editable() ) {
					throw new RestException( 'lw_migration_backup_dir_locked', FolderMessages::of( FolderRefusedException::LOCKED ), 403 );
				}
				$storage = $this->services->storage();
				$target  = self::target( $body, $storage->setting() );
				$storage->ensure();
				try {
					return MoveJobs::create( $this->services->jobs(), $this->services->lock(), $storage, FolderContext::rules( $storage ), $target, time() );
				} catch ( FolderRefusedException $e ) {
					throw RestException::invalid( FolderMessages::of( $e->reason, $storage->ownerName() ), 'path' );
				}
			}
		);
	}

	/**
	 * The folder as it may be shown ('' = the default one).
	 *
	 * @param string $path A stored folder.
	 */
	public static function display( string $path ): string {
		return BackupDir::display( $path );
	}

	/**
	 * @param array<string, mixed> $body    Request body.
	 * @param BackupDir            $setting Current setting.
	 * @return string The folder to move to ('' = the default one).
	 * @throws RestException 400 for a body that is neither form, or an action without an unfinished move.
	 */
	private static function target( array $body, BackupDir $setting ): string {
		if ( [ 'path' ] === array_keys( $body ) && is_string( $body['path'] ) && strlen( $body['path'] ) <= BackupDirRules::MAX_BYTES ) {
			return $body['path'];
		}
		if ( [ 'action' ] === array_keys( $body ) && in_array( $body['action'], self::ACTIONS, true ) ) {
			if ( null === $setting->moving ) {
				throw RestException::invalid( FolderMessages::of( FolderRefusedException::NOTHING_PENDING ), 'path' );
			}

			return 'finish' === $body['action'] ? $setting->moving : $setting->path;
		}
		throw RestException::invalidField( 'path' );
	}
}
