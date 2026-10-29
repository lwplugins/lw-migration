<?php
/**
 * The "LW Migration" folder in the connected Google Drive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\GoogleDrive;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;

defined( 'ABSPATH' ) || exit;

/**
 * M7c plan E12. With drive.file the app sees only files it created, so the
 * folders ("LW Migration", and one per site inside it) are found by their
 * private app property (not by name: the admin may rename them) and created
 * when there is none. Two processes may both create
 * one (no lock, preflight P-M20); the search is ordered by creation time, so
 * every later search takes the oldest and the other stays unused.
 */
final class DriveFolder {

	public const NAME = 'LW Migration';

	public const KEY = 'lwMigration';

	public const VALUE = 'backups';

	public const SITE_KEY = 'lwMigrationSite';

	/** A Drive file id (lenient: the shapes in Google's examples, preflight P-I3). */
	public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,200}\z/';

	private const MIME = 'application/vnd.google-apps.folder';

	/**
	 * The site's folder inside "LW Migration" (both are found or created).
	 *
	 * @param OAuthHttp $http Authorized requests.
	 * @param string    $site The site's folder name (SiteFolder::PATTERN).
	 * @return string Site folder id.
	 * @throws PermanentFailure INVALID for a bad site name or an answer without a usable id (CloudFailure when Drive refuses, DriveErrors).
	 */
	public static function ensure( OAuthHttp $http, string $site ): string {
		if ( 1 !== preg_match( SiteFolder::PATTERN, $site ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s did not get a usable site folder name.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID );
		}
		$parent = self::findOrCreate( $http, '', self::KEY, self::VALUE, self::NAME, [] );

		return self::findOrCreate( $http, "'" . $parent . "' in parents and ", self::SITE_KEY, $site, $site, [ 'parents' => [ $parent ] ] );
	}

	/**
	 * @param OAuthHttp            $http   Authorized requests.
	 * @param string               $scope  Extra search terms before the marker.
	 * @param string               $key    Marker key.
	 * @param string               $value  Marker value (a plain name, escaped here).
	 * @param string               $name   Name of a folder to create.
	 * @param array<string, mixed> $extra  Extra metadata of a folder to create.
	 * @throws CloudFailure When Drive refuses (DriveErrors), or INVALID (PermanentFailure) for an answer without a usable id.
	 */
	private static function findOrCreate( OAuthHttp $http, string $scope, string $key, string $value, string $name, array $extra ): string {
		$quoted = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
		$query  = [
			'q'        => "mimeType = '" . self::MIME . "' and " . $scope . "appProperties has { key='" . $key . "' and value='" . $quoted . "' } and trashed = false",
			'fields'   => 'files(id)',
			'orderBy'  => 'createdTime',
			'pageSize' => 10,
			'spaces'   => 'drive',
		];
		$answer = $http->request( 'GET', GoogleDriveDestination::FILES . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		if ( 200 !== $answer['status'] ) {
			$failure = DriveErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $http->now() );
			throw $failure;
		}
		$files = $http->json( $answer )['files'] ?? null;
		$found = is_array( $files ) && is_array( $files[0] ?? null ) ? ( $files[0]['id'] ?? null ) : null;
		if ( null !== $found ) {
			return self::id( $found );
		}
		$marker = [ self::KEY => self::VALUE ];
		if ( self::KEY !== $key ) {
			$marker = [
				self::KEY => 'site',
				$key      => $value,
			];
		}
		$meta    = [
			'name'     => $name,
			'mimeType' => self::MIME,
		];
		$body    = (string) wp_json_encode( $meta + $extra + [ 'appProperties' => $marker ] );
		$created = $http->request( 'POST', GoogleDriveDestination::FILES . '?fields=id', [ 'Content-Type' => 'application/json; charset=UTF-8' ], $body );
		if ( 200 !== $created['status'] ) {
			$failure = DriveErrors::classify( $created['status'], $created['body'], $created['headers']['retry-after'] ?? '', $http->now() );
			throw $failure;
		}

		return self::id( $http->json( $created )['id'] ?? null );
	}

	/**
	 * @param mixed $id Id from an answer.
	 * @throws PermanentFailure INVALID for anything but a Drive id.
	 */
	private static function id( $id ): string {
		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s did not give a usable site folder.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID );
		}

		return $id;
	}
}
