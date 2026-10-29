<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LinksTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private function links( string $key = 'k' ): Links {
		return new Links( null, new SecretBox( str_repeat( $key, 32 ) ) );
	}

	/**
	 * @return array{id: string, direction: string, address: string, name: string, allow: array<int, string>, createdBy: array{id: int, login: string}, pairedFrom: string}
	 */
	private static function link( string $id = '0123456789abcdef', string $direction = Links::IN ): array {
		return [
			'id'        => $id,
			'direction' => $direction,
			'address'   => 'https://a.example',
			'name'      => 'Site A',
			'allow'      => [ 'push', 'pull', 'push' ],
			'createdBy'  => [ 'id' => 7, 'login' => 'boss' ],
			'pairedFrom' => '203.0.113.5',
		];
	}

	public function test_the_secret_is_stored_sealed_and_never_listed(): void {
		$db     = FakeWpdb::install();
		$secret = random_bytes( 32 );
		$this->links()->add( self::link(), $secret, self::NOW, true );

		$raw = (string) $db->value( Options::LINKS );
		$this->assertStringNotContainsString( bin2hex( $secret ), $raw );
		$this->assertStringNotContainsString( base64_encode( $secret ), $raw );
		$this->assertSame( 'off', $db->rows[ Options::LINKS ]['autoload'] );
		$all = $this->links()->all( self::NOW );
		$this->assertSame(
			[
				[
					'id'         => '0123456789abcdef',
					'direction'  => Links::IN,
					'address'    => 'https://a.example',
					'name'       => 'Site A',
					'allow'      => [ 'pull', 'push' ],
					'createdAt'  => self::NOW,
					'createdBy'  => [ 'id' => 7, 'login' => 'boss' ],
					'lastUsedAt' => 0,
					'pairedFrom' => '203.0.113.5',
					'state'      => 'ready',
					'insecure'   => false,
				],
			],
			$all
		);
		$found = $this->links()->find( '0123456789abcdef', self::NOW );
		$this->assertSame( $secret, $found['key'] );
		$this->assertArrayNotHasKey( 'secret', $found );
	}

	public function test_another_key_reads_as_a_broken_link(): void {
		FakeWpdb::install();
		$this->links()->add( self::link(), random_bytes( 32 ), self::NOW, true );
		$this->assertNull( $this->links( 'z' )->find( '0123456789abcdef', self::NOW )['key'] );
		$this->assertSame( 'broken', $this->links( 'z' )->all( self::NOW )[0]['state'] );
	}

	public function test_a_pending_link_lives_fifteen_minutes_unless_confirmed(): void {
		FakeWpdb::install();
		$links = $this->links();
		$links->add( self::link(), random_bytes( 32 ), self::NOW, false );
		$this->assertSame( 'pending', $links->all( self::NOW + 899 )[0]['state'] );
		$this->assertTrue( $links->confirm( '0123456789abcdef', self::NOW + 899 ) );
		$links->add( self::link( 'dddddddddddddddd' ), random_bytes( 32 ), self::NOW, false );
		$this->assertNull( $links->find( 'dddddddddddddddd', self::NOW + 901 ), 'Expired while pending.' );
		$this->assertFalse( $links->confirm( 'dddddddddddddddd', self::NOW + 901 ), 'An expired pending link is never confirmed (m7).' );
		$this->assertSame( 'ready', $links->all( self::NOW + 5000 )[0]['state'] );
		$this->assertSame( 0, $links->prune( self::NOW + 5000 ) );
	}

	public function test_prune_drops_only_expired_pending_links(): void {
		FakeWpdb::install();
		$links = $this->links();
		$links->add( self::link( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), self::NOW, false );
		$links->add( self::link( 'bbbbbbbbbbbbbbbb' ), random_bytes( 32 ), self::NOW, true );
		$this->assertSame( 1, $links->prune( self::NOW + 901 ) );
		$this->assertSame( [ 'bbbbbbbbbbbbbbbb' ], array_column( $links->all( self::NOW + 901 ), 'id' ) );
	}

	public function test_twenty_links_are_the_limit_and_ids_are_unique(): void {
		FakeWpdb::install();
		$links = $this->links();
		for ( $i = 0; $i < Links::MAX; ++$i ) {
			$links->add( self::link( sprintf( '%016x', $i + 1 ) ), random_bytes( 32 ), self::NOW, true );
		}
		$this->assertFalse( $links->room( self::NOW ) );
		foreach ( [ [ 'ffffffffffffffff', Links::FULL ], [ '0000000000000001', Links::TAKEN ] ] as [ $id, $code ] ) {
			try {
				$links->add( self::link( $id ), random_bytes( 32 ), self::NOW, true );
				$this->fail( 'Refused: ' . $id );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( $code, $e->getCode() );
			}
		}
	}

	public function test_an_http_link_is_marked(): void {
		FakeWpdb::install();
		$links = $this->links();
		$links->add( array_merge( self::link(), [ 'address' => 'http://a.example' ] ), random_bytes( 32 ), self::NOW, true );
		$this->assertTrue( $links->all( self::NOW )[0]['insecure'] );
	}

	public function test_malformed_input_and_rows_are_refused_or_ignored(): void {
		$db = FakeWpdb::install();
		foreach ( [ [ 'id' => 'XYZ' ], [ 'direction' => 'both' ], [ 'allow' => [] ], [ 'allow' => [ 'delete' ] ], [ 'address' => 'ftp://a' ], [ 'name' => str_repeat( 'x', 201 ) ], [ 'createdBy' => 7 ], [ 'createdBy' => [ 'id' => 0, 'login' => 'boss' ] ], [ 'pairedFrom' => str_repeat( '1', 65 ) ] ] as $change ) {
			try {
				$this->links()->add( array_merge( self::link(), $change ), random_bytes( 32 ), self::NOW, true );
				$this->fail( 'Refused: ' . json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$db->rows[ Options::LINKS ] = [ 'value' => '[{"id":"0123456789abcdef","direction":"in"},"x",5]', 'autoload' => 'off' ];
		$this->assertSame( [], $this->links()->all( self::NOW ) );
	}

	public function test_concurrent_adds_both_land_and_touch_is_throttled(): void {
		$db    = FakeWpdb::install();
		$links = $this->links();
		$links->add( self::link( 'aaaaaaaaaaaaaaaa' ), random_bytes( 32 ), self::NOW, true );
		$other = random_bytes( 32 );
		$once  = true;
		$db->beforeWrite = function () use ( &$once, $other ): void {
			if ( $once ) {
				$once = false;
				$this->links()->add( self::link( 'cccccccccccccccc' ), $other, self::NOW, true );
			}
		};
		$links->add( self::link( 'bbbbbbbbbbbbbbbb' ), random_bytes( 32 ), self::NOW, true );
		$db->beforeWrite = null;
		$this->assertSame( [ 'aaaaaaaaaaaaaaaa', 'cccccccccccccccc', 'bbbbbbbbbbbbbbbb' ], array_column( $links->all( self::NOW ), 'id' ) );
		$links->touch( 'aaaaaaaaaaaaaaaa', self::NOW + 10 );
		$links->touch( 'aaaaaaaaaaaaaaaa', self::NOW + 30 );
		$this->assertSame( self::NOW + 10, $links->all( self::NOW )[0]['lastUsedAt'] );
		$links->touch( 'aaaaaaaaaaaaaaaa', self::NOW + 71 );
		$this->assertSame( self::NOW + 71, $links->all( self::NOW )[0]['lastUsedAt'] );
		$this->assertTrue( $links->remove( 'cccccccccccccccc' ) );
		$this->assertFalse( $links->remove( 'cccccccccccccccc' ) );
	}

	public function test_invalid_utf8_is_refused_and_never_wipes_the_row(): void {
		$db    = FakeWpdb::install();
		$links = $this->links();
		$links->add( self::link(), random_bytes( 32 ), self::NOW, true );
		$before = $db->value( Options::LINKS );

		foreach ( [ [ 'name' => "x\xC3" ], [ 'address' => "https://a.example/\xC3" ], [ 'pairedFrom' => "1\xC3" ], [ 'createdBy' => [ 'id' => 7, 'login' => "bo\xC3" ] ] ] as $change ) {
			try {
				$links->add( array_merge( self::link( 'aaaaaaaaaaaaaaaa' ), $change ), random_bytes( 32 ), self::NOW, true );
				$this->fail( 'Refused: ' . bin2hex( (string) json_encode( $change, JSON_INVALID_UTF8_SUBSTITUTE ) ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertSame( $before, $db->value( Options::LINKS ), 'The stored connections are untouched.' );
		$this->assertSame( [ 'id' => 7, 'login' => 'boss' ], $links->find( '0123456789abcdef', self::NOW )['createdBy'] );
	}

	public function test_a_nested_right_is_ignored_without_a_warning(): void {
		$this->assertSame( [ 'pull' ], Links::allow( [ [ 'pull' ], 'pull', 5, null ] ) );
	}
}
