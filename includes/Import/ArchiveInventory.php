<?php
/**
 * What an archive holds, read from its entry headers only.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\Entry;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * For the confirmation: a walk over the entry headers (each header is read,
 * its data skipped; nothing is decoded or extracted), resumable between
 * ticks as plain arrays. A size is what the entries take in the archive
 * (compressed or encrypted as stored). Media are the uploads (and blogs.dir)
 * files; plugins and themes are counted per top-level item (a plugin folder
 * or a PHP file other than index.php; a theme folder), without this plugin's
 * folder, which is never written; the must-use count is the PHP files
 * directly in the folder (the ones WordPress loads). The database's table
 * count would need reading the dump, so it stays unknown.
 */
final class ArchiveInventory {

	/** Job state key of the finished (or given up) inventory. */
	public const KEY = 'inventory';

	/** Part => package.json flag that leaves it out. */
	private const FLAGS = [
		'database'  => 'NoDatabase',
		'media'     => 'NoMedia',
		'plugins'   => 'NoPlugins',
		'themes'    => 'NoThemes',
		'muPlugins' => 'NoMustUsePlugins',
	];

	/** @var int Header offset of the next entry. */
	private int $offset;

	/** @var int End of the entry data (Reader::dataEnd()), 0 before the first walk. */
	private int $end;

	/** @var bool Every header was read. */
	private bool $complete;

	/** @var bool The archive could not be read: nothing is known from it. */
	private bool $failed;

	/** @var int|null Modification time of package.json (when the archive was made). */
	private ?int $createdAt;

	/** @var int Stored bytes of every entry but the configuration files. */
	private int $bytes;

	/** @var int|null Stored bytes of database.sql, null without one. */
	private ?int $database;

	/** @var array{bytes: int, files: int} */
	private array $media;

	/** @var array<string, int> Top-level item ("name/" for a folder) => bytes. */
	private array $plugins;

	/** @var array<string, int> Top-level item ("name/" for a folder) => bytes. */
	private array $themes;

	/** @var array{bytes: int, count: int} */
	private array $muPlugins;

	/**
	 * @param array<string, mixed> $saved toArray() of an earlier walk ([] = not started).
	 */
	private function __construct( array $saved ) {
		$media           = (array) ( $saved['media'] ?? [] );
		$mu              = (array) ( $saved['muPlugins'] ?? [] );
		$this->offset    = (int) ( $saved['offset'] ?? 0 );
		$this->end       = (int) ( $saved['end'] ?? 0 );
		$this->complete  = true === ( $saved['complete'] ?? false );
		$this->failed    = true === ( $saved['failed'] ?? false );
		$this->createdAt = isset( $saved['createdAt'] ) ? (int) $saved['createdAt'] : null;
		$this->bytes     = (int) ( $saved['bytes'] ?? 0 );
		$this->database  = isset( $saved['database'] ) ? (int) $saved['database'] : null;
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
	}

	/**
	 * @param array<string, mixed> $saved toArray() of an earlier walk ([] = not started).
	 */
	public static function fromArray( array $saved ): self {
		return new self( $saved );
	}

	/** An archive that could not be read: the confirmation goes on without its contents. */
	public static function unreadable(): self {
		return new self( [ 'failed' => true ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'offset'    => $this->offset,
			'end'       => $this->end,
			'complete'  => $this->complete,
			'failed'    => $this->failed,
			'createdAt' => $this->createdAt,
			'bytes'     => $this->bytes,
			'database'  => $this->database,
			'media'     => $this->media,
			'plugins'   => $this->plugins,
			'themes'    => $this->themes,
			'muPlugins' => $this->muPlugins,
		];
	}

	public function isFinished(): bool {
		return $this->complete || $this->failed;
	}

	/**
	 * Reads headers from where the last walk stopped until the deadline (at
	 * least one) or the end.
	 *
	 * @param Reader   $reader    The archive.
	 * @param Deadline $deadline  Time budget.
	 * @param string   $ownPlugin This plugin's basename (Site::$ownPlugin).
	 */
	public function walk( Reader $reader, Deadline $deadline, string $ownPlugin ): void {
		if ( $this->isFinished() ) {
			return;
		}
		$this->end = $reader->dataEnd();
		$own       = explode( '/', $ownPlugin )[0];
		foreach ( $reader->entries( $this->offset ) as $entry ) {
			$this->add( $entry, $own );
			$this->offset = $entry->dataOffset() + $entry->size;
			if ( $deadline->reached() ) {
				break;
			}
		}
		$this->complete = $this->offset >= $this->end;
	}

	/** 0-100 of the headers read. */
	public function progress(): int {
		if ( $this->isFinished() ) {
			return 100;
		}

		return $this->end > 0 ? (int) ( 100 * $this->offset / $this->end ) : 0;
	}

	public function createdAt(): ?int {
		return $this->complete ? $this->createdAt : null;
	}

	/**
	 * The files' own size: known only when the archive stores them as they are.
	 *
	 * @param PackageConfig $package The archive's package.json.
	 */
	public function expandedSize( PackageConfig $package ): ?int {
		$stored = '' === $package->compression() && ! $package->isEncrypted();

		return $this->complete && $stored ? $this->bytes : null;
	}

	/**
	 * Part => whether the archive holds it, its stored bytes and its count
	 * (media files, plugins, themes, must-use PHP files; null for the
	 * database). Before the walk finished (or when it was given up) only a
	 * part package.json leaves out is known (included false); the rest is null.
	 *
	 * @param PackageConfig $package The archive's package.json.
	 * @return array<string, array{included: bool|null, bytes: int|null, count: int|null}>
	 */
	public function parts( PackageConfig $package ): array {
		if ( ! $this->complete ) {
			$parts = [];
			foreach ( self::FLAGS as $part => $flag ) {
				$parts[ $part ] = self::part( empty( $package->get( $flag ) ) ? null : false, null, null );
			}

			return $parts;
		}
		$plugins = array_filter( array_keys( $this->plugins ), static fn( string $item ): bool => str_ends_with( $item, '/' ) || ( str_ends_with( $item, '.php' ) && 'index.php' !== $item ) );
		$themes  = array_filter( array_keys( $this->themes ), static fn( string $item ): bool => str_ends_with( $item, '/' ) );

		return [
			'database'  => self::part( null !== $this->database, (int) $this->database, null ),
			'media'     => self::part( $this->media['files'] > 0, $this->media['bytes'], $this->media['files'] ),
			'plugins'   => self::part( [] !== $this->plugins, (int) array_sum( $this->plugins ), count( $plugins ) ),
			'themes'    => self::part( [] !== $this->themes, (int) array_sum( $this->themes ), count( $themes ) ),
			'muPlugins' => self::part( $this->muPlugins['bytes'] > 0 || $this->muPlugins['count'] > 0, $this->muPlugins['bytes'], $this->muPlugins['count'] ),
		];
	}

	/**
	 * @param Entry  $entry Entry header.
	 * @param string $own   This plugin's folder.
	 */
	private function add( Entry $entry, string $own ): void {
		if ( 0 === $entry->headerOffset ) {
			$this->createdAt = $entry->mtime > 0 ? $entry->mtime : null;
		}
		if ( $entry->isConfig() ) {
			return;
		}
		$this->bytes += $entry->size;
		if ( Format::DATABASE === $entry->name ) {
			$this->database = (int) $this->database + $entry->size;
			return;
		}
		[ $root, $rest ] = array_pad( explode( '/', $entry->name, 2 ), 2, '' );
		if ( '' === $rest ) {
			return;
		}
		if ( 'uploads' === $root || 'blogs.dir' === $root ) {
			$this->media['bytes'] += $entry->size;
			++$this->media['files'];
		} elseif ( 'plugins' === $root && ! str_starts_with( $rest, $own . '/' ) ) {
			$item                   = self::item( $rest );
			$this->plugins[ $item ] = ( $this->plugins[ $item ] ?? 0 ) + $entry->size;
		} elseif ( 'themes' === $root ) {
			$item                  = self::item( $rest );
			$this->themes[ $item ] = ( $this->themes[ $item ] ?? 0 ) + $entry->size;
		} elseif ( 'mu-plugins' === $root ) {
			$this->muPlugins['bytes'] += $entry->size;
			$this->muPlugins['count'] += ! str_contains( $rest, '/' ) && str_ends_with( $rest, '.php' ) ? 1 : 0;
		}
	}

	/**
	 * The top-level item of a path below plugins/ or themes/: "name/" for a folder, the file name otherwise.
	 *
	 * @param string $rest Path below the root folder.
	 */
	private static function item( string $rest ): string {
		$slash = strpos( $rest, '/' );

		return false === $slash ? $rest : substr( $rest, 0, $slash + 1 );
	}

	/**
	 * @param bool|null $included Whether the archive holds the part.
	 * @param int|null  $bytes    Stored bytes.
	 * @param int|null  $count    Items.
	 * @return array{included: bool|null, bytes: int|null, count: int|null}
	 */
	private static function part( ?bool $included, ?int $bytes, ?int $count ): array {
		return [
			'included' => $included,
			'bytes'    => $bytes,
			'count'    => $count,
		];
	}

	/**
	 * @param mixed $saved Saved item => bytes map.
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
