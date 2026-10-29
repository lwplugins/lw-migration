<?php
/**
 * Token requests through the LW sign-in service, which holds the LW app's secret.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;
use LightweightPlugins\Migration\Storage\Destinations\HttpErrors;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec auth-lwplugins-com §2.3/§2.4/§2.6/§7. POST /v1/token (the code, the
 * PKCE verifier and the relay state the callback brought) and POST
 * /v1/refresh (the refresh token), JSON both ways, through GuardedHttp in
 * the hosted-preset mode: the relay's fixed host (Relay::base()) is checked
 * and pinned, TLS is verified, no redirect is followed, the answer is capped
 * at MAX_ANSWER. The code, verifier, relay state and tokens travel only in
 * the body. Only the six token fields of an answer are read. A relayed code
 * needs its relay state, so exchange() of the interface is never used
 * (redeem() is). Errors (ruling R6 of plan M7d): invalid_grant and
 * invalid_request are REAUTH on a refresh and INVALID (connect again) on an
 * exchange; rate_limited, temporarily_unavailable, every passing status and
 * a transport failure cURL saw are transient; relay_misconfigured is
 * transient with our own text (no reconnect, no mail); anything else is
 * INVALID. A refusal before sending (a lookup that failed, a refused
 * address, a request WordPress never sent) keeps GuardedHttp's class and
 * reason with our own text, since GuardedHttp's names the relay's address.
 * Nothing the relay sends, and not its host, is ever put in a message.
 */
final class RelayTokenEndpoint implements TokenEndpoint {

	public const TIMEOUT = 30;

	public const MAX_ANSWER = 65536;

	/** Longest relay state accepted from the callback: the relay's own limit (its state.MaxTokenLength). */
	public const MAX_STATE = 4096;

	/** The relay's token answer (§2.3 step 4); anything else is dropped before reading. */
	public const FIELDS = [ 'access_token', 'refresh_token', 'expires_in', 'token_type', 'scope', 'id_token' ];

	private const STATE_PATTERN = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/';

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
	public function __construct( private ?\Closure $send = null, ?\Closure $now = null, private ?AddressGuard $guard = null ) {
		$this->now = $now ?? static fn(): int => time();
	}

	public static function isRelayState( string $value ): bool {
		return strlen( $value ) <= self::MAX_STATE && 1 === preg_match( self::STATE_PATTERN, $value );
	}

	/**
	 * @param OAuthApp $app         App.
	 * @param string   $code        Code.
	 * @param string   $verifier    Verifier.
	 * @param string   $redirectUri Redirect address.
	 * @throws \LogicException Always: a relayed code is exchanged with redeem(), which carries the relay state.
	 */
	public function exchange( OAuthApp $app, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $redirectUri ): TokenSet {
		throw new \LogicException( 'A relayed code is exchanged with redeem().' );
	}

	/**
	 * @param OAuthApp $app        The LW app of the provider (no Client ID, no secret).
	 * @param string   $code       Authorization code from the relayed callback.
	 * @param string   $verifier   The state's PKCE verifier (the relay checks it against the challenge).
	 * @param string   $relayState The callback's relay_state.
	 * @throws CloudFailure On any refusal (INVALID for unusable input, nothing sent).
	 */
	public function redeem( OAuthApp $app, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, #[\SensitiveParameter] string $relayState ): TokenSet {
		if ( '' === $code || ! Pkce::isVerifier( $verifier ) || ! self::isRelayState( $relayState ) ) {
			$failure = DirectTokenEndpoint::codeGone( $app->provider->label, 0 );
			throw $failure;
		}

		return $this->post(
			'/v1/token',
			[
				'v'             => 1,
				'provider'      => $app->provider->key,
				'code'          => $code,
				'code_verifier' => $verifier,
				'relay_state'   => $relayState,
			],
			$app,
			false
		);
	}

	public function refresh( OAuthApp $app, #[\SensitiveParameter] string $refreshToken ): TokenSet {
		if ( '' === $refreshToken ) {
			$failure = DirectTokenEndpoint::withdrawn( $app->provider->label, 0 );
			throw $failure;
		}

		return $this->post(
			'/v1/refresh',
			[
				'v'             => 1,
				'provider'      => $app->provider->key,
				'refresh_token' => $refreshToken,
			],
			$app,
			true
		);
	}

	/**
	 * @param OAuthApp             $app        App.
	 * @param int                  $status     HTTP status (not 200).
	 * @param array<string, mixed> $json       Decoded answer (only classified, never shown).
	 * @param bool                 $refreshing A refresh (not an exchange).
	 * @param string               $retryAfter Retry-After header.
	 * @param int                  $now        Unix time.
	 */
	public static function refusal( OAuthApp $app, int $status, array $json, bool $refreshing, string $retryAfter, int $now ): CloudFailure {
		$error = is_string( $json['error'] ?? null ) ? $json['error'] : '';
		$label = $app->provider->label;
		$after = HttpFailures::retryAfter( $retryAfter, $now );
		if ( 'relay_misconfigured' === $error ) {
			$text = $refreshing
				? __( 'The LW sign-in service cannot refresh the connection right now; we are on it. Backups try again later.', 'lw-migration' )
				: __( 'The LW sign-in service cannot complete the connection right now; we are on it. Try Connect again later.', 'lw-migration' );
			return new TransientFailure( $text, TransientFailure::SERVER, $status, $after );
		}
		if ( 'rate_limited' === $error || 429 === $status ) {
			return new TransientFailure( __( 'The LW sign-in service asked to slow down; trying again later.', 'lw-migration' ), TransientFailure::BUSY, $status, $after );
		}
		if ( 'temporarily_unavailable' === $error ) {
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( '%s is not available right now; trying again later.', 'lw-migration' ), $label ), TransientFailure::SERVER, $status, $after );
		}
		if ( HttpErrors::passing( $status ) ) {
			/* translators: %d: HTTP status */
			return new TransientFailure( sprintf( __( 'The LW sign-in service is not available right now (%d); trying again later.', 'lw-migration' ), $status ), TransientFailure::SERVER, $status, $after );
		}
		if ( 400 === $status && in_array( $error, [ 'invalid_grant', 'invalid_request' ], true ) ) {
			return $refreshing ? DirectTokenEndpoint::withdrawn( $label, $status ) : DirectTokenEndpoint::codeGone( $label, $status );
		}

		/* translators: %d: HTTP status */
		return new PermanentFailure( sprintf( __( 'The LW sign-in service refused the request (%d).', 'lw-migration' ), $status ), PermanentFailure::INVALID, $status );
	}

	/**
	 * GuardedHttp's refusal (a lookup that failed, a refused address, a
	 * request never sent) with its class, reason and status kept, but our own
	 * text: GuardedHttp's names the relay's address (ruling R8).
	 *
	 * @param CloudFailure $failure GuardedHttp's failure.
	 */
	private static function unsent( CloudFailure $failure ): CloudFailure {
		if ( $failure instanceof TransientFailure ) {
			return new TransientFailure( __( 'The LW sign-in service could not be reached; trying again later.', 'lw-migration' ), $failure->reason, $failure->status, $failure->retryAfter );
		}

		return new PermanentFailure( __( 'This site did not send the request to the LW sign-in service: this server refused its address or the request.', 'lw-migration' ), $failure->reason, $failure->status, $failure->retryAfter );
	}

	/**
	 * @param string               $path       /v1/token or /v1/refresh.
	 * @param array<string, mixed> $body       JSON body.
	 * @param OAuthApp             $app        App.
	 * @param bool                 $refreshing A refresh.
	 * @throws CloudFailure On a refused address or a request never sent (our text), or a refusal.
	 * @throws TransientFailure NETWORK on a transport failure cURL saw.
	 * @throws PermanentFailure INVALID for an answer that is not an array.
	 */
	private function post( string $path, #[\SensitiveParameter] array $body, OAuthApp $app, bool $refreshing ): TokenSet {
		$base  = Relay::base();
		$parts = (array) parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- A constant, or a test base Relay checked.
		$host  = (string) ( $parts['host'] ?? '' );
		$http  = new GuardedHttp( $base, isset( $parts['port'] ) ? $host . ':' . (int) $parts['port'] : $host, (string) ( $parts['scheme'] ?? 'https' ), false, $this->send, $this->guard );
		try {
			$response = $http->send(
				$base . $path,
				[
					'method'              => 'POST',
					'headers'             => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'body'                => (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES ),
					'timeout'             => self::TIMEOUT,
					'limit_response_size' => self::MAX_ANSWER,
					'sslverify'           => true,
					'redirection'         => 0,
				]
			);
		} catch ( CloudFailure $e ) {
			throw self::unsent( $e );
		}
		if ( is_wp_error( $response ) ) {
			throw new TransientFailure( __( 'The LW sign-in service could not be reached; trying again later.', 'lw-migration' ), TransientFailure::NETWORK );
		}
		if ( ! is_array( $response ) ) {
			throw new PermanentFailure( __( 'The LW sign-in service sent an unexpected answer.', 'lw-migration' ), PermanentFailure::INVALID );
		}

		return $this->answer( $app, $response, $refreshing );
	}

	/**
	 * Mirrors DirectTokenEndpoint::answer(), but reads only FIELDS and names the relay, not the provider.
	 *
	 * @param OAuthApp             $app        App.
	 * @param array<string, mixed> $response   Transport answer.
	 * @param bool                 $refreshing A refresh.
	 * @throws CloudFailure On a refusal.
	 * @throws PermanentFailure INVALID for an unusable answer.
	 */
	private function answer( OAuthApp $app, #[\SensitiveParameter] array $response, bool $refreshing ): TokenSet {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$now    = (int) ( $this->now )();
		$json   = strlen( $body ) < self::MAX_ANSWER ? HttpFailures::json( $body ) : [];
		if ( 200 !== $status ) {
			throw self::refusal( $app, $status, $json, $refreshing, (string) wp_remote_retrieve_header( $response, 'retry-after' ), $now );
		}
		$set = TokenSet::fromAnswer( array_intersect_key( $json, array_flip( self::FIELDS ) ), $now );
		if ( null === $set ) {
			throw new PermanentFailure( __( 'The LW sign-in service sent an unexpected token answer.', 'lw-migration' ), PermanentFailure::INVALID, $status );
		}

		return $set;
	}
}
