<?php
/**
 * How each destination of a job did.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The job's state.cloudResults[<profile id>] = {status, name, type, message, remote,
 * at, pruned}. The profile's name and type are copied in when the result is
 * written, so the run log, the mail and the job screen still name a
 * destination deleted later. Messages are the failures' texts (translated,
 * no secret) clipped to MESSAGE_BYTES. partial(): a job with a plan whose
 * destinations did not all end `ok` (spec 21.1 "részben sikeres").
 */
final class CloudOutcome {

	public const OK = 'ok';

	public const FAILED = 'failed';

	public const SKIPPED = 'skipped';

	private const KEY = 'cloudResults';

	private const MESSAGE_BYTES = 500;

	/**
	 * @param Job    $job     Job.
	 * @param string $id      Profile id.
	 * @param string $status  OK, FAILED or SKIPPED.
	 * @param string $name    Profile name.
	 * @param string $type    Profile type.
	 * @param string $message Why (FAILED, SKIPPED).
	 * @param string $remote  The remote file's reference (OK).
	 * @param int    $at      Unix time.
	 */
	public static function record( Job $job, string $id, string $status, string $name, string $type, string $message = '', string $remote = '', int $at = 0 ): void {
		$pruned                         = (array) ( $job->state[ self::KEY ][ $id ]['pruned'] ?? [] );
		$job->state[ self::KEY ][ $id ] = [
			'status'  => $status,
			'name'    => $name,
			'type'    => $type,
			'message' => Text::clip( $message, self::MESSAGE_BYTES ),
			'remote'  => $remote,
			'at'      => $at,
			'pruned'  => $pruned,
		];
	}

	/**
	 * @param Job                $job   Job.
	 * @param string             $id    Profile id.
	 * @param array<int, string> $names Remote backups deleted by retention.
	 */
	public static function addPruned( Job $job, string $id, array $names ): void {
		if ( isset( $job->state[ self::KEY ][ $id ] ) ) {
			$job->state[ self::KEY ][ $id ]['pruned'] = array_values( array_merge( (array) ( $job->state[ self::KEY ][ $id ]['pruned'] ?? [] ), $names ) );
		}
	}

	/**
	 * @param Job    $job Job.
	 * @param string $id  Profile id.
	 * @return array<string, mixed>|null
	 */
	public static function get( Job $job, string $id ): ?array {
		$row = $job->state[ self::KEY ][ $id ] ?? null;

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param Job $job Job.
	 * @return array<string, array<string, mixed>>
	 */
	public static function all( Job $job ): array {
		$all = $job->state[ self::KEY ] ?? [];

		return is_array( $all ) ? array_filter( $all, 'is_array' ) : [];
	}

	public static function allOk( Job $job, CloudPlan $plan ): bool {
		foreach ( $plan->ids() as $id ) {
			if ( self::OK !== ( self::get( $job, $id )['status'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	public static function partial( Job $job ): bool {
		$plan = CloudPlan::of( $job );

		return null !== $plan && ! self::allOk( $job, $plan );
	}

	/**
	 * @param Job $job Job.
	 * @return array<int, array{id: string, name: string, type: string, status: string, message: string, pruned: int}>
	 */
	public static function view( Job $job ): array {
		$plan = CloudPlan::of( $job );
		$view = [];
		foreach ( null === $plan ? [] : $plan->ids() as $id ) {
			$row    = self::get( $job, $id ) ?? [];
			$view[] = [
				'id'      => $id,
				'name'    => (string) ( $row['name'] ?? '' ),
				'type'    => (string) ( $row['type'] ?? '' ),
				'status'  => (string) ( $row['status'] ?? 'pending' ),
				'message' => (string) ( $row['message'] ?? '' ),
				'pruned'  => count( (array) ( $row['pruned'] ?? [] ) ),
			];
		}

		return $view;
	}

	/**
	 * @param Job $job Job.
	 * @return array<int, array{name: string, status: string, error: string}>
	 */
	public static function forLog( Job $job ): array {
		return array_map(
			static fn( array $row ): array => [
				'name'   => $row['name'],
				'status' => $row['status'],
				'error'  => $row['message'],
			],
			self::view( $job )
		);
	}
}
