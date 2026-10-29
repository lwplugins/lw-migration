<?php
/**
 * Who may use the signed routes of a connection.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Permission callbacks of Rest\LinkPeerRoutes, the signed routes another
 * site calls without a login (spec 19: not cookie-based, only by signature).
 * verify() only reads until the signature is proven: a single site, no query
 * parameter but rest_route, the route /link itself or one under /link/
 * without control characters, well-formed headers, a confirmed incoming link
 * whose secret opens (an unconfirmed one only for the hello), the time window
 * and the HMAC (hash_equals). The request object may carry only its raw body
 * (no form fields or files, a JSON or byte media type: plainBody()). Then the nonce is remembered (a replay is
 * refused) and the link's last use noted. Every refusal is the same constant
 * 401, logged in or not, so a probe learns nothing; a replay cache that cannot
 * be written answers a retryable 503. The verified link waits for the route
 * callback in a WeakMap keyed by the request object (link()). The second
 * call core makes for its Allow header (on rest_post_dispatch) is refused
 * read-only, so a request core never dispatched writes nothing.
 */
final class Guard {

	public const PREFIX = '/lw-migration/v1/link/';

	/** The route of a link itself (DELETE /link, review I1). */
	public const ROOT = '/lw-migration/v1/link';

	/**
	 * Media types a signed or pairing request may declare ('' = none). Core's
	 * transports declare a form for a POST without a body, so a form type is
	 * allowed while it brings no field (plainBody()).
	 */
	private const MEDIA_TYPES = [ '', 'application/json', 'application/octet-stream', 'application/x-www-form-urlencoded' ];

	/**
	 * Verified links by request object (gone with the request).
	 *
	 * @var \WeakMap<\WP_REST_Request, array<string, mixed>>|null
	 */
	private static ?\WeakMap $verified = null;

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		return self::guard( $request, false );
	}

	/**
	 * Only for /link/hello: also a link that waits for its first hello.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function checkPending( \WP_REST_Request $request ) {
		return self::guard( $request, true );
	}

	/**
	 * /link/pair: the open pairing code is the credential. Read only; the
	 * callback uses the code up.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function pairing( \WP_REST_Request $request ) {
		try {
			$only = [] === array_diff( array_keys( $request->get_query_params() ), [ 'rest_route' ] );

			return $only && self::plainBody( $request ) && Pairing::fromWordPress()->opens( $request->get_json_params() ) ? true : self::refusal();
		} catch ( \Throwable $e ) {
			return self::refusal();
		}
	}

	/**
	 * @param string               $method     HTTP method.
	 * @param string               $route      Dispatched REST route.
	 * @param array<string, mixed> $query      Query parameters.
	 * @param array<string, mixed> $headers    Values by Signer::HEADER_* name.
	 * @param string               $body       Raw body.
	 * @param bool                 $pendingToo Whether an unconfirmed link is accepted (the hello).
	 * @param Links                $links      Links.
	 * @param Nonces               $nonces     Replay cache.
	 * @param int                  $now        Unix time.
	 * @return array<string, mixed>|string The link (with its key), '' for a refusal, or Nonces::BUSY.
	 */
	public static function verify( string $method, string $route, array $query, array $headers, string $body, bool $pendingToo, Links $links, Nonces $nonces, int $now ) {
		$ours = self::ROOT === $route || str_starts_with( $route, self::PREFIX );
		if ( is_multisite() || [] !== array_diff( array_keys( $query ), [ 'rest_route' ] ) || ! $ours || 1 === preg_match( '/[\x00-\x1F\x7F]/', $route ) ) {
			return '';
		}
		$parts = Signer::parse( $headers );
		$link  = null === $parts ? null : $links->find( $parts['link'], $now );
		if ( null === $parts || null === $link || Links::IN !== $link['direction'] || null === $link['key'] || ( ! $link['confirmed'] && ! $pendingToo ) ) {
			return '';
		}
		if ( ! Signer::verify( $parts, $link['key'], $method, $route, $body, $now ) ) {
			return '';
		}
		$remembered = $nonces->remember( $parts['link'], $parts['nonce'], $parts['time'], $now );
		if ( Nonces::OK !== $remembered ) {
			return Nonces::SEEN === $remembered ? '' : Nonces::BUSY;
		}
		$links->touch( $link['id'], $now );

		return $link;
	}

	/**
	 * The link Guard verified for this request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 * @throws \LogicException When the route was not guarded.
	 */
	public static function link( \WP_REST_Request $request ): array {
		$link = null === self::$verified ? null : ( self::$verified[ $request ] ?? null );
		if ( null === $link ) {
			throw new \LogicException( 'This route runs only behind Guard::check.' );
		}

		return $link;
	}

	/**
	 * Not translated on purpose: the same bytes for every refusal and locale.
	 */
	public static function refusal(): \WP_Error {
		return new \WP_Error( 'lw_migration_link_refused', 'This request is not accepted.', [ 'status' => 401 ] );
	}

	public static function busy(): \WP_Error {
		return new \WP_Error( 'lw_migration_link_busy', __( 'This site is busy answering the other site; it tries again in a moment.', 'lw-migration' ), [ 'status' => 503 ] );
	}

	/**
	 * Whether the request carries nothing but its raw body. The signature
	 * covers php://input only, which PHP leaves empty for multipart/form-data
	 * while core fills the body and file parameters from $_POST and $_FILES:
	 * such fields and files, and any media type but JSON, bytes or an empty
	 * form, are refused.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function plainBody( \WP_REST_Request $request ): bool {
		$type  = $request->get_header( 'Content-Type' );
		$media = null === $type ? '' : strtolower( trim( explode( ';', (string) $type, 2 )[0] ) );

		return [] === $request->get_body_params() && [] === $request->get_file_params() && in_array( $media, self::MEDIA_TYPES, true );
	}

	/**
	 * @param \WP_REST_Request $request    Request.
	 * @param bool             $pendingToo Whether an unconfirmed link is accepted.
	 * @return true|\WP_Error
	 */
	private static function guard( \WP_REST_Request $request, bool $pendingToo ) {
		try {
			// Core's Allow header (rest_send_allow_header) asks every guard again after the dispatch, also for a
			// request core refused before it (garbled JSON): that pass never uses a nonce or touches a link.
			if ( doing_filter( 'rest_post_dispatch' ) || ! self::plainBody( $request ) ) {
				return self::refusal();
			}
			$headers = [];
			foreach ( Signer::HEADERS as $name ) {
				$headers[ $name ] = $request->get_header( $name ); // Core normalises the name; a repeated header comes joined with "," (no pattern accepts it).
			}
			$result = self::verify( (string) $request->get_method(), (string) $request->get_route(), $request->get_query_params(), $headers, (string) $request->get_body(), $pendingToo, new Links(), new Nonces(), time() );
		} catch ( \Throwable $e ) {
			return self::refusal();
		}
		if ( is_array( $result ) ) {
			self::$verified           ??= new \WeakMap();
			self::$verified[ $request ] = $result;

			return true;
		}

		return Nonces::BUSY === $result ? self::busy() : self::refusal();
	}
}
