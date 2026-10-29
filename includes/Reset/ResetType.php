<?php
/**
 * The five resets and their steps.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. A full reset runs every part in the order plugins, themes, media,
 * database, so it ends as a fresh install with the default theme active; the
 * backup (when asked for) comes before anything is changed.
 */
final class ResetType {

	public const PLUGINS  = 'plugins';
	public const THEMES   = 'themes';
	public const MEDIA    = 'media';
	public const DATABASE = 'database';
	public const FULL     = 'full';

	public const ALL = [ self::PLUGINS, self::THEMES, self::MEDIA, self::DATABASE, self::FULL ];

	public const BACKUP       = 'reset_backup';
	public const PLUGINS_STEP = 'reset_plugins';
	public const THEMES_STEP  = 'reset_themes';
	public const MEDIA_STEP   = 'reset_media';
	public const DB_BUILD     = 'reset_db_build';
	public const DB_SWAP      = 'reset_db_swap';
	public const DB_FINISH    = 'reset_db_finish';

	public const STEP_KEYS = [ self::BACKUP, self::PLUGINS_STEP, self::THEMES_STEP, self::MEDIA_STEP, self::DB_BUILD, self::DB_SWAP, self::DB_FINISH ];

	private const DATABASE_STEPS = [ self::DB_BUILD, self::DB_SWAP, self::DB_FINISH ];

	public static function isValid( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}

	/**
	 * @param string $type   One of ALL.
	 * @param bool   $backup Whether the backup runs first.
	 * @return array<int, string>
	 * @throws \InvalidArgumentException For an unknown type.
	 */
	public static function steps( string $type, bool $backup ): array {
		$parts = [
			self::PLUGINS  => [ self::PLUGINS_STEP ],
			self::THEMES   => [ self::THEMES_STEP ],
			self::MEDIA    => [ self::MEDIA_STEP ],
			self::DATABASE => self::DATABASE_STEPS,
			self::FULL     => array_merge( [ self::PLUGINS_STEP, self::THEMES_STEP, self::MEDIA_STEP ], self::DATABASE_STEPS ),
		];
		if ( ! isset( $parts[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Unknown reset type.' );
		}

		return $backup ? array_merge( [ self::BACKUP ], $parts[ $type ] ) : $parts[ $type ];
	}

	public static function touchesCode( string $type ): bool {
		return in_array( $type, [ self::PLUGINS, self::THEMES, self::FULL ], true );
	}

	public static function touchesMedia( string $type ): bool {
		return in_array( $type, [ self::MEDIA, self::FULL ], true );
	}

	public static function touchesDatabase( string $type ): bool {
		return in_array( $type, [ self::DATABASE, self::FULL ], true );
	}

	/**
	 * Decision D2: a network's database is never rebuilt in 1.0.
	 *
	 * @param string $type One of ALL.
	 */
	public static function allowedOnNetwork( string $type ): bool {
		return in_array( $type, [ self::PLUGINS, self::THEMES, self::MEDIA ], true );
	}
}
