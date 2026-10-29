<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\SchedulesApi;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Schedules\ScheduleSecrets;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class SchedulesApiTest extends MonkeyTestCase {

	private const NOW = 1767225600;

	/** @var array<string, int> */
	private array $planned = [];

	private SchedulesApi $api;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'wp_next_scheduled' )->alias( fn( string $hook, array $args = [] ) => $this->planned[ $args[0] ?? '' ] ?? false );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook, array $args = [] ): int {
				unset( $this->planned[ $args[0] ?? '' ] );
				return 1;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $time, string $hook, array $args = [] ): bool {
				$this->planned[ $args[0] ] = $time;
				return true;
			}
		);
		$this->api = new SchedulesApi( new Repository( static fn(): int => self::NOW ), new Queue(), new SealedPassword(), static fn(): int => self::NOW, static fn(): \DateTimeZone => new \DateTimeZone( 'UTC' ), static fn(): ?string => null );
	}

	private static function code( \Closure $call ): string {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e->status . ' ' . $e->errorCode;
		}
		return 'accepted';
	}

	public function test_the_list_shows_the_default_schedule_cron_health_and_timezone(): void {
		$all = $this->api->all();

		$this->assertCount( 1, $all['items'] );
		$this->assertSame( [ 'Daily backup', false, null, [] ], [ $all['items'][0]['title'], $all['items'][0]['enabled'], $all['items'][0]['nextRun'], $all['items'][0]['upcoming'] ] );
		$this->assertSame( [ 'disabled', 'late' ], array_keys( $all['cron'] ) );
		$this->assertSame( 'UTC', $all['timezone'] );
	}

	public function test_the_list_carries_the_week_and_the_history(): void {
		$id = $this->api->create( [ 'title' => 'Hourly', 'enabled' => true, 'frequency' => 'hourly', 'minute' => 10 ] )['id'];
		for ( $i = 1; $i <= 16; $i++ ) {
			Log::add( $id, [ 'status' => 1 === $i ? 'failed' : 'done', 'trigger' => 'cron', 'endedAt' => $i ] );
		}
		$item = $this->api->all()['items'][1];

		$this->assertCount( 168, $item['week'] );
		$this->assertSame( $item['upcoming'], array_slice( $item['week'], 0, 5 ) );
		$this->assertCount( 14, $item['history'] );
		$this->assertSame( [ 3, 16 ], [ $item['history'][0]['endedAt'], $item['history'][13]['endedAt'] ] );
		$this->assertSame( [], $this->api->all()['items'][0]['week'], 'The default schedule is off.' );
		$this->assertSame( [], $this->api->all()['items'][0]['history'] );
	}

	public function test_the_default_title_is_shown_in_the_readers_language(): void {
		Functions\when( '__' )->alias( static fn( string $text ): string => 'Daily backup' === $text ? 'Napi mentés' : $text );
		$this->assertSame( 'Napi mentés', $this->api->all()['items'][0]['title'] );
	}

	public function test_create_seals_the_password_and_plans_the_next_run(): void {
		$item = $this->api->create(
			[
				'title'     => 'Nightly',
				'enabled'   => true,
				'frequency' => 'daily',
				'hour'      => 4,
				'export'    => [ 'encrypt' => true ],
				'password'  => 'very-secret-pw',
			]
		);

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}\z/', $item['id'] );
		$this->assertTrue( $item['hasPassword'] );
		$this->assertSame( self::NOW + 4 * 3600, $item['nextRun'] );
		$this->assertCount( 5, $item['upcoming'] );
		$this->assertSame( self::NOW + 4 * 3600, $this->planned[ $item['id'] ] );
		$this->assertStringNotContainsString( 'very-secret-pw', (string) json_encode( $item ) . serialize( FakeOptions::$values ) );
		$this->assertSame( self::NOW, ( new Repository() )->find( $item['id'] )?->anchor );
	}

	public function test_update_changes_only_the_keys_sent_and_switching_off_drops_the_event(): void {
		$id = $this->api->create(
			[
				'title'    => 'Nightly',
				'enabled'  => true,
				'export'   => [ 'encrypt' => true ],
				'password' => 'pw',
			]
		)['id'];

		$item = $this->api->update( $id, [ 'enabled' => false ] );

		$this->assertSame( [ 'Nightly', false, true ], [ $item['title'], $item['enabled'], $item['hasPassword'] ] );
		$this->assertArrayNotHasKey( $id, $this->planned );
	}

	public function test_a_missing_schedule_is_a_404_everywhere(): void {
		$starter = new ScheduleJobs( new JobStore( '/nonexistent' ), new SiteLock(), new MemorySecretStore(), static fn(): Site => SiteFixture::make( '/s' ), new SealedPassword() );
		foreach ( [
			fn() => $this->api->update( 'ffffffff', [] ),
			fn() => $this->api->delete( 'ffffffff' ),
			fn() => $this->api->log( 'ffffffff' ),
			fn() => $this->api->clearLog( 'ffffffff' ),
			fn() => $this->api->run( 'ffffffff', $starter ),
			fn() => $this->api->update( '../x', [] ),
		] as $call ) {
			$this->assertSame( '404 lw_migration_schedule_missing', self::code( $call ) );
		}
	}

	public function test_delete_removes_the_event_the_waiting_run_and_the_log(): void {
		$id = $this->api->create(
			[
				'title'   => 'Nightly',
				'enabled' => true,
			]
		)['id'];
		( new Queue() )->add( $id, self::NOW );
		Log::add( $id, [ 'status' => 'done' ] );

		$this->api->delete( $id );

		$this->assertArrayNotHasKey( $id, $this->planned );
		$this->assertSame( [], ( new Queue() )->all() );
		$this->assertArrayNotHasKey( Options::SCHEDULE_LOG_PREFIX . $id, FakeOptions::$values );
		$this->assertSame( '404 lw_migration_schedule_missing', self::code( fn() => $this->api->log( $id ) ) );
	}

	public function test_run_now_starts_a_manual_job_and_an_unusable_schedule_is_a_409(): void {
		$dir     = TempDir::create();
		$jobs    = new JobStore( $dir );
		$starter = new ScheduleJobs( $jobs, new SiteLock(), new MemorySecretStore(), static fn(): Site => SiteFixture::make( '/s' ), new SealedPassword() );
		try {
			$id  = $this->api->create( [ 'title' => 'Nightly' ] )['id'];
			$job = $this->api->run( $id, $starter );
			$this->assertSame( [ $id, 'manual' ], [ $job->state['schedule']['id'], $job->state['schedule']['trigger'] ] );

			( new SiteLock() )->release( $job->id );
			FakeOptions::$values[ Options::SCHEDULES ][1]['export']   = [ 'encrypt' => true ];
			FakeOptions::$values[ Options::SCHEDULES ][1]['password'] = 'lwm1:not-openable';
			$this->assertSame( '409 lw_migration_schedule_unusable', self::code( fn() => $this->api->run( $id, $starter ) ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_run_now_without_a_usable_backups_folder_is_a_409_with_its_own_code(): void {
		$starter = new ScheduleJobs( new JobStore( '/nonexistent-jobs' ), new SiteLock(), new MemorySecretStore(), static fn(): Site => throw new FolderRefusedException( FolderRefusedException::CHANGED ), new SealedPassword() );
		$id      = $this->api->create( [ 'title' => 'Nightly' ] )['id'];

		$this->assertSame( '409 lw_migration_backup_dir_unusable', self::code( fn() => $this->api->run( $id, $starter ) ) );
	}

	public function test_the_twenty_first_schedule_is_refused(): void {
		for ( $i = 1; $i < Repository::MAX; $i++ ) {
			$this->api->create( [ 'title' => 'S' . $i ] );
		}

		$this->assertSame( '409 lw_migration_too_many_schedules', self::code( fn() => $this->api->create( [ 'title' => 'One too many' ] ) ) );
	}

	public function test_an_edit_that_keeps_the_timing_keeps_an_overdue_event(): void {
		$id                   = $this->api->create(
			[
				'title'   => 'Nightly',
				'enabled' => true,
			]
		)['id'];
		$this->planned[ $id ] = self::NOW - 5000;

		$this->api->update(
			$id,
			[
				'title'  => 'Renamed',
				'notify' => [ 'when' => 'always' ],
			]
		);
		$this->assertSame( self::NOW - 5000, $this->planned[ $id ], 'A title change must not drop a pending run.' );

		$this->api->update( $id, [ 'hour' => 5 ] );
		$this->assertSame( self::NOW + 5 * 3600, $this->planned[ $id ], 'A timing change re-plans it.' );
	}

	public function test_an_edit_plans_a_missing_event(): void {
		$id = $this->api->create(
			[
				'title'   => 'Nightly',
				'enabled' => true,
			]
		)['id'];
		unset( $this->planned[ $id ] );

		$this->api->update( $id, [ 'title' => 'Renamed' ] );

		$this->assertSame( self::NOW + 3 * 3600, $this->planned[ $id ] );
	}

	public function test_the_list_heals_the_events(): void {
		$on                   = $this->api->create(
			[
				'title'   => 'On',
				'enabled' => true,
			]
		)['id'];
		$off                  = $this->api->create( [ 'title' => 'Off' ] )['id'];
		$this->planned        = [];
		$this->planned[ $off ] = self::NOW + 10;

		$items = $this->api->all()['items'];

		$this->assertSame( [ $on => self::NOW + 3 * 3600 ], $this->planned );
		$this->assertSame( self::NOW + 3 * 3600, $items[1]['nextRun'] );
	}

	public function test_the_list_looks_for_the_running_job_once(): void {
		$calls = 0;
		$api   = new SchedulesApi(
			new Repository( static fn(): int => self::NOW ),
			new Queue(),
			new SealedPassword(),
			static fn(): int => self::NOW,
			static fn(): \DateTimeZone => new \DateTimeZone( 'UTC' ),
			static function () use ( &$calls ): ?string {
				++$calls;
				return null;
			}
		);

		$api->all();

		$this->assertSame( 1, $calls, 'Each look scans the job folders.' );
	}

	public function test_a_run_given_up_is_not_shown_as_running(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		$services = new Services();
		$id       = $this->api->create(
			[
				'title'   => 'Nightly',
				'enabled' => true,
			]
		)['id'];
		try {
			$services->storage()->ensure();
			$job                    = $services->jobs()->create( 'export', [ 'init' ], [], time() - 60 );
			$job->state['schedule'] = [ 'id' => $id ];
			$services->jobs()->save( $job );
			Log::add( // The driver gave it up: its job.json could not be saved any more, so it still reads as unfinished.
				$id,
				[
					'job'    => $job->id,
					'status' => 'failed',
				]
			);

			$items = SchedulesApi::fromServices( $services )->all()['items'];
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}

		$this->assertSame( [ $id => false ], array_column( array_filter( $items, static fn( array $item ): bool => $item['id'] === $id ), 'running', 'id' ) );
	}

	public function test_saving_without_a_new_password_keeps_the_stored_seal(): void {
		$id     = $this->api->create(
			[
				'title'    => 'Nightly',
				'export'   => [ 'encrypt' => true ],
				'password' => 'pw',
			]
		)['id'];
		$sealed = FakeOptions::$values[ Options::SCHEDULES ][1]['password'];

		$this->api->update( $id, [ 'title' => 'Renamed' ] );
		$this->api->update(
			$id,
			[
				'export' => [
					'encrypt' => true,
					'noCache' => true,
				],
			]
		);
		$item = $this->api->update( $id, [ 'enabled' => true ] );

		$this->assertSame( $sealed, FakeOptions::$values[ Options::SCHEDULES ][1]['password'], 'No re-seal on an unrelated edit.' );
		$this->assertSame( 'pw', ( new SealedPassword() )->open( $sealed ) );
		$this->assertTrue( $item['hasPassword'] );
		$this->assertStringNotContainsString( $sealed, (string) json_encode( $item ) . json_encode( $this->api->all() ) );
	}

	public function test_switching_the_protection_off_forgets_the_password_but_a_running_job_keeps_its_own(): void {
		$dir     = TempDir::create();
		$jobs    = new JobStore( $dir );
		$secrets = new MemorySecretStore();
		$starter = new ScheduleJobs( $jobs, new SiteLock(), $secrets, static fn(): Site => SiteFixture::make( '/s' ), new SealedPassword() );
		try {
			$id  = $this->api->create(
				[
					'title'    => 'Nightly',
					'export'   => [ 'encrypt' => true ],
					'password' => 'pw',
				]
			)['id'];
			$job = $this->api->run( $id, $starter );

			$item = $this->api->update( $id, [ 'export' => [ 'encrypt' => false ] ] );

			$this->assertFalse( $item['hasPassword'] );
			$this->assertSame( '', FakeOptions::$values[ Options::SCHEDULES ][1]['password'] );
			$this->assertTrue( $jobs->load( $job->id )->options['encrypt'], 'The running job keeps its own flag.' );
			$this->assertSame( [ 'password' => 'pw' ], ( new ScheduleSecrets( $secrets, $jobs, new Repository(), new SealedPassword() ) )->get( $job->id ), 'and its own password.' );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/**
	 * An encrypted, enabled schedule whose seal no longer opens (the salts changed).
	 */
	private function unreadable(): string {
		$id = $this->api->create(
			[
				'title'    => 'Nightly',
				'enabled'  => true,
				'export'   => [ 'encrypt' => true ],
				'password' => 'pw',
			]
		)['id'];
		FakeOptions::$values[ Options::SCHEDULES ][1]['password'] = 'lwm1:sealed-under-the-old-salt';

		return $id;
	}

	public function test_an_unreadable_seal_still_lets_the_schedule_be_switched_off(): void {
		$id = $this->unreadable();

		$item = $this->api->update( $id, [ 'enabled' => false ] );

		$this->assertFalse( $item['enabled'] );
		$this->assertArrayNotHasKey( $id, $this->planned );
	}

	public function test_an_unreadable_seal_still_lets_the_schedule_be_renamed(): void {
		$id = $this->unreadable();

		$item = $this->api->update( $id, [ 'title' => 'Renamed' ] );

		$this->assertSame( [ 'Renamed', true ], [ $item['title'], $item['hasPassword'] ] );
		$this->assertSame( 'lwm1:sealed-under-the-old-salt', FakeOptions::$values[ Options::SCHEDULES ][1]['password'] );
	}
}
