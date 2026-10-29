<?php
/**
 * The network an import restores into.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data (decision 9), read once when the import job is created and kept
 * in state.target. The domain never carries the port: wp_blogs.domain does on
 * a network served on a port, so every site row is built through blogDomain().
 */
final class TargetNetwork {

	/**
	 * @param bool               $subdomain      Subdomain install.
	 * @param string             $domain         Main site host, lower case, without port.
	 * @param string             $path           Main site path, with slashes on both ends.
	 * @param string             $scheme         Scheme of the main home URL.
	 * @param int|null           $port           Port of the main site address, if any.
	 * @param int                $networkId      Current network id.
	 * @param int                $networks       Networks of the install.
	 * @param bool               $uploadBlogsDir Legacy files rewriting (blogs.dir).
	 * @param string             $uploadsDir     Main site uploads folder, no trailing slash.
	 * @param string             $uploadsUrl     Main site uploads URL, no trailing slash.
	 * @param string             $basePrefix     Base table prefix.
	 * @param int                $mainSiteId     Main site id.
	 * @param array<int, string> $reserved       Names a subdirectory site may not use.
	 */
	public function __construct(
		public bool $subdomain,
		public string $domain,
		public string $path,
		public string $scheme,
		public ?int $port,
		public int $networkId,
		public int $networks,
		public bool $uploadBlogsDir,
		public string $uploadsDir,
		public string $uploadsUrl,
		public string $basePrefix,
		public int $mainSiteId,
		public array $reserved
	) {
	}

	/**
	 * @param array<string, mixed> $a toArray() data (missing keys take defaults).
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		$port   = $a['port'] ?? null;
		$scheme = $a['scheme'] ?? '';
		$path   = '/' . trim( is_string( $a['path'] ?? null ) ? $a['path'] : '', '/' ) . '/';

		return new self(
			! empty( $a['subdomain'] ),
			strtolower( is_string( $a['domain'] ?? null ) ? $a['domain'] : '' ),
			'//' === $path ? '/' : $path,
			'https' === $scheme ? 'https' : 'http',
			is_int( $port ) && $port > 0 ? $port : null,
			(int) ( $a['networkId'] ?? 1 ),
			(int) ( $a['networks'] ?? 1 ),
			! empty( $a['uploadBlogsDir'] ),
			is_string( $a['uploadsDir'] ?? null ) ? $a['uploadsDir'] : '',
			is_string( $a['uploadsUrl'] ?? null ) ? $a['uploadsUrl'] : '',
			is_string( $a['basePrefix'] ?? null ) ? $a['basePrefix'] : '',
			(int) ( $a['mainSiteId'] ?? 1 ),
			array_values( array_filter( (array) ( $a['reserved'] ?? [] ), 'is_string' ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'subdomain'      => $this->subdomain,
			'domain'         => $this->domain,
			'path'           => $this->path,
			'scheme'         => $this->scheme,
			'port'           => $this->port,
			'networkId'      => $this->networkId,
			'networks'       => $this->networks,
			'uploadBlogsDir' => $this->uploadBlogsDir,
			'uploadsDir'     => $this->uploadsDir,
			'uploadsUrl'     => $this->uploadsUrl,
			'basePrefix'     => $this->basePrefix,
			'mainSiteId'     => $this->mainSiteId,
			'reserved'       => $this->reserved,
		];
	}

	/** The main site's address: scheme://domain[:port]path, without a trailing slash. */
	public function mainUrl(): string {
		return $this->scheme . '://' . $this->blogDomain( $this->domain ) . rtrim( $this->path, '/' );
	}

	/**
	 * A host as wp_blogs.domain holds it on this network (ruling R9).
	 *
	 * @param string $host Host without port.
	 */
	public function blogDomain( string $host ): string {
		return null === $this->port ? $host : $host . ':' . $this->port;
	}

	/** The uploads folder relative to the main address (e.g. wp-content/uploads). */
	public function uploadsRel(): string {
		$path = (string) parse_url( $this->uploadsUrl, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of a stored address.
		$path = trim( $path, '/' );
		$main = trim( $this->path, '/' );
		if ( '' !== $main && str_starts_with( $path . '/', $main . '/' ) ) {
			$path = trim( substr( $path, strlen( $main ) ), '/' );
		}

		return '' === $path ? 'wp-content/uploads' : $path;
	}
}
