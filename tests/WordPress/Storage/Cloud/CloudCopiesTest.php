<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudCopiesTest extends MonkeyTestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
	}

	public function test_marks_forgets_and_drops_a_deleted_destination(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::PENDING, 1 );
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 2 );
		CloudCopies::mark( 'a.wpress', 'bbbb2222', CloudCopies::FAILED, 3 );
		CloudCopies::mark( 'b.wpress', 'bbbb2222', CloudCopies::UP, 4 );

		$this->assertSame( [ 'aaaa1111' => [ 'status' => 'up', 'at' => 2 ], 'bbbb2222' => [ 'status' => 'failed', 'at' => 3 ] ], CloudCopies::of( 'a.wpress' ) );
		CloudCopies::forget( 'a.wpress', 'aaaa1111' );
		CloudCopies::forgetDestination( 'bbbb2222' );
		$this->assertSame( [], CloudCopies::all() );
	}

	public function test_keeps_at_most_five_hundred_names_dropping_the_oldest(): void {
		for ( $i = 0; $i < CloudCopies::MAX + 3; $i++ ) {
			CloudCopies::mark( 'n' . $i . '.wpress', 'aaaa1111', CloudCopies::UP, $i );
		}

		$all = CloudCopies::all();
		$this->assertCount( CloudCopies::MAX, $all );
		$this->assertArrayNotHasKey( 'n0.wpress', $all );
		$this->assertArrayHasKey( 'n502.wpress', $all );
		$this->assertCount( CloudCopies::MAX, json_decode( (string) $this->db->value( Options::CLOUD_COPIES ), true ) );
		$this->assertSame( 'off', $this->db->rows[ Options::CLOUD_COPIES ]['autoload'] );
	}

	public function test_a_mark_keeps_a_copy_recorded_between_its_read_and_its_write(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::PENDING, 1 );
		$this->db->beforeWrite = static function (): void {
			CloudCopies::mark( 'b.wpress', 'bbbb2222', CloudCopies::UP, 2 );
		};

		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 3 );

		$names = array_keys( CloudCopies::all() );
		sort( $names );
		$this->assertSame( [ 'a.wpress', 'b.wpress' ], $names );
		$this->assertSame( 'up', CloudCopies::of( 'a.wpress' )['aaaa1111']['status'] );
	}

	public function test_forgetting_a_destination_keeps_a_copy_recorded_meanwhile(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 1 );
		$this->db->beforeWrite = static function (): void {
			CloudCopies::mark( 'b.wpress', 'bbbb2222', CloudCopies::UP, 2 );
		};

		CloudCopies::forgetDestination( 'aaaa1111' );

		$this->assertSame( [ 'b.wpress' ], array_keys( CloudCopies::all() ) );
	}

	public function test_reconcile_forgets_an_up_copy_the_listing_no_longer_shows(): void {
		CloudCopies::mark( 'gone.wpress', 'aaaa1111', CloudCopies::UP, 10 );
		CloudCopies::mark( 'kept.wpress', 'aaaa1111', CloudCopies::UP, 10 );

		CloudCopies::reconcile( 'aaaa1111', [ 'kept.wpress', 'notes.txt' ], 20 );

		$this->assertSame( [ 'kept.wpress' ], array_keys( CloudCopies::all() ) );
	}

	public function test_reconcile_keeps_a_copy_recorded_after_the_listing_was_taken(): void {
		CloudCopies::mark( 'late.wpress', 'aaaa1111', CloudCopies::UP, 20 );

		CloudCopies::reconcile( 'aaaa1111', [], 20 );

		$this->assertSame( 'up', CloudCopies::of( 'late.wpress' )['aaaa1111']['status'] );
	}

	public function test_reconcile_keeps_pending_and_failed_copies(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::PENDING, 1 );
		CloudCopies::mark( 'b.wpress', 'aaaa1111', CloudCopies::FAILED, 1 );

		CloudCopies::reconcile( 'aaaa1111', [], 20 );

		$this->assertSame( [ 'pending', 'failed' ], [ CloudCopies::of( 'a.wpress' )['aaaa1111']['status'], CloudCopies::of( 'b.wpress' )['aaaa1111']['status'] ] );
	}

	public function test_reconcile_leaves_other_destinations_alone(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 1 );
		CloudCopies::mark( 'a.wpress', 'bbbb2222', CloudCopies::UP, 1 );

		CloudCopies::reconcile( 'aaaa1111', [], 20 );

		$this->assertSame( [ 'bbbb2222' ], array_keys( CloudCopies::of( 'a.wpress' ) ) );
	}

	public function test_reconcile_writes_nothing_when_nothing_changes(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 1 );
		CloudCopies::mark( 'b.wpress', 'bbbb2222', CloudCopies::UP, 1 );
		$wrote                 = false;
		$this->db->beforeWrite = static function () use ( &$wrote ): void {
			$wrote = true;
		};

		CloudCopies::reconcile( 'aaaa1111', [ 'a.wpress' ], 20 );
		CloudCopies::reconcile( 'cccc3333', [], 20 );

		$this->assertFalse( $wrote );
	}
	public function test_reconcile_says_whether_it_forgot_a_copy(): void {
		CloudCopies::mark( 'gone.wpress', 'aaaa1111', CloudCopies::UP, 10 );

		$this->assertSame( [ true, false ], [ CloudCopies::reconcile( 'aaaa1111', [], 20 ), CloudCopies::reconcile( 'aaaa1111', [], 20 ) ] );
	}

	public function test_adopt_records_an_up_copy_only_where_the_destination_has_no_record(): void {
		CloudCopies::mark( 'failed.wpress', 'aaaa1111', CloudCopies::FAILED, 1 );
		CloudCopies::mark( 'pending.wpress', 'aaaa1111', CloudCopies::PENDING, 1 );
		CloudCopies::mark( 'other.wpress', 'bbbb2222', CloudCopies::UP, 1 );

		$added = CloudCopies::adopt( 'aaaa1111', [ 'failed.wpress', 'pending.wpress', 'other.wpress', 'new.wpress' ], 30 );

		$this->assertTrue( $added );
		$this->assertSame(
			[
				'failed.wpress'  => [ 'aaaa1111' => [ 'status' => 'failed', 'at' => 1 ] ],
				'pending.wpress' => [ 'aaaa1111' => [ 'status' => 'pending', 'at' => 1 ] ],
				'other.wpress'   => [
					'bbbb2222' => [ 'status' => 'up', 'at' => 1 ],
					'aaaa1111' => [ 'status' => 'up', 'at' => 30 ],
				],
				'new.wpress'     => [ 'aaaa1111' => [ 'status' => 'up', 'at' => 30 ] ],
			],
			CloudCopies::all()
		);
	}

	public function test_adopt_writes_nothing_when_every_name_has_a_record(): void {
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 1 );
		$wrote                 = false;
		$this->db->beforeWrite = static function () use ( &$wrote ): void {
			$wrote = true;
		};

		$this->assertFalse( CloudCopies::adopt( 'aaaa1111', [ 'a.wpress' ], 30 ) );
		$this->assertFalse( CloudCopies::adopt( 'aaaa1111', [], 30 ) );
		$this->assertFalse( $wrote );
	}
}
