<?php
/**
 * Tests for SharesApi.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\SharesApi;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SharesApiTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const NAME = 'site-20261009-120000-001.wpress';

	private const URL = 'http://site.test/index.php?rest_route=/lw-migration/v1/backups/shared';

	/**
	 * Scratch folder: own/ and external/.
	 *
	 * @var string
	 */
	private string $dir;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/external' );
		file_put_contents( $this->dir . '/own/' . self::NAME, 'backup' );
		file_put_contents( $this->dir . '/external/old-20261009-120000-001.wpress', 'old' );
		$this->db = FakeWpdb::install();
		Functions\when( 'add_query_arg' )->alias( static fn( string $key, string $value, string $url ): string => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function api(): SharesApi {
		return new SharesApi( new Repository( $this->dir . '/own', $this->dir . '/external' ), new Shares() );
	}

	/**
	 * @param array<string, mixed> $body Request body.
	 */
	private function refusal( array $body ): RestException {
		try {
			$this->api()->create( $body, 1, self::NOW, self::URL );
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Refused: ' . json_encode( $body ) );
	}

	public function test_create_validates_the_body(): void {
		$base = [
			'folder' => Backup::OWN,
			'name'   => self::NAME,
		];
		foreach ( [
			'share'   => array_merge( $base, [ 'path' => '/etc' ] ),
			'folder'  => [ 'folder' => 'uploads', 'name' => self::NAME ],
			'name'    => [ 'folder' => Backup::OWN, 'name' => '../wp-config.php' ],
			'expires' => array_merge( $base, [ 'expires' => '3600' ] ),
		] as $field => $body ) {
			$e = $this->refusal( $body );
			$this->assertSame( [ 400, 'lw_migration_invalid', $field ], [ $e->status, $e->errorCode, $e->data['field'] ?? null ], $field );
		}
		foreach ( [ 299, 604801, 3600.0, true, null ] as $expires ) {
			$e = $this->refusal( array_merge( $base, [ 'expires' => $expires ] ) );
			$this->assertSame( [ 400, 'expires' ], [ $e->status, $e->data['field'] ?? null ], (string) json_encode( $expires ) );
		}
		$this->assertSame( [], $this->db->rows, 'Nothing stored for a refused body.' );

		$e = $this->refusal( [ 'folder' => Backup::EXTERNAL, 'name' => self::NAME ] );
		$this->assertSame( [ 404, 'lw_migration_backup_missing' ], [ $e->status, $e->errorCode ] );

		for ( $i = 0; $i < Shares::MAX_LIVE; $i++ ) {
			$this->api()->create( $base, 1, self::NOW, self::URL );
		}
		$e = $this->refusal( $base );
		$this->assertSame( [ 409, 'lw_migration_share_limit' ], [ $e->status, $e->errorCode ] );
	}

	/** Final review T7: a file whose fingerprint cannot be read gets no link that would never open. */
	public function test_a_backup_whose_fingerprint_cannot_be_read_gets_no_link(): void {
		$api = new SharesApi( new Repository( $this->dir . '/own', $this->dir . '/external' ), new Shares(), static fn( string $path ): string => '' );

		try {
			$api->create( [ 'folder' => Backup::OWN, 'name' => self::NAME ], 1, self::NOW, self::URL );
			$this->fail( 'Refused.' );
		} catch ( RestException $e ) {
			$this->assertSame( [ 404, 'lw_migration_backup_missing' ], [ $e->status, $e->errorCode ] );
		}
		$this->assertSame( [], ( new Shares() )->all() );
	}

	public function test_create_returns_the_url_once(): void {
		$created = $this->api()->create(
			[
				'folder'  => Backup::OWN,
				'name'    => self::NAME,
				'expires' => 600,
			],
			7,
			self::NOW,
			self::URL
		);

		$this->assertSame( [ 'id', 'url', 'expiresAt' ], array_keys( $created ) );
		$this->assertSame( self::NOW + 600, $created['expiresAt'] );
		$this->assertMatchesRegularExpression( '/^' . preg_quote( self::URL, '/' ) . '&share=[a-f0-9]{32}\z/', $created['url'] );
		$token = substr( $created['url'], -32 );
		$this->assertNotNull( ( new Shares() )->open( $token, self::NOW ) );

		$list = $this->api()->all( self::NOW );
		$this->assertSame(
			[
				[
					'id'         => $created['id'],
					'folder'     => Backup::OWN,
					'name'       => self::NAME,
					'createdAt'  => self::NOW,
					'expiresAt'  => self::NOW + 600,
					'expired'    => false,
					'available'  => true,
					'revoked'    => false,
					'uses'       => 0,
					'lastUsedAt' => 0,
				],
			],
			$list['items']
		);
		$json = (string) json_encode( $list );
		$this->assertStringNotContainsString( $token, $json );
		$this->assertStringNotContainsString( hash( 'sha256', $token ), $json );
		$this->assertStringNotContainsString( $this->dir, $json );

		unlink( $this->dir . '/own/' . self::NAME );
		$this->assertSame( [ false, false ], [ $this->api()->all( self::NOW )['items'][0]['available'], $this->api()->all( self::NOW )['items'][0]['revoked'] ], 'A missing file is not "revoked".' );
		$later = $this->api()->all( self::NOW + 600 )['items'][0];
		$this->assertSame( [ true, false ], [ $later['expired'], $later['available'] ] );
	}

	public function test_a_file_replaced_under_the_name_kills_the_link_and_lists_it_as_revoked(): void {
		$created = $this->api()->create(
			[
				'folder' => Backup::OWN,
				'name'   => self::NAME,
			],
			1,
			self::NOW,
			self::URL
		);
		$path    = $this->dir . '/own/' . self::NAME;
		$mtime   = (int) filemtime( $path );
		unlink( $path );
		file_put_contents( $path, 'backup' ); // Same size, new inode.
		touch( $path, $mtime );
		clearstatcache();

		$item = $this->api()->all( self::NOW )['items'][0];
		$this->assertSame( [ false, true ], [ $item['available'], $item['revoked'] ] );
		$this->assertNotNull( ( new Shares() )->open( substr( $created['url'], -32 ), self::NOW ), 'The row is still there; the file check is the guard.' );
		$this->assertFalse( Shares::matches( ( new Shares() )->open( substr( $created['url'], -32 ), self::NOW )['identity'], Fingerprint::of( $path ) ) );
	}

	public function test_the_default_expiry_comes_from_the_filter_clamped(): void {
		Filters\expectApplied( 'lw_migration_share_ttl' )->times( 3 )->with( Shares::DEFAULT_TTL )->andReturn( 60, 'x', 7200 );
		$body = [
			'folder' => Backup::OWN,
			'name'   => self::NAME,
		];

		$this->assertSame( self::NOW + Shares::MIN_TTL, $this->api()->create( $body, 1, self::NOW, self::URL )['expiresAt'] );
		$this->assertSame( self::NOW + Shares::DEFAULT_TTL, $this->api()->create( $body, 1, self::NOW, self::URL )['expiresAt'] );
		$this->assertSame( self::NOW + 7200, $this->api()->create( $body, 1, self::NOW, self::URL )['expiresAt'] );
	}

	public function test_revoke_removes_the_link(): void {
		$created = $this->api()->create(
			[
				'folder' => Backup::OWN,
				'name'   => self::NAME,
			],
			1,
			self::NOW,
			self::URL
		);

		$this->assertSame( [ 'revoked' => true ], $this->api()->revoke( $created['id'] ) );
		$this->assertSame( [], $this->api()->all( self::NOW )['items'] );
		$this->assertSame( '[]', $this->db->value( Options::SHARES ) );
		try {
			$this->api()->revoke( $created['id'] );
			$this->fail( 'An unknown id is a 404.' );
		} catch ( RestException $e ) {
			$this->assertSame( [ 404, 'lw_migration_share_missing' ], [ $e->status, $e->errorCode ] );
		}
	}
}
