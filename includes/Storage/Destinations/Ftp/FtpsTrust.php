<?php
/**
 * The cURL options that decide which FTPS certificate is accepted.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #5 (review I1: never fail open). Without a confirmed fingerprint
 * the certificate and the name are checked against WordPress's CA list;
 * with one, the confirmed certificate's public key is pinned and only then
 * is the CA check turned off. The options are set one by one, the security
 * ones first, and every refusal counts: a handle that did not take all of
 * them is never used (cURL's setopt array stops at the first refused one
 * and would leave the rest, the pin included, unset). canPin() tries the
 * pin on a throwaway handle: a TLS backend that cannot pin refuses it at
 * setopt time, and then FTPS is not offered at all.
 */
final class FtpsTrust {

	/** A well-formed pin (SHA-256 of nothing in particular) for the capability check. */
	private const TRIAL_PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

	/**
	 * The security options, in the order they must be set.
	 *
	 * @param string   $pin   The confirmed fingerprint ('' = none: the CA list decides).
	 * @param \Closure $probe fn(): array{fingerprint: string, publicKey: string}.
	 * @param string   $where For messages.
	 * @return array<int, mixed>
	 * @throws PermanentFailure CERT_CHANGED when the server shows another certificate than the confirmed one.
	 */
	public static function options( string $pin, \Closure $probe, string $where ): array {
		if ( '' === $pin ) {
			$bundle = defined( 'ABSPATH' ) && defined( 'WPINC' ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';

			return [
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			] + ( '' !== $bundle && is_readable( $bundle ) ? [ CURLOPT_CAINFO => $bundle ] : [] );
		}
		$shown = $probe();
		CertificatePin::check( $pin, $shown['fingerprint'], $where );

		return [
			CURLOPT_PINNEDPUBLICKEY => $shown['publicKey'], // First: the CA check goes off only once the pin is in place.
			CURLOPT_SSL_VERIFYPEER  => false,
			CURLOPT_SSL_VERIFYHOST  => 0,
		];
	}

	/**
	 * Sets the options one by one, in order.
	 *
	 * @param mixed             $handle  cURL handle.
	 * @param array<int, mixed> $options Options.
	 * @param \Closure          $setopt  fn( $handle, int $option, mixed $value ): bool.
	 * @return bool False at the first option cURL refused (the rest are not set).
	 */
	public static function apply( $handle, array $options, \Closure $setopt ): bool {
		foreach ( $options as $option => $value ) {
			if ( true !== $setopt( $handle, $option, $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this cURL can pin a public key (it has the option and its TLS backend takes it).
	 *
	 * @param \Closure|null $setopt fn( $handle, int $option, mixed $value ): bool (tests); null = curl_setopt.
	 */
	public static function canPin( ?\Closure $setopt = null ): bool {
		if ( ! defined( 'CURLOPT_PINNEDPUBLICKEY' ) || ! function_exists( 'curl_init' ) ) {
			return false;
		}
		$trial = curl_init();

		return false !== $trial && self::apply( $trial, [ CURLOPT_PINNEDPUBLICKEY => self::TRIAL_PIN ], $setopt ?? self::setopt() );
	}

	/**
	 * @return \Closure fn( $handle, int $option, mixed $value ): bool — curl_setopt.
	 */
	public static function setopt(): \Closure {
		return static fn( $handle, int $option, $value ): bool => curl_setopt( $handle, $option, $value );
	}
}
