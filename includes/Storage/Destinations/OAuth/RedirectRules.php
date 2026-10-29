<?php
/**
 * Whether a provider accepts this site's redirect address.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5 ("a UI ezt a bekötés előtt jelzi"): checked when the schema is
 * built and again before a connect starts (before any state is issued), with
 * the rules of Provider. A localhost host passes the https rule (all three
 * exempt localhost); 127.0.0.1 and [::1] only where the provider's loopIp
 * rule allows them (not Microsoft). The public-suffix rule is approximated: a
 * host without a dot, or under a reserved or private-use top-level name, is
 * refused for Google. firstBroken() calls no WordPress function (unit
 * tested); check() adds our translated text. The text never echoes the
 * address.
 */
final class RedirectRules {

	public const HTTPS = 'https';

	public const IP = 'ip';

	public const DOMAIN = 'domain';

	public const QUERY = 'query';

	public const LENGTH = 'length';

	public const CHARS = 'chars';

	public const IDN = 'idn';

	private const LOOPBACK_IPS = [ '127.0.0.1', '[::1]' ];

	private const PRIVATE_TLDS = [ 'test', 'example', 'invalid', 'local', 'localhost', 'internal', 'lan', 'home', 'corp', 'intranet' ];

	/**
	 * @param Provider $provider Provider.
	 * @param string   $uri      Redirect address.
	 * @return array{code: string, text: string}|null Null when acceptable.
	 */
	public static function check( Provider $provider, string $uri ): ?array {
		$code = self::firstBroken( $provider, $uri );

		return null === $code ? null : [
			'code' => $code,
			'text' => self::text( $provider, $code ),
		];
	}

	/**
	 * @param Provider $provider Provider.
	 * @param string   $uri      Redirect address.
	 * @return string|null The first rule the address breaks (a class constant), null when acceptable.
	 */
	public static function firstBroken( Provider $provider, string $uri ): ?string {
		$parts = parse_url( $uri ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure path (unit tested); PHP 8 parses these addresses correctly.
		if ( ! is_array( $parts ) ) {
			return self::HTTPS;
		}
		$scheme   = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host     = strtolower( (string) ( $parts['host'] ?? '' ) );
		$loopback = 'localhost' === $host || ( $provider->rule( 'loopIp' ) && in_array( $host, self::LOOPBACK_IPS, true ) );
		if ( '' === $host || ( 'https' !== $scheme && ! ( 'http' === $scheme && $loopback ) ) ) {
			return self::HTTPS;
		}
		if ( ! $provider->rule( 'ip' ) && ! $loopback && false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return self::IP;
		}
		if ( $provider->rule( 'suffix' ) && ! $loopback && ! self::looksPublic( $host ) ) {
			return self::DOMAIN;
		}
		if ( ! $provider->rule( 'query' ) && isset( $parts['query'] ) ) {
			return self::QUERY;
		}
		$length = (int) $provider->rule( 'length' );
		if ( $length > 0 && strlen( $uri ) > $length ) {
			return self::LENGTH;
		}
		if ( $provider->rule( 'chars' ) && false !== strpbrk( $uri, "!$'(),;" ) ) {
			return self::CHARS;
		}
		if ( ! $provider->rule( 'idn' ) && ( 1 === preg_match( '/[^\x00-\x7F]/', $host ) || 1 === preg_match( '/(^|\.)xn--/', $host ) ) ) {
			return self::IDN;
		}

		return null;
	}

	private static function looksPublic( string $host ): bool {
		$dot = strrpos( $host, '.' );

		return false !== $dot && ! in_array( substr( $host, $dot + 1 ), self::PRIVATE_TLDS, true );
	}

	private static function text( Provider $provider, string $code ): string {
		switch ( $code ) {
			case self::HTTPS:
				/* translators: %s: provider name, e.g. Google Drive */
				return sprintf( __( '%s accepts only an https address here (or localhost). Switch this site to https first.', 'lw-migration' ), $provider->label );
			case self::IP:
				return __( 'Google does not accept an address with a bare IP number. Give the site a domain name first.', 'lw-migration' );
			case self::DOMAIN:
				return __( 'Google accepts only addresses on a public domain (not .test, .local or a name without a dot).', 'lw-migration' );
			case self::QUERY:
				return __( 'Microsoft does not accept a "?" in this address for apps that sign in personal accounts. Turn on pretty permalinks (Settings > Permalinks), or use a work-only app with its tenant ID.', 'lw-migration' );
			case self::LENGTH:
				return __( 'This address is longer than the 256 characters Microsoft accepts.', 'lw-migration' );
			case self::CHARS:
				return __( 'Microsoft does not accept the characters ! $ \' ( ) , ; in this address.', 'lw-migration' );
			default:
				return __( 'Microsoft does not accept an internationalized domain name in this address.', 'lw-migration' );
		}
	}
}
