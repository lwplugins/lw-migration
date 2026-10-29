<?php
/**
 * The network, sites and subdomain archives made by the established .wpress tooling read cleanly.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use PHPUnit\Framework\TestCase;

final class NetworkFixturesTest extends TestCase {

	private const DIR = __DIR__ . '/../../Fixtures/wpress/';

	/** @return array<string, array{0: string, 1: bool, 2: array<int, int>}> */
	public static function archives(): array {
		return [
			'network'           => [ 'network.wpress', true, [ 1, 2, 3 ] ],
			'sites-2'           => [ 'sites-2.wpress', false, [ 2 ] ],
			'sites-1-3'         => [ 'sites-1-3.wpress', false, [ 1, 3 ] ],
			'subdomain-sites-2' => [ 'subdomain-sites-2.wpress', false, [ 2 ] ],
		];
	}

	/**
	 * @dataProvider archives
	 * @param array<int, int> $ids
	 */
	public function test_reference_made_network_archives( string $file, bool $network, array $ids ): void {
		$facts  = json_decode( (string) file_get_contents( self::DIR . 'network-fixtures.json' ), true );
		$reader = new Reader( self::DIR . $file );
		try {
			$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
			$names = [];
			$json  = null;
			foreach ( $reader->entries() as $entry ) {
				$names[] = $entry->name;
				$data    = $reader->readContents( $entry, 64 * 1048576 );
				if ( Format::MULTISITE === $entry->name ) {
					$json = $data;
				}
			}
		} finally {
			$reader->close();
		}
		$this->assertSame( [ Format::PACKAGE, Format::MULTISITE ], array_slice( $names, 0, 2 ) );
		$this->assertSame( Format::DATABASE, end( $names ) );
		$this->assertSame( $facts['entries'][ $file ], $names );
		$config = MultisiteConfig::fromJson( (string) $json );
		$this->assertSame( $network, $config->isNetwork() );
		$this->assertSame( $ids, array_map( static fn( array $s ): int => (int) $s['BlogID'], $config->sites() ) );
		$this->assertSame( [ 'Network', 'Networks', 'Sites', 'Plugin', 'Plugins', 'Admins' ], array_keys( (array) json_decode( (string) $json, true ) ) );
	}
}
