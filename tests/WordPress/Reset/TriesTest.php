<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use LightweightPlugins\Migration\Reset\Tries;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class TriesTest extends MonkeyTestCase {

	private const NOW = 1791363600;

	public function test_five_failures_in_the_window_lock_for_the_lock_time(): void {
		FakeWpdb::install();
		$tries = new Tries();
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertTrue( $tries->reserve( 7, self::NOW + $i ) );
			$this->assertSame( 0, $tries->lockedFor( 7, self::NOW + $i ) );
		}
		$this->assertTrue( $tries->reserve( 7, self::NOW + 10 ) );
		$this->assertSame( 900, $tries->lockedFor( 7, self::NOW + 10 ) );
		$this->assertSame( 1, $tries->lockedFor( 7, self::NOW + 909 ) );
		$this->assertSame( 0, $tries->lockedFor( 7, self::NOW + 910 ) );
		$this->assertSame( 0, $tries->lockedFor( 8, self::NOW + 10 ), 'Per user.' );
	}

	public function test_failures_older_than_the_window_start_over(): void {
		FakeWpdb::install();
		$tries = new Tries();
		for ( $i = 0; $i < 4; $i++ ) {
			$tries->reserve( 7, self::NOW );
		}
		$tries->reserve( 7, self::NOW + 901 );
		$this->assertSame( 0, $tries->lockedFor( 7, self::NOW + 901 ) );
	}

	public function test_clear_forgets_the_user_and_the_row_stays_small(): void {
		$db    = FakeWpdb::install();
		$tries = new Tries();
		for ( $user = 1; $user <= 150; $user++ ) {
			$tries->reserve( $user, self::NOW );
		}
		$this->assertLessThanOrEqual( 100, count( (array) json_decode( (string) $db->value( 'lw_migration_reset_tries' ), true ) ) );
		$tries->reserve( 7, self::NOW );
		$tries->clear( 7 );
		$this->assertArrayNotHasKey( '7', (array) json_decode( (string) $db->value( 'lw_migration_reset_tries' ), true ) );
	}

	public function test_failures_of_other_accounts_never_push_out_a_lock(): void {
		FakeWpdb::install();
		$tries = new Tries();
		for ( $i = 0; $i < 5; $i++ ) {
			$tries->reserve( 7, self::NOW );
		}
		for ( $user = 100; $user < 300; $user++ ) {
			$this->assertTrue( $tries->reserve( $user, self::NOW + 1 ) );
		}
		$this->assertSame( 899, $tries->lockedFor( 7, self::NOW + 1 ) );
		$this->assertFalse( $tries->reserve( 7, self::NOW + 1 ), 'A locked user reserves nothing.' );
	}

	public function test_an_attempt_the_cap_would_drop_is_not_reserved(): void {
		$locked = [];
		for ( $user = 100; $user < 200; $user++ ) {
			$locked[ $user ] = [
				'n'     => 0,
				'first' => self::NOW,
				'until' => self::NOW + 900,
			];
		}
		FakeWpdb::install( [ 'lw_migration_reset_tries' => (string) json_encode( $locked ) ] );
		$tries = new Tries();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertFalse( $tries->reserve( 7, self::NOW ), 'A row full of locks leaves no room to count: refuse.' );
		}
	}

	public function test_a_lock_from_a_skewed_clock_never_lasts_longer_than_the_lock_time(): void {
		FakeWpdb::install(
			[
				'lw_migration_reset_tries' => (string) json_encode(
					[
						7 => [
							'n'     => 0,
							'first' => self::NOW + 86400,
							'until' => self::NOW + 86400,
						],
					]
				),
			]
		);
		$tries = new Tries();
		$this->assertSame( 900, $tries->lockedFor( 7, self::NOW ) );
		$this->assertTrue( $tries->reserve( 8, self::NOW ) );
		$this->assertSame( 900, $tries->lockedFor( 7, self::NOW ), 'A write keeps the lock, capped to the lock time.' );
		$this->assertSame( 0, $tries->lockedFor( 7, self::NOW + 900 ), 'And it ends after the lock time.' );
	}

	public function test_a_failure_that_loses_every_race_is_not_recorded(): void {
		$db             = FakeWpdb::install( [ 'lw_migration_reset_tries' => '{}' ] );
		$db->failWrites = true;
		$this->assertFalse( ( new Tries() )->reserve( 7, self::NOW ) );
	}

	public function test_a_garbled_row_counts_as_empty(): void {
		FakeWpdb::install( [ 'lw_migration_reset_tries' => 'not json' ] );
		$this->assertSame( 0, ( new Tries() )->lockedFor( 7, self::NOW ) );
		$this->assertTrue( ( new Tries() )->reserve( 7, self::NOW ) );
	}
}
