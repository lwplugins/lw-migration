<?php
/**
 * Tests for Shares.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SharesTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const NAME = 'site-20261009-120000-001.wpress';

	public function test_only_the_digest_is_stored(): void {
		$db      = FakeWpdb::install();
		$created = ( new Shares() )->create( Backup::OWN, self::NAME, 7, self::NOW, 3600, str_repeat( 'a', 32 ) );

		$this->assertMatchesRegularExpression( Shares::TOKEN_PATTERN, $created['token'] );
		$this->assertMatchesRegularExpression( Shares::ID_PATTERN, $created['id'] );
		$this->assertSame( self::NOW + 3600, $created['expiresAt'] );
		$raw = (string) $db->value( Options::SHARES );
		$this->assertStringContainsString( hash( 'sha256', $created['token'] ), $raw );
		$this->assertStringNotContainsString( $created['token'], $raw );
		$this->assertSame( 'off', $db->rows[ Options::SHARES ]['autoload'] );
		$all = ( new Shares() )->all();
		$this->assertCount( 1, $all );
		$this->assertArrayNotHasKey( 'hash', $all[0] );
		$this->assertSame(
			[
				'id'         => $created['id'],
				'folder'     => Backup::OWN,
				'name'       => self::NAME,
				'identity'   => str_repeat( 'a', 32 ),
				'createdAt'  => self::NOW,
				'createdBy'  => 7,
				'expiresAt'  => self::NOW + 3600,
				'uses'       => 0,
				'lastUsedAt' => 0,
			],
			$all[0]
		);
	}

	public function test_a_link_opens_only_while_it_lives(): void {
		FakeWpdb::install();
		$shares  = new Shares();
		$created = $shares->create( Backup::EXTERNAL, self::NAME, 7, self::NOW, 600 );
		$other   = $shares->create( Backup::OWN, self::NAME, 7, self::NOW, 600 );
		$token   = $created['token'];

		$this->assertSame(
			[
				'id'       => $created['id'],
				'folder'   => Backup::EXTERNAL,
				'name'     => self::NAME,
				'identity' => '',
			],
			$shares->open( $token, self::NOW + 599 )
		);
		foreach ( [ substr( $token, 1 ), strtoupper( $token ), substr( $token, 1 ) . '/', $token . "\n", str_repeat( '0', 32 ), '' ] as $bad ) {
			$this->assertNull( $shares->open( $bad, self::NOW ), $bad );
		}
		$this->assertNull( $shares->open( $token, self::NOW + 600 ), 'Expired.' );
		$this->assertTrue( $shares->revoke( $created['id'] ) );
		$this->assertNull( $shares->open( $token, self::NOW ), 'Revoked.' );
		$this->assertFalse( $shares->revoke( $created['id'] ) );
		$this->assertNotNull( $shares->open( $other['token'], self::NOW ), 'The other link still opens.' );
	}

	public function test_a_broken_or_tampered_row_opens_nothing(): void {
		$token = str_repeat( 'ab', 16 );
		$row   = [
			'id'         => '0a1b2c3d',
			'hash'       => hash( 'sha256', $token ),
			'folder'     => Backup::OWN,
			'name'       => self::NAME,
			'createdAt'  => self::NOW,
			'createdBy'  => 1,
			'expiresAt'  => self::NOW + 600,
			'uses'       => 0,
			'lastUsedAt' => 0,
		];
		foreach ( [ 'not json', '{"a":1}', (string) json_encode( [ array_merge( $row, [ 'name' => '../x.wpress' ] ) ] ), (string) json_encode( [ array_merge( $row, [ 'folder' => '/etc' ] ) ] ), (string) json_encode( [ array_merge( $row, [ 'expiresAt' => '9999999999' ] ) ] ) ] as $raw ) {
			FakeWpdb::install( [ Options::SHARES => $raw ] );
			$this->assertNull( ( new Shares() )->open( $token, self::NOW ), $raw );
			$this->assertSame( [], ( new Shares() )->all(), $raw );
		}
		FakeWpdb::install( [ Options::SHARES => (string) json_encode( [ $row ] ) ] );
		$this->assertNotNull( ( new Shares() )->open( $token, self::NOW ) );
	}

	public function test_expiry_is_clamped(): void {
		$this->assertSame( 300, Shares::clamp( 1 ) );
		$this->assertSame( 604800, Shares::clamp( 10 ** 9 ) );
		$this->assertSame( 3600, Shares::clamp( 3600 ) );
		$this->assertSame( 300, Shares::clamp( -5 ) );
		FakeWpdb::install();
		$this->assertSame( self::NOW + 604800, ( new Shares() )->create( Backup::OWN, self::NAME, 1, self::NOW, PHP_INT_MAX )['expiresAt'] );
	}

	public function test_fifty_live_links_are_the_limit_and_expired_ones_make_room(): void {
		$db     = FakeWpdb::install();
		$shares = new Shares();
		$first  = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 300 );
		for ( $i = 1; $i < Shares::MAX_LIVE; $i++ ) {
			$shares->create( Backup::OWN, self::NAME, 1, self::NOW, 3600 );
		}
		try {
			$shares->create( Backup::OWN, self::NAME, 1, self::NOW + 299, 3600 );
			$this->fail( 'The 51st live link is refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( Shares::FULL, $e->getCode() );
		}
		$this->assertCount( 50, $shares->all() );

		$next = $shares->create( Backup::OWN, self::NAME, 1, self::NOW + 300, 3600 );

		$ids = array_column( $shares->all(), 'id' );
		$this->assertCount( 50, $ids );
		$this->assertNotContains( $first['id'], $ids, 'The expired link made room.' );
		$this->assertContains( $next['id'], $ids );
		$this->assertCount( 50, array_unique( $ids ) );
		$this->assertStringNotContainsString( $first['token'], (string) $db->value( Options::SHARES ) );
	}

	public function test_concurrent_creates_both_land(): void {
		$db              = FakeWpdb::install();
		$shares          = new Shares();
		$other           = null;
		$db->beforeWrite = static function () use ( &$other ): void {
			$other = ( new Shares() )->create( Backup::OWN, 'other-20261009-120000-001.wpress', 2, self::NOW, 600 );
		};

		$mine = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 600 );

		$this->assertIsArray( $other );
		$this->assertEqualsCanonicalizing( [ $mine['id'], $other['id'] ], array_column( $shares->all(), 'id' ) );
		$this->assertNotNull( $shares->open( $mine['token'], self::NOW ) );
		$this->assertNotNull( $shares->open( $other['token'], self::NOW ) );
	}

	public function test_a_row_that_cannot_be_written_is_an_error(): void {
		$db             = FakeWpdb::install();
		$db->failWrites = true;

		$this->expectException( \RuntimeException::class );
		( new Shares() )->create( Backup::OWN, self::NAME, 1, self::NOW, 600 );
	}

	public function test_touch_counts_downloads_from_the_start_and_throttles_last_use(): void {
		$db      = FakeWpdb::install();
		$shares  = new Shares();
		$created = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 3600 );
		$t       = self::NOW + 5;
		$row     = static fn(): array => ( new Shares() )->all()[0];

		$shares->touch( $created['id'], true, $t );
		$this->assertSame( [ 1, $t ], [ $row()['uses'], $row()['lastUsedAt'] ] );

		$shares->touch( $created['id'], false, $t + 10 );
		$this->assertSame( [ 1, $t ], [ $row()['uses'], $row()['lastUsedAt'] ], 'A resume within a minute writes nothing.' );

		$shares->touch( $created['id'], false, $t + 61 );
		$this->assertSame( [ 1, $t + 61 ], [ $row()['uses'], $row()['lastUsedAt'] ] );

		$shares->touch( $created['id'], true, $t + 62 );
		$this->assertSame( [ 2, $t + 62 ], [ $row()['uses'], $row()['lastUsedAt'] ] );

		$before          = $db->value( Options::SHARES );
		$db->beforeWrite = static function () use ( $db ): void {
			$db->rows[ Options::SHARES ]['value'] .= ' ';
		};
		$shares->touch( $created['id'], true, $t + 200 );
		$this->assertSame( $before . ' ', $db->value( Options::SHARES ), 'A conflict is swallowed: one try only.' );

		$shares->touch( 'ffffffff', true, $t + 300 );
		$this->assertSame( 2, $row()['uses'], 'An unknown id changes nothing.' );
	}

	public function test_prune_removes_only_expired_rows(): void {
		FakeWpdb::install();
		$shares = new Shares();
		$short  = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 300 );
		$long   = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 3600 );

		$this->assertSame( 0, $shares->prune( self::NOW + 299 ) );
		$this->assertSame( 1, $shares->prune( self::NOW + 300 ) );
		$this->assertSame( [ $long['id'] ], array_column( $shares->all(), 'id' ) );
		$this->assertNull( $shares->open( $short['token'], self::NOW ) );
		$this->assertSame( 0, $shares->prune( self::NOW + 300 ) );
	}

	public function test_nothing_is_written_while_nothing_changes(): void {
		$db             = FakeWpdb::install();
		$db->failWrites = true;
		$shares         = new Shares();

		$this->assertSame( 0, $shares->prune( self::NOW ) );
		$this->assertFalse( $shares->revoke( '0a1b2c3d' ) );
		$this->assertNull( $shares->open( str_repeat( 'a', 32 ), self::NOW ) );
		$this->assertSame( [], $db->rows );
	}

	public function test_a_name_with_invalid_utf8_is_an_error_and_never_wipes_the_row(): void {
		$db     = FakeWpdb::install();
		$shares = new Shares();
		$shares->create( Backup::OWN, self::NAME, 1, self::NOW, 600 );
		$before = $db->value( Options::SHARES );

		try {
			$shares->create( Backup::OWN, "x\xC3", 1, self::NOW, 600 );
			$this->fail( 'Refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$this->assertSame( $before, $db->value( Options::SHARES ) );
		$this->assertCount( 1, $shares->all() );
	}

	public function test_the_links_of_a_deleted_backup_are_revoked_and_no_other(): void {
		FakeWpdb::install();
		$shares = new Shares();
		$a      = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 600, 'x' );
		$b      = $shares->create( Backup::OWN, self::NAME, 1, self::NOW, 600, 'x' );
		$ext    = $shares->create( Backup::EXTERNAL, self::NAME, 1, self::NOW, 600, 'x' );
		$other  = $shares->create( Backup::OWN, 'other-20261009-120000-001.wpress', 1, self::NOW, 600, 'x' );

		$this->assertSame( 2, $shares->revokeFor( Backup::OWN, self::NAME ) );
		$this->assertSame( 0, $shares->revokeFor( Backup::OWN, self::NAME ) );
		$this->assertSame( [ $ext['id'], $other['id'] ], array_column( $shares->all(), 'id' ) );
		$this->assertNull( $shares->open( $a['token'], self::NOW ) );
		$this->assertNull( $shares->open( $b['token'], self::NOW ) );
	}

	public function test_a_legacy_row_without_a_fingerprint_is_listed_and_never_matches(): void {
		$token = str_repeat( 'ab', 16 );
		$row   = [
			'id'         => 'abcdef01',
			'hash'       => hash( 'sha256', $token ),
			'folder'     => Backup::OWN,
			'name'       => self::NAME,
			'createdAt'  => self::NOW,
			'createdBy'  => 1,
			'expiresAt'  => self::NOW + 600,
			'uses'       => 0,
			'lastUsedAt' => 0,
		];
		FakeWpdb::install( [ Options::SHARES => (string) json_encode( [ $row ] ) ] );
		$shares = new Shares();

		$this->assertSame( [ 'abcdef01' ], array_column( $shares->all(), 'id' ) );
		$open = $shares->open( $token, self::NOW );
		$this->assertSame( '', $open['identity'] ?? null );
		$this->assertFalse( Shares::matches( $open['identity'], '' ) );
		$this->assertFalse( Shares::matches( $open['identity'], str_repeat( 'a', 32 ) ) );
		$this->assertFalse( Shares::matches( '', '' ) );
		$this->assertTrue( Shares::matches( 'abc', 'abc' ) );
		$this->assertFalse( Shares::matches( 'abc', 'abd' ) );
	}
}
