<?php
/**
 * Token requests straight to the provider with the site's own client secret.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\HttpErrors;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E5/E7. A form POST with client_id and client_secret in the body (all
 * three providers document it); the PKCE verifier on an exchange; Microsoft
 * repeats the scope. Sent through ProviderHttp (P-C2: checked and pinned,
 * TLS checked, no redirect followed, the answer capped at MAX_ANSWER). The
 * code, verifier, secret and tokens travel only in the POST body, never in
 * a URL or a message. invalid_grant: on a refresh the grant is gone
 * (REAUTH), on an exchange the code is (INVALID: connect again);
 * Microsoft's interaction_required and consent_required likewise need the
 * user. invalid_client / unauthorized_client: AUTH. temporarily_unavailable
 * (Microsoft) and every passing status: transient, retried by the caller.
 * Only a code from SHOWN may reach a message (P-M10), else the status.
 */
final class DirectTokenEndpoint implements TokenEndpoint {

	public const TIMEOUT = 30;

	public const MAX_ANSWER = 65536;

	/** The user must sign in again (RFC 6749 5.2; Microsoft's v2 token endpoint error table, read 2026-10-13). */
	private const NEEDS_USER = [ 'invalid_grant', 'interaction_required', 'consent_required' ];

	/** The app's credentials or registration are refused. */
	private const CLIENT = [ 'invalid_client', 'unauthorized_client' ];

	/** Codes that may appear in our message. */
	private const SHOWN = [ 'invalid_request', 'invalid_scope', 'unsupported_grant_type', 'invalid_resource', 'access_denied' ];

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

	public function exchange( OAuthApp $app, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $redirectUri ): TokenSet {
		if ( '' === $code || ( $app->provider->pkce && ! Pkce::isVerifier( $verifier ) ) ) {
			throw self::codeGone( $app->provider->label, 0 );
		}
		$fields = [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirectUri,
		];
		if ( $app->provider->pkce ) {
			$fields['code_verifier'] = $verifier;
		}

		return $this->post( $app, $fields, false );
	}

	public function refresh( OAuthApp $app, #[\SensitiveParameter] string $refreshToken ): TokenSet {
		if ( '' === $refreshToken ) {
			throw self::withdrawn( $app->provider->label, 0 );
		}

		return $this->post(
			$app,
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refreshToken,
			],
			true
		);
	}

	/**
	 * @param OAuthApp             $app        App.
	 * @param int                  $status     HTTP status (not 200).
	 * @param array<string, mixed> $json       Decoded error answer (only classified, never shown).
	 * @param bool                 $refreshing A refresh (not an exchange).
	 * @param string               $retryAfter Retry-After header.
	 * @param int                  $now        Unix time.
	 */
	public static function refusal( OAuthApp $app, int $status, array $json, bool $refreshing, string $retryAfter, int $now ): CloudFailure {
		$error = is_string( $json['error'] ?? null ) ? $json['error'] : '';
		$label = $app->provider->label;
		if ( HttpErrors::passing( $status ) ) {
			return HttpFailures::generic( $status, $label, $retryAfter, $now );
		}
		if ( 'temporarily_unavailable' === $error ) {
			/* translators: %s: provider name */
			return new TransientFailure( sprintf( __( '%s asked to slow down; trying again later.', 'lw-migration' ), $label ), TransientFailure::BUSY, $status, HttpFailures::retryAfter( $retryAfter, $now ) );
		}
		if ( in_array( $error, self::NEEDS_USER, true ) ) {
			return $refreshing ? self::withdrawn( $label, $status ) : self::codeGone( $label, $status );
		}
		if ( in_array( $error, self::CLIENT, true ) ) {
			/* translators: %s: provider name */
			return new PermanentFailure( sprintf( __( '%s refused the Client ID or the client secret. Check both in the destination (a Microsoft client secret expires and must be renewed).', 'lw-migration' ), $label ), PermanentFailure::AUTH, $status );
		}
		$shown = in_array( $error, self::SHOWN, true ) ? $error : (string) $status;

		/* translators: 1: provider name, 2: error code from the provider (e.g. invalid_scope) or the HTTP status */
		return new PermanentFailure( sprintf( __( '%1$s refused the token request (%2$s).', 'lw-migration' ), $label, $shown ), PermanentFailure::AUTH, $status );
	}

	/**
	 * @param OAuthApp              $app        App.
	 * @param array<string, string> $fields     Grant fields.
	 * @param bool                  $refreshing A refresh.
	 * @throws PermanentFailure AUTH when the Client ID is missing (any CloudFailure of the request or the answer passes through).
	 */
	private function post( OAuthApp $app, #[\SensitiveParameter] array $fields, bool $refreshing ): TokenSet {
		$label = $app->provider->label;
		if ( '' === $app->clientId ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'The Client ID for %s is missing. Enter it in the destination.', 'lw-migration' ), $label ), PermanentFailure::AUTH );
		}
		$fields['client_id'] = $app->clientId;
		if ( '' !== $app->clientSecret ) {
			$fields['client_secret'] = $app->clientSecret;
		}
		if ( $app->provider->scopeOnToken ) {
			$fields['scope'] = $app->provider->scope();
		}
		$response = $this->http->send(
			$app->provider->tokenEndpoint,
			[
				'method'              => 'POST',
				'headers'             => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				],
				'body'                => http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ),
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => self::MAX_ANSWER,
			],
			$label
		);

		return $this->answer( $app, $response, $refreshing );
	}

	/**
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
		$set = TokenSet::fromAnswer( $json, $now );
		if ( null === $set ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s sent an unexpected token answer.', 'lw-migration' ), $app->provider->label ), PermanentFailure::INVALID, $status );
		}

		return $set;
	}

	/**
	 * The code was used or expired (INVALID: connect again). Also the relay's.
	 *
	 * @param string $label  Provider name.
	 * @param int    $status HTTP status (0 = none).
	 */
	public static function codeGone( string $label, int $status ): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( 'The sign-in at %s could not be completed (the code was used or expired). Start the connection again.', 'lw-migration' ), $label ), PermanentFailure::INVALID, $status );
	}

	/**
	 * The grant is gone (REAUTH). Also the relay's.
	 *
	 * @param string $label  Provider name.
	 * @param int    $status HTTP status (0 = none).
	 */
	public static function withdrawn( string $label, int $status ): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( 'The access to %s was withdrawn or has expired. Connect the account again.', 'lw-migration' ), $label ), PermanentFailure::REAUTH, $status );
	}
}
