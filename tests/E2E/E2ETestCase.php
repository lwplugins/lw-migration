<?php
/**
 * Base class of the end-to-end tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;
use PHPUnit\Framework\TestCase;

abstract class E2ETestCase extends TestCase {

	protected function setUp(): void {
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			$this->markTestSkipped( 'Run bin/test-wp.sh to execute the end-to-end tests.' );
		}
	}

	/**
	 * @return array{0: string, 1: string} Backup name and path.
	 */
	protected static function export( string $site, string ...$flags ): array {
		$out = WpSites::ok( $site, 'lw-migration', 'export', ...$flags );
		if ( 1 !== preg_match( '/Backup file: (\S+\.wpress)/', $out, $name ) || 1 !== preg_match( '/Backup location: (.+\.wpress)/', $out, $path ) ) {
			throw new \RuntimeException( "Unexpected export output:\n" . $out );
		}

		return [ $name[1], trim( $path[1] ) ];
	}

	/** @return array<int, string> */
	protected static function names( Reader $reader ): array {
		$names = [];
		foreach ( $reader->entries() as $entry ) {
			$names[] = $entry->name;
		}
		return $names;
	}

	/** @return array<int, string> Backup names of a site. */
	protected static function backups( string $site ): array {
		$list = json_decode( WpSites::ok( $site, 'lw-migration', 'list', '--format=json' ), true );

		return array_column( (array) $list, 'name' );
	}
}
