<?php
/**
 * One answer of a token endpoint.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * The tokens of one answer. refreshToken is '' when the answer carried
 * none (Google and Dropbox on a refresh: the stored one stays, plan E6);
 * expiresAt is absolute. The answer is read strictly, without a WordPress
 * function: every token is visible ASCII of at most MAX_TOKEN bytes (the
 * access token goes into an Authorization header, so a line break must
 * never pass); token_type is Bearer when given (all three providers
 * document Bearer); expires_in is a whole number (missing: one hour)
 * clamped to 5 min .. 1 day. An optional field that is absent or JSON null
 * counts as absent; one that is present but malformed refuses the whole
 * answer.
 */
final class TokenSet {

	public const MAX_TOKEN = 8192;

	private const MAX_ACCOUNT = 255;

	/** Shortest lifetime believed: longer than twice TokenKeeper::MARGIN, so a token is cached for a while (a shorter real one meets one 401 and a refresh). */
	private const MIN_LIFETIME = 300;

	/**
	 * @param string $accessToken  Access token.
	 * @param int    $expiresAt    Unix time it expires.
	 * @param string $refreshToken New refresh token ('' = none in the answer).
	 * @param string $idToken      OpenID Connect id_token ('' = none).
	 * @param string $accountId    Provider account id ('' = none; Dropbox sends one).
	 */
	public function __construct(
		#[\SensitiveParameter] public string $accessToken,
		public int $expiresAt,
		#[\SensitiveParameter] public string $refreshToken = '',
		#[\SensitiveParameter] public string $idToken = '',
		public string $accountId = ''
	) {
	}

	/**
	 * @param array<string, mixed> $json Decoded answer.
	 * @param int                  $now  Unix time.
	 * @return self|null Null when the answer is not a usable token answer.
	 */
	public static function fromAnswer( #[\SensitiveParameter] array $json, int $now ): ?self {
		$access  = self::text( $json, 'access_token', self::MAX_TOKEN );
		$refresh = self::text( $json, 'refresh_token', self::MAX_TOKEN );
		$id      = self::text( $json, 'id_token', self::MAX_TOKEN );
		$account = self::text( $json, 'account_id', self::MAX_ACCOUNT );
		$life    = self::lifetime( $json['expires_in'] ?? null );
		$type    = $json['token_type'] ?? 'Bearer';
		if ( null === $access || '' === $access || null === $refresh || null === $id || null === $account || null === $life || ! is_string( $type ) || 0 !== strcasecmp( $type, 'Bearer' ) ) {
			return null;
		}

		return new self( $access, $now + $life, $refresh, $id, $account );
	}

	/**
	 * @param array<string, mixed> $json Decoded answer.
	 * @param string               $key  Field.
	 * @param int                  $max  Longest accepted value.
	 * @return string|null '' when absent or null, null when present but malformed.
	 */
	private static function text( #[\SensitiveParameter] array $json, string $key, int $max ): ?string {
		$value = $json[ $key ] ?? null;
		if ( null === $value ) {
			return '';
		}

		return is_string( $value ) && strlen( $value ) <= $max && 1 === preg_match( '/^[\x21-\x7E]+\z/', $value ) ? $value : null;
	}

	/**
	 * @param mixed $value expires_in as sent.
	 * @return int|null Seconds (MIN_LIFETIME .. 86400), null when malformed.
	 */
	private static function lifetime( $value ): ?int {
		if ( null === $value ) {
			return 3600;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,9}\z/', $value ) ) {
			$value = (int) $value;
		}

		return is_int( $value ) ? max( self::MIN_LIFETIME, min( 86400, $value ) ) : null;
	}
}
