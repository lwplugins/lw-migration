<?php
/**
 * Withdraws one connection's access at its provider, best effort.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #6 (ruling R6), straight from this site, through ProviderHttp
 * (checked and pinned, TLS checked, no redirect, TIMEOUT seconds). Google,
 * own app only: POST GOOGLE with the refresh token as the form field
 * `token` (developers.google.com/identity/protocols/oauth2/web-server,
 * "Token revocation", read 2026-10-15: it withdraws every scope the account
 * granted the app's project, so a caller never revokes a grant another
 * connection still uses — GrantWithdrawal). Through the LW app that grant is
 * shared by every site using the LW app with the same Google account, so it
 * is never revoked from here: the answer is the account's app-permissions
 * page, like OneDrive's (C1 ruling). Dropbox, in both modes: POST DROPBOX with the
 * access token as Bearer; it disables that token, its refresh token and the
 * refresh token's other access tokens (the Dropbox SDK's auth_token_revoke,
 * read 2026-10-15). An expired cached access token is renewed first through
 * the connection's own token endpoint (the LW sign-in service for the LW
 * app) and is never stored; through the LW app it is used only after the
 * provider named the account recorded at connect for it (AccountCheck,
 * #12), else the notice comes instead. Microsoft has no revoke: the answer is always
 * the account's app-permissions page. Only a 200 counts as withdrawn; any
 * other answer, a refusal before sending or a failed refresh gives the
 * notice with the provider's page. No token ever reaches a message.
 */
final class GrantRevoker {

	public const TIMEOUT = 10;

	public const GOOGLE = 'https://oauth2.googleapis.com/revoke';

	public const DROPBOX = 'https://api.dropboxapi.com/2/auth/token/revoke';

	/** Where the account owner removes an app's access, by provider (Microsoft: personal, work or school). */
	public const PAGES = [
		Provider::GOOGLE  => 'https://myaccount.google.com/connections',
		Provider::DROPBOX => 'https://www.dropbox.com/account/connected_apps',
		'personal'        => 'https://account.live.com/consent/Manage',
		'work'            => 'https://myapps.microsoft.com',
	];

	/**
	 * The guarded send path.
	 *
	 * @var ProviderHttp
	 */
	private ProviderHttp $http;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * @param \Closure|null     $send  Transport (default wp_remote_request).
	 * @param \Closure|null     $now   Clock (default time()).
	 * @param AddressGuard|null $guard Address check (default: real DNS, or the tests' resolver).
	 */
	public function __construct( ?\Closure $send = null, ?\Closure $now = null, ?AddressGuard $guard = null ) {
		$this->http = new ProviderHttp( $send, $guard );
		$this->now  = $now ?? static fn(): int => time();
	}

	/**
	 * @param OAuthDestinationType  $type     The profile's type.
	 * @param array<string, mixed>  $settings The settings the tokens were issued under.
	 * @param array<string, string> $secrets  The opened secrets holding the tokens.
	 * @return string|null Null when the access was withdrawn or there was none; else the notice (translated, with the provider's page).
	 */
	public function revoke( OAuthDestinationType $type, array $settings, #[\SensitiveParameter] array $secrets ): ?string {
		$refresh  = (string) ( $secrets[ TokenKeeper::REFRESH ] ?? '' );
		$provider = $type->provider( $settings );
		if ( '' === $refresh ) {
			return null;
		}
		if ( Provider::MICROSOFT === $provider->key ) {
			return self::microsoft( $provider );
		}
		if ( Provider::GOOGLE === $provider->key && ! OAuthMode::ownApp( $settings ) ) {
			return self::sharedApp( $provider );
		}
		try {
			$status = Provider::GOOGLE === $provider->key ? $this->google( $refresh, $provider->label ) : $this->dropbox( $type, $settings, $secrets, $provider->label );
		} catch ( CloudFailure $e ) {
			$status = 0;
		}

		return 200 === $status ? null : self::notice( $provider );
	}

	/**
	 * The notice for a grant this site could not withdraw (also when its
	 * tokens can no longer be read).
	 *
	 * @param Provider $provider Provider.
	 */
	public static function notice( Provider $provider ): string {
		if ( Provider::MICROSOFT === $provider->key ) {
			return self::microsoft( $provider );
		}

		/* translators: 1: provider name, 2: web address of the account's app permissions */
		return sprintf( __( 'This site could not withdraw its access to %1$s. Remove the app\'s access in your account: %2$s', 'lw-migration' ), $provider->label, self::PAGES[ $provider->key ] ?? '' );
	}

	/**
	 * The LW app's Google grant is the account's grant to the LW app, which
	 * every site connected with that account uses: never revoked from one site.
	 *
	 * @param Provider $provider The Google provider.
	 */
	private static function sharedApp( Provider $provider ): string {
		/* translators: 1: provider name, 2: web address of the account's app permissions */
		return sprintf( __( 'The access to %1$s through the LW app is not withdrawn from here: it would also disconnect your other sites that use the LW app with the same account. To end it, remove the app\'s access in your account: %2$s', 'lw-migration' ), $provider->label, self::PAGES[ $provider->key ] );
	}

	/**
	 * @param string $refresh The refresh token.
	 * @param string $label   Provider name.
	 * @return int HTTP status.
	 * @throws CloudFailure When the request is refused before sending or fails on the way.
	 */
	private function google( #[\SensitiveParameter] string $refresh, string $label ): int {
		return $this->post( self::GOOGLE, [ 'Content-Type' => 'application/x-www-form-urlencoded' ], http_build_query( [ 'token' => $refresh ], '', '&', PHP_QUERY_RFC3986 ), $label );
	}

	/**
	 * @param OAuthDestinationType  $type     Type.
	 * @param array<string, mixed>  $settings Settings.
	 * @param array<string, string> $secrets  Opened secrets.
	 * @param string                $label    Provider name.
	 * @return int HTTP status.
	 * @throws CloudFailure When no access token can be had, or the request fails.
	 */
	private function dropbox( OAuthDestinationType $type, array $settings, #[\SensitiveParameter] array $secrets, string $label ): int {
		$access = '';
		if ( 1 === preg_match( '/^(\d{1,12}):(.+)\z/s', (string) ( $secrets[ TokenKeeper::ACCESS ] ?? '' ), $m ) && (int) $m[1] - TokenKeeper::MARGIN > (int) ( $this->now )() ) {
			$access = $m[2];
		}
		if ( '' === $access ) {
			$app    = $type->app( $settings, (string) ( $secrets[ OAuthDestinationType::CLIENT_SECRET ] ?? '' ) );
			$answer = $type->endpointFor( $settings )->refresh( $app, (string) $secrets[ TokenKeeper::REFRESH ] );
			if ( ! OAuthMode::ownApp( $settings ) && ! $this->sameAccount( $type, $settings, (string) ( $secrets[ TokenKeeper::IDENTITY ] ?? '' ), $answer->accessToken ) ) {
				return 0; // Not confirmed as the connected account (#12): never sent; the notice says where to remove the access.
			}
			$access = $answer->accessToken;
		}

		return $this->post(
			self::DROPBOX,
			[
				'Authorization' => 'Bearer ' . $access,
				'Content-Type'  => 'application/json',
			],
			'null',
			$label
		);
	}

	/**
	 * Through the LW app: whether the provider names the recorded account
	 * for a token the LW sign-in service refreshed (false when none was
	 * recorded, so it cannot be confirmed).
	 *
	 * @param OAuthDestinationType $type     Type.
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $recorded The account recorded at connect.
	 * @param string               $access   The refreshed access token.
	 * @throws CloudFailure When the provider does not answer.
	 */
	private function sameAccount( OAuthDestinationType $type, array $settings, string $recorded, #[\SensitiveParameter] string $access ): bool {
		if ( '' === $recorded || TokenKeeper::NO_IDENTITY === $recorded ) {
			return false;
		}

		return hash_equals( $recorded, $type->identify( $settings, $access ) );
	}

	/**
	 * @param string                $url     Revoke endpoint (a constant).
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    Body.
	 * @param string                $label   Provider name.
	 * @throws CloudFailure When the request is refused before sending or fails on the way.
	 */
	private function post( string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body, string $label ): int {
		$response = $this->http->send(
			$url,
			[
				'method'              => 'POST',
				'headers'             => $headers,
				'body'                => $body,
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => DirectTokenEndpoint::MAX_ANSWER,
			],
			$label
		);

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Microsoft has no revoke: the account's app-permissions page, by the
	 * tenant the connection signs in to (common: both).
	 *
	 * @param Provider $provider A Microsoft provider.
	 */
	private static function microsoft( Provider $provider ): string {
		$tenant = (string) ( explode( '/', (string) parse_url( $provider->tokenEndpoint, PHP_URL_PATH ) )[1] ?? 'common' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- An address this plugin built (Provider::microsoft()).
		if ( 'consumers' === $tenant ) {
			$page = self::PAGES['personal'];
		} elseif ( 'common' === $tenant ) {
			/* translators: 1: web address for a personal Microsoft account, 2: web address for a work or school account */
			$page = sprintf( __( '%1$s (personal account) or %2$s (work or school account)', 'lw-migration' ), self::PAGES['personal'], self::PAGES['work'] );
		} else {
			$page = self::PAGES['work'];
		}

		/* translators: 1: provider name, 2: web address(es) of the account's app permissions */
		return sprintf( __( '%1$s cannot withdraw an app\'s access from a site. Remove the app\'s access in your account: %2$s', 'lw-migration' ), $provider->label, $page );
	}
}
