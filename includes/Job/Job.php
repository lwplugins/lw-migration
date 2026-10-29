<?php
/**
 * Persistent state of one export/import/reset job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data object stored as job.json. Steps read/write `cursor` (reset between
 * steps) and `state` (shared by all steps). Passwords never go in here.
 */
final class Job {

	public const PENDING     = 'pending';
	public const RUNNING     = 'running';
	public const NEEDS_INPUT = 'needs_input';
	public const DONE        = 'done';
	public const FAILED      = 'failed';
	public const CANCELLED   = 'cancelled';

	private const MAX_WARNINGS = 100;

	/**
	 * Secrets for this run (e.g. password). In memory only: never saved.
	 *
	 * @var array<string, mixed>
	 */
	public array $secrets = [];

	/**
	 * Every warning added in this request, beyond the stored cap: the Runner
	 * writes them to job.log. In memory only: never saved.
	 *
	 * @var array<int, string>
	 */
	public array $newWarnings = [];

	/**
	 * @param string               $id           Job id (16 hex chars).
	 * @param string               $type         export | import | reset ...
	 * @param array<int, string>   $steps        Step keys in order.
	 * @param int                  $stepIndex    Current step.
	 * @param array<string, mixed> $cursor       Current step's progress.
	 * @param array<string, mixed> $options      User options.
	 * @param array<string, mixed> $state        Data shared between steps.
	 * @param string               $status       One of the status constants.
	 * @param int                  $progress     0-100 of the current step.
	 * @param string               $message      Human-readable status line.
	 * @param array<int, string>   $warnings     First warnings.
	 * @param int                  $warningCount All warnings.
	 * @param array<string, mixed> $input        Pending input request or null.
	 * @param string               $error        Failure message.
	 * @param int                  $createdAt    Unix time.
	 * @param int                  $updatedAt    Unix time.
	 * @param array<string, mixed> $attempt      Start counter of the current step (Runner guard).
	 */
	public function __construct(
		public string $id,
		public string $type,
		public array $steps,
		public int $stepIndex = 0,
		public array $cursor = [],
		public array $options = [],
		public array $state = [],
		public string $status = self::PENDING,
		public int $progress = 0,
		public string $message = '',
		public array $warnings = [],
		public int $warningCount = 0,
		public ?array $input = null,
		public string $error = '',
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public array $attempt = []
	) {
	}

	public function currentStep(): ?string {
		return $this->steps[ $this->stepIndex ] ?? null;
	}

	public function isFinished(): bool {
		return in_array( $this->status, [ self::DONE, self::FAILED, self::CANCELLED ], true );
	}

	public function addWarning( string $warning ): void {
		++$this->warningCount;
		$this->newWarnings[] = $warning;
		if ( count( $this->warnings ) < self::MAX_WARNINGS ) {
			$this->warnings[] = $warning;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = get_object_vars( $this );
		unset( $data['secrets'], $data['newWarnings'] );

		return $data;
	}

	/**
	 * @param array<string, mixed> $a Saved job.
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		return new self(
			(string) ( $a['id'] ?? '' ),
			(string) ( $a['type'] ?? '' ),
			array_values( array_map( 'strval', (array) ( $a['steps'] ?? [] ) ) ),
			(int) ( $a['stepIndex'] ?? 0 ),
			(array) ( $a['cursor'] ?? [] ),
			(array) ( $a['options'] ?? [] ),
			(array) ( $a['state'] ?? [] ),
			(string) ( $a['status'] ?? self::PENDING ),
			(int) ( $a['progress'] ?? 0 ),
			(string) ( $a['message'] ?? '' ),
			array_values( array_map( 'strval', (array) ( $a['warnings'] ?? [] ) ) ),
			(int) ( $a['warningCount'] ?? 0 ),
			isset( $a['input'] ) && is_array( $a['input'] ) ? $a['input'] : null,
			(string) ( $a['error'] ?? '' ),
			(int) ( $a['createdAt'] ?? 0 ),
			(int) ( $a['updatedAt'] ?? 0 ),
			isset( $a['attempt'] ) && is_array( $a['attempt'] ) ? $a['attempt'] : []
		);
	}
}
