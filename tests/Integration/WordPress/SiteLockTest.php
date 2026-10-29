<?php
/**
 * The site lock is atomic on a real MySQL/MariaDB options table.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\WordPress;

use Brain\Monkey;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class SiteLockTest extends DatabaseTestCase {

	/** @var array<int, MysqliWpdb> */
	private array $links = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		FakeWpdb::stubCache();
		$this->exec( "CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')" );
	}

	protected function tearDown(): void {
		foreach ( $this->links as $link ) {
			$link->close();
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_two_acquires_on_a_free_lock_have_exactly_one_winner(): void {
		$first              = $this->link();
		$second             = $this->link();
		$secondWon          = null;
		$first->beforeWrite = static function () use ( $second, &$secondWon ): void {
			$secondWon = ( new SiteLock( $second ) )->acquire( 'second', 100 );
		};

		$firstWon = ( new SiteLock( $first ) )->acquire( 'first', 100 );

		$this->assertTrue( $secondWon );
		$this->assertFalse( $firstWon );
		$this->assertSame( 'second', ( new SiteLock( $this->link() ) )->holder( 100 ) );
		$this->assertSame( [ [ 'autoload' => 'off' ] ], self::db()->query( "SELECT autoload FROM wp_options WHERE option_name = '" . Options::LOCK . "'" ) );
	}

	public function test_takeover_loses_when_the_value_changed_after_it_was_read(): void {
		( new SiteLock( $this->link() ) )->acquire( 'old', 0 );
		$mine              = $this->link();
		$other             = $this->link();
		$mine->beforeWrite = static function () use ( $other ): void {
			( new SiteLock( $other ) )->acquire( 'other', SiteLock::TTL + 10 );
		};

		$this->assertFalse( ( new SiteLock( $mine ) )->acquire( 'mine', SiteLock::TTL + 10 ) );
		$this->assertSame( 'other', ( new SiteLock( $this->link() ) )->holder( SiteLock::TTL + 10 ) );
	}

	public function test_refresh_and_reacquire_in_the_same_second_hold_the_lock(): void {
		$lock = new SiteLock( $this->link() );
		$this->assertTrue( $lock->acquire( 'aaaa', 100 ) );
		$this->assertTrue( $lock->acquire( 'aaaa', 100 ) );
		$this->assertTrue( $lock->refresh( 'aaaa', 100 ) );
		$this->assertTrue( $lock->refresh( 'aaaa', 200 ) );
		$this->assertFalse( $lock->refresh( 'bbbb', 200 ) );
		$this->assertFalse( $lock->acquire( 'bbbb', 200 + SiteLock::TTL - 1 ) );
	}

	public function test_release_by_a_non_owner_is_a_no_op(): void {
		$lock = new SiteLock( $this->link() );
		$lock->acquire( 'aaaa', 100 );
		$lock->release( 'bbbb' );
		$this->assertSame( 'aaaa', $lock->holder( 101 ) );
	}

	public function test_release_keeps_a_lock_taken_over_after_it_was_read(): void {
		( new SiteLock( $this->link() ) )->acquire( 'aaaa', 0 );
		$owner              = $this->link();
		$other              = $this->link();
		$owner->beforeWrite = static function () use ( $other ): void {
			( new SiteLock( $other ) )->acquire( 'bbbb', SiteLock::TTL + 10 );
		};

		( new SiteLock( $owner ) )->release( 'aaaa' );
		$this->assertSame( 'bbbb', ( new SiteLock( $this->link() ) )->holder( SiteLock::TTL + 10 ) );
	}

	private function link(): MysqliWpdb {
		$link          = new MysqliWpdb();
		$this->links[] = $link;

		return $link;
	}
}
