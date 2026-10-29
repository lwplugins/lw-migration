<?php
/**
 * Which network addresses a storage endpoint may point at.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 (SSRF). A custom endpoint is typed by an admin, so it must not
 * reach the cloud metadata services or other special ranges. Always refused:
 * IPv4 0.0.0.0/8, 169.254.0.0/16 (link-local, metadata), 100.100.100.200,
 * 224.0.0.0/4 and 240.0.0.0/4; IPv6 ::, fe80::/10, ff00::/8, fd00:ec2::254.
 * IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96) and NAT64
 * (64:ff9b::/96) addresses are checked as the IPv4 address they carry.
 * Allowed on purpose (a local MinIO or a self-hosted store is supported):
 * loopback, 10/8, 172.16/12, 192.168/16, fc00::/7 and 100.64/10.
 * WordPress-free; the resolver is injected: fn( string $host ): array of IPs.
 * webKind() is the stricter rule of a request to an address someone else
 * may have supplied (a connection, a URL import; issue #2).
 */
final class AddressGuard {

	/** For webKind(): an address on the public internet. */
	public const WEB_PUBLIC = 'public';

	/** For webKind(): loopback, a private or a documentation range (allowed only where WordPress allows the host). */
	public const WEB_INTERNAL = 'internal';

	/** For webKind(): never reached by a web request. */
	public const WEB_REFUSED = 'refused';

	/**
	 * Process-wide resolver for tests (null = the real one).
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $override = null;

	/**
	 * @var \Closure
	 */
	private \Closure $resolver;

	/**
	 * @param \Closure|null $resolver fn( string $host ): array<int, string>.
	 */
	public function __construct( ?\Closure $resolver = null ) {
		$this->resolver = $resolver ?? self::$override ?? static fn( string $host ): array => self::lookup( $host );
	}

	/**
	 * @param \Closure|null $resolver Replaces the real DNS lookup for guards built later (tests only).
	 */
	public static function useResolver( ?\Closure $resolver ): void {
		self::$override = $resolver;
	}

	/**
	 * Splits "host", "host:port" and "[v6]:port"; brackets are removed.
	 *
	 * @param string $hostPort Host as in a URL authority.
	 * @param int    $default  Port when none is given.
	 * @return array{0: string, 1: int}
	 */
	public static function split( string $hostPort, int $default ): array {
		if ( 1 === preg_match( '/^\[([^\]]*)\](?::(\d{1,5}))?\z/', $hostPort, $m ) ) {
			return [ $m[1], isset( $m[2] ) ? (int) $m[2] : $default ];
		}
		if ( 1 === preg_match( '/^([^:]*):(\d{1,5})\z/', $hostPort, $m ) ) {
			return [ $m[1], (int) $m[2] ];
		}

		return [ $hostPort, $default ];
	}

	/**
	 * Whether the host is an IP address written out (not a name).
	 *
	 * @param string $host Host without port or brackets.
	 */
	public static function isLiteral( string $host ): bool {
		return false !== filter_var( $host, FILTER_VALIDATE_IP );
	}

	/**
	 * @param string $host Host without port or brackets.
	 * @return array<int, string> The addresses (the host itself when it is one); empty when it does not resolve.
	 */
	public function addresses( string $host ): array {
		if ( self::isLiteral( $host ) ) {
			return [ $host ];
		}
		$found = [];
		foreach ( ( $this->resolver )( $host ) as $ip ) {
			if ( is_string( $ip ) && self::isLiteral( $ip ) ) {
				$found[] = $ip;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * @param array<int, string> $ips Addresses.
	 * @return bool True when there is at least one address and every one is allowed.
	 */
	public static function allAllowed( array $ips ): bool {
		if ( [] === $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! self::allowed( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	public static function allowed( string $ip ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false on a bad literal is handled.
		if ( false === $bin ) {
			return false;
		}
		if ( 4 === strlen( $bin ) ) {
			return self::allowedV4( $bin );
		}
		if ( str_repeat( "\0", 16 ) === $bin ) {
			return false;
		}
		if ( str_repeat( "\0", 15 ) . "\1" === $bin ) {
			return true;
		}
		$v4 = self::embeddedV4( $bin );
		if ( null !== $v4 ) {
			return self::allowedV4( $v4 );
		}
		$first = ord( $bin[0] );
		if ( 0xff === $first || ( 0xfe === $first && 0x80 === ( ord( $bin[1] ) & 0xc0 ) ) ) {
			return false;
		}

		return (string) inet_pton( 'fd00:ec2::254' ) !== $bin;
	}

	/**
	 * Issue #2: the stricter rule of link calls and URL imports. Refused is
	 * everything allowed() refuses, and also 100.64.0.0/10 (carrier-grade NAT)
	 * and 198.18.0.0/15 (benchmarking). Internal are the IPv4 ranges WordPress
	 * core sends to only when the host is allowed (wp_http_validate_url() in
	 * 7.1.2: loopback, 10/8, 172.16/12, 192.168/16, 192.0.0/24, 192.0.2/24,
	 * 192.88.99/24, 198.51.100/24, 203.0.113/24) and IPv6 ::1 and fc00::/7.
	 * An IPv4 address carried in IPv6 is decided as that IPv4 address.
	 *
	 * @param string $ip Address.
	 * @return string WEB_PUBLIC, WEB_INTERNAL or WEB_REFUSED.
	 */
	public static function webKind( string $ip ): string {
		if ( ! self::allowed( $ip ) ) {
			return self::WEB_REFUSED;
		}
		$bin = (string) inet_pton( $ip );
		if ( str_repeat( "\0", 15 ) . "\1" === $bin ) {
			return self::WEB_INTERNAL;
		}
		$v4 = self::embeddedV4( $bin );
		if ( null !== $v4 ) {
			return self::webKindV4( $v4 );
		}

		return 0xfc === ( ord( $bin[0] ) & 0xfe ) ? self::WEB_INTERNAL : self::WEB_PUBLIC;
	}

	/**
	 * @param array<int, string> $ips      Addresses of one host.
	 * @param bool               $internal Whether internal addresses may be used for this host.
	 * @return bool True when there is at least one address and every one may be used.
	 */
	public static function webAllowed( array $ips, bool $internal ): bool {
		if ( [] === $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			$kind = self::webKind( $ip );
			if ( self::WEB_REFUSED === $kind || ( self::WEB_INTERNAL === $kind && ! $internal ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $bin Packed address.
	 * @return string|null The packed IPv4 address it is or carries (mapped, compatible, NAT64); null for another IPv6 address.
	 */
	private static function embeddedV4( string $bin ): ?string {
		if ( 4 === strlen( $bin ) ) {
			return $bin;
		}
		$head = substr( $bin, 0, 12 );

		return in_array( $head, [ str_repeat( "\0", 12 ), str_repeat( "\0", 10 ) . "\xff\xff", "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) ], true ) ? substr( $bin, 12 ) : null;
	}

	/**
	 * @param string $bin Packed IPv4 address that allowedV4() allows.
	 */
	private static function webKindV4( string $bin ): string {
		$a = ord( $bin[0] );
		$b = ord( $bin[1] );
		if ( ( 100 === $a && 64 === ( $b & 0xc0 ) ) || ( 198 === $a && 18 === ( $b & 0xfe ) ) ) {
			return self::WEB_REFUSED;
		}
		$c24 = substr( $bin, 0, 3 );
		if ( 127 === $a || 10 === $a || ( 172 === $a && 16 === ( $b & 0xf0 ) ) || ( 192 === $a && 168 === $b )
			|| in_array( $c24, [ "\xc0\x00\x00", "\xc0\x00\x02", "\xc0\x58\x63", "\xc6\x33\x64", "\xcb\x00\x71" ], true ) ) {
			return self::WEB_INTERNAL;
		}

		return self::WEB_PUBLIC;
	}

	private static function allowedV4( string $bin ): bool {
		$a = ord( $bin[0] );
		$b = ord( $bin[1] );
		if ( 0 === $a || $a >= 224 || ( 169 === $a && 254 === $b ) ) {
			return false;
		}

		return "\x64\x64\x64\xc8" !== $bin;
	}

	/**
	 * @param string $host Name.
	 * @return array<int, string>
	 */
	private static function lookup( string $host ): array {
		$ips = [];
		$v4  = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false on failure is handled.
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false on failure is handled.
			foreach ( is_array( $v6 ) ? $v6 : [] as $row ) {
				if ( is_string( $row['ipv6'] ?? null ) ) {
					$ips[] = $row['ipv6'];
				}
			}
		}

		return $ips;
	}
}
