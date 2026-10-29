<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export;

use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class BackupPublisherTest extends MonkeyTestCase {

	private const NAME = 'site-20260101-000000-001.wpress';

	private const TEMP = '.lwm-0123456789abcdef.part';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		file_put_contents( $this->dir . '/' . self::TEMP, 'backup' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function withoutLinks(): BackupPublisher {
		return new BackupPublisher( $this->dir, static fn( string $from, string $to ): bool => false );
	}

	public function test_a_hard_link_is_always_tried_first(): void {
		$this->assertSame( BackupPublisher::DONE, ( new BackupPublisher( $this->dir ) )->publish( self::TEMP, self::NAME ) );
		$this->assertSame( 'backup', file_get_contents( $this->dir . '/' . self::NAME ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . self::TEMP );
	}

	public function test_without_hard_links_the_caller_saves_the_name_before_anything_is_created(): void {
		$this->assertSame( BackupPublisher::RESERVE, $this->withoutLinks()->publish( self::TEMP, self::NAME ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . self::NAME );

		$this->assertSame( BackupPublisher::DONE, $this->withoutLinks()->publishReserved( self::TEMP, self::NAME ) );
		$this->assertSame( 'backup', file_get_contents( $this->dir . '/' . self::NAME ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . self::TEMP );
	}

	public function test_a_reserved_name_accepts_only_an_empty_file(): void {
		touch( $this->dir . '/' . self::NAME );
		$this->assertSame( BackupPublisher::DONE, $this->withoutLinks()->publishReserved( self::TEMP, self::NAME ), 'Our empty reservation from a request that died.' );

		file_put_contents( $this->dir . '/' . self::TEMP, 'another' );
		$this->assertSame( BackupPublisher::TAKEN, $this->withoutLinks()->publishReserved( self::TEMP, self::NAME ) );
		$this->assertSame( 'backup', file_get_contents( $this->dir . '/' . self::NAME ), 'A name holding bytes is never replaced.' );
	}

	public function test_discard_removes_the_temp_and_an_empty_reservation_only(): void {
		touch( $this->dir . '/' . self::NAME );
		file_put_contents( $this->dir . '/other-20260101-000000-001.wpress', 'a backup' );
		$publisher = new BackupPublisher( $this->dir );

		$publisher->discard( self::TEMP, self::NAME );
		$publisher->discard( '', 'other-20260101-000000-001.wpress' );
		$publisher->discard( '', '../' . self::NAME );

		$this->assertSame( [ 'other-20260101-000000-001.wpress' ], array_values( array_diff( (array) scandir( $this->dir ), [ '.', '..' ] ) ) );
	}
}
