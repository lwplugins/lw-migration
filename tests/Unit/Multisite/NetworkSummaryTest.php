<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkSummary;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lines (translated) are in tests/WordPress/Multisite/NetworkPlanRefusalTest.php.
 */
final class NetworkSummaryTest extends TestCase {

	public function test_the_network_part_lists_every_site_and_the_unlisted_ones(): void {
		$map     = BlogMap::plan(
			ArchiveKind::NETWORK,
			[
				SiteEntry::fromArray( [ 'BlogID' => 1, 'HomeURL' => 'http://old.test', 'Domain' => 'old.test', 'Path' => '/' ] ),
				SiteEntry::fromArray( [ 'BlogID' => 2, 'HomeURL' => 'http://old.test/b2', 'Domain' => 'old.test', 'Path' => '/b2/' ] ),
			],
			'http://old.test',
			self::target()
		);
		$summary = NetworkSummary::build( ArchiveKind::NETWORK, $map, [ [ 'id' => 4, 'url' => 'https://new.test/b4' ] ] );

		$this->assertSame(
			[
				'kind'     => 'network',
				'sites'    => [
					[
						'index'    => 0,
						'from'     => [ 'blogId' => 1, 'url' => 'http://old.test' ],
						'proposal' => 'https://new.test',
						'address'  => 'https://new.test',
						'existing' => null,
						'error'    => null,
					],
					[
						'index'    => 1,
						'from'     => [ 'blogId' => 2, 'url' => 'http://old.test/b2' ],
						'proposal' => 'https://new.test/b2',
						'address'  => 'https://new.test/b2',
						'existing' => null,
						'error'    => null,
					],
				],
				'unlisted' => [ [ 'id' => 4, 'url' => 'https://new.test/b4' ] ],
			],
			$summary
		);
	}

	/**
	 * Final review M1: valid UTF-8 loses only its control code points (C0,
	 * DEL, C1) and keeps every other character; only invalid UTF-8 shows
	 * question marks.
	 *
	 * @param string $text     Text from an archive.
	 * @param string $expected What is shown.
	 */
	#[DataProvider( 'printables' )]
	public function test_printable_strips_control_code_points_and_keeps_unicode( string $text, string $expected ): void {
		$this->assertSame( $expected, NetworkSummary::printable( $text ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function printables(): array {
		return [
			'unicode host'          => [ "http://b\u{00FC}cher.\u{4F8B}\u{3048}.test/\u{00E1}rv\u{00ED}zt\u{0171}r\u{0151}", "http://b\u{00FC}cher.\u{4F8B}\u{3048}.test/\u{00E1}rv\u{00ED}zt\u{0171}r\u{0151}" ],
			'C0, DEL and C1 points' => [ "http://a.test/\x1B[2J\x7F\u{0085}\u{009B}x\u{00A0}y", "http://a.test/[2Jx\u{00A0}y" ],
			'NUL and newline'       => [ "http://a.test/\0b\nc", 'http://a.test/bc' ],
			'invalid UTF-8'         => [ "http://a.test/\xC3(\xFF\u{00FC}\xC2\x85z", "http://a.test/?(?\u{00FC}z" ],
		];
	}

	public function test_a_given_address_and_an_existing_site_show(): void {
		$map = BlogMap::plan( ArchiveKind::SINGLE, [ SiteEntry::fromArray( [ 'HomeURL' => 'https://shop.example' ] ) ], 'https://shop.example', self::target() )
			->withAddresses( [ 0 => 'https://new.test/store' ], self::target() );

		$site = NetworkSummary::build( ArchiveKind::SINGLE, $map, [], [ 0 => 5 ] )['sites'][0];
		$this->assertSame( [ 'blogId' => null, 'url' => 'https://shop.example' ], $site['from'] );
		$this->assertSame( [ 'https://new.test/shop', 'https://new.test/store', 5, null ], [ $site['proposal'], $site['address'], $site['existing'], $site['error'] ] );
		$this->assertSame( 'Refused.', NetworkSummary::build( ArchiveKind::SINGLE, $map, [], [], [ 0 => 'Refused.' ] )['sites'][0]['error'] );
	}

	private static function target(): TargetNetwork {
		return TargetNetwork::fromArray(
			[
				'subdomain' => false,
				'domain'    => 'new.test',
				'path'      => '/',
				'scheme'    => 'https',
			]
		);
	}
}
