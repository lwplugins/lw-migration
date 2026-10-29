<?php
/**
 * Builds the old → new search/replace pairs for an import.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Multisite\BlogUrlMove;
use LightweightPlugins\Migration\Package\PackageConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Every URL in four encodings (plain, urlencode, rawurlencode, JSON-escaped
 * slashes), three schemes (http, https, protocol-relative), with and without
 * "www.", plus quoted host forms, e-mail domains, paths and custom pairs.
 */
final class UrlPairs {

	private const SCHEMES = [ 'http', 'https', '' ];

	/** @var array<string, string> */
	private array $pairs = [];

	/** @var array<string, string> */
	private array $raw = [];

	public static function build( PackageConfig $package, SiteTarget $to ): self {
		$p = new self();
		$p->userPairs( $package );
		$p->core( $package, $to );

		return $p;
	}

	/**
	 * Network restore (decision 16): user pairs, then every site's move, then the main-site pairs.
	 *
	 * @param PackageConfig           $package The archive's package.json.
	 * @param SiteTarget              $to      The network's main site.
	 * @param array<int, BlogUrlMove> $moves   One per mapped site.
	 * @return self
	 */
	public static function forNetwork( PackageConfig $package, SiteTarget $to, array $moves ): self {
		$p       = new self();
		$noEmail = ! empty( $package->get( 'NoEmailReplace' ) );
		$main    = [ self::bareHost( self::str( $package->get( 'HomeURL' ) ) ), self::bareHost( self::str( $package->get( 'SiteURL' ) ) ) ];
		$p->userPairs( $package );
		foreach ( $moves as $move ) {
			// Mail addresses at the archive's main host stay with the network's main site.
			$shared = in_array( self::bareHost( $move->oldHomeUrl ), $main, true ) || in_array( self::bareHost( $move->oldSiteUrl ), $main, true );
			$p->addBlog( $move, $noEmail || $shared );
		}
		$p->core( $package, $to );

		return $p;
	}

	/**
	 * One site's uploads paths, ms-files addresses, site and home URLs and uploads URL.
	 *
	 * @param BlogUrlMove $m       The site's move.
	 * @param bool        $noEmail Leave its mail domain alone.
	 * @return void
	 */
	public function addBlog( BlogUrlMove $m, bool $noEmail ): void {
		$isMain = null === $m->oldBlogId || $m->oldBlogId <= 1;
		$rel    = trim( $m->uploadsRel, '/' );
		$rel    = '' === $rel || str_contains( $rel, '..' ) ? 'wp-content/uploads' : $rel;
		$new    = $m->newBlogId > 1;
		$path   = $m->uploadBlogsDir && $new ? '/wp-content/blogs.dir/' . $m->newBlogId . '/files/' : '/' . $rel . '/' . ( $new ? 'sites/' . $m->newBlogId . '/' : '' );
		$this->encoded( $isMain ? '/wp-content/uploads/' : '/wp-content/blogs.dir/' . $m->oldBlogId . '/files/', $path );
		if ( ! $isMain ) {
			$this->encoded( '/wp-content/uploads/sites/' . $m->oldBlogId . '/', $path );
		}
		// Home before site, as for the main site: equal old URLs map content links to home.
		foreach ( [ [ $m->oldHomeUrl, $m->oldInternalHomeUrl, $m->newHomeUrl ], [ $m->oldSiteUrl, $m->oldInternalSiteUrl, $m->newSiteUrl ] ] as [ $url, $internal, $to ] ) {
			foreach ( [ self::internal( $url ), self::internal( $internal ) ] as $old ) {
				if ( ! $isMain ) {
					$this->filesUrl( $old, $m );
				}
				$this->site( $old, $to, $noEmail );
			}
		}
		$this->uploads( $m->oldUploadsUrl, $m->newUploadsUrl );
	}

	/**
	 * The user's own Replace pairs of the archive come first.
	 *
	 * @param PackageConfig $package The archive's package.json.
	 */
	private function userPairs( PackageConfig $package ): void {
		$olds = (array) $package->get( 'Replace.OldValues', [] );
		$news = (array) $package->get( 'Replace.NewValues', [] );
		foreach ( $olds as $i => $old ) {
			$new = $news[ $i ] ?? '';
			if ( is_string( $old ) && is_string( $new ) && '' !== $old && '' !== $new ) {
				$this->encoded( $old, $new );
			}
		}
	}

	/**
	 * The archive's main site → this site: uploads path, core paths, home, site, uploads URL, paths on disk.
	 *
	 * @param PackageConfig $package The archive's package.json.
	 * @param SiteTarget    $to      This site.
	 */
	private function core( PackageConfig $package, SiteTarget $to ): void {
		$noEmail = ! empty( $package->get( 'NoEmailReplace' ) );
		$rel     = trim( $to->uploadsRelPath, '/' );
		if ( '' !== $rel && ! str_starts_with( $to->uploadsRelPath, '/' ) && ! str_contains( $rel, '..' ) ) {
			$this->encoded( '/wp-content/uploads/', '/' . $rel . '/' );
		}
		// Equal keys are first-wins and Replacer (strtr) prefers longer keys, so
		// these longer core-path keys beat the bare home/site pairs below.
		$oldSite = rtrim( self::str( $package->get( 'SiteURL' ) ), '/' );
		$oldHome = rtrim( self::str( $package->get( 'HomeURL' ) ), '/' );
		if ( rtrim( $to->siteUrl, '/' ) !== rtrim( $to->homeUrl, '/' ) || $oldSite !== $oldHome ) {
			foreach ( [ self::internal( $package->get( 'SiteURL' ) ), self::internal( $package->get( 'InternalSiteURL' ) ) ] as $old ) {
				$this->corePaths( $old, $to->siteUrl );
			}
		}
		// Home before site: when both old URLs are equal, content links map to home.
		foreach ( [ self::internal( $package->get( 'HomeURL' ) ), self::internal( $package->get( 'InternalHomeURL' ) ) ] as $old ) {
			$this->site( $old, $to->homeUrl, $noEmail );
		}
		foreach ( [ self::internal( $package->get( 'SiteURL' ) ), self::internal( $package->get( 'InternalSiteURL' ) ) ] as $old ) {
			$this->site( $old, $to->siteUrl, $noEmail );
		}
		$this->uploads( self::str( $package->get( 'WordPress.UploadsURL' ) ), $to->uploadsUrl );
		$this->encoded( self::str( $package->get( 'WordPress.Absolute' ) ), $to->abspath );
		$this->encoded( self::str( $package->get( 'WordPress.Content' ) ), $to->contentDir );
	}

	/**
	 * The legacy ms-files address <url>/files/ → the new uploads URL (three schemes, four encodings, both www forms).
	 *
	 * @param string      $old Old site or home URL.
	 * @param BlogUrlMove $m   The site's move.
	 */
	private function filesUrl( string $old, BlogUrlMove $m ): void {
		if ( '' === $old || $m->uploadBlogsDir ) {
			return;
		}
		$newScheme = (string) parse_url( $m->newUploadsUrl, PHP_URL_SCHEME );
		foreach ( [ $old, self::invertWww( $old ) ] as $url ) {
			foreach ( self::SCHEMES as $scheme ) {
				$this->encoded( rtrim( self::scheme( $url, $scheme ), '/' ) . '/files/', self::scheme( $m->newUploadsUrl, '' === $scheme ? '' : $newScheme ) );
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	public function pairs(): array {
		return $this->pairs;
	}

	/**
	 * @return array<string, string>
	 */
	public function raw(): array {
		return $this->raw;
	}

	private function site( string $old, string $new, bool $noEmail ): void {
		if ( '' === $old ) {
			return;
		}
		$newScheme = (string) parse_url( $new, PHP_URL_SCHEME );
		$newHost   = self::host( $new );
		$newPath   = (string) parse_url( $new, PHP_URL_PATH );
		foreach ( [ $old, self::invertWww( $old ) ] as $url ) {
			$host = self::host( $url );
			$path = (string) parse_url( $url, PHP_URL_PATH );
			// Raw pairs run on the statement text: both sides are SQL literals, escaped as the dump writes them.
			$this->add( $this->raw, self::literals( $host, rtrim( $path, '/' ) . '/' ), self::literals( $newHost, rtrim( $newPath, '/' ) . '/' ) );
			$this->add( $this->pairs, "='" . $host . rtrim( $path, '/' ), "='" . $newHost . rtrim( $newPath, '/' ) );
			$this->add( $this->pairs, '="' . $host . rtrim( $path, '/' ), '="' . $newHost . rtrim( $newPath, '/' ) );
			foreach ( self::SCHEMES as $scheme ) {
				$this->encoded( rtrim( self::scheme( $url, $scheme ), '/' ), rtrim( self::scheme( $new, '' === $scheme ? '' : $newScheme ), '/' ) );
			}
			// A mail domain has no port: the pair swaps the bare host and never adds one.
			if ( ! $noEmail && '' !== $host ) {
				$mailHost = (string) parse_url( $url, PHP_URL_HOST );
				$mailNew  = (string) parse_url( $new, PHP_URL_HOST );
				$this->add( $this->pairs, '@' . $mailHost, (string) str_ireplace( '@www.', '@', '@' . $mailNew ) );
			}
		}
	}

	private function corePaths( string $old, string $newSite ): void {
		if ( '' === $old ) {
			return;
		}
		$newScheme = (string) parse_url( $newSite, PHP_URL_SCHEME );
		foreach ( [ $old, self::invertWww( $old ) ] as $url ) {
			foreach ( self::SCHEMES as $scheme ) {
				$from = rtrim( self::scheme( $url, $scheme ), '/' );
				$to   = rtrim( self::scheme( $newSite, '' === $scheme ? '' : $newScheme ), '/' );
				foreach ( [ '/wp-content', '/wp-includes', '/wp-admin' ] as $path ) {
					$this->encoded( $from . $path, $to . $path );
				}
			}
		}
	}

	private function uploads( string $old, string $new ): void {
		if ( '' === $old ) {
			return;
		}
		$new       = str_ends_with( $old, '/' ) ? rtrim( $new, '/' ) . '/' : rtrim( $new, '/' );
		$newScheme = (string) parse_url( $new, PHP_URL_SCHEME );
		$newPath   = (string) parse_url( $new, PHP_URL_PATH );
		foreach ( [ $old, self::invertWww( $old ) ] as $url ) {
			$path = (string) parse_url( $url, PHP_URL_PATH );
			if ( '' !== basename( $path ) ) {
				$this->add( $this->pairs, "='" . rtrim( $path, '/' ) . '/', "='" . rtrim( $newPath, '/' ) . '/' );
				$this->add( $this->pairs, '="' . rtrim( $path, '/' ) . '/', '="' . rtrim( $newPath, '/' ) . '/' );
			}
			foreach ( self::SCHEMES as $scheme ) {
				$this->encoded( self::scheme( $url, $scheme ), self::scheme( $new, '' === $scheme ? '' : $newScheme ) );
			}
		}
	}

	private function encoded( string $old, string $new ): void {
		if ( '' === $old ) {
			return;
		}
		$this->add( $this->pairs, $old, $new );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- the form-encoded variant (+ for space) is stored in the data.
		$this->add( $this->pairs, urlencode( $old ), urlencode( $new ) );
		$this->add( $this->pairs, rawurlencode( $old ), rawurlencode( $new ) );
		$this->add( $this->pairs, addcslashes( $old, '/' ), addcslashes( $new, '/' ) );
	}

	/**
	 * @param array<string, string> $list List to extend.
	 * @param string                $old  Search.
	 * @param string                $new  Replace.
	 * @return void
	 */
	private function add( array &$list, string $old, string $new ): void {
		if ( '' === $old || $old === $new || array_key_exists( $old, $list ) ) {
			return;
		}
		$list[ $old ] = $new;
	}

	/**
	 * The domain and path literals of a wp_blogs row, as the dump writes them.
	 *
	 * @param string $domain Domain (with :port when it has one).
	 * @param string $path   Path with a trailing slash.
	 */
	private static function literals( string $domain, string $path ): string {
		return "'" . SqlEscape::escape( $domain ) . "','" . SqlEscape::escape( $path ) . "'";
	}

	private static function scheme( string $url, string $scheme ): string {
		return (string) preg_replace( '#^\w+://#', '' === $scheme ? '//' : $scheme . '://', $url );
	}

	private static function invertWww( string $url ): string {
		return false !== stripos( $url, '//www.' )
			? (string) str_ireplace( '//www.', '//', $url )
			: (string) str_ireplace( '//', '//www.', $url );
	}

	private static function host( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$port = parse_url( $url, PHP_URL_PORT );

		return null === $port ? $host : $host . ':' . $port;
	}

	/**
	 * Lower-case host without port and "www.", for comparing sites.
	 *
	 * @param string $url Address.
	 */
	private static function bareHost( string $url ): string {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @param mixed $value package.json value.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @param mixed $value Internal URL candidate.
	 * @return string The URL when it has a scheme and host, else ''.
	 */
	private static function internal( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return ( '' !== (string) parse_url( $value, PHP_URL_SCHEME ) && '' !== (string) parse_url( $value, PHP_URL_HOST ) ) ? $value : '';
	}
}
