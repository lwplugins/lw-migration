<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class SiteLockTest extends MonkeyTestCase {

	public function test_one_job_at_a_time_until_release_or_expiry(): void {
		$db   = FakeWpdb::install();
		$lock = new SiteLock();

		$this->assertTrue( $lock->acquire( 'aaaa', 100 ) );
		$this->assertSame( 'off', $db->rows[ Options::LOCK ]['autoload'] );
		$this->assertTrue( $lock->acquire( 'aaaa', 100 ), 'The holder may acquire again, also within the same second.' );
		$this->assertTrue( $lock->acquire( 'aaaa', 101 ) );
		$this->assertFalse( $lock->acquire( 'bbbb', 102 ) );
		$this->assertSame( 'aaaa', $lock->holder( 102 ) );

		$this->assertTrue( $lock->refresh( 'aaaa', 1000 ) );
		$this->assertTrue( $lock->refresh( 'aaaa', 1000 ), 'An unchanged refresh still holds the lock.' );
		$this->assertSame( 1000 + SiteLock::TTL, $this->stored( $db )['expires'] );
		$this->assertFalse( $lock->refresh( 'bbbb', 1000 ) );
		$this->assertFalse( $lock->acquire( 'bbbb', 1000 + SiteLock::TTL - 1 ) );
		$this->assertTrue( $lock->acquire( 'bbbb', 1000 + SiteLock::TTL + 1 ), 'An expired lock is taken over.' );

		$lock->release( 'aaaa' );
		$this->assertSame( 'bbbb', $lock->holder( 1000 + SiteLock::TTL + 2 ) );
		$lock->release( 'bbbb' );
		$this->assertNull( $lock->holder( 0 ) );
		$this->assertArrayNotHasKey( Options::LOCK, $db->rows );
	}

	public function test_expires_at_reports_an_active_lock_only(): void {
		FakeWpdb::install();
		$lock = new SiteLock();
		$this->assertNull( $lock->expiresAt( 100 ) );

		$lock->acquire( 'aaaa', 100 );
		$this->assertSame( 100 + SiteLock::TTL, $lock->expiresAt( 200 ) );
		$this->assertNull( $lock->expiresAt( 100 + SiteLock::TTL ), 'An expired lock frees the site.' );
	}

	public function test_garbage_option_counts_as_free(): void {
		FakeWpdb::install( [ Options::LOCK => 'garbage' ] );
		$this->assertTrue( ( new SiteLock() )->acquire( 'cccc', 1 ) );
		$this->assertSame( 'cccc', ( new SiteLock() )->holder( 2 ) );
	}

	public function test_a_concurrent_insert_wins_over_a_free_lock_acquire(): void {
		$db              = FakeWpdb::install();
		$db->beforeWrite = static function (): void {
			( new SiteLock() )->acquire( 'other', 100 );
		};

		$this->assertFalse( ( new SiteLock() )->acquire( 'mine', 100 ) );
		$this->assertSame( 'other', ( new SiteLock() )->holder( 100 ) );
	}

	public function test_takeover_loses_when_the_lock_changed_after_it_was_read(): void {
		$db              = FakeWpdb::install( [ Options::LOCK => (string) json_encode( [ 'job' => 'old', 'expires' => 10 ] ) ] );
		$db->beforeWrite = static function (): void {
			( new SiteLock() )->acquire( 'other', 100 );
		};

		$this->assertFalse( ( new SiteLock() )->acquire( 'mine', 100 ) );
		$this->assertSame( 'other', ( new SiteLock() )->holder( 100 ) );
	}

	public function test_release_by_another_job_keeps_the_lock(): void {
		$db   = FakeWpdb::install();
		$lock = new SiteLock();
		$lock->acquire( 'aaaa', 100 );
		$lock->release( 'bbbb' );
		$this->assertSame( 'aaaa', $lock->holder( 101 ) );
		$this->assertArrayHasKey( Options::LOCK, $db->rows );
	}

	public function test_every_write_drops_the_cached_option_and_its_not_found_entry(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
		Functions\expect( 'wp_cache_delete' )->times( 2 )->with( Options::LOCK, 'options' )->andReturn( true );
		Functions\when( 'wp_cache_get' )->alias(
			static function ( $key ) {
				return 'notoptions' === $key ? [ Options::LOCK => true, 'x' => true ] : false;
			}
		);
		Functions\expect( 'wp_cache_set' )->times( 2 )->with( 'notoptions', [ 'x' => true ], 'options' )->andReturn( true );

		$lock = new SiteLock();
		$lock->acquire( 'aaaa', 100 );
		$lock->release( 'aaaa' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored( FakeWpdb $db ): array {
		return (array) json_decode( (string) $db->value( Options::LOCK ), true );
	}
}
