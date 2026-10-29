<?php
/**
 * What must hold before a reset may start.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\WpConnection;

defined( 'ABSPATH' ) || exit;

/**
 * Checked before the password and before the job exists, in this order: a
 * network allows only plugins, themes and media (D2); a database reset needs
 * the plain MySQL setup it rebuilds (I2) and an address to type as its
 * confirmation; plugins and themes need file
 * changes allowed and their folder writable; media needs a writable uploads
 * folder that holds no core folder, compared by identity so another spelling
 * (a link, a different case) of the same folder is caught too. A missing
 * uploads folder needs no cleaning. A file reset (plugins, themes, media,
 * full) never runs as root or as an unknown user (RootGuard); the database
 * reset may.
 */
final class Preflight {

	/**
	 * @param Site           $site            Site snapshot.
	 * @param bool           $network         Whether this is a multisite network.
	 * @param \Closure       $fileModsAllowed fn(): bool.
	 * @param \Closure       $writable        fn( string $dir ): bool.
	 * @param \Closure|null  $databaseSetup   fn(): ?string — 'sqlite', 'drop_in', 'custom_users', 'core_view:<table>' or null.
	 * @param RootGuard|null $root           Null = this process.
	 * @param \Closure|null  $ownerName      fn(): string, the site owner's user name for the root refusal ('' = unknown).
	 */
	public function __construct(
		private Site $site,
		private bool $network,
		private \Closure $fileModsAllowed,
		private \Closure $writable,
		private ?\Closure $databaseSetup = null,
		private ?RootGuard $root = null,
		private ?\Closure $ownerName = null
	) {
	}

	public static function fromWordPress( Site $site ): self {
		return new self(
			$site,
			is_multisite(),
			static fn(): bool => wp_is_file_mod_allowed( 'lw_migration_reset' ),
			static fn( string $dir ): bool => is_dir( $dir ) && wp_is_writable( $dir ),
			static fn(): ?string => self::databaseSetupOf( $site ),
			RootGuard::current(),
			static fn(): string => self::ownerNameOf( $site->contentDir )
		);
	}

	/**
	 * @param string $type One of ResetType::ALL.
	 * @return string|null A ResetRefused reason, or null when the reset may start.
	 * @throws \InvalidArgumentException For an unknown type.
	 */
	public function reason( string $type ): ?string {
		return $this->refusal( $type )[0];
	}

	/**
	 * @param string $type One of ResetType::ALL.
	 * @return void
	 * @throws ResetRefused With the reason and its text (an unknown type: \InvalidArgumentException).
	 */
	public function assert( string $type ): void {
		[ $reason, $detail ] = $this->refusal( $type );
		if ( null !== $reason ) {
			throw new ResetRefused( $reason, ResetTexts::refusal( $reason, 0, $detail ) );
		}
	}

	/**
	 * @param string $type One of ResetType::ALL.
	 * @return array{0: string|null, 1: string} The reason (null = none) and its detail.
	 * @throws \InvalidArgumentException For an unknown type.
	 */
	private function refusal( string $type ): array {
		if ( ! ResetType::isValid( $type ) ) {
			throw new \InvalidArgumentException( __( 'Choose what to reset.', 'lw-migration' ) );
		}
		if ( $this->network && ! ResetType::allowedOnNetwork( $type ) ) {
			return [ ResetRefused::NETWORK, '' ];
		}
		if ( ( ResetType::touchesCode( $type ) || ResetType::touchesMedia( $type ) ) && ( $this->root ?? RootGuard::current() )->isRoot() ) {
			return [ ResetRefused::AS_ROOT, null === $this->ownerName ? '' : ( $this->ownerName )() ];
		}
		if ( ResetType::touchesDatabase( $type ) && '' === ConfirmPhrase::of( $this->site->homeUrl ) ) {
			return [ ResetRefused::NO_ADDRESS, '' ]; // An empty phrase would confirm nothing.
		}
		if ( ResetType::touchesDatabase( $type ) && ResetTables::unsafePrefix( $this->site->tablePrefix ) ) {
			return [ ResetRefused::DATABASE_SETUP, 'table_prefix' ];
		}
		if ( ResetType::touchesDatabase( $type ) && null !== $this->databaseSetup ) {
			$setup = ( $this->databaseSetup )();
			if ( null !== $setup ) {
				return [ ResetRefused::DATABASE_SETUP, (string) $setup ];
			}
		}
		if ( ResetType::touchesCode( $type ) ) {
			if ( ! ( $this->fileModsAllowed )() ) {
				return [ ResetRefused::FILE_MODS, '' ];
			}
			foreach ( $this->codeFolders( $type ) as $dir ) {
				if ( ! ( $this->writable )( $dir ) ) {
					return [ ResetRefused::NOT_WRITABLE, '' ];
				}
			}
		}

		return [ ResetType::touchesMedia( $type ) ? $this->uploadsReason() : null, '' ];
	}

	/**
	 * The user name that owns the content folder, for the root refusal's
	 * example command; '' when unknown, root, or not a plain user name.
	 *
	 * @param string $contentDir The content folder.
	 */
	private static function ownerNameOf( string $contentDir ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$uid  = @fileowner( $contentDir );
		$user = false !== $uid && 0 !== $uid && function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;
		$name = is_array( $user ) ? (string) $user['name'] : '';

		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_.-]{0,31}\z/', $name ) ? $name : '';
	}

	/**
	 * @param string $type A type that touches code.
	 * @return array<int, string> The folders the type changes.
	 */
	private function codeFolders( string $type ): array {
		$folders = [
			ResetType::PLUGINS => [ $this->site->pluginsDir ],
			ResetType::THEMES  => [ $this->site->themeRoot ],
		];

		return $folders[ $type ] ?? [ $this->site->pluginsDir, $this->site->themeRoot ];
	}

	private function uploadsReason(): ?string {
		$uploads = FolderIdentity::of( $this->site->uploadsDir );
		if ( null === $uploads ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			$present = @file_exists( $this->site->uploadsDir ) || @is_link( $this->site->uploadsDir );

			return $present ? ResetRefused::UPLOADS_UNSAFE : null; // Something that is no folder we can compare, or nothing to clean.
		}
		if ( ! ( $this->writable )( $this->site->uploadsDir ) ) {
			return ResetRefused::NOT_WRITABLE;
		}

		return $this->holdsCore( $uploads ) ? ResetRefused::UPLOADS_UNSAFE : null;
	}

	/**
	 * Whether a core folder is the uploads folder or lies under it.
	 *
	 * @param string $uploads Identity of the uploads folder.
	 */
	private function holdsCore( string $uploads ): bool {
		$core = [ $this->site->abspath, $this->site->contentDir, $this->site->pluginsDir, $this->site->muPluginsDir, $this->site->themeRoot ];
		foreach ( array_filter( $core, static fn( string $dir ): bool => '' !== $dir ) as $dir ) {
			foreach ( FolderIdentity::chain( $dir ) as $link ) {
				if ( $link['id'] === $uploads ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The database setup a reset cannot rebuild, or null for plain MySQL.
	 *
	 * @param Site $site Site snapshot.
	 * @return string|null 'sqlite', 'drop_in', 'custom_users', 'core_view:<table>' or null.
	 */
	private static function databaseSetupOf( Site $site ): ?string {
		if ( '' !== $site->sqliteDb ) {
			return 'sqlite';
		}
		try {
			$connection = WpConnection::fromWpdb();
		} catch ( DatabaseException ) {
			return 'drop_in';
		}
		if ( defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ) ) {
			return 'custom_users';
		}
		global $wpdb;
		$views  = ( new TableLister( $connection ) )->list( [ $site->tablePrefix ] )['views'];
		$folded = (int) $connection->variable( 'lower_case_table_names' ) >= 1;

		return self::coreView( $wpdb->tables( 'all', false ), $views, $site->tablePrefix, $folded );
	}

	/**
	 * The first view that stands where a core table belongs, compared the way
	 * the server compares table names (case-insensitively when it folds them).
	 *
	 * @param array<int, string> $suffixes Core table names without the prefix.
	 * @param array<int, string> $views    Views with the prefix.
	 * @param string             $prefix   Table prefix.
	 * @param bool               $folded   Whether lower_case_table_names is 1 or 2.
	 * @return string|null 'core_view:<view>' or null.
	 */
	public static function coreView( array $suffixes, array $views, string $prefix, bool $folded ): ?string {
		$key = static fn( string $name ): string => $folded ? strtolower( $name ) : $name;
		foreach ( $views as $view ) {
			foreach ( $suffixes as $suffix ) {
				if ( $key( $view ) === $key( $prefix . $suffix ) ) {
					return 'core_view:' . $view;
				}
			}
		}

		return null;
	}
}
