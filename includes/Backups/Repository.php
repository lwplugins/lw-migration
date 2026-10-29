<?php
/**
 * Backups on disk.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Storage\StorageDir;

defined( 'ABSPATH' ) || exit;

/**
 * Our backups folder plus the backups folder of the other .wpress tool, so
 * existing backups can be restored. Only regular files with a valid name;
 * a symlinked file is never a backup, nor is an empty file. While a move of
 * the backups is unfinished, both of our folders are ours (the current one
 * first; a name in both counts once and a delete removes it from both).
 * A chosen backups folder is stored canonical and never a link
 * (Storage\BackupDirRules); the default folder may itself be a link (deploy
 * setups share one) — unchanged. Folders are compared by identity
 * (device and inode), never by their spelling.
 */
final class Repository {

	/** Backups folder of existing .wpress tooling, relative to wp-content. */
	public const EXTERNAL_DIR = 'ai1wm-backups';

	/**
	 * @param string      $ownDir      Our current backups folder.
	 * @param string      $externalDir Backups folder of existing .wpress tooling ('' = none).
	 * @param string      $movingDir   Target of an unfinished move ('' = none).
	 * @param Shares|null $shares Transfer links; the links of a deleted backup are revoked (null = none).
	 */
	public function __construct( private string $ownDir, private string $externalDir, private string $movingDir = '', private ?Shares $shares = null ) {
	}

	public static function fromWordPress( StorageDir $storage ): self {
		return new self( $storage->backupsDir(), WP_CONTENT_DIR . '/' . self::EXTERNAL_DIR, (string) $storage->movingDir(), new Shares() );
	}

	/**
	 * @return array<int, Backup> Newest first.
	 */
	public function all(): array {
		$all  = [];
		$seen = [];
		foreach ( $this->dirs( Backup::OWN ) as $dir ) {
			foreach ( $this->scan( $dir, Backup::OWN ) as $backup ) {
				if ( ! isset( $seen[ $backup->name ] ) ) {
					$seen[ $backup->name ] = true;
					$all[]                 = $backup;
				}
			}
		}
		foreach ( $this->dirs( Backup::EXTERNAL ) as $dir ) {
			$all = array_merge( $all, $this->scan( $dir, Backup::EXTERNAL ) );
		}
		usort( $all, static fn( Backup $a, Backup $b ): int => [ $b->mtime, $a->name ] <=> [ $a->mtime, $b->name ] );

		return $all;
	}

	public function find( string $name ): ?Backup {
		return $this->findIn( $name, Backup::OWN ) ?? $this->findIn( $name, Backup::EXTERNAL );
	}

	/**
	 * A backup in one folder only (the browser names the folder; the CLI uses find()).
	 *
	 * @param string $name   Backup file name.
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 */
	public function findIn( string $name, string $folder ): ?Backup {
		if ( ! BackupName::isValid( $name ) ) {
			return null;
		}
		foreach ( $this->dirs( $folder ) as $dir ) {
			$backup = $this->entry( $dir, $name, $folder );
			if ( null !== $backup ) {
				return $backup;
			}
		}

		return null;
	}

	public function delete( string $name ): bool {
		$backup = $this->find( $name );

		return null !== $backup && $this->deleteIn( $name, $backup->source );
	}

	/**
	 * Removes the name from every folder of the source: a copy an interrupted
	 * move left in our other folder would otherwise show up again.
	 *
	 * @param string $name   Backup file name.
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 */
	public function deleteIn( string $name, string $folder ): bool {
		$deleted = false;
		foreach ( BackupName::isValid( $name ) ? $this->dirs( $folder ) : [] as $dir ) {
			$deleted = $this->remove( $this->entry( $dir, $name, $folder ) ) || $deleted;
		}
		if ( $deleted ) {
			$this->shares?->revokeFor( $folder, $name ); // A file of the same name may appear later.
		}

		return $deleted;
	}

	private function remove( ?Backup $backup ): bool {
		// Checked first: a failed unlink would print a PHP warning with the absolute path.
		return null !== $backup && is_writable( dirname( $backup->path ) ) && unlink( $backup->path );
	}

	/**
	 * Folders of one source, the current one first; a folder that is one of
	 * ours by identity is never also the external one.
	 *
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 * @return array<int, string>
	 */
	private function dirs( string $folder ): array {
		$own = array_values( array_unique( array_filter( [ $this->ownDir, $this->movingDir ], static fn( string $d ): bool => '' !== $d ) ) );
		if ( Backup::OWN === $folder ) {
			$ids  = [];
			$kept = [];
			foreach ( $own as $dir ) {
				$id = FolderIdentity::of( $dir );
				if ( null === $id || ! isset( $ids[ $id ] ) ) {
					$kept[] = $dir;
				}
				if ( null !== $id ) {
					$ids[ $id ] = true;
				}
			}
			return $kept;
		}
		if ( Backup::EXTERNAL !== $folder || '' === $this->externalDir ) {
			return [];
		}
		$id = FolderIdentity::of( $this->externalDir );

		return null !== $id && isset( FolderIdentity::ids( $own )[ $id ] ) ? [] : [ $this->externalDir ];
	}

	/**
	 * @param string $dir    Folder.
	 * @param string $source Backup::OWN or Backup::EXTERNAL.
	 * @return array<int, Backup>
	 */
	private function scan( string $dir, string $source ): array {
		$names = is_dir( $dir ) && is_readable( $dir ) ? scandir( $dir ) : false;
		if ( false === $names ) {
			return [];
		}
		$found = [];
		foreach ( $names as $name ) {
			$backup = BackupName::isValid( (string) $name ) ? $this->entry( $dir, (string) $name, $source ) : null;
			if ( null !== $backup ) {
				$found[] = $backup;
			}
		}

		return $found;
	}

	private function entry( string $dir, string $name, string $source ): ?Backup {
		$path = rtrim( $dir, '/' ) . '/' . $name;
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$size = (int) filesize( $path );

		// An empty file is no backup: a name reserved by a store that died (BackupPublisher).
		return 0 === $size ? null : new Backup( $name, $path, $size, (int) filemtime( $path ), $source );
	}
}
