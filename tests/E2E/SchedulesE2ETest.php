<?php
/**
 * Schedules on a real WordPress site: the command line, WP-Cron with loopback
 * slices over HTTP, the REST routes, retention, mails; and on network n.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;

/**
 * A test-only must-use plugin on site a (schedules-probe.php) writes mails to
 * a file instead of sending them, counts the loopback requests the route
 * accepted, and lets options set the loopback address, the cron budget and
 * the tick length. The class removes its schedules, their run logs, their
 * backups, its files and the probe when it ends (no later suite sees a
 * schedule).
 */
final class SchedulesE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-schedules-probe.php';

	private const MAILS = '/wp-content/lwm-e2e-mails.jsonl';

	private const LOOPBACKS = '/wp-content/lwm-e2e-loopbacks.log';

	private const PAUSED = '/wp-content/lwm-e2e-paused.log';

	private const SMALL = [
		'noMedia'   => true,
		'noPlugins' => true,
		'noThemes'  => true,
	];

	private const NS = '\\LightweightPlugins\\Migration\\';

	private static ?RestClient $client = null;

	/** @var array<int, string> Manual backups the tests made. */
	private static array $manual = [];

	public static function setUpBeforeClass(): void {
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			return;
		}
		$mu = WpSites::path( 'a' ) . '/wp-content/mu-plugins';
		if ( ! is_dir( $mu ) ) {
			mkdir( $mu, 0755, true );
		}
		copy( __DIR__ . '/schedules-probe.php', $mu . '/' . self::PROBE );
	}

	public static function tearDownAfterClass(): void {
		if ( null !== self::$client ) {
			self::$client->stop();
			self::$client = null;
		}
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			return;
		}
		$php = '$names = ' . var_export( self::$manual, true ) . ';'
			. ' $repo = \\LightweightPlugins\\Migration\\Services::get()->backups();'
			. ' foreach ( $repo->all() as $b ) { if ( 1 === preg_match( "/-[a-f0-9]{8}\\.wpress\\z/", $b->name ) || in_array( $b->name, $names, true ) ) { $repo->delete( $b->name ); \\LightweightPlugins\\Migration\\Backups\\Labels::remove( $b->name ); } }'
			. ' global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( "lw_migration_schedule_log_" ) . "%" ) ) as $o ) { delete_option( $o ); }'
			. ' foreach ( [ "lw_migration_schedules", "lw_migration_schedule_queue", "lw_migration_loopback", "lwm_e2e_loopback_url", "lwm_e2e_cron_seconds", "lwm_e2e_tick_seconds", "lwm_e2e_cron_pause" ] as $o ) { delete_option( $o ); }'
			. ' wp_unschedule_hook( "lw_migration_schedule" ); wp_unschedule_hook( "lw_migration_schedule_continue" );';
		WpSites::ok( 'a', 'eval', $php );
		foreach ( [ '/wp-content/mu-plugins/' . self::PROBE, self::MAILS, self::LOOPBACKS, self::PAUSED ] as $file ) {
			if ( is_file( WpSites::path( 'a' ) . $file ) ) {
				unlink( WpSites::path( 'a' ) . $file );
			}
		}
	}

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ self::MAILS, self::LOOPBACKS, self::PAUSED ] as $file ) {
			if ( is_file( WpSites::path( 'a' ) . $file ) ) {
				unlink( WpSites::path( 'a' ) . $file );
			}
		}
	}

	/**
	 * Saves a schedule through the plugin's own classes (as the REST route does).
	 *
	 * @param array<string, mixed> $fields   Schedule fields.
	 * @param string               $password Plain password, sealed before it is stored.
	 * @return string Its id.
	 */
	private static function schedule( array $fields, string $password = '' ): string {
		$php = '$f = json_decode( ' . var_export( (string) json_encode( $fields ), true ) . ', true );'
			. ' $p = ' . var_export( $password, true ) . ';'
			. ' if ( "" !== $p ) { $f["password"] = ( new ' . self::NS . 'Schedules\SealedPassword() )->seal( $p ); }'
			. ' $s = ' . self::NS . 'Schedules\Schedule::fromArray( array_merge( [ "id" => ' . self::NS . 'Schedules\Schedule::newId(), "anchor" => time() ], $f ) );'
			. ' ( new ' . self::NS . 'Schedules\Repository() )->save( $s );'
			. ' ' . self::NS . 'Schedules\Events::sync( $s, time() );'
			. ' echo $s->id;';

		return trim( WpSites::ok( 'a', 'eval', $php ) );
	}

	/** Moves the schedule's event a minute into the past: its time has come. */
	private static function makeDue( string $id ): void {
		WpSites::ok( 'a', 'eval', '$id = ' . var_export( $id, true ) . '; wp_clear_scheduled_hook( "lw_migration_schedule", [ $id ] ); wp_schedule_single_event( time() - 60, "lw_migration_schedule", [ $id ] );' );
	}

	/** @return array<int, array<string, mixed>> Newest first. */
	private static function log( string $id ): array {
		return (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'schedule', 'log', $id, '--format=json' ), true );
	}

	/** @return array<int, array{to: mixed, subject: string, message: string}> */
	private static function mails(): array {
		$file = WpSites::path( 'a' ) . self::MAILS;
		if ( ! is_file( $file ) ) {
			return [];
		}

		return array_map( static fn( string $line ): array => (array) json_decode( $line, true ), array_values( array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) ) );
	}

	/** @return array<int, string> This schedule's backups. */
	private static function scheduled( string $id ): array {
		return array_values( array_filter( self::backups( 'a' ), static fn( string $name ): bool => str_ends_with( $name, '-' . $id . '.wpress' ) ) );
	}

	private static function client(): RestClient {
		if ( null === self::$client ) {
			self::$client = new RestClient( 'a' );
			self::$client->start();
		}

		return self::$client;
	}

	/**
	 * @param array<int, array{to: mixed, subject: string, message: string}> $mails Captured mails.
	 */
	private function assertNoPrivateText( array $mails ): void {
		$private = [ basename( WpSites::storage( 'a' ) ), WpSites::path( 'a' ), (string) realpath( WpSites::path( 'a' ) ) ];
		foreach ( $mails as $mail ) {
			foreach ( $private as $text ) {
				$this->assertStringNotContainsString( $text, $mail['subject'] . $mail['message'] );
			}
		}
	}

	public function test_due_runs_from_the_command_line_keep_their_own_retention_and_mail(): void {
		[ $manual ]       = self::export( 'a', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		self::$manual[]   = $manual;
		$id               = self::schedule(
			[
				'title'     => 'E2E nightly',
				'enabled'   => true,
				'export'    => self::SMALL,
				'retention' => [ 'count' => 2 ],
				'notify'    => [
					'when'  => 'always',
					'email' => 'ops@site-a.test',
				],
			]
		);

		for ( $run = 1; $run <= 3; $run++ ) {
			self::makeDue( $id );
			$out = WpSites::ok( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertStringContainsString( 'E2E nightly: backup ready', $out );
			sleep( 1 );
		}

		$this->assertCount( 2, self::scheduled( $id ), 'The schedule keeps its own two.' );
		$this->assertContains( $manual, self::backups( 'a' ), 'A manual backup is never pruned by a schedule.' );
		$log = self::log( $id );
		$this->assertSame( [ 'done', 'done', 'done' ], array_column( $log, 'status' ) );
		$this->assertSame( [ 'cron', 'cron', 'cron' ], array_column( $log, 'trigger' ) );
		$this->assertSame( [ 'sent', 'sent', 'sent' ], array_column( $log, 'mail' ) );
		$this->assertNotNull( self::nextRun( $id ), 'The fired event planned the next run.' );
		$this->assertGreaterThan( time(), (int) self::nextRun( $id ) );
		$mails = self::mails();
		$this->assertCount( 3, $mails );
		foreach ( $mails as $mail ) {
			$this->assertStringContainsString( 'Scheduled backup "E2E nightly" is ready', $mail['subject'] );
			$this->assertSame( 'ops@site-a.test', is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] );
		}
		$this->assertNoPrivateText( $mails );

		$out = WpSites::ok( 'a', 'lw-migration', 'schedule', 'run', $id );
		$this->assertMatchesRegularExpression( '/Backup file: \S+-' . $id . '\.wpress/', $out );
		$this->assertSame( [ 'manual', 'done', '' ], [ self::log( $id )[0]['trigger'], self::log( $id )[0]['status'], self::log( $id )[0]['mail'] ] );
		$this->assertCount( 3, self::mails(), 'A manual run sends no mail.' );

		$list = (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'schedule', 'list', '--format=json' ), true );
		$row  = array_values( array_filter( $list, static fn( array $item ): bool => $item['id'] === $id ) );
		$this->assertCount( 1, $row );
		$this->assertSame( [ 'E2E nightly', 'yes', 'daily', 'done' ], [ $row[0]['title'], $row[0]['enabled'], $row[0]['frequency'], $row[0]['last'] ] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) $row[0]['next'] );

		$out = WpSites::ok( 'a', 'lw-migration', 'schedule', 'run', '--due' );
		$this->assertStringContainsString( 'Nothing was due.', $out );
		// The server cron line the admin page shows: quiet when there is nothing to do.
		[ $code, $quiet, $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', '--due', '--quiet' );
		$this->assertSame( [ 0, '', '' ], [ $code, trim( $quiet ), trim( $err ) ] );
	}

	public function test_a_failing_schedule_mails_once_a_day_and_the_command_fails(): void {
		$id = self::schedule(
			[
				'title'   => 'E2E broken',
				'enabled' => true,
				'export'  => [ 'encrypt' => true ],
				'notify'  => [ 'when' => 'failure' ],
			],
			'e2e-broken-pw'
		);
		// As after the site's security keys changed: the stored seal no longer opens.
		WpSites::ok( 'a', 'eval', '$all = get_option( "lw_migration_schedules" ); foreach ( $all as $i => $s ) { if ( $s["id"] === ' . var_export( $id, true ) . ' ) { $all[ $i ]["password"] = "lwm1:not-openable"; } } update_option( "lw_migration_schedules", $all, false );' );

		for ( $run = 1; $run <= 2; $run++ ) {
			self::makeDue( $id );
			[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertSame( 1, $code );
			$this->assertStringContainsString( 'E2E broken', $err );
			$this->assertStringContainsString( 'password', $err );
		}

		$this->assertSame( [ 'failed', 'failed' ], array_column( self::log( $id ), 'status' ) );
		$this->assertSame( [ 'throttled', 'sent' ], array_column( self::log( $id ), 'mail' ) );
		$this->assertCount( 1, self::mails() );
		$this->assertSame( 'admin@site-a.test', is_array( self::mails()[0]['to'] ) ? self::mails()[0]['to'][0] : self::mails()[0]['to'] );
		$this->assertNoPrivateText( self::mails() );
		$this->assertSame( [], self::scheduled( $id ) );

		[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', $id );
		$this->assertSame( 1, $code, 'A manual run of it fails too.' );
		$this->assertStringContainsString( 'password', $err );
		self::switchOff( $id ); // Never again in the other tests (the command line heals the events of enabled schedules).
	}

	public function test_the_command_line_gives_a_schedule_its_lost_event_back(): void {
		$id   = self::schedule(
			[
				'title'   => 'E2E healed',
				'enabled' => true,
				'export'  => self::SMALL,
			]
		);
		$lose = static fn(): string => WpSites::ok( 'a', 'eval', 'wp_unschedule_hook( "lw_migration_schedule" );' ); // An import replaced the cron table.

		$lose();
		$list = (array) json_decode( WpSites::ok( 'a', 'lw-migration', 'schedule', 'list', '--format=json' ), true );
		$row  = array_values( array_filter( $list, static fn( array $item ): bool => $item['id'] === $id ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ( $row[0]['next'] ?? '' ), 'The list heals before it shows the next run.' );
		$this->assertGreaterThan( time(), (int) self::nextRun( $id ) );

		$lose();
		$this->assertStringContainsString( 'Nothing was due.', WpSites::ok( 'a', 'lw-migration', 'schedule', 'run', '--due' ) );
		$this->assertGreaterThan( time(), (int) self::nextRun( $id ), 'The server cron line heals too.' );
		self::switchOff( $id );
	}

	/** Switches the schedule off and takes its event. */
	private static function switchOff( string $id ): void {
		WpSites::ok( 'a', 'eval', '$all = get_option( "lw_migration_schedules" ); foreach ( $all as $i => $s ) { if ( $s["id"] === ' . var_export( $id, true ) . ' ) { $all[ $i ]["enabled"] = false; } } update_option( "lw_migration_schedules", $all, false ); wp_clear_scheduled_hook( "lw_migration_schedule", [ ' . var_export( $id, true ) . ' ] );' );
	}

	public function test_wp_cron_runs_a_due_schedule_in_loopback_slices(): void {
		self::client();
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_loopback_url', 'http://127.0.0.1:' . RestClient::PORT . '/?rest_route=/lw-migration/v1/schedules/continue' );
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_cron_seconds', '1' );
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_tick_seconds', '1' ); // The shortest tick (WebLimits::tickSeconds() never goes below 1 s).
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_cron_pause', '1' ); // The WP-Cron request yields after its first tick.
		$id = self::schedule(
			[
				'title'   => 'E2E cron',
				'enabled' => true,
				'export'  => [ 'noMedia' => true ],
				'notify'  => [ 'when' => 'off' ],
			]
		);
		self::makeDue( $id );
		WpSites::ok( 'a', 'transient', 'delete', 'doing_cron' ); // A lock taken before the probe was in place (the site's setup).

		$cron = curl_init( 'http://127.0.0.1:' . RestClient::PORT . '/wp-cron.php' );
		curl_setopt_array(
			$cron,
			[
				CURLOPT_HTTPHEADER     => [ 'Host: site-a.test' ],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 60,
			]
		);
		curl_exec( $cron ); // One WP-Cron request; nothing else calls the site from here on.

		$log = [];
		for ( $i = 0; $i < 120 && [] === $log; $i++ ) {
			sleep( 1 );
			$log = self::log( $id );
		}
		WpSites::ok( 'a', 'option', 'delete', 'lwm_e2e_cron_pause' );
		$this->assertSame( 1, self::lines( self::PAUSED ), 'The WP-Cron request fired the event and yielded after one tick.' );
		$this->assertSame( 'done', $log[0]['status'] ?? null, 'Finished by loopback slices alone (the fallback event is a minute away and nothing calls WP-Cron).' );
		$this->assertSame( 'cron', $log[0]['trigger'] );
		$this->assertCount( 1, self::scheduled( $id ) );
		$this->assertGreaterThanOrEqual( 1, self::lines( self::LOOPBACKS ), 'The rest came through the loopback route.' );
		$this->assertNull( WpSites::lock( 'a' ), 'The finished run gave the site back.' );
		$this->assertGreaterThan( time(), (int) self::nextRun( $id ), 'The fired event planned the next run.' );
	}

	/** Lines in a probe file of site a (0 when it does not exist). */
	private static function lines( string $file ): int {
		$path = WpSites::path( 'a' ) . $file;

		return is_file( $path ) ? count( (array) file( $path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ) ) : 0;
	}

	public function test_wp_cli_cron_runs_a_due_schedule_and_its_retention(): void {
		$id = self::schedule(
			[
				'title'     => 'E2E cron event',
				'enabled'   => true,
				'export'    => self::SMALL,
				'retention' => [ 'count' => 1 ],
				'notify'    => [ 'when' => 'off' ],
			]
		);

		for ( $run = 1; $run <= 2; $run++ ) {
			self::makeDue( $id );
			WpSites::ok( 'a', 'cron', 'event', 'run', '--due-now' );
			sleep( 1 );
		}

		$log = self::log( $id );
		$this->assertSame( [ 'done', 'done' ], array_column( $log, 'status' ) );
		$this->assertSame( [ 'cron', 'cron' ], array_column( $log, 'trigger' ) );
		$this->assertSame( [ $log[0]['backup'] ], self::scheduled( $id ), 'Retention 1 kept the newest only.' );
		$this->assertNull( WpSites::lock( 'a' ) );
		$this->assertGreaterThan( time(), (int) self::nextRun( $id ) );
	}

	public function test_a_job_that_can_no_longer_be_saved_stops_the_command_then_ends_with_one_failed_log_entry(): void {
		$id  = self::schedule(
			[
				'title'   => 'E2E full disk',
				'enabled' => true,
				'export'  => self::SMALL,
				'notify'  => [ 'when' => 'failure' ],
			]
		);
		$job = trim(
			WpSites::ok(
				'a',
				'eval',
				'$s = ' . self::NS . 'Services::get();'
				. ' $j = ' . self::NS . 'Schedules\ScheduleJobs::fromServices( $s, $s->webSecrets() )->start( $s->schedules()->find( ' . var_export( $id, true ) . ' ), "cron", time() );'
				. ' $s->jobs()->release( $s->jobs()->acquire( $j->id ) );' // The lock file exists before the folder is closed.
				. ' echo $j->id;'
			)
		);
		$dir = trim( WpSites::ok( 'a', 'eval', 'echo ' . self::NS . 'Services::get()->jobs()->dir( ' . var_export( $job, true ) . ' );' ) );
		chmod( $dir, 0500 ); // Like a full disk: nothing can be written there any more.
		try {
			[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertSame( 1, $code, 'A job that cannot save its progress fails the command (STUCK): ' . $out . $err );
			$this->assertStringContainsString( 'cannot save its progress', $err );
			$this->assertStringNotContainsString( 'Nothing was due', $out );
			$this->assertSame( [], self::log( $id ), 'Not given up before the lock time.' );

			// The lock time passed without a save: the next call gives the job up.
			chmod( $dir, 0700 );
			WpSites::ok( 'a', 'eval', '$s = ' . self::NS . 'Services::get(); $j = $s->jobs()->load( ' . var_export( $job, true ) . ' ); $j->updatedAt = time() - ' . self::NS . 'WordPress\SiteLock::TTL - 1; $s->jobs()->save( $j );' );
			chmod( $dir, 0500 );
			[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertSame( 1, $code, $err );
			$this->assertStringContainsString( 'E2E full disk', $err );
			$this->assertNull( WpSites::lock( 'a' ), 'The job given up gave the site back.' );

			$out = WpSites::ok( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertStringContainsString( 'Nothing was due.', $out, 'A job given up is never offered again.' );
		} finally {
			chmod( $dir, 0700 );
			WpSites::ok( 'a', 'eval', '$s = ' . self::NS . 'Services::get(); $s->lock()->release( ' . var_export( $job, true ) . ' ); $s->jobs()->delete( ' . var_export( $job, true ) . ' );' );
		}

		$log = self::log( $id );
		$this->assertCount( 1, $log, 'One entry for the run.' );
		$this->assertSame( [ 'failed', 'cron', $job ], [ $log[0]['status'], $log[0]['trigger'], self::logJob( $id ) ] );
		$this->assertStringContainsString( 'could not be saved', (string) $log[0]['error'] );
		$this->assertCount( 1, self::mails(), 'One failure mail.' );
		$this->assertNoPrivateText( self::mails() );
	}

	public function test_due_schedules_wait_for_another_job_with_exit_code_2_then_run(): void {
		$id    = self::schedule(
			[
				'title'   => 'E2E waits',
				'enabled' => true,
				'export'  => self::SMALL,
				'notify'  => [ 'when' => 'off' ],
			]
		);
		$other = trim(
			WpSites::ok(
				'a',
				'eval',
				'$s = ' . self::NS . 'Services::get(); $s->storage()->ensure();'
				. ' $j = ' . self::NS . 'Export\ExportJobs::create( $s->jobs(), $s->lock(), ' . self::NS . 'WordPress\SiteReader::read(), ' . self::NS . 'Export\ExportOptions::fromArray( [ "noMedia" => true ] ), null, new ' . self::NS . 'Job\MemorySecretStore(), time() );'
				. ' echo $j->id;'
			)
		);
		try {
			self::makeDue( $id );
			[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertSame( 2, $code, 'Waiting is not a failure (1) and not a success (0): ' . $out . $err );
			$this->assertStringContainsString( 'Due schedules are waiting', $err );
			$this->assertStringNotContainsString( 'Nothing was due', $out . $err );
			$this->assertSame( [], self::log( $id ) );
		} finally {
			WpSites::ok( 'a', 'eval', '$s = ' . self::NS . 'Services::get(); $s->lock()->release( ' . var_export( $other, true ) . ' ); $s->jobs()->delete( ' . var_export( $other, true ) . ' );' );
		}

		$out = WpSites::ok( 'a', 'lw-migration', 'schedule', 'run', '--due' );
		$this->assertStringContainsString( 'E2E waits: backup ready', $out, 'The queued run starts once the site is free.' );
		$this->assertSame( [ 'done' ], array_column( self::log( $id ), 'status' ) );
	}

	public function test_loopback_refuses_a_wrong_expired_other_or_replayed_token_with_one_answer(): void {
		$client = self::client();
		$job    = '0123456789abcdef';
		$other  = 'fedcba9876543210';
		$issue  = static fn( int $ago ): string => trim( WpSites::ok( 'a', 'eval', 'echo ( new ' . self::NS . 'Schedules\Loopback() )->issue( "' . $job . '", time() - ' . $ago . ' );' ) );
		$post   = static fn( string $id, string $token ): array => $client->call( 'POST', '/schedules/continue', null, [], [ 'job' => $id, 'token' => $token ] );

		$refusals   = [];
		$refusals[] = $post( $job, str_repeat( 'a', 32 ) );
		$expired    = $issue( 601 );
		$refusals[] = $post( $job, $expired );
		$token      = $issue( 0 );
		$refusals[] = $post( $other, $token );
		$first      = $post( $job, $token );
		$refusals[] = $post( $job, $token );

		$this->assertSame( 202, $first['status'], $first['body'] );
		$this->assertSame( [ 'ok' => true ], $first['json'], 'No job data in the answer.' );
		foreach ( $refusals as $refusal ) {
			$this->assertSame( 401, $refusal['status'], $refusal['body'] );
			$this->assertSame( $refusals[0]['body'], $refusal['body'], 'Every refusal reads the same.' );
		}
		$this->assertSame( 'rest_forbidden', $refusals[0]['json']['code'] ?? null );
	}

	public function test_run_now_over_rest_is_a_manual_run_and_the_password_never_comes_back(): void {
		$client = self::client();
		$rest   = new CheckedRest( $client, [ basename( WpSites::storage( 'a' ) ), WpSites::path( 'a' ), 'admin-pass', 'e2e-schedule-pw' ] );
		$auth   = $client->login( 'admin' );

		$created = $rest->call(
			'POST',
			'/schedules',
			$auth,
			[],
			[
				'title'    => 'E2E run now',
				'export'   => array_merge( self::SMALL, [ 'encrypt' => true ] ),
				'password' => 'e2e-schedule-pw',
				'notify'   => [ 'when' => 'always' ],
			]
		);
		$this->assertSame( 201, $created['status'], $created['body'] );
		$this->assertTrue( $created['json']['hasPassword'] );
		$id = (string) $created['json']['id'];
		$this->assertSame( 200, $rest->call( 'GET', '/schedules', $auth )['status'] );
		$this->assertStringNotContainsString( 'e2e-schedule-pw', WpSites::ok( 'a', 'option', 'get', 'lw_migration_schedules', '--format=json' ) );

		$run = $rest->call( 'POST', '/schedules/' . $id . '/run', $auth );
		$this->assertSame( 201, $run['status'], $run['body'] );
		$job = $rest->drive( $auth, (string) $run['json']['id'] );
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );

		$log = $rest->call( 'GET', '/schedules/' . $id . '/log', $auth );
		$this->assertSame( [ 'manual', 'done', '' ], [ $log['json']['entries'][0]['trigger'], $log['json']['entries'][0]['status'], $log['json']['entries'][0]['mail'] ] );
		$this->assertSame( [], self::mails(), 'Run now sends no mail.' );
		$this->assertCount( 1, self::scheduled( $id ) );
		$jobLog = WpSites::storage( 'a' ) . '/jobs/' . $job['id'] . '/job.log';
		$this->assertStringNotContainsString( 'e2e-schedule-pw', ( is_file( $jobLog ) ? (string) file_get_contents( $jobLog ) : '' ) . WpSites::ok( 'a', 'lw-migration', 'schedule', 'log', $id, '--format=json' ) );
		$this->assertSame( 401, $client->call( 'GET', '/schedules', null )['status'], 'Not without a login.' );
	}

	public function test_schedules_run_on_a_network(): void {
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh to execute the network tests.' );
		}
		$before = self::backups( 'n' );
		$create = static function ( array $export ): string {
			// Through the REST validator, as the schedule form saves it.
			$php = '$e = json_decode( ' . var_export( (string) json_encode( $export ), true ) . ', true );'
				. ' $s = ' . self::NS . 'Schedules\Schedule::fromArray( [ "id" => ' . self::NS . 'Schedules\Schedule::newId(), "anchor" => time(), "enabled" => true, "export" => ' . self::NS . 'Rest\JobInput::exportOptions( $e )->toArray() ] );'
				. ' ( new ' . self::NS . 'Schedules\Repository() )->save( $s ); ' . self::NS . 'Schedules\Events::sync( $s, time() ); echo $s->id;';

			return trim( WpSites::ok( 'n', 'eval', $php ) );
		};
		try {
			$whole  = $create( self::SMALL );
			$chosen = $create( self::SMALL + [ 'sites' => [ 2 ] ] );
			$this->assertNotSame( '0', trim( WpSites::ok( 'n', 'eval', 'echo (int) wp_next_scheduled( "lw_migration_schedule", [ ' . var_export( $chosen, true ) . ' ] );' ) ), 'The main site plans the event.' );

			foreach ( [ $whole => [ 1, 2, 3 ], $chosen => [ 2 ] ] as $id => $blogs ) {
				WpSites::ok( 'n', 'lw-migration', 'schedule', 'run', (string) $id );
				$made = array_values( array_filter( array_diff( self::backups( 'n' ), $before ), static fn( string $name ): bool => str_ends_with( $name, '-' . $id . '.wpress' ) ) );
				$this->assertCount( 1, $made, (string) $id );
				$reader    = new Reader( WpSites::storage( 'n' ) . '/backups/' . $made[0] );
				$multisite = $reader->find( 'multisite.json' );
				$this->assertNotNull( $multisite, 'A network backup.' );
				$json = (array) json_decode( $reader->readContents( $multisite ), true );
				$reader->close();
				$this->assertSame( $blogs, array_map( 'intval', array_column( (array) $json['Sites'], 'BlogID' ) ), (string) $id );
			}

			[ $code, , $err ] = WpSites::wpUrl( 'n', 'http://net-n.test/b2/', 'lw-migration', 'schedule', 'list' );
			$this->assertSame( 1, $code );
			$this->assertStringContainsString( "Run this command on the network's main site", $err );
		} finally {
			$php = '$keep = ' . var_export( $before, true ) . '; $repo = ' . self::NS . 'Services::get()->backups();'
				. ' foreach ( $repo->all() as $b ) { if ( ! in_array( $b->name, $keep, true ) ) { $repo->delete( $b->name ); } }'
				. ' global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( "lw_migration_schedule_log_" ) . "%" ) ) as $o ) { delete_option( $o ); }'
				. ' foreach ( [ "lw_migration_schedules", "lw_migration_schedule_queue", "lw_migration_loopback" ] as $o ) { delete_option( $o ); }'
				. ' wp_unschedule_hook( "lw_migration_schedule" ); wp_unschedule_hook( "lw_migration_schedule_continue" );';
			WpSites::ok( 'n', 'eval', $php );
		}
	}

	private static function nextRun( string $id ): ?int {
		$next = trim( WpSites::ok( 'a', 'eval', 'echo (int) wp_next_scheduled( "lw_migration_schedule", [ ' . var_export( $id, true ) . ' ] );' ) );

		return '0' === $next ? null : (int) $next;
	}

	private static function logJob( string $id ): string {
		return trim( WpSites::ok( 'a', 'eval', 'echo ' . self::NS . 'Schedules\Log::last( ' . var_export( $id, true ) . ' )["job"] ?? "";' ) );
	}
}
