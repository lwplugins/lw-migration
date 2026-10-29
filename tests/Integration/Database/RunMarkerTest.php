<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class RunMarkerTest extends DatabaseTestCase {

	public function test_a_run_id_is_reserved_once_for_the_whole_database(): void {
		$this->assertSame( 'lwyab12_lwm_run', RunMarker::name( 'lwtab12_' ) );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtab12_' ) );
		$this->assertFalse( RunMarker::reserve( self::db(), 'lwtab12_' ), 'Any site already holding it wins.' );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtcd34_' ) );
		$this->assertTrue( RunMarker::is( 'lwyab12_lwm_run' ) );
		$this->assertFalse( RunMarker::is( 'lwyab12_lwm_run2' ) );
		$this->assertFalse( RunMarker::is( 'lwtab12_lwm_run' ) );
	}

	public function test_the_marker_carries_the_token_of_the_job_that_reserved_it(): void {
		$token = RunMarker::newToken();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}\z/', $token );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtab12_', $token ) );

		$this->assertTrue( RunMarker::heldWith( self::db(), 'lwtab12_', $token ) );
		$this->assertFalse( RunMarker::heldWith( self::db(), 'lwtab12_', RunMarker::newToken() ), 'Another token: another job.' );
		$this->assertFalse( RunMarker::heldWith( self::db(), 'lwtcd34_', $token ), 'No marker.' );
		$this->assertFalse( RunMarker::heldWith( self::db(), 'lwtab12_', "x' OR '1'='1" ), 'Only real tokens are compared.' );
		$this->assertFalse( RunMarker::reserve( self::db(), 'lwtab12_', RunMarker::newToken() ) );
	}

	public function test_the_marker_goes_last_and_only_after_the_run_is_forgotten(): void {
		RunMarker::reserve( self::db(), 'lwtab12_' );
		$this->exec( 'CREATE TABLE lwoab12_posts (v INT)' );
		$forgot = [];
		$forget = static function ( string $run ) use ( &$forgot ): bool {
			$forgot[] = $run;
			return true;
		};

		$this->assertFalse( RunMarker::releaseIfUnused( self::db(), 'lwtab12_', $forget ), 'Other tables of the run are left.' );
		$this->assertSame( [], $forgot );
		$this->exec( 'DROP TABLE lwoab12_posts' );
		$this->assertFalse( RunMarker::releaseIfUnused( self::db(), 'lwtab12_', static fn( string $run ): bool => false ) );
		$this->assertSame( [ 'lwyab12_lwm_run' ], $this->left(), 'Not forgotten: the id stays reserved.' );

		$this->assertTrue( RunMarker::releaseIfUnused( self::db(), 'lwtab12_', $forget ) );
		$this->assertSame( [ 'ab12' ], $forgot );
		$this->assertSame( [], $this->left() );
	}

	public function test_the_swap_never_touches_the_marker(): void {
		$this->exec( 'CREATE TABLE wp_options (v INT)', 'CREATE TABLE lwtab12_options (v INT)', 'CREATE TABLE wbk_a (v INT)', 'CREATE TABLE lwxab12_wbk_a (v INT)' );
		RunMarker::reserve( self::db(), 'lwtab12_' );
		$swap = new TableSwap( self::db(), 'lwtab12_', 'wp_' );

		$swap->swap();
		$this->assertSame( [], $swap->tablesWithPrefix( 'lwyab12_' ), 'The swap does not see the marker.' );
		$swap->cleanup();

		$this->assertSame( [ 'lwyab12_lwm_run', 'wbk_a', 'wp_options' ], $this->left() );
	}

	/**
	 * @return array<int, string>
	 */
	private function left(): array {
		$left = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $left );

		return $left;
	}
}
