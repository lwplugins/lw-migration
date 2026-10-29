<?php
/**
 * Network backups on a real subdirectory network, compared with the reference-made archives.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;

final class NetworkExportE2ETest extends E2ETestCase {

	private const FIXTURES = __DIR__ . '/../Fixtures/wpress';

	private const HOME = 'http://net-n.test';

	/** @var array<string, array{0: string, 1: string}> Backups made in setUpBeforeClass: fixture => [name, path]. */
	private static array $made = [];

	/** @var array{0: string, 1: string} The --sites=2 backup while blog 2 has a theme of its own. */
	private static array $ownTheme = [ '', '' ];

	public static function setUpBeforeClass(): void {
		if ( ! WpSites::hasNetworks() ) {
			return;
		}
		NetworkSeed::ensure();
		$flags                          = [ '--exclude-inactive-themes', '--exclude-inactive-plugins', '--exclude-cache' ];
		self::$made['network.wpress']   = self::export( 'n', ...$flags );
		self::$made['sites-2.wpress']   = self::export( 'n', '--sites=2', ...$flags );
		self::$made['sites-1-3.wpress'] = self::export( 'n', '--sites=1,3', ...$flags );
		self::ownTheme( WpSites::path( 'n' ), 'lwm-b2-theme' );
		self::$ownTheme = self::export( 'n', '--sites=2', ...$flags );
		self::ownTheme( WpSites::path( 'n' ), 'lwm-ref-theme' );
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh to execute the network tests.' );
		}
	}

	public function test_entries_match_the_reference_layout(): void {
		$fixtures = json_decode( (string) file_get_contents( self::FIXTURES . '/network-fixtures.json' ), true );
		foreach ( self::$made as $file => [ , $path ] ) {
			$ours   = self::read( $path );
			$theirs = self::read( self::FIXTURES . '/' . $file );

			$this->assertSame( 'package.json', $ours['names'][0], $file );
			$this->assertSame( 'multisite.json', $ours['names'][1], $file );
			$this->assertSame( 'database.sql', end( $ours['names'] ), $file );
			$expected = $fixtures['entries'][ $file ];
			$this->assertSame( $theirs['names'], $expected, 'The fixture file and its list agree: ' . $file );
			$this->assertSame( self::sorted( $expected ), self::sorted( self::withoutOurPlugin( $ours['names'] ) ), $file );

			$this->assertSame( array_keys( $theirs['multisite'] ), array_keys( $ours['multisite'] ), $file );
			$this->assertSame( array_keys( $theirs['multisite']['Sites'][0] ), array_keys( $ours['multisite']['Sites'][0] ), $file );
			$this->assertSame( array_column( $theirs['multisite']['Sites'], 'BlogID' ), array_column( $ours['multisite']['Sites'], 'BlogID' ), $file );
			$this->assertSame( $theirs['multisite']['Network'], $ours['multisite']['Network'], $file );
			$this->assertSame( $theirs['multisite']['Plugins'], $ours['multisite']['Plugins'], $file );
			$this->assertSame( $theirs['multisite']['Admins'], $ours['multisite']['Admins'], $file );
			$strip = static fn( array $site ): array => array_diff_key( $site, [ 'WordPress' => true ] );
			$this->assertSame( array_map( $strip, $theirs['multisite']['Sites'] ), array_map( $strip, $ours['multisite']['Sites'] ), 'Same values but the server paths: ' . $file );

			$tables = $fixtures['tables'][ $file ];
			$this->assertSame( $tables, $theirs['tables'], 'The fixture file and its table list agree: ' . $file );
			$this->assertSame( array_values( array_intersect( $tables, $ours['tables'] ) ), array_values( array_intersect( $ours['tables'], $tables ) ), 'Same order: ' . $file );
			$this->assertSame( $tables, array_values( array_intersect( $ours['tables'], $tables ) ), 'Every reference table is there: ' . $file );
		}
	}

	public function test_a_subsite_with_its_own_theme_leaves_the_main_site_theme_out(): void {
		$ours   = self::read( self::$ownTheme[1] );
		$theirs = self::read( self::FIXTURES . '/sites-2-own-theme.wpress' );

		$this->assertSame( self::sorted( $theirs['names'] ), self::sorted( self::withoutOurPlugin( $ours['names'] ) ) );
		$this->assertContains( 'themes/lwm-b2-theme/style.css', $ours['names'] );
		$this->assertSame( [], preg_grep( '#^themes/lwm-ref-theme/#', $ours['names'] ), 'As the reference: only the chosen sites\' themes.' );
		$strip = static fn( array $site ): array => array_diff_key( $site, [ 'WordPress' => true ] );
		$this->assertSame( array_map( $strip, $theirs['multisite']['Sites'] ), array_map( $strip, $ours['multisite']['Sites'] ) );
		$this->assertSame( $theirs['tables'], $ours['tables'] );
	}

	public function test_chosen_sites_carry_their_posts_users_and_uploads(): void {
		$sites = self::read( self::$made['sites-2.wpress'][1] );
		$this->assertStringContainsString( 'Network Fixture B2', $sites['sql'] );
		$this->assertStringNotContainsString( 'Network Fixture B3', $sites['sql'] );
		$this->assertStringNotContainsString( 'Network Fixture Main', $sites['sql'] );
		$this->assertStringContainsString( "'editor2'", $sites['sql'] );
		$this->assertStringNotContainsString( "'author3'", $sites['sql'] );
		$this->assertStringNotContainsString( "'lw_migration_", $sites['sql'] );
		$this->assertContains( 'uploads/sites/2/2026/10/b2.txt', $sites['names'] );

		$network = self::read( self::$made['network.wpress'][1] );
		foreach ( [ 'Network Fixture Main', 'Network Fixture B2', 'Network Fixture B3', "'editor2'", "'author3'" ] as $text ) {
			$this->assertStringContainsString( $text, $network['sql'] );
		}
		$this->assertNull( WpSites::lock( 'n' ), 'A finished network backup releases the lock.' );
	}

	public function test_a_subsite_url_is_refused(): void {
		$before = self::backups( 'n' );

		[ $code, , $err ] = WpSites::wpUrl( 'n', self::HOME . '/b2/', 'lw-migration', 'export' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( "Run this command on the network's main site", $err );
		$this->assertSame( $before, self::backups( 'n' ) );
	}

	public function test_unknown_sites_are_refused(): void {
		$before = self::backups( 'n' );

		[ $code, , $err ] = WpSites::wp( 'n', 'lw-migration', 'export', '--sites=2,99' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'There is no site with ID 99 in this network.', $err );
		$this->assertSame( $before, self::backups( 'n' ) );
		$this->assertNull( WpSites::lock( 'n' ) );
	}

	/**
	 * Blog 2 switches to $theme (made once, enabled for the network).
	 */
	private static function ownTheme( string $net, string $theme ): void {
		$dir = $net . '/wp-content/themes/' . $theme;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
			file_put_contents( $dir . '/style.css', "/*\nTheme Name: LWM B2 Theme\n*/\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
			WpSites::ok( 'n', 'theme', 'enable', $theme, '--network' );
		}
		NetworkSeed::okUrl( self::HOME . '/b2/', 'theme', 'activate', $theme );
		WpSites::ok( 'n', 'transient', 'delete', '--all', '--network' );
	}

	/**
	 * @return array{names: array<int, string>, multisite: array<string, mixed>, sql: string, tables: array<int, string>}
	 */
	private static function read( string $path ): array {
		$reader    = new Reader( $path );
		$names     = self::names( $reader );
		$multisite = $reader->find( 'multisite.json' );
		$database  = $reader->find( 'database.sql' );
		if ( null === $multisite || null === $database ) {
			throw new \RuntimeException( 'No multisite.json or database.sql in ' . basename( $path ) );
		}
		$json = (array) json_decode( $reader->readContents( $multisite ), true );
		$sql  = $reader->readContents( $database, 64 * 1048576 );
		$reader->close();
		preg_match_all( '/^CREATE TABLE `([^`]+)`/m', $sql, $m );

		return [
			'names'     => $names,
			'multisite' => $json,
			'sql'       => $sql,
			'tables'    => $m[1],
		];
	}

	/**
	 * @param array<int, string> $names Entry names.
	 * @return array<int, string>
	 */
	private static function withoutOurPlugin( array $names ): array {
		return array_values( array_filter( $names, static fn( string $n ): bool => ! str_starts_with( $n, 'plugins/lw-migration/' ) ) );
	}

	/**
	 * @param array<int, string> $names Entry names.
	 * @return array<int, string>
	 */
	private static function sorted( array $names ): array {
		sort( $names );

		return $names;
	}
}
