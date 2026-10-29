<?php
/**
 * The only shape in which a job leaves the server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\FailureReporter;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\MoveJobs;

defined( 'ABSPATH' ) || exit;

/**
 * A whitelist, field by field: job.json also holds absolute paths (archive,
 * work files, the site snapshot, cursors), the download address with its
 * tokens and the safety backup's path — none of that is copied. An address is
 * shown only in its display form, files only by name. The texts (message,
 * warnings, error) lose the site's paths and show addresses only in display
 * form once more, in case a producer missed one.
 */
final class JobView {

	/**
	 * @param Job                $job          Job.
	 * @param bool               $pastNoReturn Whether a cancel would only be recorded (Runner::defersCancel()).
	 * @param array<int, string> $redactPaths  Absolute paths removed from the texts (Services::redactPaths()).
	 * @return array<string, mixed>
	 */
	public static function of( Job $job, bool $pastNoReturn, array $redactPaths ): array {
		$redact = static fn( string $text ): string => AddressRedactor::reduce( FailureReporter::redactWith( $text, $redactPaths ) );

		return [
			'id'             => $job->id,
			'type'           => $job->type,
			'status'         => $job->status,
			'step'           => (string) $job->currentStep(),
			'steps'          => array_values( $job->steps ),
			'stepIndex'      => $job->stepIndex,
			'progress'       => $job->progress,
			'message'        => $redact( $job->message ),
			'warnings'       => array_map( $redact, array_values( $job->warnings ) ),
			'warningCount'   => $job->warningCount,
			'error'          => $redact( $job->error ),
			'createdAt'      => $job->createdAt,
			'updatedAt'      => $job->updatedAt,
			'input'          => self::input( $job ),
			'pastNoReturn'   => $pastNoReturn,
			'cancelDeferred' => Runner::cancelDeferred( $job ),
			'source'         => self::source( $job ),
			'result'         => self::result( $job ),
			'link'           => self::link( $job ),
		];
	}

	/**
	 * The connection a job belongs to: on the side that started it and on
	 * the side that runs it for the other site (never the ref or a secret).
	 *
	 * @param Job $job Job.
	 * @return array{id: string, name: string, address: string}|null
	 */
	private static function link( Job $job ): ?array {
		$link = $job->state['link'] ?? null;
		if ( ! is_array( $link ) || ! is_string( $link['id'] ?? null ) ) {
			return null;
		}

		return [
			'id'      => $link['id'],
			'name'    => (string) ( $link['name'] ?? '' ),
			'address' => AddressRedactor::display( (string) ( $link['address'] ?? '' ) ),
		];
	}

	/**
	 * @param Job $job Job.
	 * @return array<string, mixed>|null
	 */
	private static function input( Job $job ): ?array {
		if ( Job::NEEDS_INPUT !== $job->status || null === $job->input ) {
			return null;
		}
		$kind = (string) ( $job->input['kind'] ?? '' );
		$data = (array) ( $job->input['data'] ?? [] );
		if ( 'password' === $kind ) {
			return [
				'kind'   => 'password',
				'reason' => 'wrong' === ( $data['reason'] ?? '' ) ? 'wrong' : 'encrypted',
			];
		}
		if ( 'confirm' === $kind ) {
			return [
				'kind'    => 'confirm',
				'summary' => ConfirmSummaryView::of( $data ),
			];
		}
		if ( 'upload' === $kind ) {
			return [
				'kind'       => 'upload',
				'received'   => (int) ( $data['received'] ?? 0 ),
				'size'       => (int) ( $data['size'] ?? 0 ),
				'chunkBytes' => (int) ( $data['chunkBytes'] ?? 0 ),
			];
		}

		return [ 'kind' => 'unknown' ];
	}

	/**
	 * @param Job $job Job.
	 * @return array<string, mixed>|null
	 */
	private static function source( Job $job ): ?array {
		if ( 'import' !== $job->type ) {
			return null;
		}
		$kind  = (string) ( $job->options['source'] ?? '' );
		$where = (string) ( $job->options['location'] ?? '' );
		if ( 'url' === $kind ) {
			return [
				'kind' => 'url',
				'name' => AddressRedactor::display( $where ),
			];
		}
		if ( 'backup' === $kind ) {
			return [
				'kind'   => 'backup',
				'name'   => basename( $where ),
				'folder' => (string) ( $job->options['folder'] ?? '' ),
			];
		}
		if ( 'link' === $kind ) {
			return [
				'kind' => 'link',
				'name' => RemoteJobStep::peer( $job ),
			];
		}
		if ( ImportOptions::CLOUD === $kind ) {
			$id      = (string) ( $job->state['cloudSource']['id'] ?? '' );
			$profile = '' === $id ? null : ( new ProfileRepository() )->find( $id );

			// The destination's current name; '' once it was deleted.
			return [
				'kind'        => 'cloud',
				'name'        => basename( $where ),
				'destination' => null === $profile ? '' : $profile->name,
			];
		}
		if ( 'upload' === $kind ) {
			return [
				'kind' => 'upload',
				'name' => basename( $where ),
				'size' => (int) ( $job->state['upload']['size'] ?? 0 ),
			];
		}

		return [
			'kind' => 'file',
			'name' => basename( $where ),
		];
	}

	/**
	 * @param Job $job Job.
	 * @return array<string, mixed>
	 */
	private static function result( Job $job ): array {
		if ( ResetJobs::TYPE === $job->type ) {
			return ResetResult::of( $job );
		}
		if ( MoveJobs::TYPE === $job->type ) {
			return [
				'moved' => (int) ( $job->state['moved']['count'] ?? 0 ),
				'bytes' => (int) ( $job->state['moved']['bytes'] ?? 0 ),
				'total' => (int) ( $job->state['total']['count'] ?? 0 ),
			];
		}
		if ( PushJobs::TYPE === $job->type ) {
			$safety = $job->state['remote']['safetyBackupName'] ?? null;

			// Where this site went, and the other site's backup of what it replaced.
			return [
				'target'       => AddressRedactor::display( (string) ( $job->state['link']['address'] ?? '' ) ),
				'safetyBackup' => is_string( $safety ) && '' !== $safety ? $safety : null,
			];
		}
		if ( CloudJobs::TYPE === $job->type ) {
			$backup = $job->state['backup'] ?? null;

			return [
				'action' => (string) ( $job->options['action'] ?? '' ),
				'backup' => is_array( $backup ) && is_string( $backup['name'] ?? null ) ? [
					'name' => $backup['name'],
					'size' => (int) ( $backup['size'] ?? 0 ),
				] : null,
				'cloud'  => CloudOutcome::view( $job ),
			];
		}
		if ( 'export' === $job->type ) {
			$backup = $job->state['backup'] ?? null;

			// local: false once LocalCleanupStep removed it after every upload succeeded.
			return [
				'backup' => is_array( $backup ) && is_string( $backup['name'] ?? null ) ? [
					'name'  => $backup['name'],
					'size'  => (int) ( $backup['size'] ?? 0 ),
					'local' => false !== ( $backup['local'] ?? true ),
				] : null,
				'cloud'  => CloudOutcome::view( $job ),
			];
		}

		return self::importResult( $job );
	}

	/**
	 * A restore's result: its safety backup, whether the login was kept, and
	 * for a restore into a network the sites it put in place.
	 *
	 * @param Job $job Import job.
	 * @return array<string, mixed>
	 */
	private static function importResult( Job $job ): array {
		$safety = $job->state['safetyBackup']['name'] ?? null;
		$kept   = $job->state['loginKept'] ?? null;
		$result = [
			'safetyBackup' => is_string( $safety ) && '' !== $safety ? $safety : null,
			// Known only on a finished restore whose PreserveStep ran (null = unknown).
			'loginKept'    => Job::DONE === $job->status && is_bool( $kept ) ? $kept : null,
		];
		$sites  = $job->state['summary']['sites'] ?? null;
		if ( is_array( $sites ) ) {
			// A restore into a network (DoneStep): each restored site's address and whether it was created.
			$result['sites'] = array_values(
				array_map(
					static fn( array $site ): array => [
						'url'     => (string) ( $site['url'] ?? '' ),
						'created' => true === ( $site['created'] ?? null ),
					],
					array_filter( $sites, 'is_array' )
				)
			);
		}

		return $result;
	}
}
