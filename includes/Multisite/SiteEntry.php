<?php
/**
 * One site of a multisite archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Package\PackageConfig;

defined( 'ABSPATH' ) || exit;

/**
 * The Sites[] entry of multisite.json, with the keys and order the format
 * uses (toArray), plus the internal addresses a single-site archive carries
 * (state). Archive data is read leniently: missing keys are empty, ids that
 * are not numbers are null.
 */
final class SiteEntry {

	/**
	 * @param int|null           $blogId          Blog id (null: a single-site archive).
	 * @param int|null           $siteId          Network id.
	 * @param int|null           $langId          wp_blogs.lang_id.
	 * @param string             $siteUrl         Site URL.
	 * @param string             $homeUrl         Home URL.
	 * @param string             $domain          wp_blogs.domain.
	 * @param string             $path            wp_blogs.path.
	 * @param array<int, string> $plugins         Active plugins of the site.
	 * @param string             $template        Parent theme.
	 * @param string             $stylesheet      Theme.
	 * @param string|bool        $uploads         upload_path as stored (false when missing).
	 * @param string|bool        $uploadsUrl      upload_url_path as stored.
	 * @param string             $wpUploads       Uploads folder of the site.
	 * @param string             $wpUploadsUrl    Uploads URL of the site (trailing slash).
	 * @param string             $internalSiteUrl Raw siteurl option (single-site archives).
	 * @param string             $internalHomeUrl Raw home option (single-site archives).
	 */
	public function __construct(
		public ?int $blogId,
		public ?int $siteId,
		public ?int $langId,
		public string $siteUrl,
		public string $homeUrl,
		public string $domain,
		public string $path,
		public array $plugins,
		public string $template,
		public string $stylesheet,
		public string|bool $uploads,
		public string|bool $uploadsUrl,
		public string $wpUploads,
		public string $wpUploadsUrl,
		public string $internalSiteUrl = '',
		public string $internalHomeUrl = ''
	) {
	}

	/**
	 * @param array<string, mixed> $a A Sites[] entry (or state()).
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		$wp = is_array( $a['WordPress'] ?? null ) ? $a['WordPress'] : [];
		return new self(
			self::id( $a['BlogID'] ?? null ),
			self::id( $a['SiteID'] ?? null ),
			self::id( $a['LangID'] ?? null ),
			self::text( $a['SiteURL'] ?? '' ),
			self::text( $a['HomeURL'] ?? '' ),
			self::text( $a['Domain'] ?? '' ),
			self::text( $a['Path'] ?? '' ),
			array_values( array_filter( (array) ( $a['Plugins'] ?? [] ), 'is_string' ) ),
			self::text( $a['Template'] ?? '' ),
			self::text( $a['Stylesheet'] ?? '' ),
			is_bool( $a['Uploads'] ?? null ) ? $a['Uploads'] : self::text( $a['Uploads'] ?? '' ),
			is_bool( $a['UploadsURL'] ?? null ) ? $a['UploadsURL'] : self::text( $a['UploadsURL'] ?? '' ),
			self::text( $wp['Uploads'] ?? '' ),
			self::text( $wp['UploadsURL'] ?? '' ),
			self::text( $a['InternalSiteURL'] ?? '' ),
			self::text( $a['InternalHomeURL'] ?? '' )
		);
	}

	/**
	 * The one site of a single-site archive (decision 2): no ids, address parts from HomeURL.
	 *
	 * @param PackageConfig $p The archive's package.json.
	 * @return self
	 */
	public static function fromPackage( PackageConfig $p ): self {
		$home = rtrim( self::text( $p->get( 'HomeURL', '' ) ), '/' ) . '/';
		return new self(
			null,
			null,
			null,
			self::text( $p->get( 'SiteURL', '' ) ),
			self::text( $p->get( 'HomeURL', '' ) ),
			self::domainOf( $home ),
			(string) parse_url( $home, PHP_URL_PATH ), // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.
			array_values( array_filter( (array) $p->get( 'Plugins', [] ), 'is_string' ) ),
			self::text( $p->get( 'Template', '' ) ),
			self::text( $p->get( 'Stylesheet', '' ) ),
			self::text( $p->get( 'Uploads', '' ) ),
			self::text( $p->get( 'UploadsURL', '' ) ),
			self::text( $p->get( 'WordPress.Uploads', '' ) ),
			self::text( $p->get( 'WordPress.UploadsURL', '' ) ),
			self::text( $p->get( 'InternalSiteURL', '' ) ),
			self::text( $p->get( 'InternalHomeURL', '' ) )
		);
	}

	/**
	 * @return array<string, mixed> Reference keys and order (decision 5).
	 */
	public function toArray(): array {
		return [
			'BlogID'     => $this->blogId,
			'SiteID'     => $this->siteId,
			'LangID'     => $this->langId,
			'SiteURL'    => $this->siteUrl,
			'HomeURL'    => $this->homeUrl,
			'Domain'     => $this->domain,
			'Path'       => $this->path,
			'Plugins'    => $this->plugins,
			'Template'   => $this->template,
			'Stylesheet' => $this->stylesheet,
			'Uploads'    => $this->uploads,
			'UploadsURL' => $this->uploadsUrl,
			'WordPress'  => [
				'Uploads'    => $this->wpUploads,
				'UploadsURL' => $this->wpUploadsUrl,
			],
		];
	}

	/**
	 * @return array<string, mixed> toArray() plus the internal addresses (job state).
	 */
	public function state(): array {
		return $this->toArray() + [
			'InternalSiteURL' => $this->internalSiteUrl,
			'InternalHomeURL' => $this->internalHomeUrl,
		];
	}

	/** The format treats null, 0 and 1 as the main site. */
	public function isMain(): bool {
		return null === $this->blogId || $this->blogId <= 1;
	}

	/**
	 * Host of an address, with :port when it has one (wp_blogs.domain keeps it).
	 *
	 * @param string $url Address.
	 */
	private static function domainOf( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.
		$port = parse_url( $url, PHP_URL_PORT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.

		return is_int( $port ) ? $host . ':' . $port : $host;
	}

	/**
	 * @param mixed $value Id from the archive.
	 */
	private static function id( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		return is_string( $value ) && 1 === preg_match( '/^\d{1,18}\z/', $value ) ? (int) $value : null;
	}

	/**
	 * @param mixed $value Text from the archive.
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
