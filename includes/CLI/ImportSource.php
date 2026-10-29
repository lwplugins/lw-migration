<?php
/**
 * Finds what the import command's argument means.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Import\ImportOptions;

defined( 'ABSPATH' ) || exit;

/**
 * An http(s) address, a backup name from `list`, or a path to a .wpress file
 * (Archives::resolve(): the extension and readability are checked there).
 */
final class ImportSource {

	/**
	 * @param string     $arg          The argument as typed.
	 * @param Repository $repo         Backups.
	 * @param bool       $safetyBackup Whether a database backup is made first.
	 * @return ImportOptions
	 * @throws \InvalidArgumentException When the argument is none of them, or an unusable address.
	 */
	public static function options( string $arg, Repository $repo, bool $safetyBackup ): ImportOptions {
		if ( 1 === preg_match( '#^https?://#i', $arg ) ) {
			return ImportOptions::fromArray( self::data( ImportOptions::URL, $arg, $safetyBackup ) );
		}
		if ( null !== $repo->find( $arg ) ) {
			return ImportOptions::fromArray( self::data( ImportOptions::BACKUP, $arg, $safetyBackup ) );
		}
		$path = Archives::resolve( $arg, $repo );
		if ( null === $path ) {
			/* translators: %s: what the user typed */
			throw new \InvalidArgumentException( sprintf( __( 'No backup, readable .wpress file or http(s) address: %s', 'lw-migration' ), $arg ) );
		}

		return ImportOptions::fromArray( self::data( ImportOptions::FILE, $path, $safetyBackup ) );
	}

	/**
	 * How the source is named in messages: an address without its secrets,
	 * otherwise what the user typed (never the resolved server path).
	 *
	 * @param ImportOptions $options Resolved source.
	 * @param string        $typed   The argument as typed.
	 */
	public static function shown( ImportOptions $options, string $typed ): string {
		return ImportOptions::URL === $options->source ? AddressRedactor::display( $options->location ) : $typed;
	}

	/**
	 * @param string $source       Source kind.
	 * @param string $location     Location.
	 * @param bool   $safetyBackup Safety backup.
	 * @return array<string, mixed>
	 */
	private static function data( string $source, string $location, bool $safetyBackup ): array {
		return [
			'source'       => $source,
			'location'     => $location,
			'safetyBackup' => $safetyBackup,
		];
	}
}
