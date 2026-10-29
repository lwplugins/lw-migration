<?php
/**
 * Callbacks of the lw-migration/v1 REST routes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\PeerJobs;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * The callbacks of the routes RouteMap registers. Thin: each callback turns
 * the request into plain values, calls an API class and turns any failure
 * into a WP_Error through ErrorMap; whatever a JSON route prints is thrown
 * away (QuietOutput). The other route classes answer through respond() and
 * read bodies through json().
 */
final class Routes {

	public const NAMESPACE = 'lw-migration/v1';

	public const JOB = '/jobs/(?P<id>[a-f0-9]{16})';

	public const DOWNLOAD = '/backups/download';

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function createJob( \WP_REST_Request $request ) {
		return self::job( static fn( JobsApi $api ): Job => $api->create( self::json( $request ) ), 201 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activeJob() {
		return self::respond(
			static function (): array {
				$api = JobsApi::fromServices( Services::get() );
				$job = $api->active();

				return [ 'job' => null === $job ? null : $api->view( $job ) ];
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function getJob( \WP_REST_Request $request ) {
		return self::job( static fn( JobsApi $api ): Job => $api->get( self::id( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cancelJob( \WP_REST_Request $request ) {
		return self::respond(
			static function () use ( $request ): array {
				$api    = JobsApi::fromServices( Services::get() );
				$result = $api->cancel( self::id( $request ) );

				return [
					'job'            => $api->view( $result['job'] ),
					'cancelDeferred' => $result['cancelDeferred'],
				];
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function tickJob( \WP_REST_Request $request ) {
		return self::job( static fn( JobsApi $api ): Job => $api->tick( self::notDrivenElsewhere( self::id( $request ) ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function answerJob( \WP_REST_Request $request ) {
		return self::job( static fn( JobsApi $api ): Job => $api->answer( self::notDrivenElsewhere( self::id( $request ) ), self::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request (raw body, ?offset=).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function uploadChunk( \WP_REST_Request $request ) {
		return self::job( static fn( JobsApi $api ): Job => $api->upload( self::notDrivenElsewhere( self::id( $request ) ), JobInput::wholeNumber( $request->get_param( 'offset' ), 'offset' ), (string) $request->get_body() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listBackups() {
		return self::respond( static fn(): array => self::backups()->listing( DiskSpace::free( Services::get()->storage()->backupsDir() ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request (?folder&name).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deleteBackup( \WP_REST_Request $request ) {
		return self::respond(
			static function () use ( $request ): array {
				self::backups()->delete( $request->get_params() );

				return [ 'deleted' => true ];
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function labelBackup( \WP_REST_Request $request ) {
		return self::respond( static fn(): array => self::backups()->label( self::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function backupEntries( \WP_REST_Request $request ) {
		return self::respond( static fn(): array => self::backups()->entries( $request->get_params() ) );
	}

	/**
	 * @param \WP_REST_Request $request Request (?folder&name).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function backupContents( \WP_REST_Request $request ) {
		return self::respond( static fn(): array => ( new BackupContentsApi( self::backups(), plugin_basename( LW_MIGRATION_FILE ) ) )->contents( $request->get_params() ) );
	}

	/**
	 * A download link (token only, never the REST nonce) for one backup.
	 *
	 * @param \WP_REST_Request $request Request ({folder, name}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function downloadToken( \WP_REST_Request $request ) {
		return self::respond( static fn(): array => self::backups()->downloadToken( self::json( $request ), rest_url( self::NAMESPACE . self::DOWNLOAD ) ) );
	}

	/**
	 * Streams the backup the token names (Permission::download checked the
	 * login cookie, the capability and the token) and ends the request; a HEAD
	 * request (core serves it with this GET route) gets the headers only; only a
	 * failure before the first header comes back as a REST error, with a fixed
	 * message.
	 *
	 * @param \WP_REST_Request $request Request (?token=, Range and If-Range headers).
	 * @return \WP_Error
	 */
	public static function downloadBackup( \WP_REST_Request $request ) {
		try {
			DownloadStream::send( self::downloadable( $request ), $request );
		} catch ( \Throwable $e ) {
			if ( ! headers_sent() ) {
				return self::error( $e, __( 'The backup could not be read. Check that the file is still in its folder.', 'lw-migration' ) );
			}
		}
		exit;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function getSettings() {
		return self::respond( static fn(): array => SettingsApi::get() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function saveSettings( \WP_REST_Request $request ) {
		return self::respond( static fn(): array => SettingsApi::save( self::json( $request ) ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function getBackupDir() {
		return self::respond( static fn(): array => BackupDirApi::fromServices( Services::get() )->view() );
	}

	/**
	 * The admin home page's data, read only (OverviewApi).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function getOverview() {
		return self::respond( static fn(): array => OverviewApi::fromServices( Services::get() )->view() );
	}

	/**
	 * How big each part of a backup would be (EstimateApi): measures for a
	 * moment itself, then answers what is known.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function getEstimate() {
		return self::respond( static fn(): array => EstimateApi::fromServices( Services::get() )->view() );
	}

	/**
	 * Starts a move of the backups ({path} or {action: finish|undo}).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function moveBackups( \WP_REST_Request $request ) {
		return self::job( static fn(): Job => BackupDirApi::fromServices( Services::get() )->move( self::json( $request ) ), 201 );
	}

	/**
	 * @param \Closure $work   fn( JobsApi $api ): Job.
	 * @param int      $status Success status.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function job( \Closure $work, int $status = 200 ) {
		return self::respond(
			static function () use ( $work ): array {
				$api = JobsApi::fromServices( Services::get() );

				return $api->view( $work( $api ) );
			},
			$status
		);
	}

	/**
	 * Runs the work quietly; a failure becomes a WP_Error.
	 *
	 * @param \Closure $work   fn(): mixed — the response data.
	 * @param int      $status Success status.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function respond( \Closure $work, int $status = 200 ) {
		try {
			return new \WP_REST_Response( QuietOutput::run( $work ), $status );
		} catch ( \Throwable $e ) {
			return self::error( $e, __( 'Something went wrong on the server. Reload the page to see where things stand.', 'lw-migration' ) );
		}
	}

	/**
	 * @param \Throwable $e        The failure.
	 * @param string     $fallback Message for an unexpected failure (translated).
	 */
	private static function error( \Throwable $e, string $fallback ): \WP_Error {
		$error = ErrorMap::of( $e, self::paths(), $fallback );

		return new \WP_Error( $error['code'], $error['message'], array_merge( $error['data'], [ 'status' => $error['status'] ] ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function paths(): array {
		try {
			return Services::get()->redactPaths();
		} catch ( \Throwable $e ) {
			return [ ABSPATH, WP_CONTENT_DIR ];
		}
	}

	private static function backups(): BackupsApi {
		$services = Services::get();

		return new BackupsApi( $services->backups(), $services->jobs() );
	}

	/**
	 * The backup the download token names, checked again here.
	 *
	 * @param \WP_REST_Request $request Request (?token=).
	 * @throws RestException When the token no longer opens it (403) or it is gone (404).
	 */
	private static function downloadable( \WP_REST_Request $request ): Backup {
		$token  = $request->get_param( 'token' );
		$target = is_string( $token ) ? Permission::downloadTarget( $token ) : null;
		if ( null === $target ) {
			throw new RestException( 'lw_migration_download_expired', __( 'This download link has expired. Start the download again from the backups list.', 'lw-migration' ), 403 );
		}

		return self::backups()->find( $target );
	}

	/**
	 * A job a connected site drives here (PeerJobs::drivenElsewhere) is never
	 * ticked, answered or fed from this site's screens (ruling I5); looking
	 * at it and cancelling it stay allowed, and once its link is gone or its
	 * admin may no longer act, this site finishes it.
	 *
	 * @param string $id Job id.
	 * @return string The id.
	 * @throws RestException 409 lw_migration_link_job.
	 */
	private static function notDrivenElsewhere( string $id ): string {
		try {
			$job = Services::get()->jobs()->load( $id );
		} catch ( \Throwable $e ) {
			return $id; // JobsApi answers an unknown job with its own 404.
		}
		$peer = PeerJobs::drivenElsewhere( $job, new Links(), static fn( array $owner ): ?array => Permission::owner( $owner ) );
		if ( null !== $peer ) {
			/* translators: %s: the connected site's name */
			throw new RestException( 'lw_migration_link_job', sprintf( __( 'This job is driven by the connected site %s. Follow it here, or cancel it.', 'lw-migration' ), $peer ), 409 );
		}

		return $id;
	}

	private static function id( \WP_REST_Request $request ): string {
		return (string) $request->get_param( 'id' );
	}

	/**
	 * The JSON object of a request body; no body is an empty one. A body that
	 * is not a JSON object (not JSON, not sent as JSON, a scalar or a list)
	 * is refused instead of being read as empty.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 * @throws RestException 400 lw_migration_invalid.
	 */
	public static function json( \WP_REST_Request $request ): array {
		$data = $request->get_json_params();
		// @phpstan-ignore function.alreadyNarrowedType (the stub says array; core returns null without a JSON body and a decoded scalar as it is)
		$object = is_array( $data ) && ( [] === $data || array_keys( $data ) !== range( 0, count( $data ) - 1 ) ); // Not a list (PHP 8.0: no array_is_list).
		if ( '' !== trim( (string) $request->get_body() ) && ! $object ) {
			throw RestException::invalid( __( 'The request body must be a JSON object.', 'lw-migration' ) );
		}

		return $object ? $data : [];
	}
}
