<?php
/**
 * .wpress format constants.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-level constants of the .wpress archive format.
 */
final class Format {

	public const HEADER_SIZE = 4377;
	public const CHUNK_SIZE  = 512000;
	public const IV_LENGTH   = 16;
	public const NAME_MAX    = 255;
	public const PATH_MAX    = 4088;
	public const EXTENSION   = 'wpress';
	public const PACKAGE     = 'package.json';
	public const MULTISITE   = 'multisite.json';
	public const DATABASE    = 'database.sql';

	/** Entries that are never compressed or encrypted. */
	public const CONFIG_ENTRIES = [ self::PACKAGE, self::MULTISITE ];

	public static function isConfigEntry( string $name ): bool {
		return in_array( $name, self::CONFIG_ENTRIES, true );
	}
}
