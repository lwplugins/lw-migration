<?php
/**
 * Backups over REST: list, label, delete, browse, download links.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupKind;
use LightweightPlugins\Migration\Backups\BackupOrigin;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\DownloadToken;
use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Backups\LiveLinks;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Schedules\Repository as Schedules;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\MoveJobs;

defined( 'ABSPATH' ) || exit;

/**
 * Backups are named by folder kind and file name only, never by path; a list
 * item carries only whitelisted fields. A backup an unfinished restore reads
 * is not deleted, and nothing is deleted while the backups are moved.
 * Browsing pages through the entry headers (no data is read), from a header
 * offset the previous page returned. An item says where the backup was
 * uploaded (CloudCopies, spec 21.6), and from its package.json whether it
 * is encrypted and which other site made it (BackupOrigin), what made it
 * (BackupKind) and how many of its transfer links still open (LiveLinks). The
 * listing adds the storage strip (BackupStorage) from the same scan. A
 * download link carries a download token only (never the REST nonce),
 * issued for the logged-in user.
 */
final class BackupsApi {

	private const ENTRY_LIMIT = 200;

	private const ENTRY_MAX = 500;

	/**
	 * Issues download tokens.
	 *
	 * @var DownloadToken
	 */
	private DownloadToken $tokens;

	/**
	 * @param Repository         $backups Backups.
	 * @param JobStore           $jobs    Jobs (a restore may read a backup).
	 * @param DownloadToken|null $tokens  Download tokens (default: the real clock).
	 */
	public function __construct( private Repository $backups, private JobStore $jobs, ?DownloadToken $tokens = null ) {
		$this->tokens = $tokens ?? new DownloadToken();
	}

	/**
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public function all(): array {
		return $this->items( $this->backups->all() );
	}

	/**
	 * The list and the storage strip from one scan of the folders.
	 *
	 * @param int|null $free Free bytes where our backups are kept (null = not known).
	 * @return array{items: array<int, array<string, mixed>>, storage: array<string, mixed>}
	 */
	public function listing( ?int $free ): array {
		$backups = $this->backups->all();

		return [
			'items'   => $this->items( $backups ),
			'storage' => BackupStorage::of( $backups, $free, Retention::fromOption( get_option( Options::RETENTION, [] ) ), ( new Schedules() )->existing() ),
		];
	}

	/**
	 * @param array<string, mixed> $params {folder, name}.
	 * @throws RestException 404 when there is no such backup.
	 */
	public function find( array $params ): Backup {
		$name   = is_string( $params['name'] ?? null ) ? $params['name'] : '';
		$folder = is_string( $params['folder'] ?? null ) ? $params['folder'] : '';
		$backup = $this->backups->findIn( $name, $folder );
		if ( null === $backup ) {
			throw RestException::backupMissing();
		}

		return $backup;
	}

	/**
	 * @param array<string, mixed> $body {folder, name, label}; an empty label removes it.
	 * @return array<string, mixed> The item with the stored (sanitized) label.
	 * @throws RestException On a missing backup or a label that is not text.
	 */
	public function label( array $body ): array {
		$backup = $this->find( $body );
		$label  = $body['label'] ?? '';
		if ( ! is_string( $label ) ) {
			throw RestException::invalidField( 'label' );
		}
		try {
			Labels::set( $backup->name, $label );
		} catch ( \InvalidArgumentException $e ) {
			throw RestException::invalidField( 'name' );
		}

		$links = LiveLinks::count( [ $backup ], ( new Shares() )->all(), time() );

		return self::item( $backup, Labels::get( $backup->name ), self::copies( CloudCopies::of( $backup->name ), self::knownDestinations() ), self::home(), $links );
	}

	/**
	 * @param array<string, mixed> $params {folder, name}.
	 * @throws RestException On a missing backup, one a restore reads, while backups are moved, or on a failed delete.
	 */
	public function delete( array $params ): void {
		$backup = $this->find( $params );
		if ( MoveJobs::active( $this->jobs, time() ) ) {
			throw new RestException( 'lw_migration_backup_in_use', self::movingText(), 409 );
		}
		if ( ActiveJob::readsBackup( $this->jobs, $backup->name, $backup->source ) ) {
			throw new RestException( 'lw_migration_backup_in_use', __( 'A restore is reading this backup. Delete it when the restore has finished.', 'lw-migration' ), 409 );
		}
		if ( ! $this->backups->deleteIn( $backup->name, $backup->source ) ) {
			throw new RestException( 'lw_migration_delete_failed', __( 'The backup could not be deleted. Check the permissions of its folder.', 'lw-migration' ), 500 );
		}
		if ( null === $this->backups->find( $backup->name ) ) {
			Labels::remove( $backup->name );
		}
	}

	/** Why a delete waits while the backups are moved (shared with WP-CLI). */
	public static function movingText(): string {
		return __( 'The backups are being moved. Delete this one when the move has finished.', 'lw-migration' );
	}

	/**
	 * @param array<string, mixed> $params {folder, name, after?, limit?}.
	 * @return array{entries: array<int, array{name: string, size: int, mtime: int}>, next: int|null, encrypted: bool}
	 * @throws RestException On a missing backup, a bad page request or an unreadable archive.
	 */
	public function entries( array $params ): array {
		$backup = $this->find( $params );
		$after  = JobInput::wholeNumber( $params['after'] ?? 0, 'after' );
		$limit  = min( self::ENTRY_MAX, max( 1, JobInput::wholeNumber( $params['limit'] ?? self::ENTRY_LIMIT, 'limit' ) ) );
		$list   = [];
		$next   = null;
		try {
			$reader = new Reader( $backup->path );
			try {
				foreach ( $reader->entries( $after ) as $entry ) {
					if ( count( $list ) === $limit ) {
						$next = $entry->headerOffset;
						break;
					}
					$list[] = [
						'name'  => $entry->name,
						'size'  => $entry->size,
						'mtime' => $entry->mtime,
					];
				}
			} finally {
				$reader->close();
			}
		} catch ( ArchiveException $e ) {
			throw RestException::notReadable();
		}

		return [
			'entries'   => $list,
			'next'      => $next,
			'encrypted' => self::encrypted( $backup->path ),
		];
	}

	/**
	 * A download link for one backup, for the logged-in user in this login
	 * session. The link carries only the token; the download route also needs
	 * the same user's login cookie.
	 *
	 * @param array<string, mixed> $body        {folder, name}.
	 * @param string               $downloadUrl Address of the download route (no query needed).
	 * @return array{url: string, expires: int} The link and the Unix time it stops working.
	 * @throws RestException On a missing backup (404) or without a login session (403).
	 */
	public function downloadToken( array $body, string $downloadUrl ): array {
		$backup = $this->find( $body );
		try {
			$granted = $this->tokens->grant( get_current_user_id(), $backup->source, $backup->name );
		} catch ( \InvalidArgumentException $e ) {
			throw new RestException( 'lw_migration_download_refused', __( 'Downloads need a login in this browser. Log in to the dashboard and try again.', 'lw-migration' ), 403 );
		}

		return [
			'url'     => add_query_arg( 'token', $granted['token'], $downloadUrl ),
			'expires' => $granted['expires'],
		];
	}

	/**
	 * @param array<int, Backup> $backups Listed backups.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( array $backups ): array {
		$labels = Labels::all();
		$copies = CloudCopies::all();
		$known  = self::knownDestinations();
		$home   = self::home();
		$links  = LiveLinks::count( $backups, ( new Shares() )->all(), time() );

		return array_map( static fn( Backup $backup ): array => self::item( $backup, $labels[ $backup->name ] ?? '', self::copies( $copies[ $backup->name ] ?? [], $known ), $home, $links ), $backups );
	}

	/**
	 * @return array<string, true> Ids of the stored destination profiles.
	 */
	private static function knownDestinations(): array {
		return array_fill_keys( array_map( static fn( Profile $p ): string => $p->id, ( new ProfileRepository() )->all() ), true );
	}

	/**
	 * Where a backup was uploaded, for the destinations that still exist (a
	 * deleted profile's record is not shown). Ids stay strings: an all-digit
	 * id became an integer key on the way through the array.
	 *
	 * @param array<array-key, array{status: string, at: int}> $copies Its CloudCopies row.
	 * @param array<array-key, true>                           $known  Stored profile ids.
	 * @return array<int, array{id: string, status: string, at: int}>
	 */
	private static function copies( array $copies, array $known ): array {
		$list = [];
		foreach ( $copies as $id => $copy ) {
			if ( isset( $known[ (string) $id ] ) ) {
				$list[] = [
					'id'     => (string) $id,
					'status' => $copy['status'],
					'at'     => $copy['at'],
				];
			}
		}

		return $list;
	}

	/**
	 * @param Backup                                                 $backup The backup.
	 * @param string                                                 $label  Its label ('' = none).
	 * @param array<int, array{id: string, status: string, at: int}> $cloud  Where it was uploaded (copies()).
	 * @param string                                                 $home   This site's home address (home()).
	 * @param array<string, int>                                     $links  Live transfer links per id (LiveLinks).
	 * @return array<string, mixed>
	 */
	private static function item( Backup $backup, string $label, array $cloud, string $home, array $links ): array {
		$id = $backup->source . ':' . $backup->name;

		return [
			'id'          => $id,
			'name'        => $backup->name,
			'folder'      => $backup->source,
			'size'        => $backup->size,
			'mtime'       => $backup->mtime,
			'label'       => $label,
			'safety'      => BackupName::isSafety( $backup->name ),
			'beforeReset' => BackupName::isPreReset( $backup->name ),
			'kind'        => BackupKind::of( $backup ),
			'cloud'       => $cloud,
			'links'       => $links[ $id ] ?? 0,
		] + BackupOrigin::read( $backup->path, $home );
	}

	/** This site's home address; on a network the main site's (the backups are the network's). */
	private static function home(): string {
		return is_multisite() ? network_home_url() : home_url();
	}

	/**
	 * Whether package.json says the archive is encrypted; the entry list was
	 * already read, so an unreadable package only means "not known to be".
	 *
	 * @param string $path Archive.
	 */
	private static function encrypted( string $path ): bool {
		try {
			return PackageCodec::read( $path )->isEncrypted();
		} catch ( ArchiveException $e ) {
			return false;
		}
	}
}
