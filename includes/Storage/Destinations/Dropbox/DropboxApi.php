<?php
/**
 * Dropbox API v2 request shapes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Dropbox;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;

defined( 'ABSPATH' ) || exit;

/**
 * Read from docs.dropboxapi.com/dropbox-api/docs/technical-reference/
 * request-response-formats and json-encoding (2026-10-14). RPC endpoints (api host) take their
 * JSON argument as the body; a call without arguments sends the JSON null
 * (an empty POST would go out labelled as a form, which RPC calls refuse —
 * UNVERIFIED, Task 13 checklist). Content endpoints (content host) take
 * their argument in the Dropbox-API-Arg header, "HTTP header safe": \uXXXX
 * for 0x7F and every non-ASCII character. An upload sends the bytes as
 * application/octet-stream; a download is a GET without a body or
 * Content-Type (content-download endpoints "also support HTTP GET").
 * Both hosts are provider API hosts: every request carries the Bearer
 * token (OAuthHttp), and statuses are left to DropboxErrors.
 */
final class DropboxApi {

	public const RPC = 'https://api.dropboxapi.com/2/';

	public const CONTENT = 'https://content.dropboxapi.com/2/';

	public function __construct( private OAuthHttp $http ) {
	}

	public function http(): OAuthHttp {
		return $this->http;
	}

	/**
	 * @param string                    $route Route, e.g. files/list_folder.
	 * @param array<string, mixed>|null $arg   Argument (null: the call takes none).
	 * @return array{status: int, body: string, headers: array<string, string>} The answer (OAuthHttp's CloudFailure for a refused address or a transport failure passes through).
	 */
	public function rpc( string $route, ?array $arg ): array {
		$body = null === $arg ? 'null' : (string) wp_json_encode( $arg, JSON_UNESCAPED_SLASHES );

		return $this->http->request( 'POST', self::RPC . $route, [ 'Content-Type' => 'application/json' ], $body );
	}

	/**
	 * @param string                $route   Route, e.g. files/upload_session/append_v2.
	 * @param array<string, mixed>  $arg     Argument (the Dropbox-API-Arg header).
	 * @param string                $body    Bytes (an upload).
	 * @param array<string, string> $headers More headers (Range).
	 * @param array<string, mixed>  $options OAuthHttp options (timeout, limit).
	 * @param bool                  $upload  An upload (POST with the bytes) or a download (GET).
	 * @return array{status: int, body: string, headers: array<string, string>} The answer (OAuthHttp's CloudFailure for a refused address or a transport failure passes through).
	 */
	public function content( string $route, array $arg, string $body = '', array $headers = [], array $options = [], bool $upload = true ): array {
		$headers['Dropbox-API-Arg'] = self::arg( $arg );
		if ( $upload ) {
			$headers['Content-Type'] = 'application/octet-stream';
		}

		return $this->http->request( $upload ? 'POST' : 'GET', self::CONTENT . $route, $headers, $body, $options );
	}

	/**
	 * The argument as an HTTP-header-safe JSON text.
	 *
	 * @param array<string, mixed> $arg Argument.
	 * @throws \LogicException When it cannot be written as printable ASCII (never sent).
	 */
	public static function arg( array $arg ): string {
		$json = str_replace( "\x7f", '\\u007f', (string) wp_json_encode( $arg, JSON_UNESCAPED_SLASHES ) );
		if ( 1 !== preg_match( '/^[\x20-\x7E]+\z/', $json ) ) {
			throw new \LogicException( 'A Dropbox argument is written as printable ASCII.' );
		}

		return $json;
	}
}
