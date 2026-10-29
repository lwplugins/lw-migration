<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\ContentsCache;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\BackupContentsApi;
use LightweightPlugins\Migration\Rest\BackupsApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class BackupContentsApiTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress/';

	private const OWN_PLUGIN = 'lw-migration/lw-migration.php';

	private const PLAIN = 'site-test-20261007-101500-001.wpress';

	private string $dir;

	private BackupsApi $backups;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/' . self::PLAIN );
		copy( self::FIXTURES . 'encrypted.wpress', $this->dir . '/ext/twin.wpress' );
		$this->backups = new BackupsApi( new Repository( $this->dir . '/own', $this->dir . '/ext' ), new JobStore( $this->dir . '/jobs' ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function api( ?\Closure $deadline = null ): BackupContentsApi {
		return new BackupContentsApi( $this->backups, self::OWN_PLUGIN, $deadline ?? static fn(): Deadline => new NoDeadline() );
	}

	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	public function test_the_parts_are_read_from_the_entry_headers(): void {
		$contents = $this->api()->contents( [ 'folder' => 'own', 'name' => self::PLAIN ] );

		$this->assertSame( [ 'parts', 'encrypted', 'expandedSize', 'partial' ], array_keys( $contents ) );
		$this->assertSame(
			[
				'database'  => [ 'included' => true, 'bytes' => 26, 'count' => null ],
				'media'     => [ 'included' => true, 'bytes' => 32, 'count' => 1 ],
				'plugins'   => [ 'included' => true, 'bytes' => 600000, 'count' => 1 ],
				'themes'    => [ 'included' => false, 'bytes' => 0, 'count' => 0 ],
				'muPlugins' => [ 'included' => false, 'bytes' => 0, 'count' => 0 ],
			],
			$contents['parts']
		);
		$this->assertFalse( $contents['encrypted'] );
		$this->assertSame( 14 + 32 + 600000 + 26, $contents['expandedSize'] );
		$this->assertFalse( $contents['partial'] );
	}

	public function test_an_encrypted_backup_has_no_expanded_size(): void {
		$contents = $this->api()->contents( [ 'folder' => 'external', 'name' => 'twin.wpress' ] );

		$this->assertTrue( $contents['encrypted'] );
		$this->assertNull( $contents['expandedSize'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 64, 'count' => 1 ], $contents['parts']['media'] );
	}

	public function test_a_second_open_of_the_same_file_is_served_from_the_cache(): void {
		$params = [ 'folder' => 'own', 'name' => self::PLAIN ];
		$first  = $this->api()->contents( $params );
		$cache  = FakeOptions::$values[ Options::BACKUP_CONTENTS ];
		$this->assertCount( 1, $cache );
		$key                                   = (string) array_key_first( $cache );
		$cache[ $key ]['contents']['expandedSize'] = 1; // A marker only the cache can answer with.
		FakeOptions::$values[ Options::BACKUP_CONTENTS ] = $cache;

		$this->assertSame( 1, $this->api( static fn(): Deadline => throw new \LogicException( 'The archive was read again.' ) )->contents( $params )['expandedSize'] );

		touch( $this->dir . '/own/' . self::PLAIN, 1000 );
		$this->assertSame( $first, $this->api()->contents( $params ), 'Another file under the same name is read again.' );
	}

	public function test_a_big_archive_is_read_over_several_requests(): void {
		$params = [ 'folder' => 'own', 'name' => self::PLAIN ];
		$whole  = $this->api()->contents( $params );
		delete_option( Options::BACKUP_CONTENTS );
		$short = $this->api( static fn(): Deadline => new CountdownDeadline( 0 ) );

		$first = $short->contents( $params );
		$this->assertTrue( $first['partial'] );
		$this->assertNull( $first['expandedSize'] );
		$this->assertSame( [ 'included' => null, 'bytes' => null, 'count' => null ], $first['parts']['media'], 'Unknown until every header was read.' );
		$requests = 1;
		do {
			$contents = $short->contents( $params );
			++$requests;
		} while ( $contents['partial'] && $requests < 50 );

		$this->assertSame( $whole, $contents, 'Each request goes on where the last one stopped.' );
		$this->assertGreaterThan( 2, $requests );
	}

	public function test_a_backup_that_is_gone_answers_backup_missing(): void {
		$params = [ 'folder' => 'own', 'name' => self::PLAIN ];
		$this->api()->contents( $params );
		unlink( $this->dir . '/own/' . self::PLAIN );

		$refused = $this->refusal( fn() => $this->api()->contents( $params ) );

		$this->assertSame( 404, $refused->status );
		$this->assertSame( 'lw_migration_backup_missing', $refused->errorCode );
	}

	public function test_a_symlinked_archive_is_not_read(): void {
		$target = $this->dir . '/elsewhere.wpress';
		copy( self::FIXTURES . 'plain.wpress', $target );
		if ( ! @symlink( $target, $this->dir . '/own/linked.wpress' ) ) {
			$this->markTestSkipped( 'Symlinks cannot be made here.' );
		}

		$refused = $this->refusal( fn() => $this->api()->contents( [ 'folder' => 'own', 'name' => 'linked.wpress' ] ) );

		$this->assertSame( 404, $refused->status );
		$this->assertSame( 'lw_migration_backup_missing', $refused->errorCode );
		$this->assertArrayNotHasKey( Options::BACKUP_CONTENTS, FakeOptions::$values );
	}

	public function test_only_a_listed_backup_of_a_listed_folder_is_read(): void {
		foreach ( [ [ 'folder' => 'own', 'name' => '../ext/twin.wpress' ], [ 'folder' => 'tmp', 'name' => 'twin.wpress' ], [ 'name' => self::PLAIN ], [ 'folder' => 'own', 'name' => 'twin.wpress' ], [ 'folder' => [ 'own' ], 'name' => self::PLAIN ] ] as $params ) {
			$this->assertSame( 404, $this->refusal( fn() => $this->api()->contents( $params ) )->status );
		}
		$this->assertArrayNotHasKey( Options::BACKUP_CONTENTS, FakeOptions::$values );
	}

	public function test_a_file_that_is_no_archive_is_refused_and_not_cached(): void {
		file_put_contents( $this->dir . '/own/broken.wpress', 'not an archive' );

		$refused = $this->refusal( fn() => $this->api()->contents( [ 'folder' => 'own', 'name' => 'broken.wpress' ] ) );

		$this->assertSame( 422, $refused->status );
		$this->assertSame( 'lw_migration_not_readable', $refused->errorCode );
		$this->assertStringNotContainsString( $this->dir, $refused->getMessage() );
		$this->assertArrayNotHasKey( Options::BACKUP_CONTENTS, FakeOptions::$values );
	}

	public function test_the_cache_keeps_the_latest_entries_only(): void {
		for ( $i = 0; $i < ContentsCache::MAX_ENTRIES + 5; ++$i ) {
			ContentsCache::put( 'own', "b{$i}.wpress", str_repeat( 'a', 32 ), [ 'contents' => [ 'i' => $i ] ] );
		}
		ContentsCache::put( 'own', 'b10.wpress', str_repeat( 'b', 32 ), [ 'contents' => [ 'i' => 'again' ] ] );

		$stored = FakeOptions::$values[ Options::BACKUP_CONTENTS ];
		$this->assertCount( ContentsCache::MAX_ENTRIES, $stored );
		$this->assertNull( ContentsCache::get( 'own', 'b4.wpress', str_repeat( 'a', 32 ) ), 'The oldest went first.' );
		$this->assertSame( [ 'i' => 54 ], ContentsCache::get( 'own', 'b54.wpress', str_repeat( 'a', 32 ) )['contents'] ?? null );
		$this->assertNull( ContentsCache::get( 'own', 'b10.wpress', str_repeat( 'a', 32 ) ), 'Another fingerprint is a miss.' );
		$this->assertSame( [ 'i' => 'again' ], ContentsCache::get( 'own', 'b10.wpress', str_repeat( 'b', 32 ) )['contents'] ?? null );
		$this->assertNull( ContentsCache::get( 'external', 'b54.wpress', str_repeat( 'a', 32 ) ), 'Each folder on its own.' );
	}
}
