<?php
/**
 * Finds the archive a command argument means.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Backups\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * A backup name from `list`, or a path to a .wpress file; messages about it
 * name it the way the user typed it.
 */
final class Archives {

	public static function resolve( string $arg, Repository $repo ): ?string {
		$backup = $repo->find( $arg );
		if ( null !== $backup ) {
			return $backup->path;
		}
		if ( is_file( $arg ) && is_readable( $arg ) && Reader::hasArchiveExtension( $arg ) ) {
			return (string) realpath( $arg );
		}

		return null;
	}

	/**
	 * Replaces server paths in a message with what the user typed for them
	 * (each path also by its real path, longest first).
	 *
	 * @param string                $message Message.
	 * @param array<string, string> $shown   Path => text to show instead.
	 * @return string
	 */
	public static function redact( string $message, array $shown ): string {
		$map = [];
		foreach ( $shown as $path => $text ) {
			$real = realpath( (string) $path );
			foreach ( [ (string) $path, false === $real ? '' : $real ] as $search ) {
				if ( '' !== $search && '/' !== $search ) {
					$map[ $search ] = $text;
				}
			}
		}
		uksort( $map, static fn( $a, $b ): int => strlen( (string) $b ) <=> strlen( (string) $a ) );

		return str_replace( array_keys( $map ), array_values( $map ), $message );
	}
}
