<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\PeerJobs;
use LightweightPlugins\Migration\Rest\JobsApi;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\WebDriver;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

/**
 * B's side of a connection: a real job store and site lock; the starts,
 * the owner check, the tick and the cancel are recording closures.
 */
final class PeerJobsTest extends MonkeyTestCase {

	private const NOW = 1000;

	private const LINK = [
		'id'        => '0123456789abcdef',
		'direction' => 'in',
		'allow'     => [ 'pull', 'push' ],
		'createdBy' => [
			'id'    => 7,
			'login' => 'boss',
		],
		'name'      => 'Site A',
		'address'   => 'https://a.example',
	];

	private const REF = 'aaaabbbbccccdddd';

	private string $dir;

	private JobStore $jobs;

	private SiteLock $lock;

	/** @var array<int, array<int, mixed>> */
	private array $imports = [];

	/** @var array<int, array<int, mixed>> */
	private array $exports = [];

	/** @var array<int, array<string, mixed>> */
	private array $owners = [];

	/** @var array<int, string> */
	private array $ticked = [];

	/** @var array<int, string> */
	private array $cancelled = [];

	/** @var array<int, string> Jobs past their point of no return. */
	private array $pastNoReturn = [];

	/** What the owner closure answers (null: the admin may no longer act). */
	private ?array $owner = [
		'id'    => 7,
		'login' => 'boss',
	];

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		$this->dir  = TempDir::create();
		$this->jobs = new JobStore( $this->dir . '/jobs' );
		$this->lock = new SiteLock();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function peer(): PeerJobs {
		return new PeerJobs(
			$this->jobs,
			$this->lock,
			function ( array $link, array $owner, array $input, int $now ): Job {
				$this->imports[] = [ $link, $owner, $input, $now ];

				return $this->started( 'import', [ 'upload', 'validate' ], [ 'link' => [ 'id' => $link['id'], 'ref' => $input['ref'], 'name' => $link['name'], 'address' => $link['address'] ] ] );
			},
			function ( array $link, array $input, int $now ): Job {
				$this->exports[] = [ $link, $input, $now ];

				return $this->started( 'export', ExportPipeline::LINK_STEPS, [ 'link' => [ 'id' => $link['id'], 'ref' => $input['ref'] ] ] );
			},
			function ( array $owner ): ?array {
				$this->owners[] = $owner;

				return $this->owner;
			},
			function ( string $id ): Job {
				$this->ticked[] = $id;
				$job            = $this->jobs->load( $id );
				$job->status    = Job::NEEDS_INPUT;
				$job->input     = [
					'kind' => 'upload',
					'data' => [ 'size' => 9000 ],
				];
				$this->jobs->save( $job );

				return $job;
			},
			function ( string $id ): void {
				$this->cancelled[] = $id;
			},
			fn( Job $job ): bool => in_array( $job->id, $this->pastNoReturn, true ),
			static fn(): int => self::NOW
		);
	}

	/**
	 * A job that holds the site lock, like JobStarter leaves it.
	 *
	 * @param array<int, string>   $steps Steps.
	 * @param array<string, mixed> $state State.
	 */
	private function started( string $type, array $steps, array $state ): Job {
		return JobStarter::start(
			$this->jobs,
			$this->lock,
			$type,
			$steps,
			[],
			self::NOW,
			function ( Job $job ) use ( $state ): void {
				$job->state = $state + [ 'site' => SiteFixture::make( '/s' )->toArray() ];
				$this->jobs->save( $job );
			}
		);
	}

	/**
	 * A job of a connection that does not hold the lock.
	 *
	 * @param array<string, mixed>|null $link state.link.
	 */
	private function job( string $type, array $steps, string $status, ?array $link ): Job {
		$job         = $this->jobs->create( $type, $steps, [], self::NOW );
		$job->status = $status;
		$job->state  = [ 'site' => SiteFixture::make( '/s' )->toArray() ] + ( null === $link ? [] : [ 'link' => $link ] );
		$this->jobs->save( $job );

		return $job;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function push( array $change = [] ): array {
		return array_merge(
			[
				'ref'          => self::REF,
				'name'         => 'site-a-20261010-101500-000.wpress',
				'size'         => 2 * Format::HEADER_SIZE,
				'safetyBackup' => true,
			],
			$change
		);
	}

	private function refused( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'A RestException was expected.' );
	}

	public function test_a_push_starts_an_import_for_the_links_admin(): void {
		$job = $this->peer()->startPush( self::LINK, self::push() );

		$this->assertCount( 1, $this->imports );
		[ $link, $owner, $input, $now ] = $this->imports[0];
		$this->assertSame( self::LINK, $link );
		$this->assertSame( [ 'id' => 7, 'login' => 'boss' ], $owner );
		$this->assertSame( self::push(), $input );
		$this->assertSame( self::NOW, $now );
		$this->assertSame( $this->lock->holder( self::NOW ), $job->id );
		$this->assertSame( [], $this->exports );
	}

	public function test_the_push_body_is_exact(): void {
		$cases = [
			'ref'          => [ [ 'ref' => null ], [ 'ref' => 'AAAABBBBCCCCDDDD' ], [ 'ref' => 'aaaabbbbccccddd' ], [ 'ref' => self::REF . "\n" ], [ 'ref' => 7 ] ],
			'name'         => [ [ 'name' => null ], [ 'name' => 'x/site.wpress' ], [ 'name' => 'site.zip' ], [ 'name' => '.wpress' ], [ 'name' => [ 'a' ] ] ],
			'size'         => [ [ 'size' => null ], [ 'size' => '9000' ], [ 'size' => 9000.0 ], [ 'size' => 2 * Format::HEADER_SIZE - 1 ], [ 'size' => -1 ] ],
			'safetyBackup' => [ [ 'safetyBackup' => null ], [ 'safetyBackup' => 1 ], [ 'safetyBackup' => 'true' ] ],
			'extra'        => [ [ 'extra' => 1 ] ],
		];
		foreach ( $cases as $field => $changes ) {
			foreach ( $changes as $change ) {
				$body = array_filter( self::push( $change ), static fn( $value ): bool => null !== $value );
				$e    = $this->refused( fn() => $this->peer()->startPush( self::LINK, $body ) );
				$this->assertSame( [ 400, 'lw_migration_invalid', $field ], [ $e->status, $e->errorCode, $e->data['field'] ?? null ], json_encode( $change ) );
			}
		}
		$this->assertSame( [], $this->imports, 'Nothing started.' );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_rights_are_checked_per_link(): void {
		$pull = $this->refused( fn() => $this->peer()->startPush( [ 'allow' => [ 'pull' ] ] + self::LINK, self::push() ) );
		$push = $this->refused( fn() => $this->peer()->startPull( [ 'allow' => [ 'push' ] ] + self::LINK, [ 'ref' => self::REF ] ) );

		foreach ( [ $pull, $push ] as $e ) {
			$this->assertSame( [ 403, 'lw_migration_link_not_allowed', 'This connection does not allow that on this site.' ], [ $e->status, $e->errorCode, $e->getMessage() ] );
		}
		$this->assertSame( [ [], [] ], [ $this->imports, $this->exports ] );

		$job = $this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] );
		$this->assertSame( [ [ self::LINK, [ 'ref' => self::REF ], self::NOW ] ], $this->exports );
		$this->assertSame( Job::PENDING, $job->status, 'A pull is not ticked by its start.' );
		$this->assertSame( [], $this->ticked );
		foreach ( [ [], [ 'ref' => 'x' ], [ 'ref' => self::REF, 'more' => 1 ], [ 'ref' => 7 ] ] as $body ) {
			$e = $this->refused( fn() => $this->peer()->startPull( self::LINK, $body ) );
			$this->assertSame( [ 400, 'ref' ], [ $e->status, $e->data['field'] ?? null ] );
		}
	}

	public function test_a_push_start_answers_a_job_waiting_for_its_upload(): void {
		$job = $this->peer()->startPush( self::LINK, self::push() );

		$this->assertSame( [ $job->id ], $this->ticked, 'The pending import is ticked once before the answer (C2).' );
		$this->assertSame( [ Job::NEEDS_INPUT, 'upload' ], [ $job->status, $job->input['kind'] ] );

		$this->assertSame( $job->id, $this->peer()->startPush( self::LINK, self::push() )->id );
		$this->assertSame( [ $job->id ], $this->ticked, 'One that already waits for the upload is not ticked again.' );

		$saved         = $this->jobs->load( $job->id );
		$saved->status = Job::PENDING;
		$saved->input  = null;
		$this->jobs->save( $saved );
		$this->assertSame( Job::NEEDS_INPUT, $this->peer()->startPush( self::LINK, self::push() )->status );
		$this->assertSame( [ $job->id, $job->id ], $this->ticked, 'A retried start that finds it pending ticks it too.' );
		$this->assertCount( 1, $this->imports );
	}

	public function test_an_owner_without_the_capability_stops_the_link(): void {
		$running = $this->job( 'import', [ 'upload' ], Job::RUNNING, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$done    = $this->job( 'export', ExportPipeline::LINK_STEPS, Job::DONE, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$this->owner = null;

		foreach ( [ fn() => $this->peer()->startPush( self::LINK, self::push() ), fn() => $this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] ) ] as $start ) {
			$e = $this->refused( $start );
			$this->assertSame( [ 403, 'lw_migration_link_owner' ], [ $e->status, $e->errorCode ] );
			$this->assertSame( 'The admin who allowed this connection can no longer use the plugin on this site. Pair the sites again.', $e->getMessage() );
		}
		$this->assertSame( [ [], [] ], [ $this->imports, $this->exports ], 'Nothing started.' );
		$this->assertSame( [], $this->cancelled );

		$e = $this->refused( fn() => $this->peer()->own( self::LINK, $running->id ) );
		$this->assertSame( [ 403, 'lw_migration_link_owner' ], [ $e->status, $e->errorCode ] );
		$this->assertSame( [ $running->id ], $this->cancelled, 'Every job route cancels the running job (I8).' );

		$this->refused( fn() => $this->peer()->own( self::LINK, $done->id ) );
		$this->assertSame( [ $running->id ], $this->cancelled, 'A finished job is not cancelled again.' );
	}

	public function test_the_owner_is_matched_by_id_and_login(): void {
		$this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] );
		$this->assertSame( [ [ 'id' => 7, 'login' => 'boss' ] ], $this->owners );

		Functions\when( 'get_userdata' )->alias(
			static fn( int $id ) => 7 === $id ? (object) [
				'ID'         => 7,
				'user_login' => 'other',
			] : false
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->assertNull( Permission::owner( [ 'id' => 7, 'login' => 'boss' ] ), 'User 7 is now called other.' );
		$this->assertSame( [ 'id' => 7, 'login' => 'other' ], Permission::owner( [ 'id' => 7, 'login' => 'other' ] ) );
		$this->assertNull( Permission::owner( [ 'id' => 8, 'login' => 'other' ] ), 'No user 8.' );
		$this->assertNull( Permission::owner( [ 'id' => '7', 'login' => 'other ' ] ), 'The login must match exactly.' );
		$this->assertNull( Permission::owner( [] ) );
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertNull( Permission::owner( [ 'id' => 7, 'login' => 'other' ] ), 'Without the capability.' );
	}

	public function test_jobs_a_connection_drives_are_named(): void {
		$links = new Links( new FakeWpdb(), new SecretBox( str_repeat( 'k', 32 ) ) );
		$links->add( [ 'pairedFrom' => '' ] + self::LINK, random_bytes( 32 ), time(), true );
		$admin = fn( array $owner ): ?array => $this->owner;
		$job   = new Job( '0123456789abcdef', 'import', [ 'upload' ] );
		$job->updatedAt = time(); // Saved just now (a job untouched for SiteLock::TTL is this site's).
		$this->assertNull( PeerJobs::drivenElsewhere( $job, $links, $admin ), 'A plain job.' );

		$job->state['link'] = [ 'id' => self::LINK['id'], 'name' => 'Site A', 'address' => 'https://a.example' ];
		$this->assertNull( PeerJobs::drivenElsewhere( $job, $links, $admin ), "This site's own push or pull has no ref." );

		$job->state['link']['ref'] = self::REF;
		$this->assertSame( 'Site A', PeerJobs::drivenElsewhere( $job, $links, $admin ) );
		$job->state['link']['name'] = '';
		$this->assertSame( 'https://a.example', PeerJobs::drivenElsewhere( $job, $links, $admin ), 'Without a name, the address.' );

		$this->owner = null;
		$this->assertNull( PeerJobs::drivenElsewhere( $job, $links, $admin ), 'The link cannot drive it any more: this site may finish it.' );
		$this->owner = [ 'id' => 7, 'login' => 'boss' ];
		$links->remove( self::LINK['id'] );
		$this->assertNull( PeerJobs::drivenElsewhere( $job, $links, $admin ), 'A removed link drives nothing.' );
	}

	public function test_a_job_the_link_let_go_of_or_stopped_driving_is_this_sites(): void {
		$links = new Links( new FakeWpdb(), new SecretBox( str_repeat( 'k', 32 ) ) );
		$links->add( [ 'pairedFrom' => '' ] + self::LINK, random_bytes( 32 ), time(), true );
		$admin              = fn( array $owner ): ?array => $this->owner;
		$job                = new Job( '0123456789abcdef', 'import', [ 'swap', 'done' ] );
		$job->status        = Job::RUNNING;
		$job->updatedAt     = time();
		$job->state['link'] = [
			'id'      => self::LINK['id'],
			'ref'     => self::REF,
			'name'    => 'Site A',
			'address' => 'https://a.example',
		];
		$this->assertSame( 'Site A', PeerJobs::drivenElsewhere( $job, $links, $admin ), 'Fresh: the link drives it.' );

		$job->state['swapped']        = true;
		$job->state['cancelDeferred'] = true; // What the link's release leaves on a swapped import.
		$this->assertTrue( Runner::cancelDeferred( $job ) );
		$this->assertNull( PeerJobs::drivenElsewhere( $job, $links, $admin ), 'Released past its swap: this site finishes it.' );

		unset( $job->state['cancelDeferred'] );
		$job->updatedAt = time() - SiteLock::TTL + 60;
		$this->assertSame( 'Site A', PeerJobs::drivenElsewhere( $job, $links, $admin ), 'Quiet for less than the lock time.' );
		$job->updatedAt = time() - SiteLock::TTL;
		$this->assertNull( PeerJobs::drivenElsewhere( $job, $links, $admin ), 'Not saved for the lock time: the link stopped driving it.' );
	}

	/**
	 * Fix round 1 (Critical): past the swap the code-maker usually has a new
	 * id, or may have been demoted; the link that pushed still drives the
	 * import to its end instead of stranding it with a deferred cancel.
	 */
	public function test_a_job_past_its_point_of_no_return_is_driven_to_done_by_its_link(): void {
		$swapped = $this->job( 'import', [ 'swap', 'done' ], Job::RUNNING, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$early   = $this->job( 'import', [ 'upload', 'swap' ], Job::RUNNING, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$this->pastNoReturn = [ $swapped->id ];
		$this->owner        = null;

		$this->assertSame( $swapped->id, $this->peer()->own( self::LINK, $swapped->id )->id );
		$this->assertSame( [], $this->cancelled, 'No I8 cancel past the point of no return.' );
		$e = $this->refused( fn() => $this->peer()->own( [ 'id' => 'fedcba9876543210' ] + self::LINK, $swapped->id ) );
		$this->assertSame( 404, $e->status, 'Only the link that started it.' );

		$e = $this->refused( fn() => $this->peer()->own( self::LINK, $early->id ) );
		$this->assertSame( [ 403, 'lw_migration_link_owner' ], [ $e->status, $e->errorCode ] );
		$this->assertSame( [ $early->id ], $this->cancelled, 'Before the swap the I8 refusal still cancels.' );
	}

	/**
	 * Review m5: a busy site is told before any folder, disk or backup check.
	 */
	public function test_a_busy_site_refuses_a_new_start_before_any_work(): void {
		$this->started( 'export', ExportPipeline::STEPS, [] );

		foreach ( [ fn() => $this->peer()->startPush( self::LINK, self::push() ), fn() => $this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] ) ] as $start ) {
			try {
				$start();
				$this->fail( 'A busy site refuses.' );
			} catch ( SiteBusyException $e ) {
				$this->assertSame( self::NOW + SiteLock::TTL, $e->expiresAt );
			}
		}
		$this->assertSame( [ [], [] ], [ $this->imports, $this->exports ], 'No start closure ran.' );
	}

	public function test_a_retried_start_answers_the_same_job(): void {
		$first = $this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] );

		$this->assertSame( $first->id, $this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] )->id );
		$this->assertCount( 1, $this->exports, 'The lost answer is answered again, no second job.' );

		$busy = [
			'another ref'                => fn() => $this->peer()->startPull( self::LINK, [ 'ref' => 'ffffffffffffffff' ] ),
			'another link'               => fn() => $this->peer()->startPull( [ 'id' => 'fedcba9876543210' ] + self::LINK, [ 'ref' => self::REF ] ),
			'a finished job of this ref' => function () use ( $first ): void {
				$saved         = $this->jobs->load( $first->id );
				$saved->status = Job::CANCELLED;
				$this->jobs->save( $saved );
				$this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] );
			},
		];
		foreach ( $busy as $label => $start ) {
			try {
				$start();
				$this->fail( $label . ': the site is busy.' );
			} catch ( SiteBusyException $e ) {
				$this->assertCount( 1, $this->exports, $label . ' starts nothing while the site is held.' );
			}
		}

		$this->lock->release( $first->id );
		$this->assertNotSame( $first->id, $this->peer()->startPull( self::LINK, [ 'ref' => self::REF ] )->id, 'A free site starts anew.' );
		$this->assertCount( 2, $this->exports );
	}

	public function test_jobs_of_other_links_are_not_found(): void {
		$mine  = $this->job( 'import', [ 'upload' ], Job::RUNNING, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$other = $this->job( 'import', [ 'upload' ], Job::RUNNING, [ 'id' => 'fedcba9876543210', 'ref' => self::REF ] );
		$plain = $this->job( 'export', ExportPipeline::STEPS, Job::RUNNING, null );
		$own   = $this->job( 'export', ExportPipeline::STEPS, Job::RUNNING, [ 'id' => self::LINK['id'] ] );

		$this->assertSame( $mine->id, $this->peer()->own( self::LINK, $mine->id )->id );
		foreach ( [ $other->id, $plain->id, $own->id, '1111111111111111', '0123456789ABCDEF' ] as $id ) {
			$e = $this->refused( fn() => $this->peer()->own( self::LINK, $id ) );
			$this->assertSame( [ 404, 'lw_migration_no_job', 'This job does not exist (any more).' ], [ $e->status, $e->errorCode, $e->getMessage() ], $id );
		}
		$this->assertSame( [], $this->cancelled );
	}

	public function test_the_archive_is_served_only_when_a_link_export_is_done(): void {
		$link    = [ 'id' => self::LINK['id'], 'ref' => self::REF ];
		$ready   = $this->job( 'export', ExportPipeline::LINK_STEPS, Job::DONE, $link );
		$running = $this->job( 'export', ExportPipeline::LINK_STEPS, Job::RUNNING, $link );
		$stored  = $this->job( 'export', ExportPipeline::STEPS, Job::DONE, $link );
		$missing = $this->job( 'export', ExportPipeline::LINK_STEPS, Job::DONE, $link );
		foreach ( [ $ready, $running, $stored ] as $job ) {
			file_put_contents( $this->jobs->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, str_repeat( 'x', 123 ) );
		}

		$backup = $this->peer()->archive( self::LINK, $ready->id );
		$this->assertEquals( new Backup( BackupName::forSite( 'https://old.test', self::NOW, 0 ), $this->jobs->dir( $ready->id ) . '/' . ExportFiles::ARCHIVE, 123, (int) filemtime( $backup->path ), Backup::OWN ), $backup );
		foreach ( [ $running, $stored, $missing ] as $job ) {
			$e = $this->refused( fn() => $this->peer()->archive( self::LINK, $job->id ) );
			$this->assertSame( [ 409, 'lw_migration_archive_not_ready' ], [ $e->status, $e->errorCode ] );
		}

		$api = $this->api();
		$this->assertSame( 123, $this->peer()->view( $api, $ready )['archiveBytes'] );
		$this->assertSame( $api->view( $ready ) + [ 'archiveBytes' => 123, 'archiveSha256' => null ], $this->peer()->view( $api, $ready ), 'No SHA-256 from FinalizeStep: none is told.' );
		$ready->state['archiveSha256'] = hash( 'sha256', 'finalized' );
		$this->assertSame( hash( 'sha256', 'finalized' ), $this->peer()->view( $api, $ready )['archiveSha256'], 'The value FinalizeStep worked out.' );
		foreach ( [ $running, $stored, $missing ] as $job ) {
			$this->assertNull( $this->peer()->view( $api, $job )['archiveBytes'] );
			$this->assertNull( $this->peer()->view( $api, $job )['archiveSha256'] );
		}
	}

	public function test_release_cancels_a_running_job_or_drops_a_ready_archive(): void {
		$link    = [ 'id' => self::LINK['id'], 'ref' => self::REF ];
		$running = $this->job( 'import', [ 'upload' ], Job::RUNNING, $link );
		$ready   = $this->job( 'export', ExportPipeline::LINK_STEPS, Job::DONE, $link );
		$import  = $this->job( 'import', [ 'upload' ], Job::DONE, $link );
		foreach ( [ $ready, $import ] as $job ) {
			file_put_contents( $this->jobs->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, 'archive' );
			file_put_contents( $this->jobs->dir( $job->id ) . '/' . ExportFiles::DATABASE, 'dump' );
		}
		$calls  = [];
		$cancel = static function ( string $id ) use ( &$calls ): array {
			$calls[] = $id;

			return [
				'job'            => null,
				'cancelDeferred' => true,
			];
		};

		$this->assertSame( [ 'released' => true, 'cancelDeferred' => true ], $this->peer()->release( self::LINK, $running->id, $cancel ) );
		$this->assertSame( [ $running->id ], $calls );

		$this->assertSame( [ 'released' => true, 'cancelDeferred' => false ], $this->peer()->release( self::LINK, $ready->id, $cancel ) );
		$this->assertFileDoesNotExist( $this->jobs->dir( $ready->id ) . '/' . ExportFiles::ARCHIVE );
		$this->assertFileDoesNotExist( $this->jobs->dir( $ready->id ) . '/' . ExportFiles::DATABASE );
		$this->assertFileExists( $this->jobs->dir( $ready->id ) . '/job.json' );

		$this->assertSame( [ 'released' => true, 'cancelDeferred' => false ], $this->peer()->release( self::LINK, $import->id, $cancel ) );
		$this->assertFileExists( $this->jobs->dir( $import->id ) . '/' . ExportFiles::ARCHIVE, 'An import keeps its files.' );
		$this->assertSame( [ $running->id ], $calls );
	}

	public function test_cancel_for_stops_only_that_links_unfinished_jobs(): void {
		$running = $this->job( 'import', [ 'upload' ], Job::RUNNING, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$this->job( 'export', ExportPipeline::LINK_STEPS, Job::DONE, [ 'id' => self::LINK['id'], 'ref' => self::REF ] );
		$this->job( 'import', [ 'upload' ], Job::RUNNING, [ 'id' => 'fedcba9876543210', 'ref' => self::REF ] );
		$this->job( 'export', [ 'link_export' ], Job::RUNNING, [ 'id' => self::LINK['id'] ] );
		$calls = [];

		$count = $this->peer()->cancelFor(
			self::LINK['id'],
			static function ( string $id ) use ( &$calls ): void {
				$calls[] = $id;
			}
		);

		$this->assertSame( 1, $count );
		$this->assertSame( [ $running->id ], $calls );
	}

	private function api(): JobsApi {
		return new JobsApi(
			$this->jobs,
			$this->lock,
			new Repository( $this->dir . '/own', $this->dir . '/ext' ),
			new MemorySecretStore(),
			new WebDriver( $this->jobs, $this->lock, fn( string $type ): Runner => new Runner( $this->jobs, [] ), static fn(): int => self::NOW, 30.0 ),
			static fn(): array => [],
			static fn(): int => self::NOW,
			[ $this->dir ]
		);
	}
}
