<?php
/**
 * Tells the schedule's address that a destination needs to be connected again.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Admin\AdminPage;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Schedules\Mail;
use LightweightPlugins\Migration\Schedules\Recipients;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5 / M7c plan E8: one plain-text mail when an upload of a schedule
 * run started by cron, whose notify setting is not off, finds a destination
 * that needs to be connected again. Whether it is the first notice for that
 * connection is decided on the profile row (ProfileRepository::revoked());
 * this class only says whether a run mails at all (due()) and writes the
 * mail. To the schedule's addresses (Recipients::resolve: its own list, the
 * default, the admin's), never throttled by the failure-mail rule (another
 * event), never retried. Only our own fixed text, the profile name, the
 * schedule title and the site name, each on one line: never the provider's
 * words, the connected account, a token or an address of the upload. A send
 * that fails is a warning of the run.
 */
final class ReauthNotice {

	/**
	 * Sender: fn( array $to, string $subject, string $body ): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $send;

	/**
	 * The destinations page: fn(): string.
	 *
	 * @var \Closure
	 */
	private \Closure $pageUrl;

	/**
	 * @param \Closure|null $send    Sender (default wp_mail, plain text).
	 * @param \Closure|null $pageUrl Page address (default the admin page's Cloud destinations tab).
	 */
	public function __construct( ?\Closure $send = null, ?\Closure $pageUrl = null ) {
		$this->send    = $send ?? static fn( array $to, string $subject, string $body ): bool => (bool) wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		$this->pageUrl = $pageUrl ?? static fn(): string => AdminPage::pageUrl() . '&tab=destinations';
	}

	/**
	 * Whether this run mails a reconnect notice: a cron run of a schedule
	 * whose notify setting is not off (a person who started a run sees it).
	 *
	 * @param Job $job The run.
	 */
	public function due( Job $job ): bool {
		$schedule = $job->state['schedule'] ?? null;
		$notify   = is_array( $schedule ) && is_array( $schedule['notify'] ?? null ) ? $schedule['notify'] : null;

		return null !== $notify && RunRecorder::CRON === ( $schedule['trigger'] ?? '' ) && Schedule::NOTIFY_OFF !== ( $notify['when'] ?? Schedule::NOTIFY_OFF );
	}

	/**
	 * @param Job     $job     The run (due()).
	 * @param Profile $profile The destination.
	 * @return bool Whether wp_mail accepted the mail.
	 */
	public function notify( Job $job, Profile $profile ): bool {
		$schedule = (array) ( $job->state['schedule'] ?? [] );
		$name     = Mail::line( $profile->name );
		$to       = Recipients::resolve( (string) ( ( (array) ( $schedule['notify'] ?? [] ) )['email'] ?? '' ) );
		/* translators: 1: site name, 2: destination name */
		$subject = Mail::line( sprintf( __( '[%1$s] Connect the backup destination "%2$s" again', 'lw-migration' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), $name ) );
		$body    = implode(
			"\n\n",
			[
				/* translators: %s: destination name */
				sprintf( __( 'LW Migration can no longer upload to the backup destination "%s": its access was withdrawn or has expired, or its stored credentials can no longer be read.', 'lw-migration' ), $name ),
				/* translators: %s: schedule name */
				sprintf( __( 'Schedule: %s', 'lw-migration' ), Mail::line( (string) ( $schedule['title'] ?? '' ) ) ),
				/* translators: %s: address of the plugin's page */
				sprintf( __( 'Open %s and connect this destination again (or enter its credentials again).', 'lw-migration' ), Mail::line( ( $this->pageUrl )() ) ),
				__( 'Until then the backups stay on this site and still go to the other destinations of the schedule.', 'lw-migration' ),
			]
		) . "\n";
		try {
			if ( [] !== $to && ( $this->send )( $to, $subject, $body ) ) {
				return true;
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Its text may name a server; the warning below says enough.
		}
		/* translators: %s: destination name */
		$job->addWarning( sprintf( __( 'The mail asking to connect %s again could not be sent.', 'lw-migration' ), $name ) );

		return false;
	}
}
