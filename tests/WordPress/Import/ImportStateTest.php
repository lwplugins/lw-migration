<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ImportStateTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress/';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_codec_needs_the_password_of_an_encrypted_archive(): void {
		$job                   = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'encrypted.wpress' );
		$job->state['package'] = PackageCodec::read( self::FIXTURES . 'encrypted.wpress' )->toArray();

		$this->assertSame( self::FIXTURES . 'encrypted.wpress', ImportState::archive( $job ) );
		$this->assertNull( ImportState::codec( $job ) );
		$job->secrets = [ 'password' => '' ];
		$this->assertNull( ImportState::codec( $job ) );
		$job->secrets = [ 'password' => 'lw-fixture-pass' ];
		$this->assertInstanceOf( ChunkCodec::class, ImportState::codec( $job ) );
		$this->assertSame( 'https://new.test', ImportState::site( $job )->homeUrl );
		$this->assertNull( ImportState::subsiteId( $job ) );
		$job->state['subsiteId'] = 3;
		$this->assertSame( 3, ImportState::subsiteId( $job ) );
		$job->state['subsiteId'] = 1;
		$this->assertNull( ImportState::subsiteId( $job ) );
		$this->assertFalse( ImportState::hasDatabase( $job ) );
		$job->state['hasDatabase'] = true;
		$this->assertTrue( ImportState::hasDatabase( $job ) );
	}

	public function test_plain_archive_needs_no_password(): void {
		$job                   = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'plain.wpress' );
		$job->state['package'] = PackageCodec::read( self::FIXTURES . 'plain.wpress' )->toArray();

		$this->assertInstanceOf( ChunkCodec::class, ImportState::codec( $job ) );
	}

	public function test_missing_archive_is_an_error(): void {
		$job = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '', [ 'location' => 'b.wpress' ] );
		$this->expectException( ImportException::class );
		ImportState::archive( $job );
	}

	public function test_missing_package_is_an_error(): void {
		$job = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '', [ 'location' => 'b.wpress' ] );
		$this->expectException( ImportException::class );
		ImportState::package( $job );
	}

	public function test_missing_site_is_an_error(): void {
		$job = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '', [ 'location' => 'b.wpress' ] );
		foreach ( [ null, [], 'https://new.test' ] as $site ) {
			if ( null === $site ) {
				unset( $job->state['site'] );
			} else {
				$job->state['site'] = $site;
			}
			try {
				ImportState::site( $job );
				$this->fail( 'Accepted site: ' . json_encode( $site ) );
			} catch ( ImportException $e ) {
				$this->assertSame( 'The target site has not been read yet.', $e->getMessage() );
			}
		}
	}

	public function test_work_file_names(): void {
		$this->assertSame( 'safety', ImportFiles::SAFETY );
		$this->assertSame( Format::DATABASE, ImportFiles::DATABASE );
		$this->assertSame( 'download.wpress', ImportFiles::DOWNLOAD );
		$this->assertSame( 'deferred.sql', ImportFiles::DEFERRED );
		$this->assertSame( 'mu-plugins-staging', ImportFiles::STAGING );
	}

	public function test_forget_address_keeps_only_the_display_form(): void {
		$dir = TempDir::create();
		$job = ImportJobFixture::make( new JobStore( $dir ), $dir, '', [ 'source' => 'url', 'location' => 'https://user:pw@files.test/share/abc/site.wpress?token=tok3n' ] );
		$job->state['download'] = [
			'url'    => 'https://cdn.files.test/x/site.wpress?sig=s1gn',
			'size'   => 10,
			'ranges' => true,
		];

		$this->assertTrue( ImportState::forgetAddress( $job ) );
		$this->assertSame( 'https://files.test/…/site.wpress', $job->options['location'] );
		$this->assertSame( [ 'size' => 10, 'ranges' => true ], $job->state['download'] );
		$this->assertFalse( ImportState::forgetAddress( $job ), 'Nothing left to forget.' );
		TempDir::remove( $dir );
	}

	public function test_network_is_null_on_a_single_site_and_held_suffixes_follow_the_kind(): void {
		$job = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '', [ 'location' => 'b.wpress' ] );

		$this->assertNull( ImportState::network( $job ) );
		$this->assertSame( [], ImportState::heldSuffixes( $job ) );
		$this->assertSame( [], ImportState::siteSuffixes( $job ) );
		$job->state['kind'] = ArchiveKind::SITES; // Never read without a target network.
		$this->assertNull( ImportState::network( $job ) );
		$this->assertSame( [], ImportState::heldSuffixes( $job ) );

		$job->state['target'] = [ 'domain' => 'new.test', 'path' => '/', 'basePrefix' => 'wp_' ];
		$job->state['blogs']  = [
			[
				'old'   => [ 'BlogID' => 2 ],
				'newId' => 7,
			],
		];
		$network              = ImportState::network( $job );
		$this->assertNotNull( $network );
		$this->assertSame( ArchiveKind::SITES, $network->kind );
		$this->assertSame( 7, $network->map->newIdOf( 2 ) );
		$this->assertSame( 'new.test', $network->target->domain );
		$this->assertSame( [ 'lwmsrc_' ], ImportState::heldSuffixes( $job ) );
		$this->assertSame( [ '7_' ], ImportState::siteSuffixes( $job ) );
		$job->state['kind'] = ArchiveKind::SINGLE;
		$this->assertSame( [ 'lwmsrc_' ], ImportState::heldSuffixes( $job ) );
		$job->state['kind'] = ArchiveKind::NETWORK;
		$this->assertSame( [], ImportState::heldSuffixes( $job ), 'A whole-network archive swaps every table in.' );
		$this->assertSame( [], ImportState::siteSuffixes( $job ) );
	}

	public function test_network_carries_the_archives_network_plugins_and_super_admins(): void {
		$job                  = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '', [ 'location' => 'b.wpress' ] );
		$job->state['target'] = [ 'domain' => 'new.test', 'path' => '/', 'basePrefix' => 'wp_' ];
		$job->state['kind']   = ArchiveKind::NETWORK;
		$job->state['blogs']  = [ [ 'old' => [ 'BlogID' => 1 ], 'newId' => 1 ] ];

		$this->assertSame( [], ImportState::network( $job )->plugins, 'Before Validate read multisite.json.' );
		$this->assertSame( [], ImportState::network( $job )->admins );

		$job->state['multisite'] = [
			'network' => true,
			'plugins' => [ 'net/net.php', 7, [ 'x' ] ],
			'admins'  => [ 'admin', null, 'boss' ],
		];
		$network                 = ImportState::network( $job );
		$this->assertSame( [ 'net/net.php' ], $network->plugins, 'Only names are kept.' );
		$this->assertSame( [ 'admin', 'boss' ], $network->admins );
	}
}
