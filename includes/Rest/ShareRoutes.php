<?php
/**
 * The transfer-link routes of lw-migration/v1.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Registered by RouteMap::register(), and like every route only in the main
 * site's context. Listing, creating and revoking links need Permission::check;
 * the download (`GET /backups/shared?share=<token>`, HEAD through core) needs
 * no login: the link is the credential (Permission::share). Every refusal of
 * the download, also one that happens after the guard but before the first
 * header, is Permission::shareRefusal(); the use counter is the only write an
 * anonymous request makes, best effort.
 */
final class ShareRoutes {

	public const SHARED = '/backups/shared';

	public const ONE = '/backups/shares/(?P<sid>[a-f0-9]{8})';

	/**
	 * The guards are written out per route (never a variable), so a search for
	 * `Permission::class, 'share'` finds the one route that has it.
	 */
	public static function register(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		register_rest_route(
			Routes::NAMESPACE,
			'/backups/shares',
			[
				self::endpoint( 'GET', 'listShares' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ],
				self::endpoint( 'POST', 'createShare' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ],
			]
		);
		register_rest_route(
			Routes::NAMESPACE,
			self::ONE,
			[ self::endpoint( 'DELETE', 'revokeShare' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ] ]
		);
		register_rest_route(
			Routes::NAMESPACE,
			self::SHARED,
			[ self::endpoint( 'GET', 'download' ) + [ 'permission_callback' => [ Permission::class, 'share' ] ] ]
		);
	}

	/**
	 * @param string $method   HTTP method.
	 * @param string $callback Method of this class.
	 * @return array{methods: string, callback: array{0: class-string, 1: string}, show_in_index: false}
	 */
	private static function endpoint( string $method, string $callback ): array {
		return [
			'methods'       => $method,
			'callback'      => [ self::class, $callback ],
			'show_in_index' => false,
		];
	}

	/**
	 * Address of the transfer-link download (the token is added as ?share=).
	 */
	public static function address(): string {
		return rest_url( Routes::NAMESPACE . self::SHARED );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listShares() {
		return Routes::respond( static fn(): array => self::api()->all( time() ) );
	}

	/**
	 * @param \WP_REST_Request $request Request ({folder, name, expires?}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function createShare( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->create( Routes::json( $request ), get_current_user_id(), time(), self::address() ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function revokeShare( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->revoke( (string) $request->get_param( 'sid' ) ) );
	}

	/**
	 * Streams the backup of the link (Permission::share opened it) and ends
	 * the request; the link is checked again here, and every failure before
	 * the first header (the file gone or unreadable) is the same refusal as
	 * the guard's. A use is recorded only once the file is open, from what
	 * is about to be sent (countsAsUse()).
	 *
	 * @param \WP_REST_Request $request Request (?share=, Range and If-Range headers).
	 * @return \WP_Error
	 */
	public static function download( \WP_REST_Request $request ) {
		try {
			$token  = $request->get_param( 'share' );
			$target = is_string( $token ) ? Permission::shareTarget( $token ) : null;
			if ( null === $target ) {
				return Permission::shareRefusal(); // Gone between the guard and here.
			}
			$method = (string) $request->get_method();
			$touch  = static function ( int $status, int $first, int $last ) use ( $target, $method ): void {
				$use = self::countsAsUse( $method, $status, $first, $last );
				if ( null !== $use ) {
					( new Shares() )->touch( $target['id'], $use, time() );
				}
			};
			DownloadStream::send( $target['backup'], $request, $touch );
		} catch ( \Throwable $e ) {
			if ( ! headers_sent() ) {
				return Permission::shareRefusal();
			}
		}
		exit;
	}

	/**
	 * Whether what the Downloader is about to send is a use of the link
	 * (rulings R11, M2): null for HEAD (no download, nothing touched); true
	 * for the whole file (200: no range, several ranges, a stale If-Range) or
	 * a 206 from byte 0 that reaches past the archive header; false for any
	 * other part (a resume, or the importer's header probe 0-4376).
	 *
	 * @param string $method HTTP method.
	 * @param int    $status 200 or 206.
	 * @param int    $first  First byte sent.
	 * @param int    $last   Last byte sent.
	 */
	public static function countsAsUse( string $method, int $status, int $first, int $last ): ?bool {
		if ( 'GET' !== $method ) {
			return null;
		}

		return 200 === $status || ( 0 === $first && $last >= Format::HEADER_SIZE );
	}

	private static function api(): SharesApi {
		return SharesApi::fromServices( Services::get() );
	}
}
