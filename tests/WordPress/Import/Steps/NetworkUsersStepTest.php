<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Import\Steps\NetworkUsersStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Multisite\UserMerge;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeUserMerge;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkUsersStepTest extends MonkeyTestCase {

	private const TMP = 'lwtabcd_';

	private string $dir;

	private JobStore $store;

	private FakeUserMerge $merge;

	/** @var array<int, string> Base tables of the stand-in database. */
	private array $tables = [];

	/** @var array<int, string> Run ids forgotten. */
	private array $forgotten = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir    = TempDir::create();
		$this->store  = new JobStore( $this->dir . '/jobs' );
		$this->merge  = new FakeUserMerge();
		$this->tables = [ 'wp_users', 'wp_7_posts', self::TMP . 'lwmsrc_users', self::TMP . 'lwmsrc_usermeta', 'lwyabcd_lwm_run' ];
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_network_archive_skips_the_step(): void {
		$job                = $this->job();
		$job->state['kind'] = ArchiveKind::NETWORK;

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [], $this->merge->calls );
		$this->assertContains( self::TMP . 'lwmsrc_users', $this->tables );
	}

	public function test_without_staged_tables_the_step_ends(): void {
		$this->merge->staged = false;

		$this->assertTrue( $this->step()->run( $this->job(), new NoDeadline() )->isDone() );

		$this->assertSame( [], $this->merge->calls );
	}

	public function test_phases_run_in_order_and_the_cursor_is_saved_before_each_new_phase(): void {
		$job    = $this->job();
		$saved  = [];
		$this->merge->before = function ( string $call ) use ( $job, &$saved ): void {
			$saved[ $call ] = $this->store->load( $job->id )->cursor['phase'] ?? null;
		};

		$this->runToEnd( $job );

		$this->assertSame(
			[
				'emailClashes',
				'insertUsers',
				'shift wp_7_comments.user_id 6',
				'shift wp_7_posts.post_author 6',
				'remap wp_7_comments.user_id',
				'remap wp_7_posts.post_author',
				'unshift wp_7_comments.user_id',
				'unshift wp_7_posts.post_author',
				'sanitizeCapabilities',
				'insertUserMeta',
			],
			$this->merge->calls
		);
		$this->assertSame( 'shift', $saved['shift wp_7_comments.user_id 6'], 'Saved before the first shift.' );
		$this->assertSame( 'remap', $saved['remap wp_7_comments.user_id'], 'Saved before the first remap.' );
		$this->assertSame( 'unshift', $saved['unshift wp_7_comments.user_id'], 'Saved before the first unshift.' );
		$this->assertSame( 'meta', $saved['insertUserMeta'] );
		$this->assertSame( 'users', $saved['insertUsers'], 'The largest live id is saved before any user is added.' );
		$this->assertSame( [ [ 7 ], [ 'wp_7_posts', 'wp_7_comments' ] ], $this->merge->args['targets'], 'The mapped site ids and the tables the swap brought in.' );
		$this->assertSame( [ [ 2 => 7 ], [ 'old.test' => 'new.test:8080' ], 1, 'new.test:8080', 2 ], $this->merge->args['insertUserMeta'], 'Users above the saved live id 2 are the added ones.' );
	}

	public function test_a_replay_in_remap_never_shifts_again(): void {
		$job         = $this->job();
		$job->cursor = [
			'phase'   => 'remap',
			'index'   => 1,
			'targets' => [ [ 'wp_7_comments', 'user_id' ], [ 'wp_7_posts', 'post_author' ] ],
			'max'     => 6,
			'liveMax' => 2,
		];

		$this->runToEnd( $job );

		$this->assertSame( [ 'remap wp_7_posts.post_author', 'unshift wp_7_comments.user_id', 'unshift wp_7_posts.post_author', 'sanitizeCapabilities', 'insertUserMeta' ], $this->merge->calls );
		$this->assertSame( 2, $this->merge->args['insertUserMeta'][4] );
	}

	public function test_each_statement_moves_the_saved_cursor_on(): void {
		$job = $this->job();
		$this->step()->run( $job, new NoDeadline() ); // users.

		$result = $this->step()->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( [ 'emailClashes', 'insertUsers', 'shift wp_7_comments.user_id 6' ], $this->merge->calls, 'The deadline is checked between statements.' );
		$this->assertSame( [ 'shift', 1 ], [ $this->store->load( $job->id )->cursor['phase'], $this->store->load( $job->id )->cursor['index'] ] );
	}

	public function test_a_staged_id_above_the_offset_skips_renumbering_with_a_warning(): void {
		$this->merge->max = UserMerge::OFFSET;
		$job              = $this->job();

		$this->runToEnd( $job );

		$this->assertSame( [ 'emailClashes', 'insertUsers', 'sanitizeCapabilities', 'insertUserMeta' ], $this->merge->calls );
		$this->assertContains( 'User IDs in this archive are too large to renumber, so the authors of its posts may point to other users. Check the authors after the restore.', $job->warnings );
	}

	public function test_the_end_drops_the_staging_and_releases_the_run(): void {
		$job    = $this->job();
		$result = $this->runToEnd( $job );

		$this->assertSame( 'Users merged into the network.', $result->message() );
		$this->assertSame( [ 'wp_users', 'wp_7_posts' ], $this->tables, 'The held tables and then the run marker are gone; live tables stay.' );
		$this->assertSame( [ 'abcd' ], $this->forgotten );
	}

	public function test_a_failed_merge_is_a_warning_and_still_drops_the_staging(): void {
		$this->merge->failOn = [ 'insertUsers' ];
		$job                 = $this->job();

		$this->runToEnd( $job );

		$this->assertSame( [ 'emailClashes', 'insertUsers' ], $this->merge->calls, 'No renumbering and no meta after a failed user insert.' );
		$this->assertContains( 'The users of the archive could not be merged (database error 1205), so the restored sites\' authors may point to other users.', $job->warnings );
		$this->assertSame( [ 'wp_users', 'wp_7_posts' ], $this->tables );
	}

	public function test_a_failed_meta_copy_is_a_warning_and_still_drops_the_staging(): void {
		$this->merge->failOn = [ 'insertUserMeta' ];
		$job                 = $this->job();

		$this->runToEnd( $job );

		$this->assertContains( 'The users of the archive could not be merged (database error 1205), so the restored sites\' authors may point to other users.', $job->warnings );
		$this->assertSame( [ 'wp_users', 'wp_7_posts' ], $this->tables );
	}

	public function test_a_failed_renumbering_of_one_table_is_a_warning_and_goes_on(): void {
		$this->merge->failOn = [ 'remap' ];
		$job                 = $this->job();

		$this->runToEnd( $job );

		$this->assertContains( 'unshift wp_7_posts.post_author', $this->merge->calls );
		$this->assertContains( 'The user IDs in wp_7_comments could not be renumbered (database error 1205).', $job->warnings );
		$this->assertContains( 'The user IDs in wp_7_posts could not be renumbered (database error 1205).', $job->warnings );
	}

	public function test_a_live_user_id_above_the_offset_skips_renumbering(): void {
		$this->merge->live = [ 2, UserMerge::OFFSET ];
		$job               = $this->job();

		$this->runToEnd( $job );

		$this->assertSame( [ 'emailClashes', 'insertUsers', 'sanitizeCapabilities', 'insertUserMeta' ], $this->merge->calls );
		$this->assertContains( 'User IDs in this archive are too large to renumber, so the authors of its posts may point to other users. Check the authors after the restore.', $job->warnings );
	}

	public function test_a_table_holding_ids_above_the_offset_is_left_out(): void {
		$this->merge->shifted = [ 'wp_7_comments' ];
		$job                  = $this->job();

		$this->runToEnd( $job );

		$this->assertNotContains( 'shift wp_7_comments.user_id 6', $this->merge->calls );
		$this->assertContains( 'shift wp_7_posts.post_author 6', $this->merge->calls );
		$this->assertContains( 'The user IDs in wp_7_comments were not renumbered: the table already holds IDs outside the range this restore can renumber.', $job->warnings );
	}

	public function test_look_alike_logins_and_shared_profile_addresses_are_warned_once(): void {
		$this->merge->slugs   = [ 'newbie' => 'editor2' ];
		$this->merge->matches = [ 'Editor2' => 'editor2' ];
		$job                  = $this->job();

		$this->runToEnd( $job, 0 );

		$this->assertSame(
			[
				'The added user newbie has the same profile address name as the existing user editor2, so their author pages share an address. Change one of them.',
				'The archive\'s user Editor2 was taken as the existing user editor2, because this network treats the two logins as the same. Check that they are the same person.',
			],
			$job->warnings
		);
	}

	public function test_refused_roles_are_checked_before_the_meta_copy_and_named_once(): void {
		$this->merge->refused = [ 'newbie', 'editor2' ];
		$job                  = $this->job();

		$this->runToEnd( $job );

		$this->assertSame( [ 'sanitizeCapabilities', 'insertUserMeta' ], array_slice( $this->merge->calls, -2 ) );
		$this->assertSame( [ 'These users got no role on the restored sites, because the archive gave them roles those sites do not have or rights beyond a role: newbie, editor2. Give them their roles again under the site\'s Users.' ], $job->warnings );
	}

	public function test_email_clashes_are_warned_once(): void {
		$this->merge->clashes = [ 'newbie' => 'editor2' ];
		$job                  = $this->job();

		$this->runToEnd( $job, 0 );

		$this->assertSame( [ 'The added user newbie has the same e-mail address as the existing user editor2. Change one of them, so password resets reach the right user.' ], $job->warnings );
	}

	public function test_tables_are_trusted_only_once_the_swap_is_recorded(): void {
		$job = $this->job();
		unset( $job->state['swapped'] );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ [ 7 ], [] ], $this->merge->args['targets'] );
	}

	/**
	 * @param Job $job    Import job.
	 * @param int $checks Deadline checks allowed per call (-1: none).
	 */
	private function runToEnd( Job $job, int $checks = -1 ): StepResult {
		for ( $i = 0; $i < 30; $i++ ) {
			$result = $this->step()->run( $job, $checks < 0 ? new NoDeadline() : new CountdownDeadline( $checks ) );
			$this->store->save( $job );
			if ( $result->isDone() ) {
				return $result;
			}
		}
		$this->fail( 'The step did not end.' );
	}

	private function step(): NetworkUsersStep {
		return new NetworkUsersStep(
			$this->store,
			fn(): Connection => $this->connection(),
			function ( string $run ): bool {
				$this->forgotten[] = $run;

				return true;
			},
			fn(): UserMerge => $this->merge
		);
	}

	/** A database that lists $this->tables and drops from it. */
	private function connection(): Connection {
		$tables = &$this->tables;

		return new class( $tables ) implements Connection {

			/** @var array<int, string> */
			private array $tables;

			/** @param array<int, string> $tables Base tables (by reference). */
			public function __construct( array &$tables ) {
				$this->tables = &$tables;
			}

			public function query( string $sql ): array {
				return array_map( static fn( string $t ): array => [ 'Tables_in_db' => $t, 'Table_type' => 'BASE TABLE' ], $this->tables );
			}

			public function stream( string $sql, callable $onRow ): int {
				return 0;
			}

			public function execute( string $sql ): void {
				if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`\z/', $sql, $m ) ) {
					$this->tables = array_values( array_diff( $this->tables, [ $m[1] ] ) );
				}
			}

			public function escape( string $value ): string {
				return addslashes( $value );
			}

			public function serverVersion(): string {
				return '8.0.36';
			}

			public function variable( string $name ): ?string {
				return null;
			}

			public function databaseName(): string {
				return 'db';
			}

			public function host(): string {
				return 'localhost';
			}
		};
	}

	/** A sites archive's blog 2 restored as the new site 7 of https://new.test:8080, swapped in. */
	private function job(): Job {
		$target = TargetNetwork::fromArray(
			[
				'subdomain'  => false,
				'domain'     => 'new.test',
				'port'       => 8080,
				'path'       => '/',
				'scheme'     => 'https',
				'networkId'  => 1,
				'mainSiteId' => 1,
			]
		);
		$entry  = SiteEntry::fromArray(
			[
				'BlogID'  => 2,
				'HomeURL' => 'http://old.test/b2',
				'Domain'  => 'old.test',
				'Path'    => '/b2/',
			]
		);
		$map    = BlogMap::plan( ArchiveKind::SITES, [ $entry ], 'http://old.test', $target )->resolved(
			0,
			[
				'newId'      => 7,
				'created'    => true,
				'newHomeUrl' => 'https://new.test:8080/b2',
				'newSiteUrl' => 'https://new.test:8080/b2',
			]
		);
		$job    = ImportJobFixture::make( $this->store, $this->dir, '/a.wpress' );

		$job->state['target']        = $target->toArray();
		$job->state['kind']          = ArchiveKind::SITES;
		$job->state['blogs']         = $map->toArray();
		$job->state['hasDatabase']   = true;
		$job->state['tmpPrefix']     = self::TMP;
		$job->state['swapped']       = true;
		$job->state['swappedTables'] = [ 'wp_7_posts', 'wp_7_comments' ];
		$this->store->save( $job );

		return $job;
	}
}
