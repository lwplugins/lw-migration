<?php
/**
 * The plugin's protected folder for jobs and backups.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * Lives at wp-content/lw-migration-<12 hex>/{jobs,backups}. The random name is the
 * protection on servers that ignore .htaccess; the deny files cover the rest.
 * The backups go to the folder chosen in Settings (BackupDir) when there is one;
 * that folder is never created here, only checked (ensureBackups()).
 */
final class StorageDir {

	public const NAME_PATTERN = '/^lw-migration-[a-f0-9]{12}\z/';

	private const HTACCESS = "# Deny all web access.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n";

	private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n<system.webServer>\n<security>\n<authorization>\n<remove users=\"*\" roles=\"\" verbs=\"\" />\n<add accessType=\"Deny\" users=\"*\" />\n</authorization>\n<requestFiltering>\n<fileExtensions allowUnlisted=\"false\" />\n</requestFiltering>\n</security>\n</system.webServer>\n</configuration>\n";

	private const INDEX = "<?php\n// Silence is golden.\n";

	private const ROBOTS = "User-agent: *\nDisallow: /\n";

	/**
	 * Absolute path of the storage root, without a trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The backups folder setting this instance was built with.
	 *
	 * @var BackupDir
	 */
	private BackupDir $setting;

	/**
	 * @param string         $root    Storage root.
	 * @param BackupDir|null $setting Backups folder setting; null = the default folder.
	 */
	public function __construct( string $root, ?BackupDir $setting = null ) {
		$this->root    = rtrim( $root, '/' );
		$this->setting = $setting ?? new BackupDir();
	}

	/**
	 * The stored folder; on first use adopts an existing folder or picks a random
	 * name. Concurrent first calls converge on whichever name reached the row first.
	 *
	 * @throws StorageException When the name cannot be stored.
	 */
	public static function fromWordPress(): self {
		$row  = new OptionRow( Options::STORAGE_DIR );
		$name = $row->read();
		if ( null === $name || ! self::isValidName( $name ) ) {
			$candidate = self::adoptable( WP_CONTENT_DIR ) ?? 'lw-migration-' . bin2hex( random_bytes( 6 ) );
			if ( null === $name ) {
				$row->insert( $candidate );
			} else {
				$row->swap( $name, $candidate );
			}
			$name = $row->read();
			if ( null === $name || ! self::isValidName( $name ) ) {
				throw new StorageException( 'Cannot save the name of the storage folder in the options table.' );
			}
		}

		return new self( WP_CONTENT_DIR . '/' . $name, BackupDir::read() );
	}

	/**
	 * The stored folder, read only: null when no valid name is stored (never
	 * names or repairs one, unlike fromWordPress()).
	 */
	public static function stored(): ?self {
		$name = ( new OptionRow( Options::STORAGE_DIR ) )->read();

		return null === $name || ! self::isValidName( $name ) ? null : new self( WP_CONTENT_DIR . '/' . $name, BackupDir::read() );
	}

	public function root(): string {
		return $this->root;
	}

	public function jobsDir(): string {
		return $this->root . '/jobs';
	}

	public function setting(): BackupDir {
		return $this->setting;
	}

	/** Where backups are written now: the chosen folder, or the default one. */
	public function backupsDir(): string {
		return $this->resolve( $this->setting->path );
	}

	/** The backups folder inside the storage root. */
	public function defaultBackupsDir(): string {
		return $this->root . '/backups';
	}

	/**
	 * The target of an unfinished move, or null.
	 */
	public function movingDir(): ?string {
		return null === $this->setting->moving ? null : $this->resolve( $this->setting->moving );
	}

	/**
	 * @param string $folder A stored folder ('' = the default one).
	 */
	public function resolve( string $folder ): string {
		return '' === $folder ? $this->defaultBackupsDir() : $folder;
	}

	/**
	 * The current backups folder and, during an unfinished move, its target.
	 *
	 * @return array<int, string>
	 */
	public function backupDirs(): array {
		return array_values( array_unique( array_filter( [ $this->backupsDir(), (string) $this->movingDir() ], static fn( string $dir ): bool => '' !== $dir ) ) );
	}

	/**
	 * Makes the backups folder ready for a write: the default one is created
	 * and protected (ensure()); a chosen one must be there and still ours. It
	 * is never created here: a missing folder may be a disk that is not attached.
	 *
	 * @throws FolderRefusedException When the chosen folder cannot be used (ensure() throws a StorageException for the default one).
	 */
	public function ensureBackups(): void {
		if ( '' === $this->setting->path ) {
			$this->ensure();
			return;
		}
		$problem = BackupDirRules::inUse( $this->setting->path, $this->setting->marker, $this->owners() );
		if ( null !== $problem ) {
			throw new FolderRefusedException( $problem );
		}
	}

	/**
	 * Creates the folders (the default backups folder among them) and (re)writes their deny files.
	 *
	 * @throws StorageException When a folder or file cannot be written.
	 */
	public function ensure(): void {
		foreach ( [ $this->root, $this->jobsDir(), $this->defaultBackupsDir() ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				if ( file_exists( $dir ) || is_link( $dir ) ) {
					throw new StorageException( sprintf( 'Cannot create the folder %s: a file with this name is in the way.', $dir ) );
				}
				$parent = dirname( $dir );
				if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
					throw new StorageException( sprintf( 'Cannot create the folder %s. Check that %s exists and is writable.', $dir, $parent ) );
				}
				$mode = Files::PRIVATE_DIR;
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent request may create it after the checks above; is_dir() below decides.
				if ( @mkdir( $dir, $mode ) ) {
					chmod( $dir, $mode );
				} elseif ( ! is_dir( $dir ) ) {
					throw new StorageException( sprintf( 'Cannot create the folder %s. Check the permissions of %s.', $dir, $parent ) );
				}
			}
			self::protect( $dir );
		}
	}

	/**
	 * Writes the deny files of a folder in our storage root, rewriting one
	 * that differs (the folder and its files are ours).
	 *
	 * @param string $dir    Folder to protect.
	 * @param bool   $robots Whether robots.txt is written too.
	 * @throws StorageException When a file cannot be written.
	 */
	public static function protect( string $dir, bool $robots = false ): void {
		foreach ( self::denyFiles( $robots ) as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && file_get_contents( $path ) === $contents ) {
				continue;
			}
			$writable = is_file( $path ) ? is_writable( $path ) : ! file_exists( $path ) && ! is_link( $path ) && is_writable( $dir );
			if ( ! $writable || false === file_put_contents( $path, $contents ) ) {
				throw new StorageException( sprintf( 'Cannot write %s. Check that it is a writable file.', $path ) );
			}
		}
	}

	/**
	 * Deny files of a chosen folder (robots.txt included): each is only ever
	 * created (ExclusiveFile: never over an existing name, never through a
	 * link). One already there with our exact content is fine; anything else
	 * under the name is left alone and named in the result.
	 *
	 * @param string $dir     Chosen folder (canonical).
	 * @return array<int, string> Names left alone.
	 * @throws FolderRefusedException NOT_WRITABLE, or LINK/CHANGED when the folder is not itself.
	 */
	public static function protectNew( string $dir ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$id = @realpath( $dir ) === $dir ? FolderIdentity::of( $dir ) : null;
		if ( null === $id ) {
			throw new FolderRefusedException( FolderRefusedException::LINK );
		}

		return MarkedFolder::protect( $dir, $id );
	}

	/**
	 * Who may own a chosen folder: the owner of the storage root (the web
	 * server's user, first) and the PHP user of this process (WP-CLI may run
	 * as another user). Without POSIX functions the PHP user is the owner of a
	 * file made in the storage root just now.
	 *
	 * @return array<int, int>
	 */
	public function owners(): array {
		clearstatcache();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing root has no owner yet.
		$owners   = [ @fileowner( $this->root ) ];
		$owners[] = function_exists( 'posix_geteuid' ) ? posix_geteuid() : self::probeOwner( $this->root );

		return array_values( array_unique( array_filter( $owners, 'is_int' ) ) );
	}

	/**
	 * The name of the storage root's owner (the web server's user), for the
	 * "run this as" hint; '' when it is not known.
	 */
	public function ownerName(): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing root has no owner yet.
		$uid = @fileowner( $this->root );
		if ( false === $uid ) {
			return '';
		}
		$user = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;

		return is_array( $user ) ? $user['name'] : '#' . $uid;
	}

	/**
	 * @param string $dir Folder to make a file in.
	 * @return int|null The owner a new file gets here, or null.
	 */
	private static function probeOwner( string $dir ): ?int {
		$path = $dir . '/' . TempName::name();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' mode; a failure means unknown.
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			return null;
		}
		$stat = fstat( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		Files::removeFile( $path );

		return false === $stat ? null : (int) $stat['uid'];
	}

	/**
	 * Removes the deny files (robots.txt included) that are ours: a regular
	 * file, not a link, with our exact content. Anything else stays.
	 *
	 * @param string $dir Folder.
	 * @return void
	 */
	public static function unprotect( string $dir ): void {
		foreach ( self::denyFiles( true ) as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( self::isOurFile( $path, $contents ) ) {
				Files::removeFile( $path );
			}
		}
	}

	/**
	 * @return array<string, string> The deny files of a chosen folder: file name => content.
	 */
	public static function chosenDenyFiles(): array {
		return self::denyFiles( true );
	}

	/**
	 * @param bool $robots Whether robots.txt is included.
	 * @return array<string, string> File name => content.
	 */
	private static function denyFiles( bool $robots ): array {
		$files = [
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
			'index.php'  => self::INDEX,
			'index.html' => '',
		];

		return $robots ? $files + [ 'robots.txt' => self::ROBOTS ] : $files;
	}

	/**
	 * A regular file (not a link) whose content is exactly $contents.
	 *
	 * @param string $path     File.
	 * @param string $contents Our content.
	 */
	public static function isOurFile( string $path, string $contents ): bool {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( @is_link( $path ) || ! @is_file( $path ) || @filesize( $path ) !== strlen( $contents ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return @file_get_contents( $path ) === $contents;
	}

	/**
	 * @param string $name Folder name.
	 */
	private static function isValidName( string $name ): bool {
		return 1 === preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * An existing storage folder to reuse when the option is gone: the only one,
	 * or the one whose backups folder changed last.
	 *
	 * @param string $content wp-content folder.
	 */
	private static function adoptable( string $content ): ?string {
		$best = null;
		$rank = null;
		foreach ( (array) glob( $content . '/lw-migration-*', GLOB_ONLYDIR ) as $dir ) {
			$name = basename( (string) $dir );
			if ( ! self::isValidName( $name ) || is_link( (string) $dir ) ) {
				continue;
			}
			$backups = $dir . '/backups';
			$score   = is_dir( $backups ) ? [ 1, (int) filemtime( $backups ) ] : [ 0, 0 ];
			if ( null === $rank || $score > $rank ) {
				$best = $name;
				$rank = $score;
			}
		}

		return $best;
	}
}
