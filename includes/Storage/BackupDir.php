<?php
/**
 * The backups folder setting.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * One raw option row (OptionRow: no cache, never autoloaded, compare-and-swap):
 * path ('' = the default folder in the storage root), moving (the target of a
 * move that has not finished: '' = the default folder, null = none), marker
 * (this site's 16-hex mark in every chosen folder). Anything stored that is
 * not valid reads as the default folder.
 */
final class BackupDir {

	public const MARKER_PATTERN = '/^[a-f0-9]{16}\z/';

	/**
	 * @param string      $path   Chosen folder ('' = the default one).
	 * @param string|null $moving Target of an unfinished move ('' = the default one), or null.
	 * @param string      $marker This site's marker ('' = none yet).
	 */
	public function __construct( public string $path = '', public ?string $moving = null, public string $marker = '' ) {
	}

	/**
	 * @param object|null $db Database object (tests).
	 */
	public static function read( ?object $db = null ): self {
		$raw = ( new OptionRow( Options::BACKUP_DIR, $db ) )->read();

		return null === $raw ? new self() : self::parse( $raw );
	}

	/**
	 * @param string $raw Stored JSON.
	 */
	public static function parse( string $raw ): self {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new self();
		}
		$marker = is_string( $data['marker'] ?? null ) && 1 === preg_match( self::MARKER_PATTERN, $data['marker'] ) ? $data['marker'] : '';
		$path   = self::folder( $data['path'] ?? '' ) ?? '';
		$moving = isset( $data['moving'] ) ? self::folder( $data['moving'] ) : null;
		if ( '' === $marker ) {
			$path   = '';
			$moving = null; // A chosen folder without our marker cannot be checked: never write into it.
		}

		return new self( $path, $moving === $path ? null : $moving, $marker );
	}

	public function toJson(): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the stored row must not depend on a filtered encoder.
			[
				'path'   => $this->path,
				'moving' => $this->moving,
				'marker' => $this->marker,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/** The same setting with a marker: the existing one, or a new one made once. */
	public function withMarker(): self {
		return '' !== $this->marker ? $this : new self( $this->path, $this->moving, bin2hex( random_bytes( 8 ) ) );
	}

	/**
	 * Stores $next only while the row still reads as $expected.
	 *
	 * @param self        $expected What the caller read.
	 * @param self        $next     What to store.
	 * @param object|null $db       Database object (tests).
	 * @return bool True when the row now holds $next.
	 */
	public static function replace( self $expected, self $next, ?object $db = null ): bool {
		$row     = new OptionRow( Options::BACKUP_DIR, $db );
		$raw     = $row->read();
		$current = null === $raw ? new self() : self::parse( $raw );
		if ( $raw === $next->toJson() ) {
			return true;
		}
		if ( $current->toJson() !== $expected->toJson() ) {
			return false;
		}

		return null === $raw ? $row->insert( $next->toJson() ) : $row->swap( $raw, $next->toJson() );
	}

	/**
	 * The folder as it may be shown: a random-named folder only as
	 * <parent>/lw-migration-backups-… (the random segment is a protection, and
	 * a new move draws it again), others as they are ('' = the default one).
	 *
	 * @param string $path A stored or resolved folder.
	 */
	public static function display( string $path ): string {
		return self::hidden( $path ) ? dirname( $path ) . '/lw-migration-backups-…' : $path;
	}

	/**
	 * @param string $path A stored or resolved folder.
	 * @return bool Whether display() masks it.
	 */
	public static function hidden( string $path ): bool {
		return '' !== $path && 1 === preg_match( BackupDirRules::SUFFIX_PATTERN, basename( $path ) );
	}

	/**
	 * @param mixed $value Stored folder.
	 * @return string|null '' for the default, a normalised absolute path, or null when not valid.
	 */
	private static function folder( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( '' === $value ) {
			return '';
		}
		try {
			return BackupDirRules::normalise( $value ) === $value ? $value : null;
		} catch ( FolderRefusedException $e ) {
			return null;
		}
	}
}
