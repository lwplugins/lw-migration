<?php
/**
 * Imports a reference-made fixture archive's database into temporary tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Import\ImportPlanFactory;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;

final class ArchiveImport {

	private const DIR = __DIR__ . '/../../Fixtures/wpress/';

	/**
	 * The database step of a restore into the network at http://new.test (main site prefix wp_).
	 *
	 * @param Connection    $db      Database.
	 * @param string        $file    Fixture archive.
	 * @param NetworkImport $network The restore.
	 * @param string        $dir     Work folder.
	 * @param string        $tmp     Temporary prefix.
	 * @param string        $append  Statements added after the archive's dump.
	 * @return ImportCursor
	 */
	public static function run( Connection $db, string $file, NetworkImport $network, string $dir, string $tmp, string $append = '' ): ImportCursor {
		$path = $dir . '/database.sql';
		file_put_contents( $path, self::entry( $file, 'database.sql' ) . $append );
		$site   = SiteFixture::make( $dir . '/root', [ 'siteUrl' => 'http://new.test', 'homeUrl' => 'http://new.test', 'uploadsUrl' => 'http://new.test/wp-content/uploads/' ] );
		$plan   = ImportPlanFactory::build( $db, PackageConfig::fromJson( self::entry( $file, 'package.json' ) ), $site, $tmp, $dir . '/deferred.sql', null, static fn( string $p ): bool => false, static fn( string $t ): bool => false, $network );
		$reader = new StatementReader( $path );
		$cursor = new ImportCursor();
		try {
			for ( $i = 0; $i < 1000 && ! ( new Importer( $db, $reader, $plan ) )->run( $cursor, new NoDeadline() ); $i++ ) {
				continue;
			}
		} finally {
			$reader->close();
		}

		return $cursor;
	}

	/**
	 * @param string $file Fixture archive.
	 * @param string $name Entry name.
	 * @return string The entry's contents.
	 */
	public static function entry( string $file, string $name ): string {
		$reader = new Reader( self::DIR . $file );
		try {
			foreach ( $reader->entries() as $entry ) {
				if ( $name === $entry->name ) {
					return $reader->readContents( $entry, 64 * 1048576 );
				}
			}
		} finally {
			$reader->close();
		}
		throw new \RuntimeException( 'No ' . $name . ' in ' . $file );
	}
}
