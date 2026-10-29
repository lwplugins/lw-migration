<?php
/**
 * The `gdrive` destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\GoogleDrive;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5: Google Drive with the drive.file scope (non-sensitive) and an
 * own "LW Migration" folder. The steps ask for the app to be published
 * ("In production"): in Testing Google issues refresh tokens that expire
 * after 7 days (developers.google.com/identity/protocols/oauth2#expiration).
 * A folder found again during a job is stored through
 * ProfileRepository::remember(), only while the profile still holds the
 * connection the destination was made for (preflight P-C1, Task 7 m3);
 * best effort — the next upload finds the folder again otherwise.
 */
final class GoogleDriveType extends OAuthDestinationType {

	public const KEY = 'gdrive';

	/** Who is connected (about/get; `fields` is required). */
	private const ABOUT = 'https://www.googleapis.com/drive/v3/about?fields=user(displayName,emailAddress)';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return DriveErrors::LABEL;
	}

	public function provider( array $settings ): Provider {
		return Provider::google();
	}

	public function consoleUrl(): string {
		return 'https://console.cloud.google.com/apis/credentials';
	}

	public function steps(): array {
		return [
			__( 'In Google Cloud Console create a project (or pick one) and enable the Google Drive API.', 'lw-migration' ),
			__( 'Under Google Auth Platform set up the consent screen (External, or Internal for a Workspace-only app) and add the scope …/auth/drive.file.', 'lw-migration' ),
			__( 'Publish the app (Audience: In production). While it is in Testing, Google ends the connection after 7 days.', 'lw-migration' ),
			__( 'Under Clients create an OAuth client of type Web application and add the redirect address below as an authorized redirect URI.', 'lw-migration' ),
			__( 'Copy the Client ID and the client secret here, save, then choose Connect.', 'lw-migration' ),
		];
	}

	public function connect( OAuthHttp $http, TokenSet $tokens, Profile $profile ): array {
		$about = $http->request( 'GET', self::ABOUT );
		if ( 200 !== $about['status'] ) {
			$failure = DriveErrors::classify( $about['status'], $about['body'], $about['headers']['retry-after'] ?? '', $http->now() );
			throw $failure;
		}
		$user = $http->json( $about )['user'] ?? null;
		$name = '';
		foreach ( [ 'emailAddress', 'displayName' ] as $key ) {
			$name = '' === $name && is_array( $user ) && is_string( $user[ $key ] ?? null ) ? trim( $user[ $key ] ) : $name;
		}

		// A Client-ID/grant change clears the connection (DestinationInput) and so re-pins to the current site: accepted.
		$site = SiteFolder::of( $profile->connection );

		return [
			'account'  => '' === $name ? DriveErrors::LABEL : $name,
			'folderId' => DriveFolder::ensure( $http, $site ),
			'folder'   => $site,
		];
	}

	protected function client( Profile $profile, OAuthHttp $http ): DestinationInterface {
		$id         = $profile->id;
		$connection = (string) ( $profile->connection['id'] ?? '' );
		$site       = SiteFolder::of( $profile->connection );
		$pinned     = $profile->connection['folder'] ?? null;
		// Connected before site folders: the stored id is the shared root, never used; the first use ensures the site folder.
		$folderId = is_string( $pinned ) && 1 === preg_match( SiteFolder::PATTERN, $pinned ) ? (string) ( $profile->connection['folderId'] ?? '' ) : '';

		return new GoogleDriveDestination(
			$http,
			$folderId,
			static function ( string $folderId ) use ( $id, $connection, $site ): void {
				$repo = new ProfileRepository();
				$repo->remember( $id, 'folderId', $folderId, $connection );
				$repo->remember( $id, 'folder', $site, $connection );
			},
			null,
			$site
		);
	}

	protected function folderShown( string $folder ): string {
		return DriveFolder::NAME . '/' . $folder;
	}
}
