<?php
/**
 * The signed routes another site calls (spec 19).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Pairing;
use LightweightPlugins\Migration\Link\PeerJobs;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * No login and no cookie: a pairing code (/link/pair) or the signature of a
 * paired site (everything else) is the credential, checked by Link\Guard
 * before any callback runs. Single sites only (spec 19). The guards are
 * written out per route, so a search for `Guard::class` finds every route
 * that has one, and only here. No answer of these routes may be kept by a
 * cache (noStore()), and every answer to a verified request is signed
 * (LinkAnswers, issue #1).
 */
final class LinkPeerRoutes {

	public const JOB = '/link/jobs/(?P<id>[a-f0-9]{16})';

	public static function register(): void {
		if ( is_multisite() ) {
			return;
		}
		add_filter( 'rest_post_dispatch', [ self::class, 'noStore' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ LinkAnswers::class, 'sign' ], LinkAnswers::PRIORITY, 3 );
		register_rest_route( Routes::NAMESPACE, '/link/pair', [ self::endpoint( 'POST', 'pair' ) + [ 'permission_callback' => [ Guard::class, 'pairing' ] ] ] );
		register_rest_route( Routes::NAMESPACE, '/link/hello', [ self::endpoint( 'POST', 'hello' ) + [ 'permission_callback' => [ Guard::class, 'checkPending' ] ] ] );
		register_rest_route( Routes::NAMESPACE, '/link', [ self::endpoint( 'DELETE', 'forget' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, '/link/push', [ self::endpoint( 'POST', 'startPush' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, '/link/pull', [ self::endpoint( 'POST', 'startPull' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
		register_rest_route(
			Routes::NAMESPACE,
			self::JOB,
			[
				self::endpoint( 'GET', 'job' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ],
				self::endpoint( 'DELETE', 'release' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ],
			]
		);
		register_rest_route( Routes::NAMESPACE, self::JOB . '/tick', [ self::endpoint( 'POST', 'tick' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, self::JOB . '/input', [ self::endpoint( 'POST', 'input' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, self::JOB . '/upload/(?P<offset>\d{1,18})', [ self::endpoint( 'POST', 'upload' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, self::JOB . '/archive', [ self::endpoint( 'GET', 'archive' ) + [ 'permission_callback' => [ Guard::class, 'check' ] ] ] );
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
	 * Uses the open code (Guard::pairing checked it) and answers with this
	 * site's half of the exchange. A code another request used meanwhile is
	 * the guard's refusal; a full list or a link that could not be stored
	 * after the code was used are told (409); a storage failure before that
	 * answers busy (the code stays open, the other site may try again).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pair( \WP_REST_Request $request ) {
		try {
			$answer = QuietOutput::run( static fn(): ?array => Pairing::fromWordPress()->accept( $request->get_json_params(), self::ip() ) );
		} catch ( \RuntimeException $e ) {
			if ( Links::FULL === $e->getCode() ) {
				return new \WP_Error( 'lw_migration_link_full', __( 'This site already has 20 connections. Remove one before pairing.', 'lw-migration' ), [ 'status' => 409 ] );
			}
			if ( Links::CODE_USED === $e->getCode() ) {
				return new \WP_Error( 'lw_migration_link_code_used', __( 'The code was used but the connection could not be saved. Make a new code.', 'lw-migration' ), [ 'status' => 409 ] );
			}

			return Guard::busy(); // Storage failed before the code was used: no verdict on the code, try again.
		} catch ( \Throwable $e ) {
			return Guard::refusal();
		}

		return null === $answer ? Guard::refusal() : new \WP_REST_Response( $answer, 200 );
	}

	/**
	 * Confirms the link on its first hello and describes this site. A link
	 * that cannot be confirmed now answers busy: the other site removes its
	 * side again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function hello( \WP_REST_Request $request ) {
		try {
			$answer = QuietOutput::run( static fn(): array => Pairing::fromWordPress()->hello( Guard::link( $request ) ) );
		} catch ( \RuntimeException $e ) {
			return Guard::busy();
		} catch ( \Throwable $e ) {
			return Guard::refusal();
		}

		return new \WP_REST_Response( $answer, 200 );
	}

	/**
	 * The other site removed this connection: its jobs here stop, the link goes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function forget( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => LinksApi::fromServices( Services::get() )->forget( (string) Guard::link( $request )['id'] ) );
	}

	/**
	 * Starts the import of a push (a repeated start answers the same job).
	 *
	 * @param \WP_REST_Request $request Request ({ref, name, size, safetyBackup}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function startPush( \WP_REST_Request $request ) {
		return self::peer( static fn( PeerJobs $peer, JobsApi $api ): array => $peer->view( $api, $peer->startPush( Guard::link( $request ), Routes::json( $request ) ) ), 201 );
	}

	/**
	 * Starts the export of a pull (a repeated start answers the same job).
	 *
	 * @param \WP_REST_Request $request Request ({ref}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function startPull( \WP_REST_Request $request ) {
		return self::peer( static fn( PeerJobs $peer, JobsApi $api ): array => $peer->view( $api, $peer->startPull( Guard::link( $request ), Routes::json( $request ) ) ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function job( \WP_REST_Request $request ) {
		return self::peer( static fn( PeerJobs $peer, JobsApi $api ): array => $peer->view( $api, $peer->own( Guard::link( $request ), self::id( $request ) ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function tick( \WP_REST_Request $request ) {
		return self::peer( static fn( PeerJobs $peer, JobsApi $api ): array => $peer->view( $api, $api->tick( $peer->own( Guard::link( $request ), self::id( $request ) )->id ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request (the answer the job asks for).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function input( \WP_REST_Request $request ) {
		return self::peer( static fn( PeerJobs $peer, JobsApi $api ): array => $peer->view( $api, $api->answer( $peer->own( Guard::link( $request ), self::id( $request ) )->id, Routes::json( $request ) ) ) );
	}

	/**
	 * A chunk of the pushed archive: the offset is part of the signed path,
	 * the bytes are the raw (signed) body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function upload( \WP_REST_Request $request ) {
		return self::peer(
			static function ( PeerJobs $peer, JobsApi $api ) use ( $request ): array {
				$job    = $peer->own( Guard::link( $request ), self::id( $request ) );
				$offset = JobInput::wholeNumber( $request->get_url_params()['offset'] ?? null, 'offset' );

				return $peer->view( $api, $api->upload( $job->id, $offset, (string) $request->get_body() ) );
			}
		);
	}

	/**
	 * The other site is done with the job: cancelled, or its archive dropped.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function release( \WP_REST_Request $request ) {
		return self::peer( static fn( PeerJobs $peer, JobsApi $api ): array => $peer->release( Guard::link( $request ), self::id( $request ), static fn( string $id ): array => $api->cancel( $id ) ) );
	}

	/**
	 * Streams the ready archive of a pull and ends the request; a failure
	 * before the first header is a JSON error.
	 *
	 * @param \WP_REST_Request $request Request (Range and If-Range headers).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function archive( \WP_REST_Request $request ) {
		try {
			$backup = QuietOutput::run( static fn(): Backup => PeerJobs::fromServices( Services::get() )->archive( Guard::link( $request ), self::id( $request ) ) );
			DownloadStream::send( $backup, $request );
		} catch ( \Throwable $e ) {
			if ( ! headers_sent() ) {
				return Routes::respond(
					static function () use ( $e ): array {
						throw $e;
					}
				);
			}
		}
		exit;
	}

	/**
	 * @param \Closure $work   fn( PeerJobs $peer, JobsApi $api ): array — the answer.
	 * @param int      $status Success status.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function peer( \Closure $work, int $status = 200 ) {
		return Routes::respond(
			static function () use ( $work ): array {
				$services = Services::get();

				return $work( PeerJobs::fromServices( $services ), JobsApi::fromServices( $services ) );
			},
			$status
		);
	}

	/**
	 * The job id of the matched path (never a body or query field).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function id( \WP_REST_Request $request ): string {
		return (string) ( $request->get_url_params()['id'] ?? '' );
	}

	/**
	 * The request's IP, shown to this site's admins only (I7); never trusted for anything.
	 */
	private static function ip(): string {
		$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP.

		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * No cache may keep an answer of a connection route — a stale job view
	 * could make the other site answer twice (review m1). Hooked on
	 * rest_post_dispatch by register(), so the guards' refusals (core turns
	 * them into responses before this filter) get it too.
	 *
	 * @param \WP_HTTP_Response|mixed $response Answer.
	 * @param mixed                   $server   REST server.
	 * @param \WP_REST_Request|mixed  $request  Request.
	 * @return \WP_HTTP_Response|mixed
	 */
	public static function noStore( $response, $server, $request ) {
		unset( $server );
		$route = $request instanceof \WP_REST_Request ? (string) $request->get_route() : '';
		if ( $response instanceof \WP_HTTP_Response && ( Guard::ROOT === $route || str_starts_with( $route, Guard::PREFIX ) ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
		}

		return $response;
	}
}
