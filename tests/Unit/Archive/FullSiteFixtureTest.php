<?php
/**
 * The full-site archive made by the established .wpress importer reads cleanly.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Support\NoDeadline;
use PHPUnit\Framework\TestCase;

final class FullSiteFixtureTest extends TestCase {

	private const ARCHIVE = __DIR__ . '/../../Fixtures/wpress/full-site.wpress';

	public function test_reference_made_full_site_archive(): void {
		$facts   = json_decode( (string) file_get_contents( __DIR__ . '/../../Fixtures/wpress/full-site.json' ), true );
		$package = PackageCodec::read( self::ARCHIVE );
		$this->assertSame( $facts['siteUrl'], $package->get( 'SiteURL' ) );
		$this->assertContains( $facts['plugin'], (array) $package->get( 'Plugins' ) );
		$this->assertSame( $facts['theme'], $package->get( 'Stylesheet' ) );
		$this->assertFalse( $package->isEncrypted() );

		$reader = new Reader( self::ARCHIVE );
		$this->assertSame( 2, $reader->eof()->version );
		$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
		$names = [];
		foreach ( $reader->entries() as $entry ) {
			$names[] = $entry->name;
			$reader->readContents( $entry, 64 * 1048576 ); // Every entry's CRC matches.
		}
		$this->assertContains( $facts['upload'], $names );
		$this->assertContains( 'plugins/' . $facts['plugin'], $names );
		$this->assertContains( 'themes/' . $facts['theme'] . '/style.css', $names );
		$this->assertContains( 'mu-plugins/' . $facts['muPlugin'], $names );
		$this->assertSame( [], preg_grep( '#^lw-migration-#', $names ), 'No storage folder of this plugin in the fixture.' );
		$this->assertSame( 'database.sql', end( $names ) );
		$database = $reader->find( 'database.sql' );
		$this->assertNotNull( $database );
		$sql = $reader->readContents( $database, 64 * 1048576 );
		$reader->close();
		$this->assertStringStartsWith( '-- Reference Exporter Tool SQL Dump', $sql, 'The fixture was neutralised.' );
		$this->assertStringContainsString( $facts['post'], $sql );
		$this->assertStringContainsString( "'" . $facts['user'] . "'", $sql );
		$this->assertStringContainsString( 'SERVMASK_PREFIX_options', $sql );
		$this->assertStringNotContainsString( "'lw_migration_", $sql );
	}
}
