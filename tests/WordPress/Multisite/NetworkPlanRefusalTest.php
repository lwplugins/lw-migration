<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkSummary;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The translated refusals and lines of the pure planning classes (ArchiveKind, BlogMap, NetworkSummary).
 */
final class NetworkPlanRefusalTest extends MonkeyTestCase {

	/** @return array<string, array{0: string}> */
	public static function emptyArchives(): array {
		return [
			'sites'   => [ '{"Network":false,"Sites":[]}' ],
			'network' => [ '{"Network":true,"Sites":[]}' ],
		];
	}

	/**
	 * @dataProvider emptyArchives
	 */
	public function test_an_archive_without_sites_is_refused( string $json ): void {
		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'lists no site' );
		ArchiveKind::of( MultisiteConfig::fromJson( $json ) );
	}

	public function test_two_sites_with_the_same_address_are_both_refused(): void {
		$map = $this->sitesMap()->withAddresses( [ 0 => 'https://new.test/b3/' ], self::target() );

		$this->assertSame(
			[
				0 => 'Two sites of the archive cannot get the same address.',
				1 => 'Two sites of the archive cannot get the same address.',
			],
			$map->problems( self::target() )
		);
	}

	public function test_every_address_is_checked_also_the_kept_proposals(): void {
		$map = BlogMap::plan( ArchiveKind::SITES, [ SiteEntry::fromArray( [ 'BlogID' => 1, 'HomeURL' => 'http://old.test', 'Domain' => 'old.test', 'Path' => '/' ] ) ], 'http://old.test', self::target() );
		$this->assertSame( 'https://new.test', $map->get( 0 )['url'], 'The old main site is proposed at the main address, which must be changed.' );

		$this->assertSame( [ 0 => 'The main site cannot be replaced by a subsite import: choose another address.' ], $map->problems( self::target() ) );
	}

	public function test_a_refused_address_is_kept_as_given_so_it_can_be_shown(): void {
		$map = $this->sitesMap()->withAddresses( [ 1 => 'http://new.test/shop', 0 => [ 'x' ] ], self::target() );

		$this->assertSame( [ '', 'http://new.test/shop' ], array_column( $map->all(), 'url' ) );
		$problems = $map->problems( self::target() );
		$this->assertStringContainsString( 'full http or https address', $problems[0] );
		$this->assertSame( 'Use the network\'s scheme (https).', $problems[1] );
	}

	public function test_a_refused_address_is_stored_without_control_characters_and_clipped(): void {
		$map = $this->sitesMap()->withAddresses( [ 0 => "https://new.test/a\x1b[2J\nb\u{85}c" . str_repeat( 'é', 300 ) ], self::target() );

		$url = $map->get( 0 )['url'];
		$this->assertSame( 200, mb_strlen( $url, 'UTF-8' ) );
		$this->assertStringStartsWith( 'https://new.test/a[2Jbc', $url );
		$this->assertSame( 0, preg_match( '/\p{Cc}/u', $url ) );
		$this->assertArrayHasKey( 0, $map->problems( self::target() ), 'It is still refused.' );
	}

	/** @return array<string, array{0: array<mixed>, 1: string}> */
	public static function badAnswers(): array {
		return [
			'an index the archive lacks' => [ [ 5 => 'https://new.test/x' ], 'The archive has no site number 5.' ],
			'a text index'               => [ [ 'a' => 'https://new.test/x' ], 'The archive has no site number a.' ],
		];
	}

	/**
	 * @dataProvider badAnswers
	 * @param array<mixed> $given
	 */
	public function test_an_answer_for_a_site_the_archive_lacks_is_refused( array $given, string $reason ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $reason );
		$this->sitesMap()->withAddresses( $given, self::target() );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function damagedArchives(): array {
		return [
			'two sites with one id' => [ 'sites', '[{"BlogID":2,"Domain":"old.test","Path":"/b2/"},{"BlogID":"2","Domain":"old.test","Path":"/x/"}]' ],
			'an id that is no number' => [ 'sites', '[{"BlogID":"x","Domain":"old.test","Path":"/b2/"}]' ],
			'a missing id'           => [ 'network', '[{"BlogID":1,"Domain":"old.test","Path":"/"},{"Domain":"old.test","Path":"/b2/"}]' ],
			'a broken-down id'       => [ 'network', '[{"BlogID":1,"Domain":"old.test","Path":"/"},{"BlogID":2.5,"Domain":"old.test","Path":"/b2/"}]' ],
			'id zero'                => [ 'sites', '[{"BlogID":0,"Domain":"old.test","Path":"/"}]' ],
		];
	}

	/**
	 * @dataProvider damagedArchives
	 */
	public function test_an_archive_with_missing_or_repeated_site_ids_is_refused( string $kind, string $sites ): void {
		$entries = MultisiteConfig::fromJson( '{"Network":' . ( 'network' === $kind ? 'true' : 'false' ) . ',"Sites":' . $sites . '}' )->entries();

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The archive\'s multisite.json lists a site without a valid ID, or one ID twice, so it cannot be restored. The archive may be damaged.' );
		BlogMap::plan( $kind, $entries, 'http://old.test', self::target() );
	}

	public function test_a_network_archive_whose_sites_would_share_an_address_is_refused(): void {
		$entries = [
			SiteEntry::fromArray( [ 'BlogID' => 1, 'Domain' => 'old.test', 'Path' => '/' ] ),
			SiteEntry::fromArray( [ 'BlogID' => 2, 'Domain' => 'b2.old.test', 'Path' => '/' ] ),
			SiteEntry::fromArray( [ 'BlogID' => 3, 'Domain' => 'old.test', 'Path' => '/b2/' ] ),
		];

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'Two sites of the archive would get the same address on this network (https://new.test/b2), so it cannot be restored here.' );
		BlogMap::plan( ArchiveKind::NETWORK, $entries, 'http://old.test', self::target() );
	}

	public function test_a_refused_address_in_another_encoding_loses_its_c1_control_bytes_too(): void {
		$map = $this->sitesMap()->withAddresses( [ 0 => "https://new.test/\xff\x85a\x9bb\x1bc" ], self::target() );

		$this->assertSame( "https://new.test/?abc", $map->get( 0 )['url'], 'The invalid byte is shown as a question mark.' );
	}

	public function test_the_old_address_of_an_archive_site_is_shown_without_control_characters(): void {
		$entry   = SiteEntry::fromArray( [ 'BlogID' => 2, 'HomeURL' => "http://old.test/b2\x1b[31m\u{85}x", 'Domain' => 'old.test', 'Path' => '/b2/' ] );
		$network = NetworkSummary::build( ArchiveKind::SITES, BlogMap::plan( ArchiveKind::SITES, [ $entry ], 'http://old.test', self::target() ), [] );

		$this->assertSame( 'http://old.test/b2[31mx', $network['sites'][0]['from']['url'] );
		$this->assertStringNotContainsString( "\x1b", implode( "\n", NetworkSummary::lines( $network ) ) );
	}

	public function test_lines(): void {
		$entries = [
			SiteEntry::fromArray( [ 'BlogID' => 1, 'HomeURL' => 'http://old.test', 'Domain' => 'old.test', 'Path' => '/' ] ),
			SiteEntry::fromArray( [ 'BlogID' => 2, 'HomeURL' => 'http://old.test/b2', 'Domain' => 'old.test', 'Path' => '/b2/' ] ),
			SiteEntry::fromArray( [ 'BlogID' => 3, 'HomeURL' => 'http://old.test/b3', 'Domain' => 'old.test', 'Path' => '/b3/' ] ),
		];
		$network = NetworkSummary::build( ArchiveKind::NETWORK, BlogMap::plan( ArchiveKind::NETWORK, $entries, 'http://old.test', self::target() ), [ [ 'id' => 4, 'url' => 'https://new.test/b4' ], [ 'id' => 5, 'url' => 'https://new.test/b5' ] ] );

		$this->assertSame(
			[
				'Network archive: 3 sites keep their IDs.',
				'Site http://old.test -> https://new.test',
				'Site http://old.test/b2 -> https://new.test/b2',
				'Site http://old.test/b3 -> https://new.test/b3',
				'Not in the archive: https://new.test/b4, https://new.test/b5. These sites stay on the network marked as deleted (Network Admin > Sites), with their tables and files kept.',
			],
			NetworkSummary::lines( $network )
		);

		$sites          = NetworkSummary::build( ArchiveKind::SITES, $this->sitesMap(), [], [ 1 => 3 ], [ 0 => 'WordPress reserves the name "blog". Choose another one.' ] );
		$sites['error'] = 'The archive has no site number 5.';
		$this->assertSame(
			[
				'Site http://old.test/b2 -> https://new.test/b2 is refused: WordPress reserves the name "blog". Choose another one.',
				'Site http://old.test/b3 -> https://new.test/b3 (replaces the site there)',
				'Answer refused: The archive has no site number 5.',
			],
			NetworkSummary::lines( $sites )
		);
		$this->assertSame(
			[
				'Site http://old.test/b2 -> https://new.test/b2 is refused: WordPress reserves the name "blog". Choose another one.',
				'Answer refused: The archive has no site number 5.',
			],
			NetworkSummary::refusals( $sites )
		);
		$this->assertSame( [], NetworkSummary::refusals( $network ) );
	}

	private function sitesMap(): BlogMap {
		return BlogMap::plan(
			ArchiveKind::SITES,
			[
				SiteEntry::fromArray( [ 'BlogID' => 2, 'HomeURL' => 'http://old.test/b2', 'Domain' => 'old.test', 'Path' => '/b2/' ] ),
				SiteEntry::fromArray( [ 'BlogID' => 3, 'HomeURL' => 'http://old.test/b3', 'Domain' => 'old.test', 'Path' => '/b3/' ] ),
			],
			'http://old.test',
			self::target()
		);
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
