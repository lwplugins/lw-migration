<?php
/**
 * Multisite.json model (read side).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Package;

use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Multisite\SiteEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Network/site description stored next to package.json in multisite archives.
 */
final class MultisiteConfig {

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data Decoded JSON object.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	public static function fromJson( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new InvalidArchiveException( 'multisite.json is not a valid JSON object.' );
		}

		return new self( $data );
	}

	/**
	 * @param string $path    Dot-separated keys.
	 * @param mixed  $default Returned when a key is missing.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$node = $this->data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return $default;
			}
			$node = $node[ $key ];
		}

		return $node;
	}

	public function isNetwork(): bool {
		return ! empty( $this->data['Network'] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function sites(): array {
		$sites = $this->data['Sites'] ?? [];

		return is_array( $sites ) ? array_values( array_filter( $sites, 'is_array' ) ) : [];
	}

	/**
	 * @return array<int, SiteEntry>
	 */
	public function entries(): array {
		return array_map( [ SiteEntry::class, 'fromArray' ], $this->sites() );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function networks(): array {
		$n = $this->data['Networks'] ?? [];
		return is_array( $n ) ? array_values( array_filter( $n, 'is_array' ) ) : [];
	}

	/**
	 * @return array<int, string> Network-active plugins.
	 */
	public function plugins(): array {
		return self::strings( $this->data['Plugins'] ?? [] );
	}

	/**
	 * @return array<int, string> Super admin logins.
	 */
	public function admins(): array {
		return self::strings( $this->data['Admins'] ?? [] );
	}

	/**
	 * @param mixed $value List from the archive.
	 * @return array<int, string>
	 */
	private static function strings( $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}
}
