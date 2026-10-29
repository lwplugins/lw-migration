<?php
/**
 * The schedule queue changes by compare-and-swap on a real MySQL/MariaDB options table.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\WordPress;

use Brain\Monkey;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class QueueTest extends DatabaseTestCase {

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

	public function test_two_first_adds_at_once_both_land(): void {
		$first              = $this->link();
		$second             = $this->link();
		$secondAdded        = null;
		$first->beforeWrite = static function () use ( $second, &$secondAdded ): void {
			$secondAdded = ( new Queue( $second ) )->add( 'bbbbbbbb', 200 );
		};

		$firstAdded = ( new Queue( $first ) )->add( 'aaaaaaaa', 100 );

		$this->assertTrue( $firstAdded, 'The insert lost the race and was retried as a swap.' );
		$this->assertTrue( $secondAdded );
		$this->assertSame( [ 'aaaaaaaa', 'bbbbbbbb' ], array_column( ( new Queue( $this->link() ) )->all(), 'id' ) );
		$this->assertSame( [ [ 'autoload' => 'off' ] ], self::db()->query( "SELECT autoload FROM wp_options WHERE option_name = '" . Options::SCHEDULE_QUEUE . "'" ) );
	}

	public function test_a_change_read_before_another_write_is_never_lost(): void {
		( new Queue( $this->link() ) )->add( 'aaaaaaaa', 100 );
		$mine              = $this->link();
		$other             = $this->link();
		$mine->beforeWrite = static function () use ( $other ): void {
			( new Queue( $other ) )->remove( 'aaaaaaaa' );
			( new Queue( $other ) )->add( 'bbbbbbbb', 150 );
		};

		( new Queue( $mine ) )->add( 'cccccccc', 200 );

		$this->assertSame( [ 'bbbbbbbb', 'cccccccc' ], array_column( ( new Queue( $this->link() ) )->all(), 'id' ) );
	}

	public function test_the_same_schedule_queued_by_two_requests_waits_once(): void {
		$first              = $this->link();
		$second             = $this->link();
		$first->beforeWrite = static function () use ( $second ): void {
			( new Queue( $second ) )->add( 'aaaaaaaa', 100 );
		};

		$this->assertFalse( ( new Queue( $first ) )->add( 'aaaaaaaa', 200 ), 'The retry sees it already waiting.' );
		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => 100 ] ], ( new Queue( $this->link() ) )->all() );
	}

	private function link(): MysqliWpdb {
		$link          = new MysqliWpdb();
		$this->links[] = $link;

		return $link;
	}
}
