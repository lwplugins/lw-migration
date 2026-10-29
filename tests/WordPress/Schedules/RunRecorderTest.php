<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Notifier;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class RunRecorderTest extends MonkeyTestCase {

	private const NOW = 1767225600;

	/** @var array<int, array{to: array<int, string>, subject: string, body: string}> */
	private array $mails = [];

	private bool $exists = true;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install( [ 'admin_email' => 'admin@site.test' ] );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'size_format' )->justReturn( '5 MB' );
	}

	/** @var array<int, string> Lines noted in the job's log. */
	private array $notes = [];

	/**
	 * Runs inside the sender before it answers (null: answer true).
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $whileSending = null;

	private function recorder(): RunRecorder {
		$notifier = new Notifier(
			function ( array $to, string $subject, string $body ): bool {
				$this->mails[] = compact( 'to', 'subject', 'body' );
				return null === $this->whileSending ? true : ( $this->whileSending )();
			}
		);

		return new RunRecorder(
			$notifier,
			[ '/srv/www' ],
			fn( string $id ): bool => $this->exists,
			'lw-migration-0123456789ab',
			function ( Job $job, string $line ): void {
				$this->notes[] = $job->id . ' ' . $line;
			}
		);
	}

	/**
	 * @param string $status  Job status.
	 * @param string $trigger cron | manual.
	 * @param string $when    Notify setting.
	 */
	private static function job( string $status, string $trigger = 'cron', string $when = 'always' ): Job {
		$job                    = new Job( 'aaaaaaaaaaaaaaaa', 'export', [], 0, [], [], [], $status );
		$job->createdAt         = self::NOW - 100;
		$job->state['schedule'] = RunRecorder::snapshot(
			Schedule::fromArray(
				[
					'id'     => '0a1b2c3d',
					'title'  => 'Nightly',
					'notify' => [
						'when'  => $when,
						'email' => 'ops@site.test',
					],
				]
			),
			$trigger
		);
		$job->state['backup']   = [
			'name' => 'site-test-20260101-000000-001-0a1b2c3d.wpress',
			'path' => '/srv/www/wp-content/lw-migration-0123456789ab/backups/x.wpress',
			'size' => 5242880,
		];

		return $job;
	}

	public function test_a_finished_scheduled_job_is_logged_labelled_and_mailed(): void {
		$this->recorder()->finished( self::job( Job::DONE ), self::NOW );

		$this->assertSame(
			[
				'job'       => 'aaaaaaaaaaaaaaaa',
				'trigger'   => 'cron',
				'status'    => 'done',
				'startedAt' => self::NOW - 100,
				'endedAt'   => self::NOW,
				'backup'    => 'site-test-20260101-000000-001-0a1b2c3d.wpress',
				'size'      => 5242880,
				'error'     => '',
				'warnings'  => 0,
				'mail'      => 'sent',
			],
			Log::last( '0a1b2c3d' )
		);
		$this->assertSame( 'Nightly', FakeOptions::$values[ Options::LABELS ]['site-test-20260101-000000-001-0a1b2c3d.wpress'] );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( [ 'ops@site.test' ], $this->mails[0]['to'] );
	}

	public function test_the_snapshot_carries_the_default_title_in_the_site_language(): void {
		Functions\when( '__' )->alias( static fn( string $text ): string => 'Daily backup' === $text ? 'Napi mentés' : $text );
		$snapshot = RunRecorder::snapshot( Repository::defaultSchedule( self::NOW ), RunRecorder::CRON );

		$this->assertSame( 'Napi mentés', $snapshot['title'], 'The mail subject and the backup label use the shown name.' );
	}

	public function test_the_mail_names_no_server_path(): void {
		$job        = self::job( Job::FAILED );
		$job->error = 'Cannot write /srv/www/wp-content/lw-migration-0123456789ab/jobs/x: disk full';

		$this->recorder()->finished( $job, self::NOW );

		$entry = (array) Log::last( '0a1b2c3d' );
		$this->assertSame( [ 'failed', '', 0 ], [ $entry['status'], $entry['backup'], $entry['size'] ] );
		$this->assertStringNotContainsString( '/srv/www', (string) $entry['error'] );
		$this->assertStringContainsString( 'disk full', (string) $entry['error'] );
		$this->assertStringNotContainsString( '/srv/www', $this->mails[0]['subject'] . $this->mails[0]['body'] );
		$this->assertArrayNotHasKey( Options::LABELS, FakeOptions::$values, 'A failed run labels nothing.' );
	}

	public function test_manual_and_cancelled_runs_are_logged_without_mail(): void {
		$this->recorder()->finished( self::job( Job::DONE, 'manual' ), self::NOW );
		$this->recorder()->finished( self::job( Job::CANCELLED ), self::NOW );

		$this->assertSame( [ 'cancelled', 'done' ], array_column( Log::all( '0a1b2c3d' ), 'status' ) );
		$this->assertSame( [ '', '' ], array_column( Log::all( '0a1b2c3d' ), 'mail' ) );
		$this->assertSame( [], $this->mails );
	}

	public function test_failure_mails_are_throttled_while_it_keeps_failing(): void {
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW );
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW + 3600 );

		$this->assertSame( [ 'throttled', 'sent' ], array_column( Log::all( '0a1b2c3d' ), 'mail' ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_nothing_is_recorded_for_another_job_or_a_deleted_schedule(): void {
		$this->recorder()->finished( new Job( 'bbbbbbbbbbbbbbbb', 'export', [], 0, [], [], [], Job::DONE ), self::NOW );
		$this->exists = false;
		$this->recorder()->finished( self::job( Job::DONE ), self::NOW );

		$this->assertSame( [], Log::all( '0a1b2c3d' ) );
		$this->assertSame( [], $this->mails );
	}

	public function test_a_run_that_did_not_start_is_logged_with_its_reason(): void {
		$schedule = Schedule::fromArray(
			[
				'id'     => '0a1b2c3d',
				'title'  => 'Nightly',
				'notify' => [ 'when' => 'failure' ],
			]
		);
		$this->recorder()->notStarted( $schedule, RunRecorder::CRON, RunRecorder::SKIPPED, 'The site was busy.', self::NOW );

		$entry = (array) Log::last( '0a1b2c3d' );
		$this->assertSame( [ '', 'skipped', 'The site was busy.', 'sent' ], [ $entry['job'], $entry['status'], $entry['error'], $entry['mail'] ] );
		$this->assertSame( [ 'admin@site.test' ], $this->mails[0]['to'], 'No address on the schedule: the site admin.' );
	}

	public function test_the_storage_folder_name_is_hidden_also_where_no_path_names_it(): void {
		$job        = self::job( Job::FAILED );
		$job->error = 'Cannot write wp-content/lw-migration-0123456789ab/jobs/x or lw-migration-0123456789ab: disk full';

		$this->recorder()->finished( $job, self::NOW );

		$entry = (array) Log::last( '0a1b2c3d' );
		$this->assertSame( 'Cannot write wp-content/[storage]/jobs/x or [storage]: disk full', $entry['error'] );
		$this->assertStringNotContainsString( 'lw-migration-0123456789ab', $this->mails[0]['subject'] . $this->mails[0]['body'] );
	}

	public function test_the_random_segment_of_a_backups_folder_is_hidden_in_the_log_and_the_mail(): void {
		$job        = self::job( Job::FAILED );
		$job->error = 'Cannot write /home/u/www/lw-migration-backups-0123456789ab/x.wpress';

		$this->recorder()->finished( $job, self::NOW );

		$this->assertSame( 'Cannot write /home/u/www/lw-migration-backups-…/x.wpress', ( (array) Log::last( '0a1b2c3d' ) )['error'] );
		$this->assertStringNotContainsString( '0123456789ab', $this->mails[0]['subject'] . $this->mails[0]['body'] );
	}

	public function test_a_url_token_in_an_error_is_reduced(): void {
		$job        = self::job( Job::FAILED );
		$job->error = 'Download of https://files.test/a/b/c.wpress?token=s3cr3t failed';

		$this->recorder()->finished( $job, self::NOW );

		$entry = (array) Log::last( '0a1b2c3d' );
		$this->assertStringNotContainsString( 's3cr3t', (string) $entry['error'] );
		$this->assertStringNotContainsString( 's3cr3t', $this->mails[0]['body'] );
	}

	public function test_the_storage_folder_name_is_hidden_in_any_case(): void {
		$job        = self::job( Job::FAILED );
		$job->error = 'Cannot open LW-MIGRATION-0123456789AB/jobs/x';

		$this->recorder()->finished( $job, self::NOW );

		$this->assertSame( 'Cannot open [storage]/jobs/x', ( (array) Log::last( '0a1b2c3d' ) )['error'] );
	}

	public function test_the_entry_and_the_throttle_time_are_written_before_the_mail_goes(): void {
		$seen               = [];
		$this->whileSending = static function () use ( &$seen ): bool {
			$seen = [ ( (array) Log::last( '0a1b2c3d' ) )['mail'], Log::failureMailAt( '0a1b2c3d' ) ];
			return true;
		};

		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW );

		$this->assertSame( [ 'pending', self::NOW ], $seen );
		$this->assertSame( 'sent', ( (array) Log::last( '0a1b2c3d' ) )['mail'] );
	}

	public function test_a_lost_update_after_the_send_still_throttles_the_next_failure(): void {
		$this->whileSending = static function (): bool {
			Functions\when( 'update_option' )->justReturn( false ); // Every later write is lost.
			return true;
		};
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW );
		FakeOptions::install( FakeOptions::$values );
		$this->whileSending = null;

		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW + 3600 );

		$this->assertSame( [ 'throttled', 'pending' ], array_column( Log::all( '0a1b2c3d' ), 'mail' ) );
		$this->assertCount( 1, $this->mails, 'Only the send whose result was lost; the next failure is throttled.' );
	}

	public function test_a_failed_send_does_not_throttle_the_next_failure(): void {
		$this->whileSending = static fn(): bool => false;
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW );
		$this->whileSending = null;
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW + 60 );

		$this->assertSame( [ 'sent', 'failed' ], array_column( Log::all( '0a1b2c3d' ), 'mail' ) );
	}

	public function test_thirty_manual_runs_in_a_day_do_not_lift_the_throttle(): void {
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW );
		for ( $i = 1; $i <= Log::MAX; $i++ ) {
			$this->recorder()->finished( self::job( Job::FAILED, 'manual', 'failure' ), self::NOW + $i );
		}
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW + 3600 );

		$this->assertSame( 'throttled', ( (array) Log::last( '0a1b2c3d' ) )['mail'] );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_success_clears_the_throttle(): void {
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW );
		$this->recorder()->finished( self::job( Job::DONE, 'manual', 'failure' ), self::NOW + 60 );

		$this->assertSame( 0, Log::failureMailAt( '0a1b2c3d' ) );
		$this->recorder()->finished( self::job( Job::FAILED, 'cron', 'failure' ), self::NOW + 120 );
		$this->assertCount( 2, $this->mails );
	}

	public function test_a_sender_that_throws_is_noted_in_the_job_log_without_paths(): void {
		$this->whileSending = static function (): bool {
			throw new \RuntimeException( "SMTP refused at /srv/www/wp-content/lw-migration-0123456789ab/x\nnext" );
		};

		$this->recorder()->finished( self::job( Job::DONE ), self::NOW );

		$this->assertSame( 'failed', ( (array) Log::last( '0a1b2c3d' ) )['mail'] );
		$this->assertCount( 1, $this->notes );
		$this->assertStringStartsWith( 'aaaaaaaaaaaaaaaa ', $this->notes[0] );
		$this->assertStringContainsString( 'SMTP refused at wp-content/[storage]/x', $this->notes[0] );
		$this->assertStringNotContainsString( '/srv/www', $this->notes[0] );
	}

	/**
	 * Review Focus 4 / spec 21.1 "részben sikeres".
	 */
	public function test_a_partial_run_is_logged_as_partial_with_each_destination(): void {
		$job                 = self::job( Job::DONE, 'cron', 'failure' );
		$job->state['cloud'] = CloudPlan::manual( [ 'aaaa1111', 'bbbb2222' ], true )->toArray();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'Offsite', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::FAILED, 'Second', 's3', 'Access denied.' );

		$this->recorder()->finished( $job, self::NOW );

		$entry = Log::last( '0a1b2c3d' );
		$this->assertSame( 'partial', $entry['status'] );
		$this->assertSame(
			[
				[ 'name' => 'Offsite', 'status' => 'ok', 'error' => '' ],
				[ 'name' => 'Second', 'status' => 'failed', 'error' => 'Access denied.' ],
			],
			$entry['cloud']
		);
		$this->assertCount( 1, $this->mails, 'A partial run mails under "on failure".' );
		$this->assertStringContainsString( 'Second', $this->mails[0]['body'] );
		$this->assertStringContainsString( 'Access denied.', $this->mails[0]['body'] );
	}

	public function test_a_backup_that_lives_only_in_the_cloud_gets_no_label(): void {
		$job                           = self::job( Job::DONE );
		$job->state['backup']['local'] = false;

		$this->recorder()->finished( $job, self::NOW );

		$this->assertArrayNotHasKey( Options::LABELS, FakeOptions::$values );
		$this->assertSame( 'site-test-20260101-000000-001-0a1b2c3d.wpress', Log::last( '0a1b2c3d' )['backup'] );
	}
}
