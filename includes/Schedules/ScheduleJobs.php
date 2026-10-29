<?php
/**
 * Starts the backup job of a schedule.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Export\ExportJobs;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * A scheduled run is an ordinary export (spec 17: "az export összes opciója")
 * through ExportJobs, so the lock, the cleanup and every driver work as for a
 * manual backup. The job keeps a snapshot of the schedule (state.schedule:
 * RunRecorder::snapshot() plus a fingerprint of the sealed password), which
 * names, prunes and reports the run even when the schedule changes while it
 * runs. Nothing is created when the stored options or the password cannot be
 * used: the caller logs the reason. A network schedule with chosen sites
 * backs up those that still exist (a warning names each one gone) and starts
 * nothing when none is left; a choice is never widened to the whole network.
 */
final class ScheduleJobs {

	/**
	 * @param JobStore       $jobs      Job store.
	 * @param SiteLock       $lock      Site lock.
	 * @param SecretStore    $secrets   Where the job's password is kept while it runs.
	 * @param \Closure       $site      fn(): Site — prepares the storage folder, checks the backups folder
	 *                                  (FolderRefusedException) and reads the site.
	 * @param SealedPassword $passwords Opens the schedule's password.
	 */
	public function __construct( private JobStore $jobs, private SiteLock $lock, private SecretStore $secrets, private \Closure $site, private SealedPassword $passwords ) {
	}

	public static function fromServices( Services $services, SecretStore $secrets ): self {
		return new self(
			$services->jobs(),
			$services->lock(),
			$secrets,
			static function () use ( $services ): Site {
				$services->storage()->ensure();
				$services->storage()->ensureBackups();

				return SiteReader::read();
			},
			new SealedPassword()
		);
	}

	/**
	 * Also passes on ExportJobs::create()'s SiteBusyException (another job
	 * holds the site) and ExportException (a multisite network).
	 *
	 * @param Schedule $schedule Schedule.
	 * @param string   $trigger  RunRecorder::CRON or RunRecorder::MANUAL.
	 * @param int      $now      Unix time.
	 * @return Job The new job, holding the site lock.
	 * @throws ScheduleException When the stored options or the password cannot be used, or the backups folder cannot take a backup.
	 * @throws \InvalidArgumentException For a trigger other than CRON or MANUAL (a caller bug, never shown).
	 */
	public function start( Schedule $schedule, string $trigger, int $now ): Job {
		// RunRecorder mails only for a trigger that is exactly CRON; anything else would read as a silent manual run.
		if ( ! in_array( $trigger, [ RunRecorder::CRON, RunRecorder::MANUAL ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown run trigger.' );
		}
		try {
			$options = ExportOptions::fromArray( $schedule->export );
		} catch ( \InvalidArgumentException $e ) {
			throw new ScheduleException( $e->getMessage() );
		}
		$password = null;
		if ( $options->encrypt ) {
			$password = $this->passwords->open( $schedule->password );
			if ( null === $password || '' === $password ) {
				throw new ScheduleException( __( 'The password of this schedule can no longer be read (the site\'s security keys may have changed). Edit the schedule and enter the password again.', 'lw-migration' ) );
			}
		}
		$gone             = self::keepExistingSites( $options );
		$snapshot         = RunRecorder::snapshot( $schedule, $trigger );
		$snapshot['seal'] = self::seal( $schedule );
		$job              = ExportJobs::create( $this->jobs, $this->lock, $this->site(), $options, $password, $this->secrets, $now, [ 'schedule' => $snapshot ], self::plan( $schedule ) );
		if ( [] !== $gone ) {
			foreach ( $gone as $id ) {
				/* translators: %d: site ID */
				$job->addWarning( sprintf( __( 'Site %d of this schedule no longer exists. The backup leaves it out.', 'lw-migration' ), $id ) );
			}
			$this->jobs->save( $job );
		}

		return $job;
	}

	/**
	 * The site, once the backups folder is known to take the backup: a run
	 * never works for hours on an archive with nowhere to go.
	 *
	 * @throws ScheduleException With the folder's text (its reason as the previous exception).
	 */
	private function site(): Site {
		try {
			return ( $this->site )();
		} catch ( FolderRefusedException $e ) {
			throw new ScheduleException( FolderMessages::of( $e->reason ), 0, $e );
		}
	}

	/**
	 * Narrows the chosen sites of a network schedule to those that exist.
	 *
	 * @param ExportOptions $options Options of the run (changed in place).
	 * @return array<int, int> The chosen ids that are gone.
	 * @throws ScheduleException When none of the chosen sites exists any more.
	 */
	private static function keepExistingSites( ExportOptions $options ): array {
		if ( [] === $options->sites || ! is_multisite() ) {
			return []; // On a single site ExportJobs refuses a choice itself.
		}
		$existing = ( new NetworkReader() )->existing( $options->sites );
		if ( [] === $existing ) {
			throw new ScheduleException( __( 'None of the sites of this schedule exist any more.', 'lw-migration' ) );
		}
		$gone           = array_values( array_diff( $options->sites, $existing ) );
		$options->sites = $existing;

		return $gone;
	}

	/**
	 * The cloud plan of a schedule's run, or null without destinations. A
	 * profile deleted since the schedule was saved is skipped by the upload
	 * step with a warning (spec 21.3), never refused here.
	 *
	 * @param Schedule $schedule Schedule.
	 */
	public static function plan( Schedule $schedule ): ?CloudPlan {
		if ( [] === $schedule->destinations ) {
			return null;
		}

		return new CloudPlan( $schedule->destinations, $schedule->keepLocal, true );
	}

	/**
	 * Which sealed password a run started with (a new seal means a new password).
	 *
	 * @param Schedule $schedule Schedule.
	 */
	public static function seal( Schedule $schedule ): string {
		return '' === $schedule->password ? '' : substr( hash( 'sha256', $schedule->password ), 0, 16 );
	}
}
