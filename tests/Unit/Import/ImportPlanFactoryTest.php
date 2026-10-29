<?php
/**
 * Tests for ImportPlanFactory.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Import\ImportPlanFactory;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use PHPUnit\Framework\TestCase;

final class ImportPlanFactoryTest extends TestCase {

	public function test_plan_replaces_urls_and_maps_a_subsite(): void {
		$package = PackageConfig::fromArray(
			[
				'SiteURL'   => 'https://old.test',
				'HomeURL'   => 'https://old.test',
				'WordPress' => [ 'UploadsURL' => 'https://old.test/wp-content/uploads/sites/2/' ],
				'Plugins'   => [ 'js_composer/js_composer.php' ],
			]
		);
		$site    = SiteFixture::make( '/s', [ 'siteUrl' => 'https://new.test', 'homeUrl' => 'https://new.test', 'uploadsUrl' => 'https://new.test/wp-content/uploads/' ] );
		$plan    = ImportPlanFactory::build( new FakeConnection(), $package, $site, 'lwtab12_', '/tmp/x.sql', 2, static fn( string $p ): bool => false, static fn( string $t ): bool => false );

		$this->assertSame( 'wp_', $plan->basePrefix );
		$this->assertSame( 'lwtab12_', $plan->tmpPrefix );
		$this->assertSame( 'wp_', $plan->prefixPairs['SERVMASK_PREFIX_2_'] );
		$this->assertSame( 'wp_sitemeta', $plan->prefixPairs['SERVMASK_PREFIX_mainsite_sitemeta'] );
		$this->assertSame( 16777216, $plan->maxPacket );
		$this->assertStringContainsString( 'https://new.test/wp-content/uploads/a.jpg', $plan->replacer->statement( "INSERT INTO `t` VALUES ('https://old.test/wp-content/uploads/sites/2/a.jpg')" ) );
	}

	public function test_packet_size_comes_from_the_server_with_a_fallback(): void {
		$db                                   = new FakeConnection();
		$db->variables['max_allowed_packet'] = '67108864';
		$this->assertSame( 67108864, $this->plan( $db, null )->maxPacket );

		$db->variables['max_allowed_packet'] = '0';
		$this->assertSame( 16777216, $this->plan( $db, null )->maxPacket );
	}

	public function test_full_site_archive_has_no_subsite_or_sitemeta_pairs(): void {
		$this->assertSame(
			[
				'SERVMASK_PREFIX_mainsite_users'    => 'wp_users',
				'SERVMASK_PREFIX_mainsite_usermeta' => 'wp_usermeta',
				'SERVMASK_PREFIX_basesite_'         => 'wp_',
				'SERVMASK_PREFIX_'                  => 'wp_',
			],
			$this->plan( new FakeConnection(), null )->prefixPairs
		);
	}

	private function plan( FakeConnection $db, ?int $subsiteId ): \LightweightPlugins\Migration\Database\ImportPlan {
		$package = PackageConfig::fromArray(
			[
				'SiteURL' => 'https://old.test',
				'HomeURL' => 'https://old.test',
			]
		);

		return ImportPlanFactory::build( $db, $package, SiteFixture::make( '/s' ), 'lwtab12_', '/tmp/x.sql', $subsiteId, static fn( string $p ): bool => false, static fn( string $t ): bool => false );
	}

	public function test_a_network_plan_keeps_the_ids_and_moves_every_site(): void {
		$plan = $this->networkPlan( ArchiveKind::NETWORK, [ [ 1, 1, 'http://new.test' ], [ 2, 2, 'http://b2.new.test' ] ] );

		$this->assertSame( [ 'SERVMASK_PREFIX_' => 'wp_' ], $plan->prefixPairs );
		$this->assertSame( [], $plan->exactTables );
		$this->assertSame( ImportPlan::DEFAULT_EXTRA_PREFIXES, $plan->extraPrefixes );
		$this->assertSame( [], $plan->leftOutPrefixes );
		$this->assertStringContainsString( "'http://b2.new.test/hello/','http://new.test/about'", $plan->replacer->statement( "INSERT INTO `t` VALUES ('http://old.test/b2/hello/','http://old.test/about')" ) );
	}

	public function test_a_sites_plan_stages_the_network_tables_and_leaves_out_the_extra_prefixes(): void {
		$plan = $this->networkPlan( ArchiveKind::SITES, [ [ 2, 7, 'http://new.test/shop' ] ] );

		$this->assertSame( 'wp_7_', $plan->prefixPairs['SERVMASK_PREFIX_2_'] );
		$this->assertSame( 'wp_lwmsrc_', $plan->prefixPairs['SERVMASK_PREFIX_'] );
		$this->assertSame( 'wp_lwmsrc_users', $plan->exactTables['SERVMASK_PREFIX_users'] );
		$this->assertSame( [], $plan->extraPrefixes, 'Ruling R6: a subsite import never brings tables outside the network prefix.' );
		$this->assertSame( ImportPlan::DEFAULT_EXTRA_PREFIXES, $plan->leftOutPrefixes );
		$this->assertStringContainsString( 'http://new.test/shop/hello/', $plan->replacer->statement( "INSERT INTO `t` VALUES ('http://old.test/b2/hello/')" ) );
	}

	/**
	 * Task 7 carry: a page builder that is only network-active is detected too.
	 */
	public function test_a_network_wide_page_builder_is_detected(): void {
		$raw       = base64_encode( '<a href="http://old.test/b2/x">x</a>' );
		$statement = "INSERT INTO `t` VALUES ('[vc_raw_html]{$raw}[/vc_raw_html]')";
		$moved     = base64_encode( '<a href="http://b2.new.test/x">x</a>' );

		$plain   = $this->networkPlan( ArchiveKind::NETWORK, [ [ 1, 1, 'http://new.test' ], [ 2, 2, 'http://b2.new.test' ] ] );
		$builder = $this->networkPlan( ArchiveKind::NETWORK, [ [ 1, 1, 'http://new.test' ], [ 2, 2, 'http://b2.new.test' ] ], [ 'js_composer/js_composer.php' ] );

		$this->assertStringContainsString( $raw, $plain->replacer->statement( $statement ) );
		$this->assertStringContainsString( $moved, $builder->replacer->statement( $statement ) );
	}

	/**
	 * @param string                                       $kind    ArchiveKind value.
	 * @param array<int, array{0: int, 1: int, 2: string}> $ids     Old id, new id, new address.
	 * @param array<int, string>                           $plugins Network-active plugins of the archive.
	 */
	private function networkPlan( string $kind, array $ids, array $plugins = [] ): ImportPlan {
		$maps = [];
		foreach ( $ids as [ $old, $new, $url ] ) {
			$maps[] = [
				'old'           => [
					'BlogID'    => $old,
					'SiteURL'   => 1 === $old ? 'http://old.test' : 'http://old.test/b' . $old,
					'HomeURL'   => 1 === $old ? 'http://old.test' : 'http://old.test/b' . $old,
					'WordPress' => [ 'UploadsURL' => 'http://old.test/wp-content/uploads/' . ( 1 === $old ? '' : 'sites/' . $old . '/' ) ],
				],
				'newId'         => $new,
				'newSiteUrl'    => $url,
				'newHomeUrl'    => $url,
				'newUploadsUrl' => 'http://new.test/wp-content/uploads/' . ( 1 === $new ? '' : 'sites/' . $new . '/' ),
			];
		}
		$to      = TargetNetwork::fromArray( [ 'subdomain' => ArchiveKind::NETWORK === $kind, 'domain' => 'new.test', 'path' => '/', 'scheme' => 'http', 'uploadsUrl' => 'http://new.test/wp-content/uploads', 'basePrefix' => 'wp_' ] );
		$package = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] );
		$site    = SiteFixture::make( '/s', [ 'siteUrl' => 'http://new.test', 'homeUrl' => 'http://new.test', 'uploadsUrl' => 'http://new.test/wp-content/uploads/' ] );

		return ImportPlanFactory::build( new FakeConnection(), $package, $site, 'lwtab12_', '/tmp/x.sql', null, static fn( string $p ): bool => false, static fn( string $t ): bool => false, new NetworkImport( $kind, BlogMap::fromArray( $maps ), $to, $plugins ) );
	}
}
