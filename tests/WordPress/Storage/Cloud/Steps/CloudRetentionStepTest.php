<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudRetentionStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudRetentionStepTest extends MonkeyTestCase {

	private const HOME = 'https://example.com';

	private const NOW = 1760238000;

	private const DAY = 86400;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
	}

	/**
	 * @param CloudPlan            $plan     Plan.
	 * @param array<string, mixed> $schedule state.schedule ([] = manual).
	 */
	private static function job( CloudPlan $plan, string $uploaded, array $schedule = [] ): Job {
		$job                  = new Job( '0123456789abcdef', 'export', [ CloudRetentionStep::KEY ] );
		$job->state['site']   = [ 'homeUrl' => self::HOME ];
		$job->state['backup'] = [
			'name' => $uploaded,
			'path' => '/x',
			'size' => 1,
		];
		$job->state['cloud']  = $plan->toArray();
		if ( [] !== $schedule ) {
			$job->state['schedule'] = $schedule;
		}

		return $job;
	}

	private static function runAll( Destinations $d, Job $job, ?\Closure $exists = null ): void {
		$step = new CloudRetentionStep( $d, static fn(): int => self::NOW, $exists );
		for ( $i = 0; $i < 10 && ! $step->run( $job, new NoDeadline() )->isDone(); $i++ ) {
			$job->attempt = []; // A new cursor every call, as the Runner sees it.
		}
	}

	/**
	 * Review Focus 5.
	 */
	public function test_only_this_sites_generated_names_in_scope_are_pruned(): void {
		$d      = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$remote = FakeCloud::$remotes['aaaa1111'];
		$mine   = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$mine[]                        = BackupName::forSite( self::HOME, self::NOW - $i * self::DAY, $i );
			$remote->files[ end( $mine ) ] = 'x';
		}
		$foreign = [
			BackupName::forSite( 'https://other.example', self::NOW - 30 * self::DAY, 1 ),
			BackupName::forSite( 'https://example.com/shop', self::NOW - 30 * self::DAY, 1 ),
			BackupName::forSchedule( self::HOME, self::NOW - 30 * self::DAY, 1, 'abcd1234' ),
			BackupName::safety( self::NOW - 30 * self::DAY ),
			'example-com.wpress',
			'notes.txt',
		];
		foreach ( $foreign as $name ) {
			$remote->files[ $name ] = 'x';
		}
		$plan = new CloudPlan(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => [
						'count' => 2,
						'bytes' => 0,
						'days'  => 0,
					],
				],
			]
		);
		$job  = self::job( $plan, $mine[0] );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		self::runAll( $d, $job );

		$this->assertSame( array_merge( [ $mine[0], $mine[1] ], $foreign ), array_keys( $remote->files ) );
		$this->assertSame( [ $mine[2], $mine[3] ], CloudOutcome::get( $job, 'aaaa1111' )['pruned'] );
	}

	/**
	 * Review Focus 5 / spec 21.8 ("csak sikeres feltöltés után").
	 */
	public function test_a_failed_upload_prunes_nothing(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		for ( $i = 1; $i < 5; $i++ ) {
			FakeCloud::$remotes['aaaa1111']->files[ BackupName::forSite( self::HOME, self::NOW - $i * self::DAY, $i ) ] = 'x';
		}
		$job = self::job( CloudPlan::manual( [ 'aaaa1111' ], true ), BackupName::forSite( self::HOME, self::NOW, 0 ) );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::FAILED, 'One', 'fake', 'Denied.' );
		$profile            = $d->find( 'aaaa1111' );
		$profile->retention = [
			'count' => 1,
			'bytes' => 0,
			'days'  => 0,
		];
		$d->profiles()->save( $profile );

		self::runAll( $d, $job );

		$this->assertCount( 4, FakeCloud::$remotes['aaaa1111']->files );
		$this->assertNotContains( 'list:', FakeCloud::$remotes['aaaa1111']->calls );
	}

	public function test_a_scheduled_run_prunes_only_its_schedules_backups_with_its_own_retention(): void {
		$d      = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$remote = FakeCloud::$remotes['aaaa1111'];
		$now    = BackupName::forSchedule( self::HOME, self::NOW, 0, 'abcd1234' );
		$old    = BackupName::forSchedule( self::HOME, self::NOW - self::DAY, 1, 'abcd1234' );
		$other  = BackupName::forSchedule( self::HOME, self::NOW - self::DAY, 2, 'ffff0000' );
		$manual = BackupName::forSite( self::HOME, self::NOW - self::DAY, 3 );
		foreach ( [ $now, $old, $other, $manual ] as $name ) {
			$remote->files[ $name ] = 'x';
		}
		$plan = new CloudPlan(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => [
						'count' => 1,
						'bytes' => 0,
						'days'  => 0,
					],
				],
			]
		);
		$job  = self::job( $plan, $now, [ 'id' => 'abcd1234' ] );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		self::runAll( $d, $job, static fn( string $id ): bool => 'abcd1234' === $id );

		$this->assertSame( [ $now, $other, $manual ], array_keys( $remote->files ) );
	}

	public function test_an_upload_of_an_existing_backup_and_a_listing_failure_delete_nothing(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		FakeCloud::$remotes['aaaa1111']->files[ BackupName::forSite( self::HOME, self::NOW - self::DAY, 1 ) ] = 'x';
		$job = self::job( CloudPlan::upload( [ 'aaaa1111' ] ), BackupName::forSite( self::HOME, self::NOW, 0 ) );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		self::runAll( $d, $job );
		$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->calls, 'An upload of an existing backup never prunes.' );

		$plan = new CloudPlan(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => [
						'count' => 1,
						'bytes' => 0,
						'days'  => 0,
					],
				],
			]
		);
		$job  = self::job( $plan, BackupName::forSite( self::HOME, self::NOW, 0 ) );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );
		FakeCloud::$remotes['aaaa1111']->failNext( 'list', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		self::runAll( $d, $job );

		$this->assertCount( 1, FakeCloud::$remotes['aaaa1111']->files );
		$this->assertStringContainsString( 'No answer.', implode( "\n", $job->warnings ) );
	}

	public function test_a_replayed_attempt_prunes_nothing_and_says_so(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		for ( $i = 0; $i < 3; $i++ ) {
			FakeCloud::$remotes['aaaa1111']->files[ BackupName::forSite( self::HOME, self::NOW - $i * self::DAY, $i ) ] = 'x';
		}
		$plan = new CloudPlan(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => [
						'count' => 1,
						'bytes' => 0,
						'days'  => 0,
					],
				],
			]
		);
		$job  = self::job( $plan, BackupName::forSite( self::HOME, self::NOW, 0 ) );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );
		$job->attempt = [ 'count' => 2 ];

		$step = new CloudRetentionStep( $d, static fn(): int => self::NOW );
		$step->run( $job, new NoDeadline() );

		$this->assertCount( 3, FakeCloud::$remotes['aaaa1111']->files );
		$this->assertNotSame( [], $job->warnings );
	}

	/**
	 * @return array{0: Destinations, 1: Job, 2: array<int, string>}
	 */
	private function scheduled( array $schedule ): array {
		$d      = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$now    = BackupName::forSchedule( self::HOME, self::NOW, 0, 'abcd1234' );
		$old    = BackupName::forSchedule( self::HOME, self::NOW - self::DAY, 1, 'abcd1234' );
		FakeCloud::$remotes['aaaa1111']->files = [
			$now => 'x',
			$old => 'x',
		];
		$job = self::job( self::planWith( 1 ), $now, $schedule );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		return [ $d, $job, [ $now, $old ] ];
	}

	private static function planWith( ?int $count ): CloudPlan {
		return new CloudPlan(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => null === $count ? null : [
						'count' => $count,
						'bytes' => 0,
						'days'  => 0,
					],
				],
			]
		);
	}

	public function test_a_scheduled_run_prunes_nothing_with_an_invalid_schedule_id_or_no_lookup(): void {
		foreach ( [ [ 'id' => 'nope' ], [ 'id' => 'abcd1234' ] ] as $i => $schedule ) {
			[ $d, $job ] = $this->scheduled( $schedule );
			// Case 0: invalid id with a lookup that says yes. Case 1: valid id, no lookup at all.
			self::runAll( $d, $job, 0 === $i ? static fn(): bool => true : null );
			$this->assertCount( 2, FakeCloud::$remotes['aaaa1111']->files, "case $i" );
		}
	}

	public function test_a_deleted_schedule_keeps_its_backups_and_says_so(): void {
		[ $d, $job ] = $this->scheduled( [ 'id' => 'abcd1234' ] );

		self::runAll( $d, $job, static fn(): bool => false );

		$this->assertCount( 2, FakeCloud::$remotes['aaaa1111']->files );
		$this->assertNotSame( [], $job->warnings );
	}

	public function test_an_empty_home_url_prunes_nothing_and_warns(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = self::job( self::planWith( 1 ), BackupName::forSite( self::HOME, self::NOW, 0 ) );
		$job->state['site'] = [ 'homeUrl' => '' ];
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );
		FakeCloud::$remotes['aaaa1111']->files[ BackupName::forSite( self::HOME, self::NOW - self::DAY, 1 ) ] = 'x';

		self::runAll( $d, $job );

		$this->assertCount( 1, FakeCloud::$remotes['aaaa1111']->files );
		$this->assertNotSame( [], $job->warnings );
	}

	public function test_a_profile_deleted_after_the_upload_warns(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = self::job( self::planWith( 1 ), BackupName::forSite( self::HOME, self::NOW, 0 ) );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );
		$d->forget( 'aaaa1111' );

		self::runAll( $d, $job );

		$this->assertNotSame( [], $job->warnings );
	}

	public function test_a_replay_with_all_zero_retention_does_not_warn(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = self::job( self::planWith( 0 ), BackupName::forSite( self::HOME, self::NOW, 0 ) );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );
		$job->attempt = [ 'count' => 2 ];

		( new CloudRetentionStep( $d, static fn(): int => self::NOW ) )->run( $job, new NoDeadline() );

		$this->assertSame( [], $job->warnings );
	}

	public function test_a_manual_plan_without_its_own_retention_uses_the_profiles(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$profile            = $d->find( 'aaaa1111' );
		$profile->retention = [
			'count' => 1,
			'bytes' => 0,
			'days'  => 0,
		];
		$d->profiles()->save( $profile );
		$keep = BackupName::forSite( self::HOME, self::NOW, 0 );
		$old  = BackupName::forSite( self::HOME, self::NOW - self::DAY, 1 );
		FakeCloud::$remotes['aaaa1111']->files = [
			$keep => 'x',
			$old  => 'x',
		];
		$job = self::job( CloudPlan::manual( [ 'aaaa1111' ], true ), $keep );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		self::runAll( $d, $job );

		$this->assertSame( [ $keep ], array_keys( FakeCloud::$remotes['aaaa1111']->files ) );
	}

	public function test_it_stops_at_the_deadline_and_resumes_where_it_left_off(): void {
		$d      = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$keep   = BackupName::forSite( self::HOME, self::NOW, 0 );
		$files  = [ $keep => 'x' ];
		$stale  = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$stale[]                                    = BackupName::forSite( self::HOME, self::NOW - $i * self::DAY, $i );
			$files[ BackupName::forSite( self::HOME, self::NOW - $i * self::DAY, $i ) ] = 'x';
		}
		FakeCloud::$remotes['aaaa1111']->files = $files;
		$job = self::job( self::planWith( 1 ), $keep );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );
		$step = new CloudRetentionStep( $d, static fn(): int => self::NOW );

		$first = $step->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $first->isDone() );
		$this->assertCount( 3, FakeCloud::$remotes['aaaa1111']->files, 'One deleted, two stale left.' );
		$this->assertSame( 0, $job->cursor['index'] );
		$this->assertCount( 1, CloudOutcome::get( $job, 'aaaa1111' )['pruned'] );

		$job->attempt = [];
		$step->run( $job, new NoDeadline() );
		$this->assertSame( [ $keep ], array_keys( FakeCloud::$remotes['aaaa1111']->files ) );
		$this->assertCount( 3, CloudOutcome::get( $job, 'aaaa1111' )['pruned'] );
	}

	public function test_the_listing_forgets_a_copy_removed_by_hand_at_the_destination(): void {
		FakeWpdb::install();
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$keep = BackupName::forSite( self::HOME, self::NOW, 0 );
		$gone = BackupName::forSite( self::HOME, self::NOW - self::DAY, 1 );
		FakeCloud::$remotes['aaaa1111']->files = [ $keep => 'x' ];
		CloudCopies::mark( $gone, 'aaaa1111', CloudCopies::UP, self::NOW - self::DAY );
		CloudCopies::mark( $keep, 'aaaa1111', CloudCopies::UP, self::NOW - 5 );
		$job = self::job( self::planWith( 5 ), $keep );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		self::runAll( $d, $job );

		$this->assertSame( [ $keep ], array_keys( CloudCopies::all() ) );
	}

	public function test_the_backup_just_uploaded_keeps_its_copy_record_whatever_the_listing_says(): void {
		FakeWpdb::install();
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$keep = BackupName::forSite( self::HOME, self::NOW, 0 );
		CloudCopies::mark( $keep, 'aaaa1111', CloudCopies::UP, self::NOW - 5 );
		$job = self::job( self::planWith( 5 ), $keep );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 'fake' );

		self::runAll( $d, $job );

		$this->assertSame( 'up', CloudCopies::of( $keep )['aaaa1111']['status'] ?? null );
	}
}
