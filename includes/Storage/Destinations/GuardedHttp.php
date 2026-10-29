<?php
/**
 * Sends one storage request to a checked, pinned address.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use WpOrg\Requests\Transport\Curl;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 (SSRF), the one send path of every HTTP destination: before
 * every request the host is resolved through AddressGuard and refused when
 * any address is in a refused range; the checked address is then pinned for
 * that request with CURLOPT_RESOLVE (a one-shot http_api_curl hook), so a
 * second lookup cannot answer differently, and cURL is forced for that
 * request. A transport that never fires the hook (not cURL) cannot pin: the
 * answer is dropped and the request fails closed. An address written out
 * needs no pin. A host the admin typed (custom) behind a WordPress proxy is
 * refused; a hosted preset goes through the proxy unpinned. A request with a
 * $configure closure (a body streamed from a file, CurlUpload) always goes
 * through cURL and the same one-shot hook, which runs it before the pin (the
 * pin always wins).
 */
final class GuardedHttp {

	/**
	 * Transport: fn( string $url, array $args ): array|\WP_Error.
	 *
	 * @var \Closure
	 */
	private \Closure $send;

	/**
	 * Address check run before every request.
	 *
	 * @var AddressGuard
	 */
	private AddressGuard $guard;

	/**
	 * @param string                  $where     scheme://host[:port], for messages.
	 * @param string                  $authority host[:port] (IPv6 in brackets) the requests go to.
	 * @param string                  $scheme    http or https.
	 * @param bool                    $custom    A host the admin typed (not a provider preset).
	 * @param \Closure|null           $send      Transport (default wp_remote_request).
	 * @param AddressGuard|null       $guard     Address check (default: real DNS).
	 * @param array<string, \Closure> $probes    Environment checks for tests: curl fn(): bool, libcurl fn(): int, proxy fn( string $url ): bool.
	 */
	public function __construct( private string $where, private string $authority, private string $scheme, private bool $custom, ?\Closure $send = null, ?AddressGuard $guard = null, private array $probes = [] ) {
		$this->send  = $send ?? static fn( string $url, #[\SensitiveParameter] array $args ) => wp_remote_request( $url, $args );
		$this->guard = $guard ?? new AddressGuard();
	}

	/** The address in display form, for messages. */
	public function where(): string {
		return $this->where;
	}

	/**
	 * Checks the address, then sends with it pinned.
	 *
	 * @param string               $url       Request URL (on this authority).
	 * @param array<string, mixed> $args      Transport arguments.
	 * @param \Closure|null        $configure fn( $handle ): void, run on this request's cURL handle before the pin.
	 * @return mixed Transport answer: an array, or a WP_Error cURL saw (for the caller to classify).
	 * @throws CloudFailure On a refused or unresolvable address, when the pin cannot be applied, or when the request was never sent.
	 */
	public function send( string $url, #[\SensitiveParameter] array $args, ?\Closure $configure = null ) {
		return $this->dispatch( $url, $args, $this->checked(), $configure );
	}

	/**
	 * CURLOPT_RESOLVE pins the checked address; an empty CURLOPT_PROXY turns
	 * off proxies from the environment (http_proxy, ALL_PROXY), which would
	 * otherwise resolve the name themselves and ignore the pin.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @param string $ip   Checked address.
	 * @return array<int, mixed>
	 */
	public static function pinOptions( string $host, int $port, string $ip ): array {
		return [
			CURLOPT_RESOLVE => [ self::pinEntry( $host, $port, $ip ) ],
			CURLOPT_PROXY   => '',
		];
	}

	public static function pinEntry( string $host, int $port, string $ip ): string {
		return $host . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip );
	}

	/**
	 * Resolves and checks the host; the address to pin (null = none needed).
	 *
	 * @return array{0: string, 1: int, 2: string}|null
	 * @throws CloudFailure On a refused address or a name that does not resolve.
	 */
	private function checked(): ?array {
		[ $host, $port ] = AddressGuard::split( $this->authority, 'https' === $this->scheme ? 443 : 80 );
		$ip              = ServerAddress::connectTo( $host, $this->where, $this->guard );

		return AddressGuard::isLiteral( $host ) ? null : [ $host, $port, $ip ];
	}

	/**
	 * Sends with cURL forced, redirects off and the checked address pinned
	 * for this one request. Both hooks act only on this request's URL and are removed in
	 * finally. An error answer cURL saw (a refused connection, a timeout) is
	 * returned for the caller to classify; an error before cURL (a
	 * pre_http_request short-circuit, a blocked host) is a request never
	 * sent; any other answer that the pin hook did not see is dropped. A hosted preset behind the WordPress proxy
	 * is sent through that proxy unpinned (the pin's empty CURLOPT_PROXY
	 * would bypass it); its host is built from checked labels. A $configure
	 * closure runs in the same hook before the pin, so it too fails closed and
	 * cannot change the pinned address or the proxy.
	 *
	 * @param string                                   $url       Request URL.
	 * @param array<string, mixed>                     $args      Transport arguments.
	 * @param array{0: string, 1: int, 2: string}|null $pin       Host, port, address.
	 * @param \Closure|null                            $configure fn( $handle ): void.
	 * @return mixed Transport answer.
	 * @throws CloudFailure When the pin cannot be applied, or the request was never sent.
	 */
	private function dispatch( string $url, #[\SensitiveParameter] array $args, ?array $pin, ?\Closure $configure ) {
		$pinned = null !== $pin && ( $this->custom || ! $this->proxied( $url ) );
		if ( ! $pinned && null === $configure ) {
			return ( $this->send )( $url, $args );
		}
		$refusal = $this->unsendable( $url, $pinned ? $pin : null );
		if ( null !== $refusal ) {
			throw $refusal;
		}
		$pinWith = $pinned ? self::pinOptions( $pin[0], $pin[1], $pin[2] ) : [];
		$fired   = false;
		$force   = static function ( &$target, &$headers, &$data, &$type, &$options ) use ( $url ): void {
			if ( $target === $url ) {
				$options['transport']        = Curl::class;
				$options['follow_redirects'] = false; // Even if another plugin's filter turned it back on: a hop would go unpinned.
			}
		};
		$hook    = static function ( $handle, $parsed = null, $seen = '' ) use ( $pinWith, $configure, $url, &$fired ): void {
			if ( $seen === $url ) {
				if ( null !== $configure ) {
					$configure( $handle );
				}
				curl_setopt_array( $handle, $pinWith ); // Last, so nothing the closure sets can undo the pin.
				$fired = true;
			}
		};
		add_action( 'requests-requests.before_request', $force, 10, 5 );
		add_action( 'http_api_curl', $hook, 10, 3 );
		try {
			$response = ( $this->send )( $url, $args );
		} finally {
			remove_action( 'requests-requests.before_request', $force, 10 );
			remove_action( 'http_api_curl', $hook, 10 );
		}
		if ( ! $fired ) { // cURL never saw this request: a short-circuit or a WordPress refusal, or an unpinned answer.
			$failure = is_wp_error( $response ) ? HttpErrors::transport( (string) $response->get_error_code(), (string) $response->get_error_message(), $this->where, false ) : $this->unpinnable();
			throw $failure;
		}

		return $response;
	}

	/**
	 * What would make the pin (or cURL, for a request without one) impossible,
	 * found before anything is sent.
	 *
	 * @param string                                   $url Request URL.
	 * @param array{0: string, 1: int, 2: string}|null $pin Host, port, address (null = no pin).
	 */
	private function unsendable( string $url, ?array $pin ): ?PermanentFailure {
		$curl    = $this->probes['curl'] ?? static fn(): bool => function_exists( 'curl_init' ) && function_exists( 'curl_exec' );
		$libcurl = $this->probes['libcurl'] ?? static fn(): int => (int) ( curl_version()['version_number'] ?? 0 );
		if ( ! $curl() || ( null !== $pin && str_contains( $pin[2], ':' ) && $libcurl() < 0x073900 ) ) {
			return $this->unpinnable();
		}
		if ( null !== $pin && $this->custom && $this->proxied( $url ) ) {
			/* translators: %s: storage address */
			return new PermanentFailure( sprintf( __( "The site's WordPress proxy setting prevents checking the address of %s. Exempt the host with WP_PROXY_BYPASS_HOSTS to connect to it directly.", 'lw-migration' ), $this->where ), PermanentFailure::INVALID );
		}

		return null;
	}

	/**
	 * Whether the WordPress proxy settings send this URL through the proxy.
	 *
	 * @param string $url Request URL.
	 */
	private function proxied( string $url ): bool {
		$proxy = $this->probes['proxy'] ?? static fn( string $target ): bool => class_exists( '\\WP_HTTP_Proxy' ) && ( new \WP_HTTP_Proxy() )->is_enabled() && ( new \WP_HTTP_Proxy() )->send_through_proxy( $target );

		return (bool) $proxy( $url );
	}

	private function unpinnable(): PermanentFailure {
		/* translators: %s: storage address */
		return new PermanentFailure( sprintf( __( 'This server cannot send requests to %s in a way that can be checked (cURL is needed).', 'lw-migration' ), $this->where ), PermanentFailure::INVALID );
	}
}
