<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPrefixPairs;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use PHPUnit\Framework\TestCase;

final class NetworkPrefixPairsTest extends TestCase {

	public function test_a_network_archive_maps_the_plain_placeholder_to_the_base(): void {
		$pairs = NetworkPrefixPairs::build( self::import( ArchiveKind::NETWORK, [ [ 1, 1 ], [ 2, 2 ] ] ), 'wp_' );

		$this->assertSame( [ 'SERVMASK_PREFIX_' => 'wp_' ], $pairs['data'] );
		$this->assertSame( [], $pairs['exact'] );
	}

	public function test_a_sites_archive_with_its_main_site(): void {
		$pairs = NetworkPrefixPairs::build( self::import( ArchiveKind::SITES, [ [ 1, 7 ], [ 3, 8 ] ] ), 'wp_' );

		$this->assertSame(
			[
				'SERVMASK_PREFIX_mainsite_' => 'wp_lwmsrc_',
				'SERVMASK_PREFIX_3_'        => 'wp_8_',
				'SERVMASK_PREFIX_basesite_' => 'wp_7_',
				'SERVMASK_PREFIX_'          => 'wp_7_',
			],
			$pairs['data']
		);
		$this->assertSame( 'wp_lwmsrc_users', $pairs['exact']['SERVMASK_PREFIX_users'] );
		$this->assertSame( 'wp_lwmsrc_sitemeta', $pairs['exact']['SERVMASK_PREFIX_sitemeta'] );
		$this->assertSame( array_map( static fn( string $t ): string => 'SERVMASK_PREFIX_' . $t, NetworkPrefixPairs::GLOBAL_TABLES ), array_keys( $pairs['exact'] ) );
		$this->assertContains( 'blog_versions', NetworkPrefixPairs::GLOBAL_TABLES );
		$this->assertContains( 'sitecategories', NetworkPrefixPairs::GLOBAL_TABLES );
	}

	public function test_a_sites_archive_without_its_main_site_ends_with_the_staging_prefix(): void {
		$pairs = NetworkPrefixPairs::build( self::import( ArchiveKind::SITES, [ [ 2, 9 ] ] ), 'wp_' );

		$this->assertSame(
			[
				'SERVMASK_PREFIX_mainsite_' => 'wp_lwmsrc_',
				'SERVMASK_PREFIX_2_'        => 'wp_9_',
				'SERVMASK_PREFIX_'          => 'wp_lwmsrc_',
			],
			$pairs['data'],
			'Ruling R6: a table no mapped site claims is staged and dropped, never the main site\'s.'
		);
	}

	public function test_a_single_site_archive_lands_in_its_new_subsite(): void {
		$pairs = NetworkPrefixPairs::build( self::import( ArchiveKind::SINGLE, [ [ null, 5 ] ] ), 'wp_' );

		$this->assertSame( 'wp_5_', $pairs['data']['SERVMASK_PREFIX_'] );
		$this->assertSame( 'wp_5_', $pairs['data']['SERVMASK_PREFIX_basesite_'] );
		$this->assertSame( 'wp_lwmsrc_usermeta', $pairs['exact']['SERVMASK_PREFIX_usermeta'] );
	}

	public function test_no_pair_of_a_subsite_import_reaches_the_base_tables(): void {
		foreach ( [ [ ArchiveKind::SITES, [ [ 2, 9 ] ] ], [ ArchiveKind::SITES, [ [ 1, 7 ], [ 3, 8 ] ] ], [ ArchiveKind::SINGLE, [ [ null, 5 ] ] ] ] as [ $kind, $map ] ) {
			$pairs = NetworkPrefixPairs::build( self::import( $kind, $map ), 'wp_' );
			foreach ( array_merge( array_values( $pairs['data'] ), array_values( $pairs['exact'] ) ) as $new ) {
				$this->assertMatchesRegularExpression( '/^wp_(\d+_|lwmsrc_)/', $new );
			}
		}
	}

	public function test_an_unresolved_map_or_the_main_site_as_target_is_refused(): void {
		foreach ( [ [ [ 2, null ] ], [ [ 2, 1 ] ] ] as $map ) {
			try {
				NetworkPrefixPairs::build( self::import( ArchiveKind::SITES, $map ), 'wp_' );
				$this->fail( 'Expected a LogicException.' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'site', $e->getMessage() );
			}
		}
	}

	/**
	 * @param string                       $kind ArchiveKind value.
	 * @param array<int, array{0: int|null, 1: int|null}> $ids  Old id => new id pairs.
	 */
	private static function import( string $kind, array $ids ): NetworkImport {
		$maps = [];
		foreach ( $ids as [ $old, $new ] ) {
			$maps[] = [
				'old'   => [ 'BlogID' => $old ],
				'newId' => $new,
			];
		}

		return new NetworkImport( $kind, BlogMap::fromArray( $maps ), TargetNetwork::fromArray( [ 'domain' => 'new.test', 'path' => '/', 'basePrefix' => 'wp_' ] ) );
	}

	public function test_the_site_suffixes_name_every_mapped_site_and_only_a_subsite_import_has_them(): void {
		$this->assertSame( [ '7_', '8_' ], NetworkPrefixPairs::siteSuffixes( self::import( ArchiveKind::SITES, [ [ 1, 7 ], [ 3, 8 ] ] ) ) );
		$this->assertSame( [ '5_' ], NetworkPrefixPairs::siteSuffixes( self::import( ArchiveKind::SINGLE, [ [ null, 5 ] ] ) ) );
		$this->assertSame( [], NetworkPrefixPairs::siteSuffixes( self::import( ArchiveKind::NETWORK, [ [ 1, 1 ], [ 2, 2 ] ] ) ) );
	}
}
