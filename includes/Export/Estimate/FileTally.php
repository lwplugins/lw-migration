<?php
/**
 * Running totals of the files a backup would contain, by part.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Fed with the lines FileEnumerator lists, so it counts exactly what a
 * backup with every option on would archive. A line belongs to the root
 * whose folder and entry name it carries: uploads (and blogs.dir) are media;
 * plugins and themes are summed per top-level folder (or single file), so the
 * size with only the active ones can be worked out the way ExportRoots leaves
 * the inactive ones out; in wp-content itself, mu-plugins, cache and the rest
 * (other). The mu-plugin count is the PHP files WordPress loads (directly in
 * the folder). Stored as plain arrays between requests.
 */
final class FileTally {

	/** @var array{bytes: int, files: int} */
	private array $media;

	/** @var array<string, int> Top-level name => bytes. */
	private array $plugins;

	/** @var array<string, int> Theme folder => bytes. */
	private array $themes;

	/** @var array{bytes: int, count: int} */
	private array $muPlugins;

	/** @var int Bytes in wp-content/cache. */
	private int $cache;

	/** @var int Bytes of the rest of wp-content. */
	private int $other;

	/**
	 * @param array<string, mixed> $saved toArray() of an earlier tally ([] = empty).
	 */
	public function __construct( array $saved = [] ) {
		$media           = (array) ( $saved['media'] ?? [] );
		$mu              = (array) ( $saved['muPlugins'] ?? [] );
		$this->media     = [
			'bytes' => (int) ( $media['bytes'] ?? 0 ),
			'files' => (int) ( $media['files'] ?? 0 ),
		];
		$this->plugins   = self::sizes( $saved['plugins'] ?? [] );
		$this->themes    = self::sizes( $saved['themes'] ?? [] );
		$this->muPlugins = [
			'bytes' => (int) ( $mu['bytes'] ?? 0 ),
			'count' => (int) ( $mu['count'] ?? 0 ),
		];
		$this->cache     = (int) ( $saved['cache'] ?? 0 );
		$this->other     = (int) ( $saved['other'] ?? 0 );
	}

	/**
	 * @param Site                                          $site  The snapshot the roots were built from.
	 * @param array<int, array{dir: string, entry: string}> $roots ExportRoots::build() roots.
	 * @param string                                        $entry Archive entry name of the file.
	 * @param string                                        $path  Its path as listed.
	 * @param int                                           $size  Its size.
	 */
	public function add( Site $site, array $roots, string $entry, string $path, int $size ): void {
		[ $root, $rel ] = self::root( $roots, $entry, $path );
		$top            = explode( '/', $rel, 2 )[0];
		if ( 'plugins' === $root ) {
			$this->plugins[ $top ] = ( $this->plugins[ $top ] ?? 0 ) + $size;
		} elseif ( 'themes' === $root ) {
			$this->themes[ $top ] = ( $this->themes[ $top ] ?? 0 ) + $size;
		} elseif ( '' !== $root || 'blogs.dir' === $top ) {
			$this->media['bytes'] += $size;
			++$this->media['files'];
		} elseif ( '' !== $site->muPluginsDir && str_starts_with( $path, $site->muPluginsDir . '/' ) ) {
			$this->muPlugins['bytes'] += $size;
			$this->muPlugins['count'] += dirname( $path ) === $site->muPluginsDir && str_ends_with( $path, '.php' ) ? 1 : 0;
		} elseif ( 'cache' === $top ) {
			$this->cache += $size;
		} else {
			$this->other += $size;
		}
	}

	/**
	 * @return array{media: array{bytes: int, files: int}, plugins: array<string, int>, themes: array<string, int>, muPlugins: array{bytes: int, count: int}, cache: int, other: int}
	 */
	public function toArray(): array {
		return [
			'media'     => $this->media,
			'plugins'   => $this->plugins,
			'themes'    => $this->themes,
			'muPlugins' => $this->muPlugins,
			'cache'     => $this->cache,
			'other'     => $this->other,
		];
	}

	/**
	 * The root a listed file came from: one with an entry name whose folder
	 * and that name both lead to the file; otherwise wp-content itself.
	 *
	 * @param array<int, array{dir: string, entry: string}> $roots Roots.
	 * @param string                                        $entry Entry name.
	 * @param string                                        $path  Listed path.
	 * @return array{0: string, 1: string} Root entry name ('' = wp-content), path below the root.
	 */
	private static function root( array $roots, string $entry, string $path ): array {
		foreach ( $roots as $root ) {
			$prefix = '' === $root['entry'] ? '' : $root['entry'] . '/';
			if ( '' !== $prefix && str_starts_with( $entry, $prefix ) && $root['dir'] . '/' . substr( $entry, strlen( $prefix ) ) === $path ) {
				return [ $root['entry'], substr( $entry, strlen( $prefix ) ) ];
			}
		}

		return [ '', $entry ];
	}

	/**
	 * @param mixed $saved Saved name => bytes map.
	 * @return array<string, int>
	 */
	private static function sizes( $saved ): array {
		$sizes = [];
		foreach ( (array) $saved as $name => $bytes ) {
			$sizes[ (string) $name ] = (int) $bytes;
		}

		return $sizes;
	}
}
