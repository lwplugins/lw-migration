<?php
/**
 * What a backup's package.json tells the restore picker.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Package\PackageCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Reads only the archive's first entry (package.json, never the data):
 * whether the archive is encrypted, and the site it was made on when that
 * is not this site. A site is its host and folder (letter case, the scheme
 * and a trailing slash aside), so a site that moved to https is the same
 * one. An archive that cannot be read is unknown (null), never an error:
 * the list shows it without the tags.
 */
final class BackupOrigin {

	/**
	 * @param string $path    Archive.
	 * @param string $homeUrl This site's home address (on a network the main site's).
	 * @return array{encrypted: bool|null, fromSite: string|null} fromSite: the other site's host and folder.
	 */
	public static function read( string $path, string $homeUrl ): array {
		try {
			$package = PackageCodec::read( $path );
		} catch ( ArchiveException $e ) {
			return [
				'encrypted' => null,
				'fromSite'  => null,
			];
		}
		$home  = $package->get( 'HomeURL', '' );
		$place = self::place( is_string( $home ) ? $home : '' );
		$here  = self::place( $homeUrl );

		return [
			'encrypted' => $package->isEncrypted(),
			'fromSite'  => '' === $place || $here === $place ? null : $place,
		];
	}

	/**
	 * A site address as host (with its port) and folder, in lower case.
	 *
	 * @param string $url Address ('' = none).
	 */
	public static function place( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}
		$port = parse_url( $url, PHP_URL_PORT );
		$path = rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );

		return strtolower( $host . ( null === $port ? '' : ':' . $port ) . $path );
	}
}
