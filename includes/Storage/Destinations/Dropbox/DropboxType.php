<?php
/**
 * The `dropbox` destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Dropbox;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5: Dropbox with App folder access ("scoped access only to your
 * app's dedicated folder", developer guide), PKCE and offline tokens. The
 * account label is the e-mail of users/get_current_account (E14; shown
 * only on the card, never in a message).
 */
final class DropboxType extends OAuthDestinationType {

	public const KEY = 'dropbox';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return DropboxErrors::LABEL;
	}

	public function provider( array $settings ): Provider {
		return Provider::dropbox();
	}

	public function consoleUrl(): string {
		return 'https://www.dropbox.com/developers/apps';
	}

	public function steps(): array {
		return [
			__( 'In the Dropbox App Console create an app: Scoped access, App folder, with a name of your choice.', 'lw-migration' ),
			__( 'On the Permissions tab tick files.metadata.read, files.content.read, files.content.write and account_info.read, then Submit.', 'lw-migration' ),
			__( 'On the Settings tab add the redirect address below under OAuth 2 Redirect URIs.', 'lw-migration' ),
			__( 'Copy the App key (as Client ID) and the App secret (as client secret) here, save, then choose Connect.', 'lw-migration' ),
		];
	}

	public function connect( OAuthHttp $http, TokenSet $tokens, Profile $profile ): array {
		$answer = ( new DropboxApi( $http ) )->rpc( 'users/get_current_account', null );
		if ( 200 !== $answer['status'] ) {
			$failure = DropboxErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $http->now() );
			throw $failure;
		}
		$email = $http->json( $answer )['email'] ?? null;

		// A Client-ID/grant change clears the connection (DestinationInput) and so re-pins to the current site: accepted.
		return [
			'account' => is_string( $email ) && '' !== trim( $email ) ? trim( $email ) : DropboxErrors::LABEL,
			'folder'  => SiteFolder::of( $profile->connection ), // Pinned at the first connect, kept by a reconnect.
		];
	}

	protected function client( Profile $profile, OAuthHttp $http ): DestinationInterface {
		return new DropboxDestination( $http, SiteFolder::of( $profile->connection ) );
	}
}
