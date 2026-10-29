<?php
/**
 * Where each archive entry goes on this site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Routes: plugins/ → plugin folder, themes/ → theme root, uploads/ (by blog:
 * UploadRoutes) → uploads folder, mu-plugins/ → staging, the rest →
 * wp-content; a subsite restore into a network leaves the network's code alone
 * (SharedCode). Skip lists follow the established .wpress importer; unsafe
 * names, backup folders, our temp files, wp-config.php and this plugin's own
 * folder are never written — by name here, and by absolute target through
 * ProtectedTargets (the bases may be nested), as given and through the real
 * path of the target's folder (a linked folder already on the site).
 */
final class ContentRules {

	public const SKIP = 'skip';

	public const EXTRACT = 'extract';

	public const STAGE = 'stage';

	/** Skip reasons (SkipMessages words them). */
	public const UNSAFE = 'unsafe';

	public const DROPIN = 'dropin';

	public const HOSTING = 'hosting';

	public const GENERATED = 'generated';

	public const OWN = 'own';

	public const CONFIG = 'config';

	public const STORAGE = 'storage';

	public const PROTECTED_TARGET = 'protected';

	/** Base key of the private must-use plugins staging folder (not on the site). */
	public const STAGING_BASE = 'staging';

	/** Bases every route may point to. */
	private const BASES = [ 'content', 'plugins', 'themes', 'uploads', self::STAGING_BASE ];

	/** Top folders of the archive that are never files themselves. */
	private const ROOTS = [ 'plugins', 'themes', 'uploads', 'mu-plugins' ];

	/**
	 * Absolute targets that are never written.
	 *
	 * @var ProtectedTargets
	 */
	private ProtectedTargets $targets;

	/** Must-use plugins (files or folders) of hosting companies: they break or hijack another server. */
	private const HOSTING_MU_PLUGINS = [
		'endurance-page-cache.php',
		'endurance-php-edge.php',
		'endurance-browser-cache.php',
		'gd-system-plugin.php',
		'wp-stack-cache.php',
		'wpcomsh-loader.php',
		'wpcomsh',
		'mu-plugin.php',
		'wpe-wp-sign-on-plugin.php',
		'wpengine-security-auditor.php',
		'aaa-wp-cerber.php',
		'sqlite-database-integration',
		'0-sqlite.php',
		'eos-deactivate-plugins.php',
	];

	/** Generated or server-specific paths that are never restored. */
	private const SKIP_PATHS = [
		'themes/functions.php',
		'uploads/elementor/css',
		'uploads/bb-plugin/cache',
		'uploads/civicrm/civicrm.settings.php',
		'uploads/civicrm/templates_c',
		'uploads/civicrm/ConfigAndLog',
		'uploads/civicrm/upload',
		'uploads/civicrm/dynamic',
	];

	private const SKIP_SUFFIXES = [ '.less.cache', '.sqlite' ];

	/** Holds the database credentials of the old server. */
	private const WP_CONFIG = 'wp-config.php';

	/**
	 * @param array<string, string> $bases   content, plugins, themes, uploads, staging.
	 * @param array<int, string>    $dropins Drop-in file names (wp-content root).
	 * @param UploadRoutes          $uploads Where uploads entries go below the uploads folder.
	 * @param ProtectedTargets      $targets What the site must keep (ProtectedTargets::forSite()).
	 * @param SharedCode|null       $shared  The network's code a subsite restore keeps (null: none).
	 * @throws \InvalidArgumentException When a base is missing.
	 */
	public function __construct( private array $bases, private array $dropins, private UploadRoutes $uploads, ProtectedTargets $targets, private ?SharedCode $shared = null ) {
		foreach ( self::BASES as $key ) {
			if ( '' === (string) ( $bases[ $key ] ?? '' ) ) {
				throw new \InvalidArgumentException( sprintf( 'Missing extraction base: %s', $key ) );
			}
		}
		$this->dropins = array_map( 'strtolower', $dropins );
		$content       = rtrim( $bases['content'], '/' ) . '/';
		$paths         = [];
		foreach ( $this->dropins as $dropin ) {
			$paths[ $content . $dropin ] = self::DROPIN;
		}
		$this->targets = $targets->with( $paths, array_values( $bases ) );
	}

	/**
	 * @return array<string, string> Base key => folder, as given.
	 */
	public function bases(): array {
		return $this->bases;
	}

	/**
	 * @param string $entry Entry name inside the archive.
	 * @return array{action: string, base?: string, rel?: string, reason?: string}
	 */
	public function route( string $entry ): array {
		$entry = str_ends_with( $entry, '/' ) ? null : self::normalise( $entry );
		if ( null === $entry || in_array( $entry, self::ROOTS, true ) ) {
			return self::skip( self::UNSAFE );
		}
		if ( Format::isConfigEntry( $entry ) || Format::DATABASE === $entry ) {
			return self::skip( '' );
		}
		$reason = $this->protectedReason( $entry ) ?? ( self::generated( $entry ) ? self::GENERATED : $this->shared?->reason( $entry ) );
		if ( null !== $reason ) {
			return self::skip( $reason );
		}
		if ( str_starts_with( $entry, 'mu-plugins/' ) ) {
			$rel = substr( $entry, strlen( 'mu-plugins/' ) );
			if ( in_array( strtolower( explode( '/', $rel )[0] ), self::HOSTING_MU_PLUGINS, true ) ) {
				return self::skip( self::HOSTING );
			}
			if ( ! PathGuard::isSafeEntryName( $rel ) ) {
				return self::skip( self::UNSAFE );
			}
			return [
				'action' => self::STAGE,
				'base'   => $this->bases[ self::STAGING_BASE ],
				'rel'    => $rel,
			];
		}
		if ( str_starts_with( $entry, 'plugins/' ) ) {
			return $this->to( 'plugins', substr( $entry, strlen( 'plugins/' ) ) );
		}
		if ( str_starts_with( $entry, 'themes/' ) ) {
			return $this->to( 'themes', substr( $entry, strlen( 'themes/' ) ) );
		}

		return $this->uploads( $entry ) ?? $this->to( 'content', $entry );
	}

	/**
	 * Drops empty and "." segments; refuses absolute names, "..", NUL bytes, backslashes
	 * and segments Windows would shorten or read as a stream (trailing dot or space, colon).
	 *
	 * @param string $entry Entry name.
	 * @return string|null Normalised name, or null when it is unsafe.
	 */
	private static function normalise( string $entry ): ?string {
		if ( str_starts_with( $entry, '/' ) || 1 === preg_match( '/^[a-zA-Z]:/', $entry ) ) {
			return null;
		}
		$segments = array_filter( explode( '/', $entry ), static fn( string $s ): bool => '' !== $s && '.' !== $s );
		foreach ( $segments as $segment ) {
			if ( str_contains( $segment, ':' ) || 1 === preg_match( '/[. ]\z/', $segment ) ) {
				return null;
			}
		}
		$name = implode( '/', $segments );

		return PathGuard::isSafeEntryName( $name ) ? $name : null;
	}

	/**
	 * Entries that would overwrite something the site must keep: wp-config.php,
	 * our temp files, backup and storage folders in wp-content, drop-ins.
	 *
	 * @param string $entry Normalised entry name.
	 */
	private function protectedReason( string $entry ): ?string {
		$basename = basename( $entry );
		if ( self::WP_CONFIG === strtolower( $basename ) ) {
			return self::CONFIG;
		}
		if ( TempName::is( strtolower( $basename ) ) ) {
			return self::STORAGE;
		}
		$first = strtolower( explode( '/', $entry )[0] );
		if ( in_array( $first, [ Repository::EXTERNAL_DIR, ExportRoots::LEGACY_DIR ], true ) || 1 === preg_match( StorageDir::NAME_PATTERN, $first ) || 1 === preg_match( BackupDirRules::SUFFIX_PATTERN, $first ) ) {
			return self::STORAGE;
		}
		if ( ! str_contains( $entry, '/' ) && in_array( strtolower( $entry ), $this->dropins, true ) ) {
			return self::DROPIN;
		}

		return null;
	}

	/**
	 * @param string $entry Normalised entry name.
	 * @return array{action: string, base?: string, rel?: string, reason?: string}|null
	 */
	private function uploads( string $entry ): ?array {
		$route = $this->uploads->route( $entry );
		$roots = array_map( static fn( array $p ): string => rtrim( $p[0], '/' ), $this->uploads->prefixes() );
		if ( false === $route || in_array( $entry, $roots, true ) ) {
			return self::skip( self::UNSAFE ); // A folder itself, or uploads no restored site owns.
		}
		if ( null === $route ) {
			return null;
		}

		return self::generated( 'uploads/' . substr( $route['rel'], strlen( $route['blog'] ) ) ) ? self::skip( self::GENERATED ) : $this->to( 'uploads', $route['rel'] );
	}

	/**
	 * @param string $entry Entry name relative to wp-content.
	 */
	private static function generated( string $entry ): bool {
		foreach ( self::SKIP_SUFFIXES as $suffix ) {
			if ( str_ends_with( $entry, $suffix ) ) {
				return true;
			}
		}
		foreach ( self::SKIP_PATHS as $path ) {
			if ( $entry === $path || str_starts_with( $entry, $path . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An extraction route, or a skip when the target is unsafe, replaces a base or
	 * reaches a protected path or a storage folder of ours (also through another base).
	 *
	 * @param string $base Base key.
	 * @param string $rel  Path below the base.
	 * @return array{action: string, base?: string, rel?: string, reason?: string}
	 */
	private function to( string $base, string $rel ): array {
		if ( ! PathGuard::isSafeEntryName( $rel ) ) {
			return self::skip( self::UNSAFE );
		}
		$target = $this->bases[ $base ] . '/' . $rel;
		$reason = $this->targets->covers( $target ) ?? $this->targets->covers( self::resolved( $target ) );
		if ( null !== $reason ) {
			return self::skip( $reason );
		}

		return [
			'action' => self::EXTRACT,
			'base'   => $this->bases[ $base ],
			'rel'    => $rel,
		];
	}

	/**
	 * The target through the real path of its deepest existing folder: a folder
	 * already on the site may be a link into a protected target.
	 *
	 * @param string $target Absolute target path.
	 */
	private static function resolved( string $target ): string {
		$tail = basename( $target );
		$dir  = dirname( $target );
		while ( true ) {
			$real = realpath( $dir );
			if ( false !== $real && is_dir( $real ) ) {
				return rtrim( $real, '/' ) . '/' . $tail;
			}
			if ( dirname( $dir ) === $dir ) {
				return $target;
			}
			$tail = basename( $dir ) . '/' . $tail;
			$dir  = dirname( $dir );
		}
	}

	/**
	 * @param string $reason Skip category ('' for the archive's own files).
	 * @return array{action: string, reason: string}
	 */
	private static function skip( string $reason ): array {
		return [
			'action' => self::SKIP,
			'reason' => $reason,
		];
	}
}
