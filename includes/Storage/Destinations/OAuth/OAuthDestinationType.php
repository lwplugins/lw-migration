<?php
/**
 * What the OAuth destination types (Google Drive, Dropbox, OneDrive) share.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5 and spec auth-lwplugins-com §7. Every OAuth type connects
 * through the LW app (the default; OAuthMode) or, behind the "Use your own
 * app" switch, through the admin's own app with a Client ID (setting), a
 * client secret (sealed, required: token writes go only into a profile
 * whose secrets row exists) and the connect marker (Field::OAUTH); its
 * tokens are vault fields no form can set (TokenKeeper::FIELDS). make()
 * refuses (REAUTH, before any request) an own-app profile without the
 * client secret, a profile without a refresh token, or one whose connection
 * was made in the other mode or with another Client ID (connection.client
 * is the OAuthState mark of clientKey() the tokens were issued for): tokens
 * of another app are never used,
 * even if a race wrote them back. While the profile's connection id is not
 * the mark of the stored grant, the connection and the tokens belong to two
 * different connects: it waits (TransientFailure BUSY)
 * for COMMIT_WINDOW seconds after the connect, then asks for a new connect
 * (REAUTH), so neither account ever gets the other's backups. What the
 * admin screens see of a type and of a profile is OAuthView's.
 */
abstract class OAuthDestinationType implements DestinationType {

	public const CLIENT_ID = 'clientId';

	public const CLIENT_SECRET = 'clientSecret';

	public const ACCOUNT = 'account';

	private const CLIENT_ID_LENGTH = 256;

	/** Seconds after a connect in which a profile and its tokens may still be one write apart. */
	public const COMMIT_WINDOW = 300;

	/** Seconds a connect time may lie ahead of this server's clock (servers of one site, Task 8 m5). */
	public const CLOCK_SKEW = 5;

	/**
	 * Vault for new tokens (null: this site's, made on first use).
	 *
	 * @var SecretVault|null
	 */
	private ?SecretVault $vault;

	/**
	 * Token endpoint (null: DirectTokenEndpoint, made on first use).
	 *
	 * @var TokenEndpoint|null
	 */
	private ?TokenEndpoint $endpoint;

	/**
	 * Token endpoint of the LW app (null: RelayTokenEndpoint, made on first use).
	 *
	 * @var RelayTokenEndpoint|null
	 */
	private ?RelayTokenEndpoint $relay;

	/**
	 * Transport for API calls and tokens (null: wp_remote_request).
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $send;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * @param SecretVault|null        $vault    Vault for new tokens (default: this site's).
	 * @param TokenEndpoint|null      $endpoint Own-app token endpoint (default: DirectTokenEndpoint).
	 * @param \Closure|null           $send     Transport for API calls and tokens (tests).
	 * @param \Closure|null           $now      Clock (tests).
	 * @param RelayTokenEndpoint|null $relay    LW-app token endpoint (default: RelayTokenEndpoint).
	 */
	public function __construct( ?SecretVault $vault = null, ?TokenEndpoint $endpoint = null, ?\Closure $send = null, ?\Closure $now = null, ?RelayTokenEndpoint $relay = null ) {
		$this->vault    = $vault;
		$this->endpoint = $endpoint;
		$this->send     = $send;
		$this->now      = $now ?? static fn(): int => time();
		$this->relay    = $relay;
	}

	/**
	 * @param array<string, mixed> $settings Profile settings ([] = defaults).
	 */
	abstract public function provider( array $settings ): Provider;

	/**
	 * @return array<int, string> Translated setup steps.
	 */
	abstract public function steps(): array;

	/** Where the admin registers the app (the provider's console). */
	abstract public function consoleUrl(): string;

	/**
	 * After the first tokens: who is connected, and any provider set-up (the Drive folder).
	 *
	 * @param OAuthHttp $http    Authorized requests.
	 * @param TokenSet  $tokens  The exchange answer.
	 * @param Profile   $profile The profile.
	 * @return array<string, int|string> Connection entries (at least `account`).
	 * @throws \LightweightPlugins\Migration\Storage\Destinations\CloudFailure When the provider refuses.
	 */
	abstract public function connect( OAuthHttp $http, TokenSet $tokens, Profile $profile ): array;

	/**
	 * @param Profile   $profile The profile.
	 * @param OAuthHttp $http    Authorized requests.
	 */
	abstract protected function client( Profile $profile, OAuthHttp $http ): DestinationInterface;

	/**
	 * @return array<int, Field> Provider fields between the secret and the connect marker.
	 */
	protected function extraFields(): array {
		return [];
	}

	/**
	 * @param array<string, mixed> $raw Submitted settings.
	 * @return array<string, mixed>
	 * @throws FieldException On a refused value.
	 */
	protected function extraSettings( array $raw ): array {
		unset( $raw );
		return [];
	}

	/**
	 * The settings that choose the token authority or the consent (OneDrive's
	 * tenant and permission), normalized; '' for a type without any. A
	 * connect, its state and its connection are bound to them together with
	 * the Client ID (clientKey(); Task 10 review I1).
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public function grantMark( array $settings ): string {
		unset( $settings );
		return '';
	}

	/**
	 * What a connect is bound to: the mode, the Client ID (own app) or the
	 * provider (LW app, OAuthMode::relayKey()), and the grant settings when
	 * the type has any (OAuthState::clientMark() of it is the state's and the
	 * connection's `client`).
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public function clientKey( array $settings ): string {
		$id    = OAuthMode::ownApp( $settings ) ? (string) ( $settings[ self::CLIENT_ID ] ?? '' ) : OAuthMode::relayKey( $this->provider( $settings )->key );
		$grant = $this->grantMark( $settings );

		return '' === $id || '' === $grant ? $id : $id . "\0" . $grant;
	}

	/**
	 * Whether tokens issued under the settings before an edit still belong
	 * to the settings after it, besides the Client ID (which the input
	 * checks itself): false when the grant settings change, so the
	 * connection and its tokens are forgotten and the client secret is kept
	 * (Task 10 ruling).
	 *
	 * @param array<string, mixed> $before Stored settings.
	 * @param array<string, mixed> $after  Settings after the edit.
	 */
	public function sameGrant( array $before, array $after ): bool {
		return $this->grantMark( $before ) === $this->grantMark( $after );
	}

	public function unavailable(): ?string {
		return null;
	}

	public function fields(): array {
		$own = [ OAuthMode::OWN_APP => [ true ] ];

		return array_merge(
			[
				new Field( OAuthMode::OWN_APP, Field::TOGGLE, __( 'Use your own app', 'lw-migration' ), false, __( 'Advanced: connect through an app you registered at the provider yourself, with its Client ID and client secret.', 'lw-migration' ), [], false ),
				new Field( self::CLIENT_ID, Field::TEXT, __( 'Client ID', 'lw-migration' ), true, __( 'From your own app at the provider (see the steps).', 'lw-migration' ), [], null, $own ),
				new Field( self::CLIENT_SECRET, Field::SECRET, __( 'Client secret', 'lw-migration' ), true, '', [], null, $own ),
			],
			$this->extraFields(),
			[ new Field( self::ACCOUNT, Field::OAUTH, __( 'Account', 'lw-migration' ) ) ]
		);
	}

	public function settings( array $raw ): array {
		$own = $raw[ OAuthMode::OWN_APP ] ?? null;
		if ( null !== $own && ! is_bool( $own ) ) {
			throw new FieldException( OAuthMode::OWN_APP, __( 'Use your own app is on or off.', 'lw-migration' ) );
		}
		if ( false === $own || ( null === $own && ! array_key_exists( self::CLIENT_ID, $raw ) ) ) {
			return [ OAuthMode::OWN_APP => false ]; // The LW app: no Client ID, no provider settings (OneDrive: common, appfolder).
		}
		$id = trim( is_string( $raw[ self::CLIENT_ID ] ?? null ) ? $raw[ self::CLIENT_ID ] : '' );
		if ( '' === $id || strlen( $id ) > self::CLIENT_ID_LENGTH || 1 !== preg_match( '/^[\x21-\x7E]+\z/', $id ) ) {
			throw new FieldException( self::CLIENT_ID, __( 'Enter the Client ID exactly as the provider shows it (no spaces).', 'lw-migration' ) );
		}

		return [
			OAuthMode::OWN_APP => true,
			self::CLIENT_ID    => $id,
		] + $this->extraSettings( $raw );
	}

	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
		return $this->client( $profile, $this->http( $profile, $secrets ) );
	}

	/**
	 * Authorized requests for a connected profile; nothing is sent here.
	 *
	 * @param Profile               $profile Profile.
	 * @param array<string, string> $secrets Opened secrets.
	 * @throws PermanentFailure REAUTH without the client secret (own app), for tokens of another connection after COMMIT_WINDOW, without a refresh token, for tokens of another Client ID, or for a connection without an id (a TransientFailure BUSY for tokens of another connection within COMMIT_WINDOW seconds of the connect passes through; Task 7 review I1, round 2).
	 */
	public function http( Profile $profile, #[\SensitiveParameter] array $secrets ): OAuthHttp {
		$secret = (string) ( $secrets[ self::CLIENT_SECRET ] ?? '' );
		if ( '' === $secret && OAuthMode::ownApp( $profile->settings ) ) {
			throw new PermanentFailure( __( 'The client secret of this destination is missing. Enter it again.', 'lw-migration' ), PermanentFailure::REAUTH );
		}
		$app = $this->app( $profile->settings, $secret );
		$this->sameGeneration( $profile, $secrets, $app->provider->label );
		if ( '' === (string) ( $secrets[ TokenKeeper::REFRESH ] ?? '' ) ) {
			throw $this->notConnected( $app->provider->label );
		}
		if ( ! $this->bound( $profile ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'This destination was connected to %s with another Client ID or other sign-in settings. Choose Connect.', 'lw-migration' ), $app->provider->label ), PermanentFailure::REAUTH );
		}
		if ( '' === (string) ( $profile->connection['id'] ?? '' ) ) {
			throw $this->notConnected( $app->provider->label ); // Never committed by the callback: fail closed.
		}
		$settings = $profile->settings;
		$verify   = OAuthMode::ownApp( $settings ) ? null : fn( string $access ): string => $this->identify( $settings, $access ); // Through the LW app: every refreshed token is asked at the provider (#12).
		$keeper   = new TokenKeeper( $profile->id, $app, $secrets, $this->endpointFor( $settings ), $this->vault(), $this->now, $verify );

		return new OAuthHttp( $keeper, $this->send, $this->now );
	}

	/**
	 * Task 7 review I1 / round 2: the profile's connection (its id) and the
	 * stored tokens (their grant) must come from one connect. Right after a
	 * connect they may differ for a moment (between its two writes): wait.
	 * Later (or for a connect time more than CLOCK_SKEW seconds in the
	 * future) the token write must have
	 * failed: the run ends and asks for a new connect (REAUTH; the reconnect
	 * mail goes once). A connection without an id is left to the checks
	 * after this one.
	 *
	 * @param Profile               $profile Profile.
	 * @param array<string, string> $secrets Opened secrets.
	 * @param string                $label   Provider name.
	 * @throws TransientFailure BUSY within COMMIT_WINDOW seconds of the connect (CLOCK_SKEW ahead included).
	 * @throws PermanentFailure REAUTH after it, or before it (a connect time further in the future).
	 */
	private function sameGeneration( Profile $profile, #[\SensitiveParameter] array $secrets, string $label ): void {
		$id = (string) ( $profile->connection['id'] ?? '' );
		if ( '' === $id || hash_equals( $id, TokenKeeper::connectionId( (string) ( $secrets[ TokenKeeper::GRANT ] ?? '' ) ) ) ) {
			return;
		}
		$age = (int) ( $this->now )() - (int) ( $profile->connection['connectedAt'] ?? 0 );
		if ( $age >= -self::CLOCK_SKEW && $age < self::COMMIT_WINDOW ) { // A connect time further in the future never waits (Task 7 minor, Task 8 m5).
			/* translators: %s: provider name */
			throw new TransientFailure( sprintf( __( 'The connection to %s changed meanwhile; trying again.', 'lw-migration' ), $label ), TransientFailure::BUSY );
		}

		throw new PermanentFailure( __( 'The new sign-in could not be stored, so this destination waits for a new connection. Choose Connect again.', 'lw-migration' ), PermanentFailure::REAUTH );
	}

	private function notConnected( string $label ): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( 'This destination is not connected to %s yet. Choose Connect.', 'lw-migration' ), $label ), PermanentFailure::REAUTH );
	}

	/**
	 * The account an access token belongs to, asked straight at the
	 * provider (AccountCheck; issue #12).
	 *
	 * @param array<string, mixed> $settings    Settings.
	 * @param string               $accessToken Access token.
	 * @throws \LightweightPlugins\Migration\Storage\Destinations\CloudFailure When the provider does not confirm one.
	 */
	public function identify( array $settings, #[\SensitiveParameter] string $accessToken ): string {
		return ( new AccountCheck( $this->send, $this->now ) )->identify( $this->provider( $settings ), $accessToken );
	}

	public function endpoint(): TokenEndpoint {
		if ( null === $this->endpoint ) {
			$this->endpoint = new DirectTokenEndpoint( $this->send, $this->now );
		}

		return $this->endpoint;
	}

	public function relay(): RelayTokenEndpoint {
		if ( null === $this->relay ) {
			$this->relay = new RelayTokenEndpoint( $this->send, $this->now );
		}

		return $this->relay;
	}

	/**
	 * The endpoint a connection refreshes through: the own app's straight to
	 * the provider, the LW app's through the relay — never the other (the
	 * mode is in clientKey(), and a mode switch forgets the tokens).
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public function endpointFor( array $settings ): TokenEndpoint {
		return OAuthMode::ownApp( $settings ) ? $this->endpoint() : $this->relay();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $secret   The own app's client secret ('' and unused for the LW app: the relay holds its secret).
	 */
	public function app( array $settings, #[\SensitiveParameter] string $secret ): OAuthApp {
		$own = OAuthMode::ownApp( $settings );

		return new OAuthApp( $this->provider( $settings ), $own ? (string) ( $settings[ self::CLIENT_ID ] ?? '' ) : '', $own ? $secret : '' );
	}

	public function vault(): SecretVault {
		if ( null === $this->vault ) {
			$this->vault = new SecretVault();
		}

		return $this->vault;
	}

	public function transport(): ?\Closure {
		return $this->send;
	}

	/**
	 * The provider's name once connected: the account label (often an
	 * e-mail address) is shown only in the view's `connection` (H16), never
	 * in `where`, which also goes to CLI lists.
	 *
	 * @param Profile $profile The profile.
	 */
	public function describe( Profile $profile ): string {
		if ( '' === (string) ( $profile->connection['account'] ?? '' ) ) {
			return __( 'Not connected yet', 'lw-migration' );
		}

		return $this->provider( $profile->settings )->label . ' · ' . $this->folderShown( SiteFolder::of( $profile->connection ) );
	}

	/**
	 * How the site's folder reads in `where`.
	 *
	 * @param string $folder The site folder name.
	 */
	protected function folderShown( string $folder ): string {
		return $folder;
	}

	public function insecure( Profile $profile ): bool {
		return false;
	}

	/**
	 * Whether the profile's connection was made with its current binding
	 * (clientKey(); OAuthView asks too).
	 *
	 * @param Profile $profile Profile.
	 */
	public function bound( Profile $profile ): bool {
		$mark = (string) ( $profile->connection['client'] ?? '' );

		return '' !== $mark && hash_equals( ( new OAuthState() )->clientMark( $this->clientKey( $profile->settings ) ), $mark );
	}
}
