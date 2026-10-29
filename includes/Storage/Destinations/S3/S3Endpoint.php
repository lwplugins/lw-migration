<?php
/**
 * Where an S3 request goes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Virtual-hosted (bucket.host/key) or path-style (host/bucket/key). path()
 * is the decoded path the signer encodes; url() encodes it the same way
 * (SigV4Signer::path(), ::query()), so the signed request and the sent one
 * are identical. display() names the endpoint without anything secret.
 */
final class S3Endpoint {

	/**
	 * @param string $scheme    http or https.
	 * @param string $host      Host[:port] without the bucket.
	 * @param string $region    Signing region.
	 * @param string $bucket    Bucket name.
	 * @param bool   $pathStyle Path-style addressing.
	 * @param bool   $custom    A host the admin typed (not a provider preset).
	 */
	public function __construct( public string $scheme, public string $host, public string $region, public string $bucket, public bool $pathStyle, public bool $custom = false ) {
	}

	/**
	 * @param array<string, mixed> $settings S3 settings.
	 * @throws \LightweightPlugins\Migration\Storage\Destinations\FieldException On settings that do not address a bucket.
	 */
	public static function fromSettings( array $settings ): self {
		$resolved = S3Presets::resolve( $settings );

		$custom = S3Presets::CUSTOM === ( $settings['preset'] ?? '' );

		return new self( $resolved['scheme'], $resolved['host'], $resolved['region'], trim( (string) $settings['bucket'] ), $resolved['pathStyle'], $custom );
	}

	public function host(): string {
		return $this->pathStyle ? $this->host : $this->bucket . '.' . $this->host;
	}

	/**
	 * @param string $key Object key ('' = the bucket).
	 * @throws \LogicException On a key with a dot segment.
	 */
	public function path( string $key ): string {
		if ( [] !== array_intersect( [ '.', '..' ], explode( '/', $key ) ) ) {
			throw new \LogicException( 'An object key never has a dot segment.' );
		}
		if ( '' === $key ) {
			return $this->pathStyle ? '/' . $this->bucket : '/';
		}

		return '/' . ( $this->pathStyle ? $this->bucket . '/' : '' ) . $key;
	}

	/**
	 * @param string                $key   Object key.
	 * @param array<string, string> $query Decoded query.
	 */
	public function url( string $key, array $query = [] ): string {
		return $this->scheme . '://' . $this->host() . SigV4Signer::path( $this->path( $key ) ) . ( [] === $query ? '' : '?' . SigV4Signer::query( $query ) );
	}

	public function display(): string {
		return $this->scheme . '://' . $this->host();
	}

	public function insecure(): bool {
		return 'http' === $this->scheme;
	}
}
