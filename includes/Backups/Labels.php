<?php
/**
 * User labels of backups.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\StorageDir;

defined( 'ABSPATH' ) || exit;

/**
 * One option: file name => label.
 */
final class Labels {

	public const MAX_LENGTH = 200;

	public const MAX_ENTRIES = 500;

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		$labels = get_option( Options::LABELS, [] );
		if ( ! is_array( $labels ) ) {
			return [];
		}
		$clean = [];
		foreach ( $labels as $name => $label ) {
			$clean[ (string) $name ] = (string) $label;
		}

		return $clean;
	}

	public static function get( string $name ): string {
		return self::all()[ $name ] ?? '';
	}

	/**
	 * Stores a label (an empty one removes it) without control characters,
	 * clamped to MAX_LENGTH characters.
	 * Above MAX_ENTRIES every label of a backup that no longer exists is dropped,
	 * then the oldest labels until the cap holds.
	 *
	 * @param string        $name   Backup file name.
	 * @param string        $label  Label text.
	 * @param callable|null $exists fn( string $name ): bool; defaults to the backup folders.
	 * @throws \InvalidArgumentException When the name is not a valid backup name.
	 */
	public static function set( string $name, string $label, ?callable $exists = null ): void {
		if ( ! BackupName::isValid( $name ) ) {
			throw new \InvalidArgumentException( 'Not a valid backup file name.' );
		}
		$labels = self::all();
		// sanitize_text_field() keeps most control characters (BEL, DEL, C1); none belong in a label.
		$label = mb_substr( trim( (string) preg_replace( '/\p{Cc}+/u', '', sanitize_text_field( $label ) ) ), 0, self::MAX_LENGTH );
		unset( $labels[ $name ] );
		if ( '' !== $label ) {
			$labels[ $name ] = $label;
		}
		if ( count( $labels ) > self::MAX_ENTRIES ) {
			$labels = self::capped( $labels, $name, $exists ?? self::backupExists() );
		}
		update_option( Options::LABELS, $labels, false );
	}

	public static function remove( string $name ): void {
		$labels = self::all();
		if ( isset( $labels[ $name ] ) ) {
			unset( $labels[ $name ] );
			update_option( Options::LABELS, $labels, false );
		}
	}

	/**
	 * @param array<string, string> $labels Labels, oldest first.
	 * @param string                $name   The label just set; never dropped.
	 * @param callable              $exists fn( string $name ): bool.
	 * @return array<string, string>
	 */
	private static function capped( array $labels, string $name, callable $exists ): array {
		foreach ( array_keys( $labels ) as $other ) {
			if ( $other !== $name && ! $exists( (string) $other ) ) {
				unset( $labels[ $other ] );
			}
		}
		$excess = count( $labels ) - self::MAX_ENTRIES;
		foreach ( array_keys( $labels ) as $oldest ) {
			if ( $excess <= 0 ) {
				break;
			}
			if ( $oldest !== $name ) {
				unset( $labels[ $oldest ] );
				--$excess;
			}
		}

		return $labels;
	}

	/**
	 * @return callable fn( string $name ): bool over our folder and the external one.
	 */
	private static function backupExists(): callable {
		$repository = Repository::fromWordPress( StorageDir::fromWordPress() );

		return static fn( string $name ): bool => null !== $repository->find( $name );
	}
}
