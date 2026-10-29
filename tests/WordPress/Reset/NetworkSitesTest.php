<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Reset\NetworkSites;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkSitesTest extends MonkeyTestCase {

	public function test_sites_removed_between_two_requests_never_make_it_skip_one(): void {
		$db          = FakeWpdb::install();
		$db->blogIds = range( 1, 250 );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$job  = new Job( str_repeat( 'a', 16 ), 'reset', [ 'reset_plugins' ] );
		$seen = [];
		$each = static function ( int $id ) use ( &$seen ): void {
			$seen[] = $id;
		};
		$this->assertFalse( NetworkSites::advance( $job, 'sites', $each, new CountdownDeadline( 49 ) ) );
		$this->assertSame( range( 1, 50 ), $seen );
		$db->blogIds = array_values( array_diff( range( 1, 260 ), range( 10, 20 ) ) ); // Sites removed below, sites added above.
		$this->assertTrue( NetworkSites::advance( $job, 'sites', $each, new NoDeadline() ) );
		$this->assertSame( range( 1, 260 ), $seen );
		$this->assertTrue( NetworkSites::advance( $job, 'sites', $each, new NoDeadline() ), 'Done stays done.' );
		$this->assertCount( 260, $seen );
	}

	public function test_a_site_its_work_did_not_finish_is_not_passed(): void {
		$job   = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_media' ] );
		$calls = [];
		$left  = [ 1 => 1, 2 => 3 ]; // Calls each site needs to finish.
		$each  = static function ( int $id ) use ( &$calls, &$left ): bool {
			$calls[] = $id;
			return --$left[ $id ] <= 0;
		};
		$page  = static fn( int $after ): array => array_values( array_filter( [ 1, 2 ], static fn( int $id ): bool => $id > $after ) );
		$this->assertFalse( NetworkSites::advance( $job, 'sites', $each, new NoDeadline(), $page ) );
		$this->assertSame( [ 'last' => 1, 'done' => false ], $job->cursor['sites'], 'Site 2 is still in progress.' );
		$this->assertFalse( NetworkSites::advance( $job, 'sites', $each, new NoDeadline(), $page ) );
		$this->assertTrue( NetworkSites::advance( $job, 'sites', $each, new NoDeadline(), $page ) );
		$this->assertSame( [ 1, 2, 2, 2 ], $calls );
	}
}
