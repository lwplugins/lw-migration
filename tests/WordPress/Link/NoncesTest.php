<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use LightweightPlugins\Migration\Link\Nonces;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NoncesTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const LINK = '0123456789abcdef';

	private static function nonce( int $i ): string {
		return sprintf( '%032x', $i );
	}

	public function test_a_nonce_is_accepted_once_per_link(): void {
		FakeWpdb::install();
		$nonces = new Nonces();
		$this->assertSame( Nonces::OK, $nonces->remember( self::LINK, self::nonce( 1 ), self::NOW, self::NOW ) );
		$this->assertSame( Nonces::SEEN, $nonces->remember( self::LINK, self::nonce( 1 ), self::NOW, self::NOW + 200 ) );
		$this->assertSame( Nonces::OK, $nonces->remember( 'fedcba9876543210', self::nonce( 1 ), self::NOW, self::NOW ) );
	}

	public function test_a_nonce_is_kept_as_long_as_its_request_could_be_valid(): void {
		FakeWpdb::install();
		$nonces = new Nonces();
		$nonces->remember( self::LINK, self::nonce( 1 ), self::NOW - 250, self::NOW ); // A request from the past: kept until NOW + 300.
		$this->assertSame( Nonces::SEEN, $nonces->remember( self::LINK, self::nonce( 1 ), self::NOW - 250, self::NOW + 300 ) );
		$this->assertSame( 1, $nonces->prune( self::NOW + 301 ) );
	}

	public function test_a_full_cache_or_lost_races_are_busy_not_a_replay(): void {
		$db  = FakeWpdb::install();
		$all = [];
		for ( $i = 0; $i < Nonces::MAX; ++$i ) {
			$all[ self::LINK . ':' . self::nonce( $i ) ] = self::NOW + 300;
		}
		$db->rows[ Options::LINK_NONCES ] = [ 'value' => (string) json_encode( $all ), 'autoload' => 'off' ];
		$this->assertSame( Nonces::BUSY, ( new Nonces() )->remember( self::LINK, self::nonce( Nonces::MAX + 1 ), self::NOW, self::NOW ) );
		$this->assertSame( Nonces::OK, ( new Nonces() )->remember( self::LINK, self::nonce( Nonces::MAX + 1 ), self::NOW + 301, self::NOW + 301 ), 'Expired entries make room.' );

		$db              = FakeWpdb::install();
		$db->failWrites  = true;
		$this->assertSame( Nonces::BUSY, ( new Nonces() )->remember( self::LINK, self::nonce( 1 ), self::NOW, self::NOW ) );
	}

	public function test_a_broken_row_reads_as_empty(): void {
		$db                              = FakeWpdb::install();
		$db->rows[ Options::LINK_NONCES ] = [ 'value' => '{"x":1,"' . self::LINK . ':' . self::nonce( 1 ) . '":"soon"}', 'autoload' => 'off' ];
		$this->assertSame( Nonces::OK, ( new Nonces() )->remember( self::LINK, self::nonce( 1 ), self::NOW, self::NOW ) );
	}

	public function test_a_key_of_another_form_is_busy_and_writes_nothing(): void {
		$db = FakeWpdb::install();
		foreach ( [ [ 'XYZ', self::nonce( 1 ) ], [ self::LINK, 'zz' ], [ self::LINK . ':', self::nonce( 1 ) ] ] as [ $link, $nonce ] ) {
			$this->assertSame( Nonces::BUSY, ( new Nonces() )->remember( $link, $nonce, self::NOW, self::NOW ) );
		}
		$this->assertSame( [], $db->rows );
	}

	public function test_the_same_nonce_in_a_race_is_seen_once_and_accepted_once(): void {
		$db              = FakeWpdb::install();
		$db->beforeWrite = function (): void {
			$this->assertSame( Nonces::OK, ( new Nonces() )->remember( self::LINK, self::nonce( 1 ), self::NOW, self::NOW ) );
		};

		$this->assertSame( Nonces::SEEN, ( new Nonces() )->remember( self::LINK, self::nonce( 1 ), self::NOW, self::NOW ), 'The lost swap re-reads and finds the replay.' );
	}
}
