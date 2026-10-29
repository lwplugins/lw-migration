<?php
/**
 * In-memory secret store (tests, CLI).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Array-backed; lives as long as the process.
 */
final class MemorySecretStore implements SecretStore {

	/**
	 * Secrets by job id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $values = [];

	/**
	 * @param string               $jobId  Job id.
	 * @param array<string, mixed> $values Secret values.
	 * @return void
	 */
	public function put( string $jobId, array $values ): void {
		$this->values[ $jobId ] = $values;
	}

	/**
	 * @param string $jobId Job id.
	 * @return array<string, mixed>
	 */
	public function get( string $jobId ): array {
		return $this->values[ $jobId ] ?? [];
	}

	public function delete( string $jobId ): void {
		unset( $this->values[ $jobId ] );
	}
}
