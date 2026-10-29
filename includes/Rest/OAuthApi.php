<?php
/**
 * Connecting an OAuth destination (own app or the LW app): start and finish.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Admin\AdminPage;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Pkce;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectRules;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectUri;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayAddress;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5, plan E1–E3, E16, preflight P-C1. start() checks the profile,
 * its client secret and the redirect address (RedirectRules, before any
 * state exists), issues a state bound to the admin, the login session, the
 * profile and its binding (OAuthDestinationType::clientKey(): the mode, the
 * Client ID or the provider, the grant settings), and answers the authorize
 * address (PKCE S256).
 *
 * Through the LW app (spec auth-lwplugins-com §7) start() checks this site's
 * address against the relay's rule (RelayAddress), gives the profile a
 * secrets row (TokenKeeper::anchor()) and answers the relay's authorize
 * address with the address as the relay will use it; the callback then
 * comes back with lwm_relay=1 and a relay_state, which redeem() hands to the
 * relay with the code and the verifier. A relayed answer for an own app, or
 * a direct one for the LW app, is never exchanged.
 *
 * The callback is OAuthCallback's (finish() hands it over).
 */
final class OAuthApi {

	public const CONNECTED = 'connected';

	public const DENIED = 'denied';

	public const EXPIRED = 'expired';

	public const FAILED = 'failed';

	public const REFUSED = 'refused';

	/**
	 * @param Destinations $destinations Profiles, vault, types.
	 * @param OAuthState   $states       States.
	 * @param \Closure     $redirectUri  fn(): string — this site's redirect address.
	 * @param \Closure     $pageUrl      fn(): string — the plugin's admin page.
	 * @param \Closure     $now          fn(): int.
	 */
	public function __construct( private Destinations $destinations, private OAuthState $states, private \Closure $redirectUri, private \Closure $pageUrl, private \Closure $now ) {
	}

	public static function fromWordPress(): self {
		return new self(
			Destinations::fromWordPress(),
			new OAuthState(),
			static fn(): string => RedirectUri::current(),
			static fn(): string => AdminPage::pageUrl(),
			static fn(): int => time()
		);
	}

	/**
	 * Where the callback sends the browser: the destinations tab of the
	 * plugin's page with a fixed code (and the profile id of the state).
	 *
	 * @param string $page The plugin's admin page (AdminPage::pageUrl()).
	 * @param string $code One of the class constants.
	 * @param string $id   Profile id ('' = none).
	 */
	public static function address( string $page, string $code, string $id = '' ): string {
		$args = [ 'lwm-oauth' => $code ];
		if ( 1 === preg_match( Profile::ID_PATTERN, $id ) ) {
			$args['lwm-destination'] = $id;
		}

		return add_query_arg( $args, $page ) . '#destinations';
	}

	/**
	 * The checks of start() without a state: the command line runs them
	 * before it prints a connect link (ConnectLinks).
	 *
	 * @param string $id Profile id.
	 * @throws RestException When the profile cannot connect now.
	 */
	public function ready( string $id ): void {
		$this->prepared( $id );
	}

	/**
	 * @param string $id     Profile id.
	 * @param int    $userId The admin (current user of the request, logged in with a session).
	 * @return array{url: string}
	 * @throws RestException When the profile cannot connect now.
	 */
	public function start( string $id, int $userId ): array {
		[ $profile, $type, $own, $provider, $redirect ] = $this->prepared( $id );
		$verifier                                       = $provider->pkce || ! $own ? Pkce::verifier() : '';
		try {
			if ( ! $own ) {
				TokenKeeper::anchor( $this->destinations->vault(), $id );
				if ( null === $this->destinations->find( $id ) ) {
					$this->destinations->vault()->forget( $id ); // Deleted meanwhile: no row outlives it.
					throw RestException::destinationMissing();
				}
			}
			$state = $this->states->issue( $userId, $id, $type->clientKey( $profile->settings ), $redirect, $verifier );
		} catch ( RestException $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new RestException( 'lw_migration_oauth_unavailable', $e->getMessage(), 409 );
		}
		$challenge = '' === $verifier ? '' : Pkce::challenge( $verifier );
		if ( $own ) {
			return [ 'url' => $provider->authorizeUrl( (string) $profile->settings[ OAuthDestinationType::CLIENT_ID ], $redirect, $state, $challenge ) ];
		}

		return [ 'url' => Relay::authorizeUrl( $provider, (string) RelayAddress::normalized( $redirect, Relay::httpHosts() ), $state, $challenge ) ]; // As the relay will compare it.
	}

	/**
	 * @param string $id Profile id.
	 * @return array{0: Profile, 1: OAuthDestinationType, 2: bool, 3: Provider, 4: string} Profile, type, own app, provider, redirect address.
	 * @throws RestException When the profile cannot connect now.
	 */
	private function prepared( string $id ): array {
		[ $profile, $type ] = $this->oauthProfile( $id );
		$own                = OAuthMode::ownApp( $profile->settings );
		if ( $own ) {
			$this->assertOwnApp( $profile );
		}
		$provider = $type->provider( $profile->settings );
		$redirect = (string) ( $this->redirectUri )();
		$refusal  = $own ? RedirectRules::check( $provider, $redirect ) : RelayAddress::check( $redirect );
		if ( null !== $refusal ) {
			throw new RestException( 'lw_migration_redirect_refused', $refusal['text'], 422, [ 'reason' => $refusal['code'] ] );
		}

		return [ $profile, $type, $own, $provider, $redirect ];
	}

	/**
	 * @param Profile $profile An own-app profile.
	 * @throws RestException Without a Client ID or a stored client secret.
	 */
	private function assertOwnApp( Profile $profile ): void {
		if ( '' === (string) ( $profile->settings[ OAuthDestinationType::CLIENT_ID ] ?? '' ) ) {
			throw RestException::invalid( __( 'Enter the Client ID first.', 'lw-migration' ), OAuthDestinationType::CLIENT_ID );
		}
		if ( ! in_array( OAuthDestinationType::CLIENT_SECRET, $this->destinations->vault()->fields( $profile->id ), true ) ) {
			throw RestException::invalid( __( 'Enter the client secret first.', 'lw-migration' ), OAuthDestinationType::CLIENT_SECRET );
		}
	}

	/**
	 * The callback (OAuthCallback::finish()).
	 *
	 * @param array<string, mixed> $query  Callback query.
	 * @param int                  $userId User of the login cookie (0 = none).
	 * @return string Where to send the browser.
	 */
	public function finish( #[\SensitiveParameter] array $query, int $userId ): string {
		return ( new OAuthCallback( $this->destinations, $this->states, $this->pageUrl, $this->now ) )->finish( $query, $userId );
	}

	/**
	 * @param string $id Profile id.
	 * @return array{0: Profile, 1: OAuthDestinationType}
	 * @throws RestException 404, or 400 for a profile without an account to connect.
	 */
	private function oauthProfile( string $id ): array {
		$profile = 1 === preg_match( Profile::ID_PATTERN, $id ) ? $this->destinations->find( $id ) : null;
		if ( null === $profile ) {
			throw RestException::destinationMissing();
		}
		$type = $this->destinations->registry()->get( $profile->type );
		if ( ! $type instanceof OAuthDestinationType ) {
			throw RestException::invalid( __( 'This destination does not connect with an account.', 'lw-migration' ), 'type' );
		}

		return [ $profile, $type ];
	}
}
