<?php
/**
 * The code every site of a network runs, which a subsite restore leaves alone.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Ruling R14 / user decision U5: a sites or single-site archive restored into
 * a network never writes must-use plugins or wp-content root files (they would
 * run for every site), and never replaces a plugin or theme the network already
 * has (an old copy would change it for every site). New plugins and themes are
 * restored. What the network has is the snapshot taken before any file of the
 * archive was written (state.networkCode); without one, or for a folder that
 * could not be listed, every plugin and theme is kept (fails closed). A
 * plugin or theme the network does not have is not written either (final
 * re-review: a restored site's allowedthemes or menu_items would let its
 * administrator switch to it), so a sites or single-site restore brings no
 * plugin or theme code at all; one line names them. Other
 * shared wp-content folders (languages, fonts and the like) only gain files:
 * one the network already has is kept (review M2), and a new file PHP or the
 * web server would run is never added (final review I1). Only plugins and
 * themes the snapshot lists are activated (hadPlugin(), hadTheme()): the
 * archive's new ones are restored but stay inactive. Deviation from the
 * reference, which writes and activates them all. Pure apart from that
 * existence check.
 */
final class SharedCode {

	/** Skip reason: a must-use plugin or a wp-content root file. */
	public const SHARED = 'shared';

	/** Skip reason prefix: new:<plugins|themes>/<name>, a folder the network does not have (SkipMessages lists them). */
	public const NEW = 'new:';

	/** Skip reason prefix: kept:<plugins|themes>/<name> (SkipMessages lists them). */
	public const KEPT = 'kept:';

	/** Skip reason: a file the network already has in another shared wp-content folder. */
	public const EXISTING = 'existing';

	/** Skip reason: a new PHP or server configuration file in another shared wp-content folder. */
	public const NEW_CODE = 'code';

	/** Files PHP or the web server would run or obey: PHP scripts and per-folder server settings. */
	private const CODE_FILE = '/(\.ph(p\d*|tml|ar|t|ps)|(^|\/)\.htaccess|(^|\/)\.user\.ini|(^|\/)web\.config)\z/i';

	/** Top folders other rules route (uploads by blog). */
	private const ROUTED = [ 'uploads', 'blogs.dir' ];

	/**
	 * @param array<int, string>|null $plugins    Lower-case names in the plugins folder (null: unknown).
	 * @param array<int, string>|null $themes     Lower-case names in the theme root (null: unknown).
	 * @param string                  $contentDir The network's wp-content folder ('' : no existence check).
	 */
	private function __construct( private ?array $plugins, private ?array $themes, private string $contentDir ) {
	}

	/**
	 * @param mixed  $snapshot   snapshot() data as stored in the job, or null.
	 * @param string $contentDir The network's wp-content folder.
	 */
	public static function fromArray( $snapshot, string $contentDir = '' ): self {
		$names    = static function ( $list ): ?array {
			return is_array( $list ) ? array_values( array_map( 'strtolower', array_filter( $list, 'is_string' ) ) ) : null;
		};
		$snapshot = is_array( $snapshot ) ? $snapshot : [];

		return new self( $names( $snapshot['plugins'] ?? null ), $names( $snapshot['themes'] ?? null ), rtrim( $contentDir, '/' ) );
	}

	/**
	 * What the network's plugins folder and theme root hold now; null for a
	 * folder that exists but cannot be listed (then all of it is kept).
	 *
	 * @param string $pluginsDir Plugins folder.
	 * @param string $themeRoot  Theme root.
	 * @return array{plugins: array<int, string>|null, themes: array<int, string>|null}
	 */
	public static function snapshot( string $pluginsDir, string $themeRoot ): array {
		$list = static function ( string $dir ): ?array {
			if ( ! file_exists( $dir ) ) {
				return [];
			}
			$names = is_dir( $dir ) && is_readable( $dir ) ? scandir( $dir ) : false;
			if ( false === $names ) {
				return null;
			}

			return array_values( array_unique( array_map( 'strtolower', array_diff( $names, [ '.', '..' ] ) ) ) );
		};

		return [
			'plugins' => $list( $pluginsDir ),
			'themes'  => $list( $themeRoot ),
		];
	}

	/**
	 * @param string $entry Normalised entry name.
	 * @return string|null The skip reason, or null when the entry may be restored.
	 */
	public function reason( string $entry ): ?string {
		if ( ! str_contains( $entry, '/' ) || str_starts_with( $entry, 'mu-plugins/' ) ) {
			return self::SHARED;
		}
		[ $root, $name ] = explode( '/', $entry, 3 ) + [ '', '' ];
		$have            = [
			'plugins' => $this->plugins,
			'themes'  => $this->themes,
		];
		if ( ! array_key_exists( $root, $have ) ) {
			return $this->existing( $root, $entry );
		}
		$names = $have[ $root ];

		// Re-review: a new one is not written at all (a site administrator could switch to it).
		return null === $names || in_array( strtolower( $name ), $names, true ) ? self::KEPT . $root . '/' . $name : self::NEW . $root . '/' . $name;
	}

	/**
	 * Whether the network had this plugin before the import (true when the
	 * plugins folder could not be listed or there is no snapshot: then no
	 * plugin of the archive was written, reason() kept them all).
	 *
	 * @param string $basename Plugin basename (folder/file.php or file.php).
	 */
	public function hadPlugin( string $basename ): bool {
		return self::listed( $this->plugins, explode( '/', $basename, 2 )[0] );
	}

	/**
	 * Whether the network had this theme before the import (true without a list, as hadPlugin()).
	 *
	 * @param string $slug Theme folder name.
	 */
	public function hadTheme( string $slug ): bool {
		return self::listed( $this->themes, $slug );
	}

	/**
	 * The plugins on disk now that the network did not have before: the
	 * archive brought them, so they are never activated. One that is not
	 * installed is left to the plugin policy (it reports it as missing).
	 *
	 * @param array<int, string>    $plugins   Plugin basenames of the archive.
	 * @param array<string, string> $installed Basename => version, on disk now.
	 * @return array<int, string>
	 */
	public function newPlugins( array $plugins, array $installed ): array {
		return array_values( array_filter( $plugins, fn( string $p ): bool => isset( $installed[ $p ] ) && ! $this->hadPlugin( $p ) ) );
	}

	/**
	 * @param string $root  Top folder of the entry.
	 * @param string $entry Normalised entry name.
	 */
	private function existing( string $root, string $entry ): ?string {
		if ( in_array( $root, self::ROUTED, true ) ) {
			return null;
		}
		$target = $this->contentDir . '/' . $entry;
		if ( '' !== $this->contentDir && ( file_exists( $target ) || is_link( $target ) ) ) {
			return self::EXISTING;
		}

		return 1 === preg_match( self::CODE_FILE, $entry ) ? self::NEW_CODE : null;
	}

	/**
	 * @param array<int, string>|null $names Lower-case names (null: unknown).
	 * @param string                  $name  Name to look for.
	 */
	private static function listed( ?array $names, string $name ): bool {
		return null === $names || in_array( strtolower( $name ), $names, true );
	}
}
