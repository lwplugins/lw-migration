<?php
/**
 * Connecting an OAuth destination: the callback that finishes it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\CheckOnlyEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\GrantWithdrawal;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5, plan E1–E3, E16, preflight P-C1; split out of OAuthApi, which
 * starts a connect (issue #12).
 *
 * The callback, finish(), uses the state up before anything else (OAuthState::consume(),
 * with the user of the login cookie); the profile comes only from the
 * state's record, never from the request. Then, in this order: exchange the
 * code with the recorded redirect address; ask the provider who is connected
 * with the fresh tokens held in memory only (a TokenKeeper without a grant
 * never writes, and its CheckOnlyEndpoint never refreshes: a 401 during the
 * check fails the connect); ask the provider straight (AccountCheck,
 * issue #12) which account the access token belongs to, recorded with the
 * tokens; make a new grant; commit the connection
 * (ProfileRepository::connect(), only while the profile exists with the
 * same binding; its id is the grant's keyed mark); then the tokens under
 * that grant (TokenKeeper::commit(), only into a profile whose secrets row
 * exists). A profile whose connection id is not the mark of the stored
 * grant is never used (OAuthDestinationType::http() waits), so a reader
 * between the two writes, or a failed token write, never pairs one
 * account's label and folder with another account's tokens.
 * A refused consent, a provider error, a failed exchange or check, or a
 * binding changed meanwhile writes no token and drops nothing: a working
 * connection keeps working (only the profile's state line records the
 * failure, with our own text); a deleted profile never comes back. A
 * connect that replaced an earlier grant withdraws that one at the
 * provider afterwards, best effort (GrantWithdrawal::replaced(), issue #6).
 *
 * It always answers an address of the plugin's own page — built from
 * AdminPage::pageUrl() with a code from a closed list and the record's
 * profile id, never anything from the request — the detailed text goes on
 * the profile. Nothing of the provider's answer (error, error_description)
 * is ever shown or stored; the account label stays in the connection (the
 * admin view), never in a message.
 */
final class OAuthCallback {

	/** The callback parameter the relay adds (spec auth-lwplugins-com §2.2). */
	private const RELAY_FLAG = 'lwm_relay';

	private const RELAYED = '1';

	/** Longest authorization code accepted from the callback. */
	private const CODE_LENGTH = 4096;

	/**
	 * @param Destinations $destinations Profiles, vault, types.
	 * @param OAuthState   $states       States.
	 * @param \Closure     $pageUrl      fn(): string — the plugin's admin page.
	 * @param \Closure     $now          fn(): int.
	 */
	public function __construct( private Destinations $destinations, private OAuthState $states, private \Closure $pageUrl, private \Closure $now ) {
	}

	/**
	 * @param array<string, mixed> $query  Callback query.
	 * @param int                  $userId User of the login cookie (0 = none).
	 * @return string Where to send the browser.
	 */
	public function finish( #[\SensitiveParameter] array $query, int $userId ): string {
		$record = $this->states->consume( is_string( $query['state'] ?? null ) ? $query['state'] : '', $userId );
		if ( null === $record ) {
			return $this->back( OAuthApi::EXPIRED );
		}
		$profile = $this->destinations->find( $record['profile'] );
		$type    = null === $profile ? null : $this->destinations->registry()->get( $profile->type );
		if ( null === $profile || ! $type instanceof OAuthDestinationType ) {
			return $this->back( OAuthApi::FAILED );
		}
		$key = $type->clientKey( $profile->settings );
		if ( '' === $key || ! hash_equals( $record['client'], $this->states->clientMark( $key ) ) ) {
			$this->failedConnect( $profile, __( 'The Client ID or the sign-in settings changed while the account was being connected. Choose Connect again.', 'lw-migration' ) );
			return $this->back( OAuthApi::REFUSED, $profile->id );
		}
		$own = OAuthMode::ownApp( $profile->settings );
		if ( ( self::RELAYED === ( $query[ self::RELAY_FLAG ] ?? null ) ) === $own ) {
			return $this->back( OAuthApi::FAILED, $profile->id ); // A relayed answer for an own app, or a provider's own answer for the LW app: never exchanged.
		}
		if ( array_key_exists( 'error', $query ) ) {
			return $this->back( 'access_denied' === $query['error'] ? OAuthApi::DENIED : OAuthApi::FAILED, $profile->id );
		}
		$code = is_string( $query['code'] ?? null ) ? $query['code'] : '';
		if ( '' === $code || strlen( $code ) > self::CODE_LENGTH ) {
			return $this->back( OAuthApi::FAILED, $profile->id );
		}
		$relayState = $own || ! is_string( $query['relay_state'] ?? null ) ? '' : $query['relay_state'];
		if ( ! $own && ! RelayTokenEndpoint::isRelayState( $relayState ) ) {
			return $this->back( OAuthApi::FAILED, $profile->id );
		}
		try {
			return $this->back( $this->connect( $profile, $type, $code, $relayState, $record ), $profile->id );
		} catch ( CloudFailure $e ) {
			$this->failedConnect( $profile, $e->getMessage() );
			return $this->back( OAuthApi::FAILED, $profile->id );
		}
	}

	/**
	 * Exchange (straight to the provider, or redeemed through the relay), account check in memory, then the commit.
	 *
	 * @param Profile                                                                       $profile    Profile.
	 * @param OAuthDestinationType                                                          $type       Its type.
	 * @param string                                                                        $code       Authorization code.
	 * @param string                                                                        $relayState The relay state ('' for the own app).
	 * @param array{profile: string, client: string, redirectUri: string, verifier: string} $record     The used state.
	 * @return string CONNECTED, REFUSED or FAILED (no new token stored for the last two).
	 * @throws PermanentFailure Without a readable client secret (own app), or for an answer without a refresh token; any other CloudFailure of the exchange or the check passes through (nothing stored in either case).
	 */
	private function connect( Profile $profile, OAuthDestinationType $type, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $relayState, #[\SensitiveParameter] array $record ): string {
		$own    = OAuthMode::ownApp( $profile->settings );
		$held   = $this->destinations->vault()->open( $profile->id ) ?? []; // Also the grant a new connection replaces: withdrawn after it (#6).
		$secret = $own ? (string) ( $held[ OAuthDestinationType::CLIENT_SECRET ] ?? '' ) : '';
		if ( $own && '' === $secret ) {
			throw new PermanentFailure( __( 'The client secret of this destination can no longer be read. Enter it again.', 'lw-migration' ), PermanentFailure::REAUTH );
		}
		$app    = $type->app( $profile->settings, $secret );
		$tokens = $own ? $type->endpoint()->exchange( $app, $code, $record['verifier'], $record['redirectUri'] ) : $type->relay()->redeem( $app, $code, $record['verifier'], $relayState );
		if ( '' === $tokens->refreshToken ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s gave no lasting access. Remove the app\'s access in your account settings, then choose Connect again.', 'lw-migration' ), $app->provider->label ), PermanentFailure::INVALID );
		}
		$extra      = $type->connect( $this->checkHttp( $profile->id, $app, $type, $tokens ), $tokens, $profile );
		$identity   = $type->identify( $profile->settings, $tokens->accessToken ); // Straight at the provider; refreshes through the LW app are compared with it (#12).
		$grant      = TokenKeeper::newGrant();
		$connection = array_merge(
			[
				'client'      => $record['client'],
				'id'          => TokenKeeper::connectionId( $grant ),
				'connectedAt' => (int) ( $this->now )(),
				'redirectUri' => $record['redirectUri'],
			],
			ConnectionEntries::check( $extra, $app->provider->label )
		);

		$result = $this->commit( $profile, $connection, $type, $tokens, $grant, $identity );
		if ( OAuthApi::CONNECTED === $result ) {
			( new GrantWithdrawal( $this->destinations, $this->now ) )->replaced( $profile, $held );
		}

		return $result;
	}

	/**
	 * Authorized requests for the account check: the exchange answer in
	 * memory, no grant (never written), no refresh.
	 *
	 * @param string               $id     Profile id.
	 * @param OAuthApp             $app    App.
	 * @param OAuthDestinationType $type   Type.
	 * @param TokenSet             $tokens The exchange answer.
	 */
	private function checkHttp( string $id, OAuthApp $app, OAuthDestinationType $type, #[\SensitiveParameter] TokenSet $tokens ): OAuthHttp {
		$memory = [
			TokenKeeper::REFRESH => $tokens->refreshToken,
			TokenKeeper::ACCESS  => $tokens->expiresAt . ':' . $tokens->accessToken,
		];
		$keeper = new TokenKeeper( $id, $app, $memory, new CheckOnlyEndpoint( $app->provider->label ), $this->destinations->vault(), $this->now );

		return new OAuthHttp( $keeper, $type->transport(), $this->now );
	}

	/**
	 * The connection first (only into the profile as it is now, same binding
	 * (mode, Client ID or provider, grant settings)), then the tokens under its grant (only into its secrets row). The
	 * connection id is the grant's mark, so between the two writes, or after
	 * a failed token write, the profile and the stored tokens are two
	 * generations and the destination waits instead of uploading with the
	 * other connection's tokens (Task 7 review I1).
	 *
	 * @param Profile                   $profile    The profile as the callback read it.
	 * @param array<string, int|string> $connection New connection.
	 * @param OAuthDestinationType      $type       The profile's type (its clientKey() of the profile as it is now must still give the connection's mark).
	 * @param TokenSet                  $tokens     The exchange answer.
	 * @param string                    $grant      The grant the connection id marks.
	 * @param string                    $identity   The account it was made with (OAuthDestinationType::identify()).
	 * @return string CONNECTED, REFUSED (the binding changed meanwhile) or FAILED (deleted meanwhile, or a write failed).
	 */
	private function commit( Profile $profile, array $connection, OAuthDestinationType $type, #[\SensitiveParameter] TokenSet $tokens, #[\SensitiveParameter] string $grant, string $identity ): string {
		$id     = $profile->id;
		$states = $this->states;
		$bound  = static function ( array $settings ) use ( $type, $states, $connection ): bool {
			$key = $type->clientKey( $settings );

			return '' !== $key && hash_equals( (string) $connection['client'], $states->clientMark( $key ) );
		};
		try {
			$connected = $this->destinations->profiles()->connect( $id, $connection, $bound );
		} catch ( \RuntimeException $e ) {
			$this->failedConnect( $profile, __( 'The new connection could not be saved; the previous one stays. Choose Connect again.', 'lw-migration' ) );
			return OAuthApi::FAILED;
		}
		if ( ! $connected ) {
			return null === $this->destinations->find( $id ) ? OAuthApi::FAILED : OAuthApi::REFUSED;
		}
		try {
			$kept = TokenKeeper::commit( $this->destinations->vault(), $id, $tokens, $grant, $identity );
		} catch ( PermanentFailure $e ) {
			$this->destinations->profiles()->mark( $id, Profile::ERROR, __( 'The new sign-in could not be stored, so this destination waits for a new connection. Choose Connect again.', 'lw-migration' ) );
			return OAuthApi::FAILED;
		}

		if ( ! $kept ) {
			return OAuthApi::FAILED;
		}
		// The exchange answer was just proven at the provider (identify() above), as a passed test would: ok, no old error or reauth text.
		$this->destinations->profiles()->mark( $id, Profile::OK );

		return OAuthApi::CONNECTED;
	}

	/**
	 * Records a connect that failed while the previous connection stays: a
	 * profile that waited for a new sign-in still does (so Reconnect stays
	 * offered; final review m1), any other turns `error`.
	 *
	 * @param Profile $profile The profile as the callback read it.
	 * @param string  $message Our text.
	 */
	private function failedConnect( Profile $profile, string $message ): void {
		$this->destinations->profiles()->mark( $profile->id, Profile::REAUTH === $profile->status ? Profile::REAUTH : Profile::ERROR, $message );
	}

	private function back( string $code, string $id = '' ): string {
		return OAuthApi::address( (string) ( $this->pageUrl )(), $code, $id );
	}
}
