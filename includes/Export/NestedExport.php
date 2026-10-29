<?php
/**
 * Runs the export steps as a nested job inside another job's step.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Import\Steps\SafetyBackupStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\Steps\BackupStep;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The backup taken before an import (cursor.safety) or a reset
 * (cursor.backup): the export steps, unchanged, as a nested job in a work
 * sub-folder of the outer job. The nested job (index, cursor, state) lives in
 * the outer job's cursor under $cursorKey, so the store step's hidden temp is
 * at cursor.<key>.cursor.temp (the cleanups discard it).
 *
 * Each advance() runs exactly one nested step and returns "more" until the
 * last one is done, so the Runner saves the cursor and counts the attempt
 * before every nested call, as it would for a plain export: progress shows in
 * the cursor, a nested step that never moves is stopped by the attempt guard,
 * and the cursor changes only after a nested step returned normally. The
 * nested job sees the outer job's attempt count. A nested failure keeps its
 * warnings and reaches the Runner as the caller's exception.
 */
final class NestedExport {

	/**
	 * @param array<string, StepInterface> $exportSteps Export step implementations by key.
	 * @param array<int, string>           $keys        The nested steps, in order.
	 * @param array<string, mixed>         $options     Export options of the nested job.
	 * @param string                       $workSub     Work sub-folder of the nested job.
	 * @param \Closure                     $failure     fn( string $message, ?\Throwable $previous ): \RuntimeException.
	 * @param \Closure                     $warning     fn( string $nested ): string — a nested warning, prefixed.
	 * @param \Closure                     $progress    fn( string $nestedText ): string — the status line; '' = the idle text.
	 * @param \Closure                     $refused     fn( ?string $missingStep ): \RuntimeException — a missing step (its key) or an
	 *                                                  input request (null), in the caller's own whole sentences.
	 */
	public function __construct(
		private array $exportSteps,
		private array $keys,
		private array $options,
		private string $workSub,
		private \Closure $failure,
		private \Closure $warning,
		private \Closure $progress,
		private \Closure $refused
	) {
	}

	/**
	 * Runs the current nested step once.
	 *
	 * @param Job      $job       Outer job.
	 * @param string   $cursorKey Where the nested job lives in the outer cursor.
	 * @param Deadline $deadline  Deadline.
	 * @return StepResult|null "More" while nested steps are left; null once all are done.
	 * @throws \RuntimeException What $failure or $refused makes of a nested failure; the saved cursor is left as it was.
	 */
	public function advance( Job $job, string $cursorKey, Deadline $deadline ): ?StepResult {
		$count = count( $this->keys );
		$sub   = $this->nested( $job, $cursorKey );
		if ( $sub['index'] >= $count ) {
			return null;
		}
		[ $sub, $result ]          = $this->runOne( $job, $sub, $deadline );
		$job->cursor[ $cursorKey ] = $sub;

		return $sub['index'] < $count ? $this->more( $sub['index'], $count, $result ) : null;
	}

	/**
	 * The backup the nested store step recorded (state.backup), or null.
	 *
	 * @param Job    $job       Outer job.
	 * @param string $cursorKey Where the nested job lives.
	 * @return array<string, mixed>|null
	 */
	public static function backup( Job $job, string $cursorKey ): ?array {
		$backup = $job->cursor[ $cursorKey ]['state']['backup'] ?? null;

		return is_array( $backup ) && '' !== (string) ( $backup['name'] ?? '' ) ? $backup : null;
	}

	/**
	 * The hidden temporary name of the nested store step (cursor.<key>.cursor.temp), or null.
	 *
	 * @param array<string, mixed> $cursor    Outer job cursor.
	 * @param string               $cursorKey Where the nested job lives.
	 */
	public static function storeTemp( array $cursor, string $cursorKey ): ?string {
		$temp = $cursor[ $cursorKey ]['cursor']['temp'] ?? null;

		return is_string( $temp ) && '' !== $temp ? $temp : null;
	}

	/**
	 * The name the nested store step saved as reserving (an empty file it may have left), or ''.
	 *
	 * @param array<string, mixed> $cursor    Outer job cursor.
	 * @param string               $cursorKey Where the nested job lives.
	 */
	public static function storeReserving( array $cursor, string $cursorKey ): string {
		$name = $cursor[ $cursorKey ]['cursor']['reserving'] ?? null;

		return is_string( $name ) ? $name : '';
	}

	/**
	 * The store names of every nested backup a job cursor may hold: the
	 * import's safety backup and the reset's backup.
	 *
	 * @param array<string, mixed> $cursor Job cursor.
	 * @return array{temps: array<int, string>, reserving: array<int, string>}
	 */
	public static function namedTemps( array $cursor ): array {
		$named = [
			'temps'     => [],
			'reserving' => [],
		];
		foreach ( [ SafetyBackupStep::CURSOR, BackupStep::CURSOR ] as $key ) {
			$temp = self::storeTemp( $cursor, $key );
			if ( null !== $temp ) {
				$named['temps'][] = $temp;
			}
			$reserving = self::storeReserving( $cursor, $key );
			if ( '' !== $reserving ) {
				$named['reserving'][] = $reserving;
			}
		}

		return $named;
	}

	/**
	 * The nested step's own progress and text within this step's share, so
	 * a long nested step (one huge table) keeps showing movement: a
	 * connected site gives up on a job that shows the same for too long
	 * (RemoteJobStep).
	 *
	 * @param int        $index  Nested steps done.
	 * @param int        $count  Nested steps.
	 * @param StepResult $nested What the nested step returned.
	 */
	private function more( int $index, int $count, StepResult $nested ): StepResult {
		$within = $nested->isDone() ? 0 : min( 100, max( 0, (int) $nested->progress() ) );
		$text   = $nested->isDone() ? '' : $nested->message();

		return StepResult::more( intdiv( 100 * $index + $within, $count ), (string) ( $this->progress )( $text ) );
	}

	/**
	 * The saved nested job, or a fresh one in the work sub-folder.
	 *
	 * @param Job    $job       Outer job.
	 * @param string $cursorKey Where the nested job lives.
	 * @return array{index: int, cursor: array<string, mixed>, state: array<string, mixed>}
	 */
	private function nested( Job $job, string $cursorKey ): array {
		$saved = (array) ( $job->cursor[ $cursorKey ] ?? [] );

		return [
			'index'  => (int) ( $saved['index'] ?? 0 ),
			'cursor' => (array) ( $saved['cursor'] ?? [] ),
			'state'  => isset( $saved['state'] ) ? (array) $saved['state'] : [
				'workSub' => $this->workSub,
				'site'    => $job->state['site'] ?? [],
			],
		];
	}

	/**
	 * Runs the current nested step once; a throw leaves $sub (and so the
	 * saved cursor) as it was.
	 *
	 * @param Job                                                                          $job      Outer job.
	 * @param array{index: int, cursor: array<string, mixed>, state: array<string, mixed>} $sub      Nested job.
	 * @param Deadline                                                                     $deadline Deadline.
	 * @return array{0: array{index: int, cursor: array<string, mixed>, state: array<string, mixed>}, 1: StepResult} The nested job after the call, and what the call returned.
	 * @throws \RuntimeException When a nested step is missing, fails or asks for input.
	 */
	private function runOne( Job $job, array $sub, Deadline $deadline ): array {
		$key = $this->keys[ $sub['index'] ];
		if ( ! isset( $this->exportSteps[ $key ] ) ) {
			throw $this->refusal( $key );
		}
		$nested = new Job( $job->id, 'export', $this->keys, $sub['index'], $sub['cursor'], $this->options, $sub['state'], Job::RUNNING );

		$nested->attempt = $job->attempt;
		try {
			$result = $this->exportSteps[ $key ]->run( $nested, $deadline );
		} catch ( \Throwable $e ) {
			$this->keepWarnings( $job, $nested );
			$failure = ( $this->failure )( $e->getMessage(), $e );
			throw $failure;
		}
		$this->keepWarnings( $job, $nested );
		if ( null !== $result->needsInputKind() ) {
			throw $this->refusal( null );
		}
		$done = $result->isDone();

		return [
			[
				'index'  => $done ? $sub['index'] + 1 : $sub['index'],
				'cursor' => $done ? [] : $nested->cursor,
				'state'  => $nested->state,
			],
			$result,
		];
	}

	/**
	 * @param string|null $missingStep The key of a missing step, or null for an input request.
	 */
	private function refusal( ?string $missingStep ): \RuntimeException {
		return ( $this->refused )( $missingStep );
	}

	/**
	 * Copies the nested job's new warnings, prefixed, onto the outer job.
	 *
	 * @param Job $job    Outer job.
	 * @param Job $nested Nested export job.
	 */
	private function keepWarnings( Job $job, Job $nested ): void {
		foreach ( $nested->newWarnings as $warning ) {
			$job->addWarning( (string) ( $this->warning )( $warning ) );
		}
	}
}
