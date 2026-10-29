<?php
/**
 * Runs a plugin's uninstall routine at most once.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin whose routine starts is saved first (cursor.started), so a
 * routine that kills the request is never run again; cursor.uninstalled marks
 * it done. On the web a PHP process runs at most one routine (core defines
 * WP_UNINSTALL_PLUGIN once per process): the next waits for the next request.
 * On the command line every routine runs in the one process, as core's bulk
 * delete does; a routine that checks the constant's value may then skip its
 * own cleanup.
 */
final class UninstallRoutine {

	/** What once() answers when the next routine must wait for a new request. */
	public const NEXT_REQUEST = 'next-request';

	/** @var \Closure fn(): bool, whether this is the command line */
	private \Closure $cli;

	/** @var \Closure fn(): bool, whether an uninstall routine already ran in this process */
	private \Closure $uninstallRan;

	/**
	 * @param JobStore      $store        Job store.
	 * @param \Closure      $uninstall    fn( string $basename ): void.
	 * @param \Closure|null $cli          fn(): bool; null = WP_CLI.
	 * @param \Closure|null $uninstallRan fn(): bool; null = whether WP_UNINSTALL_PLUGIN is defined in this process.
	 */
	public function __construct( private JobStore $store, private \Closure $uninstall, ?\Closure $cli = null, ?\Closure $uninstallRan = null ) {
		$this->cli          = $cli ?? static fn(): bool => defined( 'WP_CLI' ) && WP_CLI;
		$this->uninstallRan = $uninstallRan ?? static fn(): bool => defined( 'WP_UNINSTALL_PLUGIN' );
	}

	/**
	 * Runs the plugin's uninstall routine at most once: the plugin is saved
	 * as started before its code runs, and a replay finds it there.
	 *
	 * @param Job    $job    Reset job.
	 * @param string $plugin Plugin basename.
	 * @param bool   $ours   Whether it is another copy of LW Migration: its routine never runs.
	 * @return string|null NEXT_REQUEST when a routine already ran in this web request.
	 */
	public function once( Job $job, string $plugin, bool $ours = false ): ?string {
		if ( ( $job->cursor['uninstalled'] ?? null ) === $plugin ) {
			return null;
		}
		if ( $ours ) {
			/* translators: %s: plugin file, e.g. my-plugin/my-plugin.php */
			$job->addWarning( sprintf( __( 'The plugin %s is another copy of LW Migration: its files were deleted without running its uninstall routine, which would remove the data of this reset.', 'lw-migration' ), $plugin ) );
		} elseif ( ( $job->cursor['started'] ?? null ) === $plugin ) {
			/* translators: %s: plugin file, e.g. my-plugin/my-plugin.php */
			$job->addWarning( sprintf( __( 'The uninstall routine of %s stopped the previous request, so it was deleted without running it again.', 'lw-migration' ), $plugin ) );
		} else {
			$cli = (bool) ( $this->cli )();
			if ( ! $cli && ( $this->uninstallRan )() ) {
				return self::NEXT_REQUEST;
			}
			$job->cursor['started'] = $plugin;
			$this->store->save( $job ); // Before the plugin's own code runs: a request it kills must not run it again.
			try {
				$this->runUninstall( $plugin, $cli );
			} catch ( \Throwable $e ) {
				// Its own failure: a retry would fail the same way, so it is deleted without it.
				/* translators: %s: plugin file, e.g. my-plugin/my-plugin.php */
				$job->addWarning( sprintf( __( 'The uninstall routine of %s failed, so it was deleted without it.', 'lw-migration' ), $plugin ) );
			}
		}
		$job->cursor['uninstalled'] = $plugin;
		$this->store->save( $job );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, fired as delete_plugins() fires it.
		do_action( 'delete_plugin', $plugin );

		return null;
	}

	/**
	 * On the command line a second routine's define() of WP_UNINSTALL_PLUGIN
	 * raises a warning; only that one is swallowed, everything else goes on
	 * to the handler before. Should a later PHP turn that define() into an
	 * Error, once() catches it like any failing routine: the plugin is then
	 * deleted without its routine, with a warning, never left half-done.
	 *
	 * @param string $plugin Plugin basename.
	 * @param bool   $cli    Whether this is the command line.
	 */
	private function runUninstall( string $plugin, bool $cli ): void {
		if ( ! $cli ) {
			( $this->uninstall )( $plugin );
			return;
		}
		$previous = null;
		$previous = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- narrow: one known warning of core's uninstall_plugin().
			static function ( int $no, string $message, string $file = '', int $line = 0 ) use ( &$previous ): bool {
				if ( in_array( $no, [ E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE ], true ) && str_contains( $message, 'WP_UNINSTALL_PLUGIN' ) ) {
					return true;
				}

				return is_callable( $previous ) ? (bool) $previous( $no, $message, $file, $line ) : false;
			}
		);
		try {
			( $this->uninstall )( $plugin );
		} finally {
			restore_error_handler();
		}
	}
}
