<?php
/**
 * Outcome of one step run.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Done, more work left (deadline reached), or waiting for user input. A step
 * that may only go on in a new request (nextRequest()) ends the tick even
 * before the deadline.
 */
final class StepResult {

	/**
	 * @param bool                 $done     Step finished.
	 * @param int|null             $progress 0-100 when more work is left.
	 * @param string               $message  Status line.
	 * @param string|null          $kind     Input kind when input is needed.
	 * @param array<string, mixed> $data     Input request details.
	 * @param bool                 $endsTick Whether the runner stops this tick after it.
	 */
	private function __construct(
		private bool $done,
		private ?int $progress,
		private string $message,
		private ?string $kind = null,
		private array $data = [],
		private bool $endsTick = false
	) {
	}

	public static function done( string $message = '' ): self {
		return new self( true, null, $message );
	}

	public static function more( int $progress, string $message = '' ): self {
		return new self( false, max( 0, min( 100, $progress ) ), $message );
	}

	/**
	 * More work that only a new request (a new PHP process on the web) may do.
	 *
	 * @param int    $progress 0-100.
	 * @param string $message  Status line.
	 */
	public static function nextRequest( int $progress, string $message = '' ): self {
		return new self( false, max( 0, min( 100, $progress ) ), $message, null, [], true );
	}

	/**
	 * @param string               $kind Input kind (password, confirm ...).
	 * @param array<string, mixed> $data Details for the UI.
	 * @return self
	 */
	public static function needsInput( string $kind, array $data = [] ): self {
		return new self( false, null, '', $kind, $data );
	}

	public function isDone(): bool {
		return $this->done;
	}

	public function endsTick(): bool {
		return $this->endsTick;
	}

	public function needsInputKind(): ?string {
		return $this->kind;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function inputData(): array {
		return $this->data;
	}

	public function progress(): ?int {
		return $this->progress;
	}

	public function message(): string {
		return $this->message;
	}
}
