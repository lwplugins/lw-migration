<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\OverviewApi;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Repository as Schedules;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxType;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpType;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class OverviewApiTest extends MonkeyTestCase {

	private const SCHEDULE = 'abcd1234';

	private const HOST_KEY = 'SHA256:AbCdEfGhIjKlMnOpQrStUvWxYz0123456789+/AbCdE';

	private const CERT = '4F:9A:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:C2';

	private const S3_SECRET = 'plain-s3-secret-key-value-123';

	private const LINK_SECRET = 'link-secret-thirty-two-bytes-xyz';

	private const SEALED_PASSWORD = 'sealed-schedule-password-blob';

	private string $dir;

	private JobStore $jobs;

	private FakeWpdb $db;

	private int $now;

	private string $zone = 'UTC';

	/** @var array{path: string, problem: string|null, free: int|null} */
	private array $folder = [
		'path'    => '',
		'problem' => null,
		'free'    => 44200000000,
	];

	private bool $network = false;

	private ?string $holder = null;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		$this->db = FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->now = self::at( '2026-10-16 10:00:00' );
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		$this->jobs = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private static function at( string $utc ): int {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	private function api(): OverviewApi {
		return new OverviewApi(
			new Repository( $this->dir . '/own', '' ),
			$this->jobs,
			new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ new S3Type(), new SftpType(), new FtpType( null, static fn(): bool => true ), new DropboxType() ] ) ),
			new Schedules( fn(): int => $this->now ),
			$this->network ? null : new Links( $this->db, new SecretBox( str_repeat( 'a', 32 ) ) ),
			fn(): array => $this->folder,
			fn(): ?string => $this->holder,
			fn( string $id ): ?int => self::SCHEDULE === $id ? self::at( '2026-10-17 03:00:00' ) : null,
			fn(): array => [
				'now' => $this->now,
				'tz'  => new \DateTimeZone( $this->zone ),
			],
			[
				'host'    => 'croco2.example.test',
				'network' => $this->network,
			]
		);
	}

	private function backup( string $name, int $mtime, int $bytes = 10 ): void {
		$path = $this->dir . '/own/' . $name;
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
		touch( $path, $mtime );
		clearstatcache();
	}

	/**
	 * @param array<string, mixed> $changes Schedule fields.
	 */
	private function schedule( array $changes = [] ): void {
		$row = array_merge(
			( new Schedule( self::SCHEDULE, 'Nightly', true ) )->toArray(),
			[
				'password'     => self::SEALED_PASSWORD,
				'destinations' => [
					[
						'id'        => 'aaaa1111',
						'retention' => [
							'count' => 0,
							'bytes' => 0,
							'days'  => 0,
						],
					],
				],
				'retention'    => [
					'count' => 0,
					'bytes' => 0,
					'days'  => 7,
				],
			],
			$changes
		);
		FakeOptions::$values[ Options::SCHEDULES ] = [ $row ];
	}

	/**
	 * @param array<string, mixed> $entry Log entry fields.
	 */
	private function logRun( array $entry ): void {
		Log::add(
			self::SCHEDULE,
			$entry + [
				'trigger' => 'cron',
				'job'     => '',
				'backup'  => '',
				'size'    => 0,
			]
		);
	}

	private function profile( string $id, string $type, string $name, string $status = Profile::OK, string $message = '', array $settings = [] ): void {
		( new ProfileRepository( static fn(): int => 1000 ) )->save( new Profile( $id, $type, $name, $settings, [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], $status, $message, Profile::OK === $status ? self::at( '2026-10-16 03:12:00' ) : 0 ) );
	}

	/**
	 * @param array<string, mixed> $payload The payload.
	 * @return array<string, mixed> The day's entry.
	 */
	private static function day( array $payload, string $day ): array {
		return array_column( $payload['history'], null, 'day' )[ $day ];
	}

	private function seed(): void {
		$this->schedule();
		$this->profile( 'aaaa1111', 's3', 'Offsite', Profile::OK, '', [ 'bucket' => 'b', 'prefix' => 'site', 'preset' => 'r2', 'accountId' => 'acct' ] );
		$this->profile( 'bbbb2222', 'dropbox', 'Dropbox', Profile::REAUTH, 'The sign-in expired.' );
		( new SecretVault() )->put( 'aaaa1111', [ 'secretKey' => self::S3_SECRET ] );
		$this->backup( 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress', self::at( '2026-10-16 03:00:00' ), 30 );
		$this->backup( 'site-test-20261015-030000-001-' . self::SCHEDULE . '.wpress', self::at( '2026-10-15 03:00:00' ), 20 );
		$this->backup( 'site-test-20261014-120000-002.wpress', self::at( '2026-10-14 12:00:00' ), 25 );
		CloudCopies::mark( 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress', 'aaaa1111', CloudCopies::UP, self::at( '2026-10-16 03:12:00' ) );
		CloudCopies::mark( 'site-test-20261015-030000-001-' . self::SCHEDULE . '.wpress', 'aaaa1111', CloudCopies::UP, self::at( '2026-10-15 03:12:00' ) );
		$this->logRun( [ 'status' => 'done', 'startedAt' => self::at( '2026-10-15 03:00:00' ), 'endedAt' => self::at( '2026-10-15 03:12:00' ), 'backup' => 'site-test-20261015-030000-001-' . self::SCHEDULE . '.wpress', 'size' => 20 ] );
		$this->logRun( [ 'status' => 'done', 'startedAt' => self::at( '2026-10-16 03:00:00' ), 'endedAt' => self::at( '2026-10-16 03:12:00' ), 'backup' => 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress', 'size' => 30 ] );
		( new Links( $this->db, new SecretBox( str_repeat( 'a', 32 ) ) ) )->add(
			[
				'id'         => str_repeat( 'c', 16 ),
				'direction'  => Links::OUT,
				'address'    => 'https://dev2.example.test',
				'name'       => 'Dev two',
				'allow'      => [ Links::PULL ],
				'createdBy'  => [
					'id'    => 1,
					'login' => 'admin',
				],
				'pairedFrom' => '',
			],
			self::LINK_SECRET,
			$this->now,
			true
		);
	}

	public function test_the_payload_has_every_block_with_the_contract_keys(): void {
		$this->seed();

		$payload = $this->api()->view();

		$this->assertSame( [ 'site', 'last', 'running', 'days', 'history', 'attention', 'destinations', 'schedule', 'links', 'folder' ], array_keys( $payload ) );
		$this->assertSame( [ 'host' => 'croco2.example.test', 'network' => false ], $payload['site'] );
		$this->assertSame(
			[
				'name'     => 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress',
				'time'     => filemtime( $this->dir . '/own/site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress' ), // Patchwork may shift a touch() by a second.
				'size'     => 30,
				'files'    => null,
				'dbSize'   => null,
				'dbTables' => null,
				'cloud'    => [
					[
						'id'     => 'aaaa1111',
						'name'   => 'Offsite',
						'status' => 'ok',
					],
				],
				'local'    => true,
				'outcome'  => 'ok',
			],
			$payload['last']
		);
		$this->assertNull( $payload['running'] );
		$this->assertSame( [ 'window' => 30, 'covered' => 3, 'of' => 14 ], $payload['days'] );
		$this->assertCount( 30, $payload['history'] );
		$this->assertSame( '2026-09-17', $payload['history'][0]['day'] );
		$this->assertSame( '2026-10-16', $payload['history'][29]['day'] );
		$this->assertSame( [ [ 'size' => 25, 'state' => 'local', 'manual' => true ] ], self::day( $payload, '2026-10-14' )['backups'] );
		$this->assertSame( [ [ 'size' => 30, 'state' => 'cloud', 'manual' => false ] ], self::day( $payload, '2026-10-16' )['backups'] );
		$this->assertSame( [], self::day( $payload, '2026-10-13' )['backups'] );
		$this->assertSame( [ [ 'kind' => 'reauth', 'destination' => 'bbbb2222', 'text' => 'Dropbox needs a new sign-in: the connection expired, so uploads there are skipped.', 'action' => 'destinations' ] ], $payload['attention'] );
		$this->assertSame(
			[
				[
					'id'         => 'aaaa1111',
					'name'       => 'Offsite',
					'type'       => 's3',
					'where'      => 'b/site · Cloudflare R2',
					'status'     => 'ok',
					'lastUpload' => self::at( '2026-10-16 03:12:00' ),
					'count'      => 2,
					'preset'     => 'r2',
					'mode'       => null,
				],
				[
					'id'         => 'bbbb2222',
					'name'       => 'Dropbox',
					'type'       => 'dropbox',
					'where'      => 'Not connected yet',
					'status'     => 'reauth',
					'lastUpload' => null,
					'count'      => 0,
					'preset'     => null,
					'mode'       => null,
				],
			],
			$payload['destinations']
		);
		$this->assertSame(
			[
				'next'         => self::at( '2026-10-17 03:00:00' ),
				'enabled'      => true,
				'interval'     => 86400,
				'name'         => 'Nightly',
				'destinations' => [ 'Offsite' ],
				'keep'         => '7 days',
				'runs'         => [
					[
						'time'    => self::at( '2026-10-16 03:12:00' ),
						'outcome' => 'ok',
						'reason'  => null,
					],
					[
						'time'    => self::at( '2026-10-15 03:12:00' ),
						'outcome' => 'ok',
						'reason'  => null,
					],
				],
			],
			$payload['schedule']
		);
		$this->assertSame( [ [ 'id' => str_repeat( 'c', 16 ), 'host' => 'dev2.example.test', 'direction' => 'pull', 'incoming' => false, 'secure' => true, 'status' => 'ready' ] ], $payload['links'] );
		$this->assertSame( [ 'path' => '', 'protected' => true, 'free' => 44200000000 ], $payload['folder'] );
	}

	public function test_an_empty_site_has_empty_blocks_and_no_division_by_zero(): void {
		$payload = $this->api()->view();

		$this->assertNull( $payload['last'] );
		$this->assertNull( $payload['running'] );
		$this->assertSame( [ 'window' => 30, 'covered' => 0, 'of' => 14 ], $payload['days'] );
		$this->assertCount( 30, $payload['history'] );
		$this->assertSame( [], array_merge( ...array_column( $payload['history'], 'backups' ) ) );
		$this->assertSame( [], $payload['attention'] );
		$this->assertSame( [], $payload['destinations'] );
		$this->assertNull( $payload['schedule'], 'The stored list is read; no default schedule is created.' );
		$this->assertArrayNotHasKey( Options::SCHEDULES, FakeOptions::$values );
		$this->assertSame( [], $payload['links'] );
		$this->assertStringNotContainsString( 'NAN', strtoupper( (string) json_encode( $payload ) ) );
	}

	public function test_a_failed_run_shows_in_the_history_the_status_and_the_attention_list(): void {
		$this->seed();
		$this->logRun( [ 'status' => 'failed', 'startedAt' => self::at( '2026-10-16 06:00:00' ), 'endedAt' => self::at( '2026-10-16 06:05:00' ), 'error' => 'There was too little free space.' ] );

		$payload = $this->api()->view();

		$this->assertSame( [ [ 'size' => 30, 'state' => 'cloud', 'manual' => false ], [ 'size' => 0, 'state' => 'failed', 'manual' => false ] ], self::day( $payload, '2026-10-16' )['backups'] );
		$this->assertSame( 'failed', $payload['last']['outcome'], 'The newest attempt failed after the newest backup.' );
		$this->assertSame( 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress', $payload['last']['name'] );
		$this->assertSame( [ 'time' => self::at( '2026-10-16 06:05:00' ), 'outcome' => 'failed', 'reason' => 'There was too little free space.' ], $payload['schedule']['runs'][0] );
		$this->assertSame( [ 'failedRun', 'schedules' ], [ end( $payload['attention'] )['kind'], end( $payload['attention'] )['action'] ] );
		$this->assertSame( 3, $payload['days']['covered'], 'A failed run covers no day.' );
	}

	public function test_a_partial_upload_makes_the_last_backup_partial_with_the_failed_destination(): void {
		$this->seed();
		CloudCopies::mark( 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress', 'bbbb2222', CloudCopies::FAILED, self::at( '2026-10-16 03:13:00' ) );

		$last = $this->api()->view()['last'];

		$this->assertSame( 'partial', $last['outcome'] );
		$this->assertSame( [ 'ok', 'failed' ], array_column( $last['cloud'], 'status' ) );
	}

	public function test_days_follow_the_sites_time_zone(): void {
		$this->backup( 'site-test-20261015-223000-001.wpress', self::at( '2026-10-15 22:30:00' ) ); // 00:30 on the 16th in Budapest (UTC+2).
		$this->now = self::at( '2026-10-16 22:30:00' ); // Already the 17th in Budapest.

		$utc         = $this->api()->view();
		$this->zone  = 'Europe/Budapest';
		$budapest    = $this->api()->view();

		$this->assertSame( '2026-10-16', end( $utc['history'] )['day'] );
		$this->assertCount( 1, self::day( $utc, '2026-10-15' )['backups'] );
		$this->assertSame( '2026-10-17', end( $budapest['history'] )['day'] );
		$this->assertCount( 1, self::day( $budapest, '2026-10-16' )['backups'] );
		$this->assertSame( [], self::day( $budapest, '2026-10-15' )['backups'] );
	}

	public function test_no_secret_reaches_the_payload(): void {
		$this->seed();

		$json = (string) json_encode( $this->api()->view() );

		foreach ( [ self::S3_SECRET, self::LINK_SECRET, self::SEALED_PASSWORD, $this->dir ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		foreach ( [ '"secret', '"password', '"token', '"settings', '"secrets' ] as $key ) {
			$this->assertStringNotContainsString( $key, $json );
		}
	}

	public function test_a_fingerprint_waiting_for_confirmation_is_pending_with_its_kind(): void {
		$this->profile( 'cccc3333', 'sftp', 'SFTP box', Profile::ERROR, 'sftp://backup.example.test:22 shows the host key ' . self::HOST_KEY . ' (ssh-ed25519). Compare it with the key on your server, then confirm it to connect.', [ 'host' => 'backup.example.test' ] );
		$this->profile( 'dddd4444', 'ftp', 'FTPS box', Profile::ERROR, 'A tanúsítvány ujjlenyomata ' . self::CERT . ': hasonlítsd össze.', [ 'host' => 'ftp.example.test', 'security' => 'ftps' ] );
		$this->profile( 'eeee5555', 'sftp', 'Broken box', Profile::ERROR, 'The password was refused.', [ 'host' => 'other.example.test' ] );

		$payload = $this->api()->view();

		$this->assertSame( [ 'pending', 'pending', 'error' ], array_column( $payload['destinations'], 'status' ) );
		$this->assertSame( [ 'hostKey', 'certificate', 'error' ], array_column( $payload['attention'], 'kind' ) );
		$this->assertSame( [ 'cccc3333', 'dddd4444', 'eeee5555' ], array_column( $payload['attention'], 'destination' ) );
	}

	public function test_the_backups_folder_problem_and_low_space_are_flagged(): void {
		$this->backup( 'site-test-20261016-030000-001.wpress', self::at( '2026-10-16 03:00:00' ), 50 );
		$this->folder = [
			'path'    => '/srv/backups',
			'problem' => FolderRefusedException::UNPROTECTED,
			'free'    => 40,
		];

		$payload = $this->api()->view();

		$this->assertSame( [ 'folder', 'space' ], array_column( $payload['attention'], 'kind' ) );
		$this->assertSame( [ 'settings', 'backups' ], array_column( $payload['attention'], 'action' ) );
		$this->assertSame( [ 'path' => '/srv/backups', 'protected' => false, 'free' => 40 ], $payload['folder'] );
	}

	public function test_a_backup_kept_only_in_the_cloud_counts_from_the_run_log(): void {
		$this->schedule( [ 'keepLocal' => false ] );
		$this->profile( 'aaaa1111', 's3', 'Offsite' );
		$name = 'site-test-20261016-030000-001-' . self::SCHEDULE . '.wpress';
		CloudCopies::mark( $name, 'aaaa1111', CloudCopies::UP, self::at( '2026-10-16 03:12:00' ) );
		$this->logRun( [ 'status' => 'done', 'endedAt' => self::at( '2026-10-16 03:12:00' ), 'backup' => $name, 'size' => 77, 'cloud' => [ [ 'name' => 'Offsite', 'status' => 'ok', 'error' => '' ] ] ] );

		$payload = $this->api()->view();

		$this->assertSame( [ $name, 77, false, 'ok' ], [ $payload['last']['name'], $payload['last']['size'], $payload['last']['local'], $payload['last']['outcome'] ] );
		$this->assertSame( [ [ 'size' => 77, 'state' => 'cloud', 'manual' => false ] ], self::day( $payload, '2026-10-16' )['backups'] );
	}

	public function test_the_newest_backup_takes_its_counts_from_the_job_that_made_it(): void {
		$this->backup( 'site-test-20261016-030000-001.wpress', self::at( '2026-10-16 03:00:00' ) );
		$job                    = $this->jobs->create( 'export', [ 'init', 'store' ], [], $this->now );
		$job->status            = Job::DONE;
		$job->state['files']    = [ 'count' => 18412, 'bytes' => 5 ];
		$job->state['tables']   = [ 'tables' => [ 'wp_posts', 'wp_options' ], 'views' => [] ];
		$job->state['backup']   = [ 'name' => 'site-test-20261016-030000-001.wpress', 'size' => 10 ];
		$this->jobs->save( $job );

		$last = $this->api()->view()['last'];

		$this->assertSame( [ 18412, 2, null ], [ $last['files'], $last['dbTables'], $last['dbSize'] ] );
	}

	public function test_a_running_job_is_shown_with_its_share_of_the_work(): void {
		$job            = $this->jobs->create( 'export', [ 'init', 'files', 'database', 'store' ], [], $this->now );
		$job->status    = Job::RUNNING;
		$job->stepIndex = 1;
		$job->progress  = 68;
		$this->jobs->save( $job );
		$this->holder = $job->id;

		$this->assertSame( [ 'id' => $job->id, 'type' => 'export', 'progress' => 0.42 ], $this->api()->view()['running'] );
	}

	public function test_the_connections_are_hidden_on_a_network(): void {
		$this->network = true;

		$payload = $this->api()->view();

		$this->assertNull( $payload['links'] );
		$this->assertTrue( $payload['site']['network'] );
	}

	public function test_a_disabled_schedule_has_no_next_run_and_a_retention_text(): void {
		$this->schedule(
			[
				'enabled'   => false,
				'retention' => [
					'count' => 5,
					'bytes' => 0,
					'days'  => 0,
				],
			]
		);

		$schedule = $this->api()->view()['schedule'];

		$this->assertSame( [ null, false, 'the last 5 backups', [] ], [ $schedule['next'], $schedule['enabled'], $schedule['keep'], $schedule['destinations'] ] );
	}

	public function test_a_switched_on_schedule_without_a_planned_run_says_it_is_on(): void {
		$this->schedule( [ 'id' => 'dddd4444', 'frequency' => Schedule::WEEKLY ] );

		$schedule = $this->api()->view()['schedule'];

		$this->assertSame( [ null, true, 7 * 86400 ], [ $schedule['next'], $schedule['enabled'], $schedule['interval'] ] );
	}

	public function test_five_hundred_backups_answer_quickly(): void {
		$start = $this->now - 499 * 7200; // Every two hours up to now: every day of the last 14 has one.
		for ( $i = 0; $i < 500; ++$i ) {
			$time = $start + $i * 7200;
			$this->backup( sprintf( 'site-test-%s-%03d.wpress', gmdate( 'Ymd-His', $time ), $i % 1000 ), $time, 1 );
		}

		$began   = microtime( true );
		$payload = $this->api()->view();
		$took    = microtime( true ) - $began;

		$this->assertLessThan( 1.0, $took );
		$this->assertSame( 14, $payload['days']['covered'] );
	}
}
