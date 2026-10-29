<?php
/**
 * Which provider account an access token belongs to, asked of the provider itself.
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
 * Issue #12. One request with the access token as Bearer straight to the
 * provider's API host, through ProviderHttp (checked and pinned address,
 * TLS checked, no redirect): never through the LW sign-in service, so
 * the service cannot answer it. Its answer is "<provider>:<id>":
 * - Dropbox: users/get_current_account, `account_id`;
 * - Microsoft: the app folder (me/drive/special/approot, which every
 *   permission this plugin asks for may read), the `driveId` of its
 *   parentReference, else (not seen) the folder's own `id` as `item:<id>`;
 * - Google: drive/v3/about, `user.permissionId`.
 *
 * The connect records it (OAuthCallback) in both modes; through the LW app
 * every refreshed access token is asked again before it is kept
 * (TokenKeeper::store()) and before a revoke (GrantRevoker). A transport
 * failure is ProviderHttp's (a timeout or a network error: TransientFailure
 * NETWORK); an answer that is not 200 is a TransientFailure when the
 * status passes (HttpErrors::passing(): 408, 429 as BUSY, 5xx; with its
 * Retry-After), else PermanentFailure INVALID (401, 403); a 200 without a
 * usable id is INVALID too. Nothing of the answer is put in a message.
 */
final class AccountCheck {

	public const TIMEOUT = 30;

	public const MAX_ANSWER = 65536;

	public const DROPBOX = 'https://api.dropboxapi.com/2/users/get_current_account';

	public const MICROSOFT = 'https://graph.microsoft.com/v1.0/me/drive/special/approot';

	public const GOOGLE = 'https://www.googleapis.com/drive/v3/about?fields=user(permissionId)';

	/** Longest id kept (the provider prefix comes on top). */
	private const MAX_ID = 255;

	/**
	 * The guarded send path.
	 *
	 * @var ProviderHttp
	 */
	private ProviderHttp $http;

	/**
	 * Clock: fn(): int (for Retry-After).
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
	 * @param Provider $provider    The connection's provider.
	 * @param string   $accessToken The access token to ask with.
	 * @return string "<provider>:<id>".
	 * @throws CloudFailure When the provider does not confirm an account (see the class doc).
	 */
	public function identify( Provider $provider, #[\SensitiveParameter] string $accessToken ): string {
		$headers = [
			'Authorization' => 'Bearer ' . $accessToken,
			'Accept'        => 'application/json',
		];
		$args    = [
			'method'              => 'GET',
			'headers'             => $headers,
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_ANSWER,
		];
		$url     = Provider::MICROSOFT === $provider->key ? self::MICROSOFT : self::GOOGLE;
		if ( Provider::DROPBOX === $provider->key ) {
			$url                             = self::DROPBOX;
			$args['method']                  = 'POST';
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = 'null';
		}
		$response = $this->http->send( $url, $args, $provider->label );
		$status   = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			throw self::unconfirmed( $provider->label, $status, HttpErrors::retryAfter( (string) wp_remote_retrieve_header( $response, 'retry-after' ), (int) ( $this->now )() ) );
		}
		$id = self::id( $provider->key, HttpFailures::json( (string) wp_remote_retrieve_body( $response ) ) );
		if ( '' === $id ) {
			throw self::unconfirmed( $provider->label, $status );
		}

		return $provider->key . ':' . $id;
	}

	/**
	 * @param string               $key  Provider key.
	 * @param array<string, mixed> $json The answer.
	 * @return string The id ('' when the answer has no usable one).
	 */
	private static function id( string $key, array $json ): string {
		if ( Provider::DROPBOX === $key ) {
			return self::text( $json['account_id'] ?? null );
		}
		if ( Provider::GOOGLE === $key ) {
			return self::text( is_array( $json['user'] ?? null ) ? ( $json['user']['permissionId'] ?? null ) : null );
		}
		$drive = self::text( is_array( $json['parentReference'] ?? null ) ? ( $json['parentReference']['driveId'] ?? null ) : null );
		$item  = self::text( $json['id'] ?? null );

		return '' !== $drive ? $drive : ( '' === $item ? '' : 'item:' . $item );
	}

	/**
	 * @param mixed $value A value of the answer.
	 */
	private static function text( $value ): string {
		return is_string( $value ) && strlen( $value ) <= self::MAX_ID && 1 === preg_match( '/^[\x21-\x7E]+\z/', $value ) ? $value : '';
	}

	/**
	 * @param string   $label  Provider name.
	 * @param int      $status HTTP status.
	 * @param int|null $after  Retry-After seconds (a passing status only).
	 */
	private static function unconfirmed( string $label, int $status, ?int $after = null ): CloudFailure {
		/* translators: %s: provider name */
		$text = sprintf( __( '%s did not confirm which account this sign-in belongs to.', 'lw-migration' ), $label );
		if ( HttpErrors::passing( $status ) ) {
			return new TransientFailure( $text, 429 === $status ? TransientFailure::BUSY : TransientFailure::SERVER, $status, $after );
		}

		return new PermanentFailure( $text, PermanentFailure::INVALID, $status );
	}
}
