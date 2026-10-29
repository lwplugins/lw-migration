<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Export\Estimate\DatabaseSizes;
use LightweightPlugins\Migration\Export\Estimate\EstimateParts;
use LightweightPlugins\Migration\Export\Estimate\EstimateScan;
use LightweightPlugins\Migration\Export\Estimate\EstimateStore;
use LightweightPlugins\Migration\Export\Estimate\FileSizes;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\EstimateApi;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\SiteTree;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class EstimateApiTest extends MonkeyTestCase {

	private const FREE = 44200000000;

	private string $dir;

	private int $now = 1760600000;

	private int $prepared = 0;

	/** Runs inside each slice, before it measures (null: nothing). */
	private ?\Closure $onPrepare = null;

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		SiteTree::create( $this->dir );
		$this->wpdb = FakeWpdb::install();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_two_requests_with_time_enough_answer_the_whole_estimate(): void {
		$api = $this->api( 60.0 );
		$api->view();

		$view = $api->view();

		$this->assertTrue( $view['complete'] );
		$this->assertSame( $this->now, $view['measuredAt'] );
		$this->assertSame( self::FREE, $view['freeSpace'] );
		$this->assertSame( [], $view['partial'] );
		$this->assertSame( 'site', $view['scope'] );
		$this->assertSame( EstimateParts::KEYS, array_keys( $view['parts'] ) );
		$this->assertSame(
			[
				'bytes'        => 3300,
				'tables'       => 3,
				'spamComments' => 412,
				'revisions'    => 3270,
			],
			$view['parts']['database']
		);
		$this->assertSame( [ 'bytes' => 6, 'files' => 1 ], $view['parts']['media'] );
		$this->assertSame( 1, $view['parts']['plugins']['activeCount'] );
		$this->assertNull( $this->stored()['scan'], 'A finished measuring keeps only its result.' );
		$this->assertNull( $this->stored()['lease'] );
	}

	public function test_the_answer_carries_numbers_and_never_a_path(): void {
		$api = $this->api( 60.0 );
		$api->view();
		$view = $api->view();

		array_walk_recursive(
			$view,
			function ( $value, $key ): void {
				$this->assertTrue( is_int( $value ) || is_bool( $value ) || null === $value || 'scope' === $key, (string) $key );
			}
		);
		$this->assertStringNotContainsString( $this->dir, (string) json_encode( $view ) );
	}

	public function test_with_no_time_each_request_measures_a_slice_and_says_it_is_not_complete(): void {
		$api   = $this->api( 0.0 );
		$first = $api->view();

		$this->assertFalse( $first['complete'] );
		$this->assertNull( $first['measuredAt'], 'Nothing was finished yet.' );
		$this->assertNotNull( $first['parts']['database'], 'The database is sized first.' );
		$this->assertNull( $first['parts']['media'], 'The files are not counted yet: unknown, not zero.' );
		$requests = 1;
		do {
			$view = $api->view();
			++$requests;
		} while ( ! $view['complete'] && $requests < 100 );

		$this->assertTrue( $view['complete'] );
		$this->assertGreaterThan( 4, $requests );
		FakeWpdb::install();
		$long = $this->api( 60.0 );
		$long->view();
		$this->assertSame( $long->view()['parts'], $view['parts'], 'Slices add up to one long run.' );
	}

	public function test_the_database_part_is_saved_in_a_slice_of_its_own_before_the_file_walk(): void {
		$api = $this->api( 60.0 );

		$first = $api->view();

		$this->assertFalse( $first['complete'], 'The file walk is left to the next request.' );
		$this->assertSame( 3270, $first['parts']['database']['revisions'] );
		$this->assertSame( EstimateParts::none()['media'], $first['parts']['media'], 'The walk has not begun: unknown, not zero.' );
		$this->assertSame( 'files', $this->stored()['scan']['phase'] );
		$this->assertTrue( $api->view()['complete'], 'One more request.' );
	}

	public function test_a_walk_that_keeps_dying_keeps_the_database_part_measured_before_it(): void {
		$api = $this->api( 60.0 );
		$api->view();
		$store = new EstimateStore();
		$this->assertNotNull( $store->claim( $this->now ), 'The first slice of the walk is killed by the host.' );
		$this->now += EstimateStore::LEASE;
		$this->assertNotNull( $store->claim( $this->now ), 'So is the next one.' );
		$this->now += EstimateStore::LEASE;

		$view = $api->view();

		$this->assertTrue( $view['complete'] );
		$this->assertSame( EstimateParts::FILE_KEYS, $view['partial'] );
		$this->assertSame( 412, $view['parts']['database']['spamComments'], 'Not "could not be counted": it was measured.' );
	}

	public function test_spam_and_revision_counts_are_null_until_every_table_is_counted(): void {
		$first = $this->api( 0.0 )->view();

		$this->assertSame( 3300, $first['parts']['database']['bytes'], 'The size is known after the size query.' );
		$this->assertNull( $first['parts']['database']['spamComments'], 'Not counted yet: unknown, not 0.' );
		$this->assertNull( $first['parts']['database']['revisions'] );
	}

	public function test_a_request_that_finds_another_one_measuring_answers_at_once(): void {
		$this->assertNotNull( ( new EstimateStore() )->claim( $this->now ) );

		$view = $this->api( 60.0 )->view();

		$this->assertSame( 0, $this->prepared, 'It never measures alongside the other request.' );
		$this->assertFalse( $view['complete'] );
		$this->assertSame( EstimateParts::none(), $view['parts'] );
	}

	public function test_a_fresh_result_is_answered_without_measuring(): void {
		$this->seed( $this->now - 60 );

		$view = $this->api( 60.0 )->view();

		$this->assertSame( 0, $this->prepared );
		$this->assertTrue( $view['complete'] );
		$this->assertSame( $this->now - 60, $view['measuredAt'] );
		$this->assertSame( [ 'bytes' => 1 ], $view['parts']['other'] );
	}

	public function test_a_stale_result_is_answered_while_it_is_measured_again(): void {
		$this->seed( $this->now - EstimateApi::STALE_AFTER );
		$api = $this->api( 0.0 );

		$view = $api->view();

		$this->assertSame( 1, $this->prepared );
		$this->assertFalse( $view['complete'] );
		$this->assertSame( $this->now - EstimateApi::STALE_AFTER, $view['measuredAt'] );
		$this->assertSame( [ 'bytes' => 1 ], $view['parts']['other'], 'The last result until the new one is finished.' );
		$this->assertNotNull( $this->stored()['scan'] );
		do {
			$view = $api->view();
		} while ( ! $view['complete'] && $this->prepared < 100 );
		$this->assertSame( $this->now, $view['measuredAt'] );
		$this->assertNotSame( [ 'bytes' => 1 ], $view['parts']['other'] );
	}

	public function test_a_measuring_left_alone_too_long_starts_over(): void {
		$api = $this->api( 0.0 );
		$api->view();
		$started    = $this->stored()['scan']['startedAt'];
		$this->now += EstimateApi::STALE_AFTER;

		$api->view();

		$this->assertSame( $this->now, $this->stored()['scan']['startedAt'] );
		$this->assertNotSame( $started, $this->stored()['scan']['startedAt'] );
	}

	public function test_a_failed_slice_gives_the_lease_back_and_keeps_what_was_stored(): void {
		$this->seed( $this->now - EstimateApi::STALE_AFTER );
		$before = $this->wpdb->value( Options::EXPORT_ESTIMATE );
		$api    = new EstimateApi(
			new EstimateStore(),
			$this->scan(),
			static function (): void {
				throw new \RuntimeException( 'The storage folder cannot be written.' );
			},
			fn() => SiteFixture::make( $this->dir ),
			static fn(): ?int => null,
			fn(): int => $this->now,
			false
		);

		try {
			$api->view();
			$this->fail( 'The failure reaches the route, which answers an error.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The storage folder cannot be written.', $e->getMessage() );
		}

		$this->assertNull( $this->stored()['lease'] );
		$this->assertSame( json_decode( (string) $before, true )['result'], $this->stored()['result'] );
	}

	public function test_a_database_that_cannot_be_read_leaves_only_its_part_unknown(): void {
		$storage = $this->dir . '/wp-content/lw-migration-0123456789ab';
		$scan    = new EstimateScan(
			new FileSizes( $storage . '/jobs/' . EstimateApi::WORK_DIR, $storage ),
			static function (): DatabaseSizes {
				throw new DatabaseException( 'The site uses a database drop-in.' );
			}
		);
		$api     = new EstimateApi( new EstimateStore(), $scan, static function (): void {}, fn() => SiteFixture::make( $this->dir ), static fn(): ?int => null, fn(): int => $this->now, false, 60.0 );

		$view = $api->view();

		$this->assertTrue( $view['complete'] );
		$this->assertNull( $view['parts']['database'] );
		$this->assertSame( [ 'bytes' => 6, 'files' => 1 ], $view['parts']['media'] );
	}

	public function test_after_two_slices_died_the_unfinished_parts_are_given_up(): void {
		$api = $this->api( 0.0 );
		do {
			$view = $api->view();
		} while ( null === $view['parts']['media'] && $this->prepared < 100 );
		$this->assertFalse( $view['complete'], 'The file walk is under way.' );
		$store = new EstimateStore();
		$this->assertNotNull( $store->claim( $this->now ), 'A slice that the host kills: it never saves.' );
		$this->now += EstimateStore::LEASE;
		$this->assertNotNull( $store->claim( $this->now ), 'Its lease expired; the next slice dies too.' );
		$this->now += EstimateStore::LEASE;
		$prepared = $this->prepared;

		$view = $api->view();

		$this->assertSame( $prepared, $this->prepared, 'No third try at the same folder.' );
		$this->assertTrue( $view['complete'], 'Finished: "could not be counted", not "still counting".' );
		$this->assertSame( EstimateParts::FILE_KEYS, $view['partial'] );
		foreach ( EstimateParts::FILE_KEYS as $key ) {
			$this->assertNull( $view['parts'][ $key ], $key );
		}
		$this->assertSame( 3270, $view['parts']['database']['revisions'], 'What was finished is kept.' );
		$this->assertSame( $this->now, $view['measuredAt'] );
		$this->assertSame( 0, $this->stored()['attempts'] );
		$this->assertSame( [], glob( $this->dir . '/wp-content/lw-migration-0123456789ab/jobs/estimate/*' ), 'Its work files are removed.' );
		$this->assertTrue( $api->view()['complete'], 'Not measured again until the result is stale.' );
	}

	public function test_slices_that_fail_twice_are_given_up_as_well(): void {
		$this->onPrepare = static function (): void {
			throw new \RuntimeException( 'The storage folder cannot be written.' );
		};
		$api             = $this->api( 0.0 );
		foreach ( [ 1, 2 ] as $try ) {
			try {
				$api->view();
				$this->fail( 'The failure reaches the route.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( $try, $this->stored()['attempts'] );
			}
		}

		$view = $api->view();

		$this->assertTrue( $view['complete'] );
		$this->assertSame( EstimateParts::KEYS, $view['partial'], 'Nothing was measured: every part is unknown.' );
		$this->assertSame( EstimateParts::none(), $view['parts'] );
	}

	public function test_a_slice_that_lost_its_lease_saves_nothing_and_answers_what_is_stored(): void {
		$taken           = null;
		$this->onPrepare = function () use ( &$taken ): void {
			// The slice runs past its lease; another request takes over.
			$taken = ( new EstimateStore() )->claim( $this->now + EstimateStore::LEASE );
		};

		$view = $this->api( 60.0 )->view();

		$this->assertNotNull( $taken );
		$this->assertFalse( $view['complete'], 'Its finished result was never stored, so it is not answered.' );
		$this->assertSame( EstimateParts::none(), $view['parts'] );
		$stored = $this->stored();
		$this->assertNull( $stored['result'] );
		$this->assertNull( $stored['scan'] );
		$this->assertSame( $taken['lease'], $stored['lease'] );
		$this->assertSame( 1, $stored['attempts'], 'The request that took over counted the slow one as dead.' );
	}

	public function test_on_a_network_the_estimate_is_for_the_whole_network(): void {
		$api = new EstimateApi( new EstimateStore(), $this->scan(), static function (): void {}, fn() => SiteFixture::make( $this->dir ), static fn(): ?int => null, fn(): int => $this->now, true, 60.0 );

		$view = $api->view();

		$this->assertSame( 'network', $view['scope'] );
		$this->assertNull( $view['freeSpace'], 'Unknown when the host does not tell.' );
	}

	private function api( float $budget ): EstimateApi {
		return new EstimateApi(
			new EstimateStore(),
			$this->scan(),
			function (): void {
				++$this->prepared;
				if ( null !== $this->onPrepare ) {
					( $this->onPrepare )();
				}
			},
			fn() => SiteFixture::make( $this->dir ),
			static fn(): ?int => self::FREE,
			fn(): int => $this->now,
			false,
			$budget
		);
	}

	private function scan(): EstimateScan {
		$db       = new FakeConnection();
		$db->rows = [
			'SHOW FULL TABLES'       => [ [ 'Tables_in_fake' => 'wp_options' ], [ 'Tables_in_fake' => 'wp_posts' ], [ 'Tables_in_fake' => 'wp_comments' ] ],
			'SELECT TABLE_NAME AS n' => [
				[ 'n' => 'wp_options', 'b' => '1000' ],
				[ 'n' => 'wp_posts', 'b' => '2000' ],
				[ 'n' => 'wp_comments', 'b' => '300' ],
			],
			'SELECT COUNT(*) AS n FROM `wp_comments`' => [ [ 'n' => '412' ] ],
			'SELECT COUNT(*) AS n FROM `wp_posts`'    => [ [ 'n' => '3270' ] ],
		];
		$storage  = $this->dir . '/wp-content/lw-migration-0123456789ab';

		return new EstimateScan( new FileSizes( $storage . '/jobs/' . EstimateApi::WORK_DIR, $storage ), static fn(): DatabaseSizes => new DatabaseSizes( $db ) );
	}

	/**
	 * Stores a finished result measured at $time.
	 */
	private function seed( int $time ): void {
		$parts          = EstimateParts::none();
		$parts['other'] = [ 'bytes' => 1 ];
		FakeWpdb::install(
			[
				Options::EXPORT_ESTIMATE => (string) json_encode(
					[
						'result' => [
							'parts'      => $parts,
							'measuredAt' => $time,
						],
						'scan'   => null,
						'lease'  => null,
					]
				),
			]
		);
		$this->wpdb = $GLOBALS['wpdb'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return ( new EstimateStore() )->get();
	}
}
