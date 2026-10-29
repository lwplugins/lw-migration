<?php
/**
 * AWS Signature Version 4 for one request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. Canonical request: method, the decoded path encoded per segment
 * (rawurlencode keeps A-Z a-z 0-9 - _ . ~ and encodes everything else once,
 * "/" stays — S3 does not normalise paths), the query encoded per name and
 * value and sorted, the lower-case header names with trimmed values (inner
 * whitespace collapsed), the signed header list and the payload hash. The
 * signing key is derived per day from the secret, which never leaves this
 * object (#[\SensitiveParameter] keeps it out of stack traces on PHP 8.2+).
 */
final class SigV4Signer {

	public const ALGORITHM = 'AWS4-HMAC-SHA256';

	/** SHA-256 of an empty body. */
	public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * @param string $accessKey Access key id.
	 * @param string $secretKey Secret access key.
	 * @param string $region    Signing region (R2: auto).
	 * @param string $service   Service name.
	 */
	public function __construct( private string $accessKey, #[\SensitiveParameter] private string $secretKey, private string $region, private string $service = 's3' ) {
	}

	/**
	 * @param string                $method      HTTP method.
	 * @param string                $path        Decoded path ('/' for none).
	 * @param array<string, string> $query       Decoded query (name => value).
	 * @param array<string, string> $headers     Headers to sign; Host must be one.
	 * @param string                $payloadHash Hex SHA-256 of the body.
	 * @param int                   $time        Unix time.
	 * @return array<string, string> $headers plus x-amz-date and Authorization.
	 */
	public function sign( string $method, string $path, array $query, array $headers, string $payloadHash, int $time ): array {
		$stamp                 = gmdate( 'Ymd\THis\Z', $time );
		$day                   = gmdate( 'Ymd', $time );
		$headers['x-amz-date'] = $stamp;
		$canonical             = self::headers( $headers );
		$scope                 = $day . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$request               = $this->canonicalRequest( $method, $path, $query, $canonical, $payloadHash );
		$toSign                = self::ALGORITHM . "\n" . $stamp . "\n" . $scope . "\n" . hash( 'sha256', $request );
		$signature             = hash_hmac( 'sha256', $toSign, $this->key( $day ) );

		$headers['Authorization'] = self::ALGORITHM . ' Credential=' . $this->accessKey . '/' . $scope . ', SignedHeaders=' . implode( ';', array_keys( $canonical ) ) . ', Signature=' . $signature;

		return $headers;
	}

	/**
	 * @param string                $method      HTTP method.
	 * @param string                $path        Decoded path.
	 * @param array<string, string> $query       Decoded query.
	 * @param array<string, string> $canonical   headers() output.
	 * @param string                $payloadHash Hex SHA-256 of the body.
	 */
	public function canonicalRequest( string $method, string $path, array $query, array $canonical, string $payloadHash ): string {
		$lines = '';
		foreach ( $canonical as $name => $value ) {
			$lines .= $name . ':' . $value . "\n";
		}

		return $method . "\n" . self::path( $path ) . "\n" . self::query( $query ) . "\n" . $lines . "\n" . implode( ';', array_keys( $canonical ) ) . "\n" . $payloadHash;
	}

	/**
	 * @param string $path Decoded path.
	 */
	public static function path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', '' === $path ? '/' : $path ) ) );
	}

	/**
	 * @param array<int|string, int|string> $query Decoded query (numeric names arrive as int keys).
	 */
	public static function query( array $query ): string {
		$pairs = [];
		foreach ( $query as $name => $value ) {
			$pairs[] = [ rawurlencode( (string) $name ), rawurlencode( (string) $value ) ];
		}
		// Byte order of the encoded name, then value (SigV4); <=> would compare numeric strings as numbers.
		usort( $pairs, static fn( array $a, array $b ): int => 0 !== strcmp( $a[0], $b[0] ) ? strcmp( $a[0], $b[0] ) : strcmp( $a[1], $b[1] ) );

		return implode( '&', array_map( static fn( array $pair ): string => $pair[0] . '=' . $pair[1], $pairs ) );
	}

	/**
	 * @param array<string, string> $headers Name => value.
	 * @return array<string, string> Lower-case name => trimmed value, sorted by name.
	 */
	public static function headers( array $headers ): array {
		$canonical = [];
		foreach ( $headers as $name => $value ) {
			$canonical[ strtolower( trim( (string) $name ) ) ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		ksort( $canonical, SORT_STRING );

		return $canonical;
	}

	/**
	 * @param string $day Ymd.
	 */
	private function key( string $day ): string {
		$key = hash_hmac( 'sha256', $day, 'AWS4' . $this->secretKey, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );

		return hash_hmac( 'sha256', 'aws4_request', $key, true );
	}
}
