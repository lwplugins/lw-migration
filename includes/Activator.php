<?php
/**
 * Activation and deactivation.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

use LightweightPlugins\Migration\Maintenance\Cleanup;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Storage\StorageException;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares the protected storage folder; a failure never blocks activation
 * (every job ensures the folder again and reports a clear error). Schedules the
 * daily cleanup on activation and removes it on deactivation. Re-plans the
 * events of enabled schedules on activation (it never creates the default
 * schedule) and removes every schedule event and waiting run on deactivation.
 * On a network all of that happens on the main site (network activation runs
 * there); a per-site activation on another site prepares nothing, and its
 * deactivation only clears a stray cleanup event from that site's cron.
 */
final class Activator {

	public static function activate(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		try {
			StorageDir::fromWordPress()->ensure();
		} catch ( StorageException $e ) {
			unset( $e );
		}
		Cleanup::schedule();
		Events::healAll();
	}

	public static function deactivate(): void {
		Cleanup::unschedule();
		if ( ! MainSite::isContext() ) {
			return;
		}
		Events::unscheduleAll();
		delete_option( Options::SCHEDULE_QUEUE ); // A run waiting at deactivation does not start on reactivation.
	}
}
