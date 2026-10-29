<?php
/**
 * Where a WebDAV destination keeps its files.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\FolderSetting;
use LightweightPlugins\Migration\Storage\Destinations\ServerAddress;

defined( 'ABSPATH' ) || exit;

/**
 * The WebDAV address the admin gave (base) plus the folder inside it. A
 * Nextcloud files address (…/remote.php/dav/files/<user>/, what Nextcloud's
 * Files settings show as "WebDAV") also gives the server root and the user
 * id that chunked uploads need (plan D23, D24). Checked when it is saved
 * (preflight P-I13, H5): at most 2048 characters, no white space or control
 * character, http(s) only, no user name, "?" or "#", a host that passes
 * ServerAddress, a port from 1 to 65535 (the scheme's default is dropped);
 * every path segment is decoded and encoded again (no "." or ".." segment,
 * no control character, "/" or "\" in it), so every URL built here is in one canonical
 * form. display() is scheme://host[:port] — the address WebDavClient checks
 * and pins, which every URL built here starts with — and the only part a
 * message names.
 */
final class WebDavAddress {

	private const NEXTCLOUD = '#^(.*?)/remote\.php/dav/files/([^/]+)(?:/|\z)#';

	private const LENGTH = 2048;

	/**
	 * @param string $base   Base address ending in "/".
	 * @param string $folder Folder inside it ('' or "a/b").
	 * @param string $server Nextcloud root ('' when the address is not Nextcloud-shaped).
	 * @param string $user   Nextcloud user id (decoded; '' when not Nextcloud-shaped).
	 */
	public function __construct( public string $base, public string $folder, public string $server, public string $user ) {
	}

	/**
	 * @param string $url    The WebDAV address.
	 * @param string $folder The folder setting (cleaned again with FolderSetting::clean()).
	 * @throws FieldException 'url' when it is not a plain http(s) address; 'folder' for a folder the rule refuses.
	 */
	public static function fromSettings( string $url, string $folder ): self {
		$parts   = self::parts( trim( $url ) );
		$message = self::message();
		$scheme  = strtolower( (string) $parts['scheme'] );
		$host    = ServerAddress::host( (string) $parts['host'], 'url', $message );
		$port    = isset( $parts['port'] ) ? ServerAddress::port( (int) $parts['port'], 0, 'url', $message ) : 0;
		$root    = self::root( $scheme, $host, $port );
		$path    = implode( '/', self::segments( (string) ( $parts['path'] ?? '' ) ) );
		$server  = '';
		$user    = '';
		if ( 1 === preg_match( self::NEXTCLOUD, '/' . $path, $m ) ) {
			$server = $root . $m[1];
			$user   = rawurldecode( $m[2] );
		}

		return new self( $root . '/' . ( '' === $path ? '' : $path . '/' ), FolderSetting::clean( $folder, 'folder' ), $server, $user );
	}

	public function folderUrl(): string {
		$chain = $this->folderChain();

		return [] === $chain ? $this->base : (string) end( $chain );
	}

	public function file( string $name ): string {
		return $this->folderUrl() . rawurlencode( $name );
	}

	/**
	 * @return array<int, string> The folder and each of its parents inside the base, outermost first.
	 */
	public function folderChain(): array {
		$urls = [];
		$path = $this->base;
		foreach ( '' === $this->folder ? [] : explode( '/', $this->folder ) as $segment ) {
			$path  .= rawurlencode( $segment ) . '/';
			$urls[] = $path;
		}

		return $urls;
	}

	public function isNextcloud(): bool {
		return '' !== $this->server && '' !== $this->user;
	}

	public function uploadsUrl( string $id ): string {
		return $this->server . '/remote.php/dav/uploads/' . rawurlencode( $this->user ) . '/' . rawurlencode( $id );
	}

	public function statusUrl(): string {
		return $this->server . '/status.php';
	}

	/** Scheme, host and port: what messages name, and the WebDavClient's server address. */
	public function display(): string {
		return (string) preg_replace( '#^(https?://[^/]+).*\z#', '$1', $this->base );
	}

	/**
	 * The display() form of a stored address, without checking or looking up its host
	 * (Task 4 m5: a card or a list never waits for DNS).
	 *
	 * @param string $url The stored WebDAV address.
	 * @throws FieldException 'url' when it is not a plain http(s) address.
	 */
	public static function shown( string $url ): string {
		$parts = self::parts( trim( $url ) );

		return self::root( strtolower( (string) $parts['scheme'] ), strtolower( trim( (string) $parts['host'], '[]' ) ), (int) ( $parts['port'] ?? 0 ) );
	}

	public function insecure(): bool {
		return str_starts_with( $this->base, 'http://' );
	}

	/**
	 * @param string $url Trimmed address.
	 * @return array<string, mixed> parse_url() parts with a scheme and a host.
	 * @throws FieldException 'url' when it is not a plain http(s) address.
	 */
	private static function parts( string $url ): array {
		$parts = strlen( $url ) > self::LENGTH || 1 === preg_match( '/[\s\x00-\x1F\x7F\\\\]/', $url ) ? false : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing.
		$ok    = is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] );
		if ( ! $ok ) {
			throw new FieldException( 'url', self::message() );
		}

		return $parts;
	}

	/**
	 * @param string $path URL path as typed.
	 * @return array<int, string> Its segments, each decoded and encoded again.
	 * @throws FieldException 'url' on a "." or ".." segment, or a control character, "/" or "\" decoded in one.
	 */
	private static function segments( string $path ): array {
		$segments = [];
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$plain = rawurldecode( $segment );
			if ( '.' === $plain || '..' === $plain || 1 === preg_match( '/[\x00-\x1F\x7F\/\\\\]/', $plain ) ) {
				throw new FieldException( 'url', self::message() );
			}
			$segments[] = rawurlencode( $plain );
		}

		return $segments;
	}

	/**
	 * @param string $scheme http or https.
	 * @param string $host   Lower-case host (IPv6 without brackets).
	 * @param int    $port   Port (0 or the scheme's default: none shown).
	 */
	private static function root( string $scheme, string $host, int $port ): string {
		return $scheme . '://' . ( str_contains( $host, ':' ) ? '[' . $host . ']' : $host ) . ( $port > 0 && ( 'https' === $scheme ? 443 : 80 ) !== $port ? ':' . $port : '' );
	}

	private static function message(): string {
		return __( 'Enter the WebDAV address, starting with https:// (without a user name, "?" or "#").', 'lw-migration' );
	}
}
