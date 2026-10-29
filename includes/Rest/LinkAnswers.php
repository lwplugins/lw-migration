<?php
/**
 * Signs the answers of the signed connection routes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #1: on an http connection anyone on the way could answer instead of
 * this site, so every answer to a request Link\Guard verified carries
 * Signer::HEADER_RESPONSE, the link's signature over that request's nonce,
 * the status and the body. Hooked on rest_post_dispatch by
 * LinkPeerRoutes::register() after every other filter; the body is encoded
 * as core encodes it right after (WP_REST_Server::serve_request(): the data
 * with its links, wp_json_encode() with the rest_json_encode_options
 * options). A refusal never knew a link, so it goes unsigned (the other site
 * needs a signature only on success); so does the pairing answer, which
 * proves itself with the code. The archive download streams past this
 * filter: its bytes are checked against the archiveSha256 of the signed
 * job view instead.
 */
final class LinkAnswers {

	/** After every other filter of the answer (core's own run at 10). */
	public const PRIORITY = PHP_INT_MAX;

	/**
	 * @param \WP_HTTP_Response|mixed $response Answer.
	 * @param \WP_REST_Server|mixed   $server   REST server.
	 * @param \WP_REST_Request|mixed  $request  Request.
	 * @return \WP_HTTP_Response|mixed
	 */
	public static function sign( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		try {
			$link = Guard::link( $request );
		} catch ( \LogicException $e ) {
			return $response; // Not verified: a refusal, the pairing, or another plugin's route.
		}
		$nonce = $request->get_header( Signer::HEADER_NONCE );
		$body  = self::body( $response, $server, $request );
		if ( is_string( $nonce ) && null !== $body ) {
			$response->header( Signer::HEADER_RESPONSE, Signer::answer( (string) $link['id'], (string) $link['key'], $nonce, (int) $response->get_status(), $body ) );
		}

		return $response;
	}

	/**
	 * The body core is about to send for this answer.
	 *
	 * @param \WP_HTTP_Response     $response Answer.
	 * @param \WP_REST_Server|mixed $server   REST server.
	 * @param \WP_REST_Request      $request  Request.
	 */
	private static function body( \WP_HTTP_Response $response, $server, \WP_REST_Request $request ): ?string {
		$data = $response instanceof \WP_REST_Response && $server instanceof \WP_REST_Server ? $server->response_to_data( $response, false ) : $response->get_data();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, applied as WP_REST_Server::get_json_encode_options() applies it.
		$options = (int) apply_filters( 'rest_json_encode_options', $request->has_param( '_pretty' ) ? JSON_PRETTY_PRINT : 0, $request );
		$json    = wp_json_encode( $data, $options );

		return is_string( $json ) ? $json : null;
	}
}
