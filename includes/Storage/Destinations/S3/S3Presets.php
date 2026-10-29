<?php
/**
 * Provider presets of the S3 type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\ServerAddress;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 presets. Hosts as the providers document them (checked
 * 2026-10-12): AWS s3.<region>.amazonaws.com (docs.aws.amazon.com
 * AmazonS3/latest/userguide/VirtualHosting.html), Backblaze B2
 * s3.<region>.backblazeb2.com/<bucket> (backblaze.com/apidocs
 * introduction-to-the-s3-compatible-api), Wasabi s3.<region>.wasabisys.com
 * (docs.wasabi.com service URLs), Cloudflare R2
 * <account id>.r2.cloudflarestorage.com with region "auto"
 * (developers.cloudflare.com/r2/api/s3/api/), DigitalOcean Spaces
 * <bucket>.<region>.digitaloceanspaces.com (docs.digitalocean.com
 * spaces/reference/s3-compatibility), Hetzner
 * <bucket>.<location>.your-objectstorage.com (docs.hetzner.com
 * storage/object-storage/overview), Scaleway s3.<region>.scw.cloud
 * (scaleway.com/en/docs/object-storage/concepts). The custom preset takes an
 * http(s)://host[:port] endpoint (MinIO); http is allowed only there. A
 * bucket name with a dot forces path-style on https (a wildcard certificate
 * does not cover bucket.with.dots.host); so does an uppercase letter or an
 * underscore (not valid in a DNS host label).
 */
final class S3Presets {

	public const AWS = 'aws';

	public const B2 = 'b2';

	public const WASABI = 'wasabi';

	public const R2 = 'r2';

	public const DIGITALOCEAN = 'digitalocean';

	public const HETZNER = 'hetzner';

	public const SCALEWAY = 'scaleway';

	public const CUSTOM = 'custom';

	/** Longest custom endpoint accepted (checked before it is parsed). */
	private const ENDPOINT_LENGTH = 2048;

	/** Preset => [host pattern, default region, default path-style]. */
	private const PRESETS = [
		self::AWS          => [ 's3.{region}.amazonaws.com', 'us-east-1', false ],
		self::B2           => [ 's3.{region}.backblazeb2.com', 'us-west-004', true ],
		self::WASABI       => [ 's3.{region}.wasabisys.com', 'us-east-1', false ],
		self::R2           => [ '{account}.r2.cloudflarestorage.com', 'auto', true ],
		self::DIGITALOCEAN => [ '{region}.digitaloceanspaces.com', 'nyc3', false ],
		self::HETZNER      => [ '{region}.your-objectstorage.com', 'fsn1', false ],
		self::SCALEWAY     => [ 's3.{region}.scw.cloud', 'fr-par', false ],
		self::CUSTOM       => [ '', 'us-east-1', true ],
	];

	private const REGION = '/^[a-z0-9][a-z0-9-]{1,40}\z/';

	private const ACCOUNT = '/^[a-z0-9]{8,64}\z/';

	private const BUCKET = '/^[A-Za-z0-9][A-Za-z0-9._-]{1,61}[A-Za-z0-9]\z/';

	/**
	 * @return array<int, string>
	 */
	public static function keys(): array {
		return array_keys( self::PRESETS );
	}

	/**
	 * Product names stay as they are; only "custom" is translated.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			self::AWS          => 'Amazon S3',
			self::B2           => 'Backblaze B2',
			self::WASABI       => 'Wasabi',
			self::R2           => 'Cloudflare R2',
			self::DIGITALOCEAN => 'DigitalOcean Spaces',
			self::HETZNER      => 'Hetzner Object Storage',
			self::SCALEWAY     => 'Scaleway Object Storage',
			self::CUSTOM       => __( 'Other S3-compatible storage', 'lw-migration' ),
		];
	}

	public static function defaultRegion( string $preset ): string {
		return self::PRESETS[ $preset ][1] ?? 'us-east-1';
	}

	public static function defaultPathStyle( string $preset ): bool {
		return self::PRESETS[ $preset ][2] ?? true;
	}

	/**
	 * @param array<string, mixed> $s Settings (preset, region, accountId, endpoint, bucket, pathStyle).
	 * @return array{scheme: string, host: string, region: string, pathStyle: bool}
	 * @throws FieldException On a value that cannot address a bucket.
	 */
	public static function resolve( array $s ): array {
		$preset = is_string( $s['preset'] ?? null ) ? $s['preset'] : '';
		if ( ! isset( self::PRESETS[ $preset ] ) ) {
			throw new FieldException( 'preset', __( 'Choose a storage provider.', 'lw-migration' ) );
		}
		$bucket = is_string( $s['bucket'] ?? null ) ? trim( $s['bucket'] ) : '';
		if ( 1 !== preg_match( self::BUCKET, $bucket ) || str_contains( $bucket, '..' ) ) {
			throw new FieldException( 'bucket', __( 'A bucket name has 3 to 63 letters, digits, dots, hyphens or underscores, and starts and ends with a letter or digit.', 'lw-migration' ) );
		}
		[ $scheme, $host, $region ] = self::CUSTOM === $preset ? self::custom( $s ) : self::hosted( $preset, $s );
		$pathStyle                  = is_bool( $s['pathStyle'] ?? null ) ? $s['pathStyle'] : self::defaultPathStyle( $preset );
		if ( 1 === preg_match( '/[A-Z_]/', $bucket ) || ( 'https' === $scheme && str_contains( $bucket, '.' ) ) ) {
			$pathStyle = true;
		}

		return [
			'scheme'    => $scheme,
			'host'      => $host,
			'region'    => $region,
			'pathStyle' => $pathStyle,
		];
	}

	/**
	 * @param string               $preset Preset key.
	 * @param array<string, mixed> $s      Settings.
	 * @return array{0: string, 1: string, 2: string}
	 * @throws FieldException On a bad region or account id.
	 */
	private static function hosted( string $preset, array $s ): array {
		$pattern = self::PRESETS[ $preset ][0];
		if ( self::R2 === $preset ) {
			$account = is_string( $s['accountId'] ?? null ) ? strtolower( trim( $s['accountId'] ) ) : '';
			if ( 1 !== preg_match( self::ACCOUNT, $account ) ) {
				throw new FieldException( 'accountId', __( 'Enter your Cloudflare account ID (letters and digits, from the R2 overview page).', 'lw-migration' ) );
			}

			return [ 'https', str_replace( '{account}', $account, $pattern ), 'auto' ];
		}
		$region = self::region( $s, $preset );

		return [ 'https', str_replace( '{region}', $region, $pattern ), $region ];
	}

	/**
	 * @param array<string, mixed> $s Settings.
	 * @return array{0: string, 1: string, 2: string}
	 * @throws FieldException On an endpoint that is not a plain http(s) address.
	 */
	private static function custom( array $s ): array {
		$message  = __( 'Enter the storage address as http(s)://host or http(s)://host:port, without a folder.', 'lw-migration' );
		$endpoint = is_string( $s['endpoint'] ?? null ) ? trim( $s['endpoint'] ) : '';
		if ( strlen( $endpoint ) > self::ENDPOINT_LENGTH ) {
			throw new FieldException( 'endpoint', $message );
		}
		$parts  = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing.
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$plain  = is_array( $parts ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && in_array( $parts['path'] ?? '/', [ '', '/' ], true );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! $plain || '' === (string) ( $parts['host'] ?? '' ) || 1 === preg_match( '/[\s\x00-\x1F\x7F]/', $endpoint ) ) {
			throw new FieldException( 'endpoint', $message );
		}
		// The port is dropped when it is the scheme's default: cURL sends no port then, and the signed Host must equal the sent one.
		$bare = ServerAddress::host( (string) $parts['host'], 'endpoint', $message );
		$port = isset( $parts['port'] ) ? ServerAddress::port( (int) $parts['port'], 0, 'endpoint', $message ) : 0;
		$name = str_contains( $bare, ':' ) ? '[' . $bare . ']' : $bare;
		$host = $name . ( $port > 0 && ( 'https' === $scheme ? 443 : 80 ) !== $port ? ':' . $port : '' );
		$raw  = is_string( $s['region'] ?? null ) ? trim( $s['region'] ) : '';

		return [ $scheme, $host, '' === $raw ? self::defaultRegion( self::CUSTOM ) : self::region( $s, self::CUSTOM ) ];
	}

	/**
	 * @param array<string, mixed> $s      Settings.
	 * @param string               $preset Preset key.
	 * @throws FieldException On a malformed region.
	 */
	private static function region( array $s, string $preset ): string {
		$region = is_string( $s['region'] ?? null ) ? strtolower( trim( $s['region'] ) ) : '';
		$region = '' === $region ? self::defaultRegion( $preset ) : $region;
		if ( 1 !== preg_match( self::REGION, $region ) ) {
			throw new FieldException( 'region', __( 'Enter the region code your provider shows, for example eu-central-1.', 'lw-migration' ) );
		}

		return $region;
	}
}
