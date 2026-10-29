<?php
/**
 * The LW sign-in service: its fixed address and the authorize address of a connect.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Spec auth-lwplugins-com §2.1/§7. The relay's address is a constant: no
 * filter, option or setting changes it. useForTests() replaces it for code
 * already running inside this site (the E2E probe), the way
 * AddressGuard::useResolver() replaces DNS, and names the hosts whose
 * plain-http callback address the test relay accepts (the relay's
 * RELAY_ALLOW_HTTP_HOSTS); production never calls it. authorizeUrl()
 * builds GET /v1/authorize: protocol 1, the provider, this site's callback
 * address, the site's own state and the PKCE challenge (S256); OneDrive
 * adds access=appfolder, the only value of 1.0. No client is named: the
 * relay holds the LW app's credentials. No WordPress function here.
 */
final class Relay {

	public const BASE = 'https://auth.lwplugins.com';

	public const VERSION = '1';

	private const BASE_PATTERN = '#^https?://[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?(?::[0-9]{1,5})?\z#';

	/**
	 * A test relay's base (null: BASE).
	 *
	 * @var string|null
	 */
	private static ?string $base = null;

	/**
	 * Hosts whose http callback address the test relay accepts.
	 *
	 * @var array<int, string>
	 */
	private static array $httpHosts = [];

	/**
	 * Tests only: points every later request at a test relay.
	 *
	 * @param string|null        $base      scheme://host[:port] of the test relay (null: the real one again).
	 * @param array<int, string> $httpHosts Hosts whose http callback address the test relay accepts.
	 * @throws \InvalidArgumentException For a base that is not lower-case scheme://host[:port] (nothing changes).
	 */
	public static function useForTests( ?string $base, array $httpHosts = [] ): void {
		if ( null !== $base && 1 !== preg_match( self::BASE_PATTERN, $base ) ) {
			throw new \InvalidArgumentException( 'A test relay is scheme://host[:port].' );
		}
		self::$base      = $base;
		self::$httpHosts = null === $base ? [] : array_values( array_map( 'strtolower', array_filter( $httpHosts, 'is_string' ) ) );
	}

	public static function base(): string {
		return self::$base ?? self::BASE;
	}

	/**
	 * @return array<int, string>
	 */
	public static function httpHosts(): array {
		return self::$httpHosts;
	}

	/**
	 * @param Provider $provider  Provider (its key is the relay's provider name).
	 * @param string   $return    This site's callback address (RelayAddress accepted it).
	 * @param string   $state     OAuthState::issue().
	 * @param string   $challenge Pkce::challenge() of the state's verifier.
	 */
	public static function authorizeUrl( Provider $provider, string $return, #[\SensitiveParameter] string $state, string $challenge ): string {
		$query = [
			'v'                     => self::VERSION,
			'provider'              => $provider->key,
			'return'                => $return,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => Pkce::METHOD,
		];
		if ( Provider::MICROSOFT === $provider->key ) {
			$query['access'] = 'appfolder';
		}

		return self::base() . '/v1/authorize?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}
}
