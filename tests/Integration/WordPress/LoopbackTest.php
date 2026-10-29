<?php
/**
 * The loopback token opens once on a real MySQL/MariaDB options table.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\WordPress;

use Brain\Monkey;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Loopback;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class LoopbackTest extends DatabaseTestCase {

	private const JOB = '0123456789abcdef';

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

	public function test_two_interleaved_redeems_of_one_token_have_one_winner_and_remove_the_row(): void {
		$token              = (string) ( new Loopback( $this->link() ) )->issue( self::JOB, 1000 );
		$first              = $this->link();
		$second             = $this->link();
		$secondWon          = null;
		$first->beforeWrite = static function () use ( $second, $token, &$secondWon ): void {
			$secondWon = ( new Loopback( $second ) )->redeem( self::JOB, $token, 1001 );
		};

		$firstWon = ( new Loopback( $first ) )->redeem( self::JOB, $token, 1001 );

		$this->assertSame( [ true, false ], [ $secondWon, $firstWon ] );
		$this->assertSame( [], $this->row() );
	}

	public function test_a_token_issued_between_a_redeems_read_and_delete_wins(): void {
		$token              = (string) ( new Loopback( $this->link() ) )->issue( self::JOB, 1000 );
		$redeemer           = $this->link();
		$issuer             = $this->link();
		$fresh              = null;
		$redeemer->beforeWrite = static function () use ( $issuer, &$fresh ): void {
			$fresh = ( new Loopback( $issuer ) )->issue( self::JOB, 1000 );
		};

		$this->assertFalse( ( new Loopback( $redeemer ) )->redeem( self::JOB, $token, 1001 ), 'The old token was replaced before its delete.' );
		$this->assertIsString( $fresh );
		$this->assertTrue( ( new Loopback( $this->link() ) )->redeem( self::JOB, (string) $fresh, 1001 ) );
	}

	public function test_two_concurrent_issues_store_one_token_hashed_and_not_autoloaded(): void {
		$first              = $this->link();
		$second             = $this->link();
		$secondToken        = null;
		$first->beforeWrite = static function () use ( $second, &$secondToken ): void {
			$secondToken = ( new Loopback( $second ) )->issue( self::JOB, 1000 );
		};

		$firstToken = ( new Loopback( $first ) )->issue( self::JOB, 1000 );

		$this->assertNull( $firstToken );
		$this->assertIsString( $secondToken );
		$rows = $this->row();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'off', $rows[0]['autoload'] );
		$this->assertStringNotContainsString( (string) $secondToken, $rows[0]['option_value'] );
		$this->assertTrue( ( new Loopback( $this->link() ) )->redeem( self::JOB, (string) $secondToken, 1001 ) );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function row(): array {
		return self::db()->query( "SELECT autoload, option_value FROM wp_options WHERE option_name = '" . Options::LOOPBACK . "'" );
	}

	private function link(): MysqliWpdb {
		$link          = new MysqliWpdb();
		$this->links[] = $link;

		return $link;
	}
}
