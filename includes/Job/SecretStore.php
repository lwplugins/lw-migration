<?php
/**
 * Short-lived storage for job secrets (passwords).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets never go into job.json; implementations keep them out of the job
 * directory.
 */
interface SecretStore {

	/**
	 * @param string               $jobId  Job id.
	 * @param array<string, mixed> $values Secret values.
	 * @return void
	 */
	public function put( string $jobId, array $values ): void;

	/**
	 * @param string $jobId Job id.
	 * @return array<string, mixed>
	 */
	public function get( string $jobId ): array;

	public function delete( string $jobId ): void;
}
