<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Estimate;

use LightweightPlugins\Migration\Export\Estimate\EstimateStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class EstimateStoreTest extends MonkeyTestCase {

	public function test_an_empty_store_answers_nothing_measured(): void {
		FakeWpdb::install();

		$this->assertSame(
			[
				'result'   => null,
				'scan'     => null,
				'lease'    => null,
				'attempts' => 0,
			],
			( new EstimateStore() )->get()
		);
	}

	public function test_a_lease_goes_to_one_request_until_it_finishes_or_expires(): void {
		$db    = FakeWpdb::install();
		$store = new EstimateStore();

		$first = $store->claim( 1000 );
		$this->assertNotNull( $first );
		$this->assertSame( 1000 + EstimateStore::LEASE, $first['lease']['until'] );
		$this->assertSame( 'off', $db->rows[ Options::EXPORT_ESTIMATE ]['autoload'], 'Never autoloaded.' );
		$this->assertNull( $store->claim( 1001 ), 'A second request never measures at the same time.' );

		$this->assertTrue( $store->finish( (string) $first['lease']['token'], [ 'measuredAt' => 1001 ], null ) );
		$this->assertSame( [ 'measuredAt' => 1001 ], $store->get()['result'] );
		$this->assertNull( $store->get()['lease'] );
		$this->assertNotNull( $store->claim( 1002 ), 'A finished request gives the lease back.' );
		$this->assertNotNull( $store->claim( 1002 + EstimateStore::LEASE ), 'The lease of a request that died expires.' );
	}

	public function test_attempts_count_slices_that_died_or_failed_until_one_ends_normally(): void {
		FakeWpdb::install();
		$store = new EstimateStore();

		$store->claim( 1000 );
		$this->assertSame( 0, $store->get()['attempts'], 'A first claim finds no dead request.' );
		$second = $store->claim( 1000 + EstimateStore::LEASE );
		$this->assertSame( 1, $store->get()['attempts'], 'An expired lease: its request died.' );
		$store->finish( (string) $second['lease']['token'], null, [ 'phase' => 'files' ], true );
		$this->assertSame( 2, $store->get()['attempts'], 'A failed slice counts too.' );
		$third = $store->claim( 2000 );
		$store->finish( (string) $third['lease']['token'], null, [ 'phase' => 'files' ] );

		$this->assertSame( 0, $store->get()['attempts'], 'A slice that ended normally starts the count over.' );
	}

	public function test_a_request_that_lost_its_lease_saves_nothing(): void {
		FakeWpdb::install();
		$store = new EstimateStore();
		$slow  = $store->claim( 1000 );
		$this->assertNotNull( $slow );
		$taker = $store->claim( 1000 + EstimateStore::LEASE );
		$this->assertNotNull( $taker );

		$this->assertFalse( $store->finish( (string) $slow['lease']['token'], [ 'measuredAt' => 5 ], [ 'phase' => 'files' ] ) );
		$this->assertNull( $store->get()['result'] );
		$this->assertSame( $taker['lease'], $store->get()['lease'] );
	}

	public function test_of_two_concurrent_claims_only_one_wins(): void {
		$db    = FakeWpdb::install();
		$store = new EstimateStore();
		$other = null;
		// The other request reads the empty row and inserts its lease just before this one writes.
		$db->beforeWrite = static function () use ( &$other ): void {
			$other = ( new EstimateStore() )->claim( 1000 );
		};

		$mine = $store->claim( 1000 );

		$this->assertNotNull( $other );
		$this->assertNull( $mine, 'The insert lost the race; the retry sees a live lease.' );
		$this->assertSame( $other['lease'], $store->get()['lease'] );
	}

	public function test_a_row_that_is_not_an_estimate_reads_as_empty(): void {
		FakeWpdb::install( [ Options::EXPORT_ESTIMATE => '{"lease":{"token":5,"until":"x"},"result":"y"}' ] );

		$state = ( new EstimateStore() )->get();

		$this->assertNull( $state['lease'] );
		$this->assertNull( $state['result'] );
	}

	public function test_forget_drops_the_estimate_and_the_slice_under_way_saves_nothing(): void {
		$db    = FakeWpdb::install();
		$store = new EstimateStore();
		$first = $store->claim( 1000 );
		$store->finish( (string) $first['lease']['token'], [ 'measuredAt' => 1000 ], null );
		$slice = $store->claim( 1000 + 3600 );
		$this->assertNotNull( $slice );

		$store->forget();

		$this->assertArrayNotHasKey( Options::EXPORT_ESTIMATE, $db->rows );
		$this->assertFalse( $store->finish( (string) $slice['lease']['token'], [ 'measuredAt' => 4600 ], null ), 'Its lease went with the row.' );
		$this->assertArrayNotHasKey( Options::EXPORT_ESTIMATE, $db->rows );
	}
}
