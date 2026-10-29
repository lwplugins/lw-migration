<?php
/**
 * Ctrl-C (and termination signals) while a command drives a job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * With pcntl, the first SIGINT, SIGTERM or SIGHUP only sets a flag: the
 * running tick finishes and saves, then the driver stops and the job is
 * cancelled (its site lock released). The previous handlers are put back at
 * once, so a second signal acts as usual (Ctrl-C twice kills). Without pcntl,
 * a signal kills the process mid-tick; the job stays unfinished and its site
 * lock expires after SiteLock::TTL seconds (the daily sweep removes the job
 * folder later).
 */
final class Interrupt {

	/**
	 * Whether a signal arrived.
	 *
	 * @var bool
	 */
	private bool $requested = false;

	/**
	 * Handlers before trap(), by signal number; empty while not trapped.
	 *
	 * @var array<int, mixed>
	 */
	private array $previous = [];

	/**
	 * Async signal setting before trap().
	 *
	 * @var bool
	 */
	private bool $async = false;

	public function trap(): void {
		if ( [] !== $this->previous || ! function_exists( 'pcntl_signal' ) || ! function_exists( 'pcntl_signal_get_handler' ) || ! function_exists( 'pcntl_async_signals' ) ) {
			return;
		}
		$this->async = pcntl_async_signals( true );
		$handler     = function (): void {
			$this->requested = true;
			$this->putBack();
		};
		foreach ( [ SIGINT, SIGTERM, SIGHUP ] as $signal ) {
			$this->previous[ $signal ] = pcntl_signal_get_handler( $signal );
			pcntl_signal( $signal, $handler );
		}
	}

	public function restore(): void {
		if ( [] === $this->previous ) {
			return;
		}
		$this->putBack();
		pcntl_async_signals( $this->async );
		$this->previous = [];
	}

	public function request(): void {
		$this->requested = true;
	}

	public function requested(): bool {
		return $this->requested;
	}

	private function putBack(): void {
		foreach ( $this->previous as $signal => $handler ) {
			pcntl_signal( $signal, is_callable( $handler ) || is_int( $handler ) ? $handler : SIG_DFL );
		}
	}
}
