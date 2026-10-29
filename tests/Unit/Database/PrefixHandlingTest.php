<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\ImportScope;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Import\ImportPlanFactory;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPrefixPairs;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security review: the prefixes of an import come from this site ($wpdb via
 * Site::$tablePrefix), the fixed placeholder and integer blog ids; the
 * archive's package.json Prefix is never read. Prefixes the import builds
 * are shape-checked, and every regex quotes them.
 */
final class PrefixHandlingTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostilePrefixes(): array {
		return [
			'quote'          => [ "wp_'; DROP TABLE wp_users; -- " ],
			'backtick'       => [ 'wp`_' ],
			'like wildcards' => [ '%_' ],
			'regex'          => [ '.*(?:x)[a-z]+$\\/#' ],
			'empty'          => [ '' ],
			'very long'      => [ str_repeat( 'p', 5000 ) . '_' ],
			'the site own'   => [ 'wp_' ],
			'the temporary'  => [ 'lwtab12_' ],
			'placeholder'    => [ PrefixRules::PLACEHOLDER ],
		];
	}

	#[DataProvider( 'hostilePrefixes' )]
	public function test_the_archive_prefix_never_reaches_the_plan( string $prefix ): void {
		foreach ( [ null, 2 ] as $subsite ) {
			$plain   = $this->plan( [], $subsite );
			$crafted = $this->plan( [ 'Prefix' => $prefix ], $subsite );

			$this->assertSame( 'wp_', $crafted->basePrefix );
			$this->assertSame( $plain->prefixPairs, $crafted->prefixPairs );
			$this->assertSame( $plain->exactTables, $crafted->exactTables );
			$this->assertSame( $plain->extraPrefixes, $crafted->extraPrefixes );
			$this->assertSame( $plain->leftOutPrefixes, $crafted->leftOutPrefixes );
		}
	}

	public function test_every_pair_of_a_single_site_or_subsite_plan_is_a_plain_identifier(): void {
		foreach ( [ null, 2, 4096 ] as $subsite ) {
			$this->assertPlainIdentifiers( $this->plan( [], $subsite )->prefixPairs );
		}
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function hostileBlogIds(): array {
		return [
			'quote'    => [ "3'; DROP TABLE x" ],
			'backtick' => [ '3`' ],
			'regex'    => [ '3.*' ],
			'negative' => [ -3 ],
			'float'    => [ 3.5 ],
			'huge'     => [ str_repeat( '9', 40 ) ],
		];
	}

	/**
	 * A blog id the archive names is read as a whole number or not at all
	 * (then the site counts as the main site).
	 *
	 * @param mixed $blogId BlogID from the archive.
	 */
	#[DataProvider( 'hostileBlogIds' )]
	public function test_a_crafted_blog_id_never_reaches_a_prefix_pair( $blogId ): void {
		$n     = new NetworkImport(
			ArchiveKind::SITES,
			BlogMap::fromArray( [ [ 'old' => [ 'BlogID' => $blogId ], 'newId' => 7 ] ] ),
			TargetNetwork::fromArray( [ 'domain' => 'new.test', 'path' => '/', 'basePrefix' => 'wp_' ] )
		);
		$pairs = NetworkPrefixPairs::build( $n, 'wp_' );

		$this->assertPlainIdentifiers( $pairs['data'] + $pairs['exact'] );
		$this->assertSame( 'wp_7_', $pairs['data'][ PrefixRules::PLACEHOLDER ] );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function badImportPrefixes(): array {
		return [
			'tmp quote'           => [ "lwt'abc_", [] ],
			'tmp backtick'        => [ 'lwtab1`_', [] ],
			'tmp percent'         => [ 'lwt%abc_', [] ],
			'tmp underscore'      => [ 'lwt_abc_', [] ],
			'tmp regex'           => [ 'lwt.*ab_', [] ],
			'tmp empty'           => [ '', [] ],
			'tmp long'            => [ 'lwtab12_' . str_repeat( 'a', 300 ), [] ],
			'tmp upper case'      => [ 'LWTAB12_', [] ],
			'tmp newline'         => [ "lwtab12_\n", [] ],
			'extra quote'         => [ 'lwtab12_', [ "wbk'_" ] ],
			'extra backtick'      => [ 'lwtab12_', [ 'wb`k_' ] ],
			'extra percent'       => [ 'lwtab12_', [ 'wb%_' ] ],
			'extra inner _'       => [ 'lwtab12_', [ 'a_b_' ] ],
			'extra regex'         => [ 'lwtab12_', [ 'w.k_' ] ],
			'extra empty'         => [ 'lwtab12_', [ '' ] ],
			'extra temporary'     => [ 'lwtab12_', [ 'lwtx_' ] ],
			'extra newline'       => [ 'lwtab12_', [ "wbk_\n" ] ],
			'extra no underscore' => [ 'lwtab12_', [ 'wbk' ] ],
		];
	}

	/**
	 * @param string             $tmp   Temporary prefix.
	 * @param array<int, string> $extra Extra prefixes.
	 */
	#[DataProvider( 'badImportPrefixes' )]
	public function test_the_importer_refuses_a_prefix_of_the_wrong_shape( string $tmp, array $extra ): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/db.sql', '' );
		$reader = new StatementReader( $dir . '/db.sql' );
		try {
			$this->expectException( DatabaseException::class );
			new Importer( new FakeConnection(), $reader, new ImportPlan( 'wp_', $tmp, PrefixRules::importPairs( 'wp_' ), new Replacer( [] ), new SchemaFixer( '8.0.36' ), $dir . '/v.sql', 1048576, $extra ) );
		} finally {
			$reader->close();
			TempDir::remove( $dir );
		}
	}

	public function test_regex_metacharacters_in_prefixes_are_literal(): void {
		$prefixes = [ 'a.b_', 'x(y_', 'z/_', 'q$_' ];

		$this->assertNull( PrefixRules::leftOutTable( 'INSERT INTO `aXb_t` VALUES (1);', $prefixes ) );
		$this->assertSame( 'a.b_t', PrefixRules::leftOutTable( 'INSERT INTO `a.b_t` VALUES (1);', $prefixes ) );
		$this->assertSame( 'z/_t', PrefixRules::leftOutTable( 'DROP TABLE IF EXISTS `z/_t`;', $prefixes ) );
		$this->assertSame( 'INSERT INTO `aXb_t` VALUES (1);', PrefixRules::mapExtraTables( 'INSERT INTO `aXb_t` VALUES (1);', $prefixes, 'lwxab12_' ) );
		$this->assertSame( 'INSERT INTO `lwxab12_q$_t` VALUES (1);', PrefixRules::mapExtraTables( 'INSERT INTO `q$_t` VALUES (1);', $prefixes, 'lwxab12_' ) );
	}

	public function test_the_import_scope_matches_its_prefixes_literally(): void {
		$s = new ImportScope( 'w.p_', 'lwtab12_' );

		$this->assertNull( $s->deferrableView( 'DROP VIEW IF EXISTS `wXp_v`;' ) );
		$this->assertNotNull( $s->deferrableView( 'DROP VIEW IF EXISTS `w.p_v`;' ) );
		$this->assertFalse( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwtab12Xt`;' ) );
	}

	/**
	 * @param array<string, string> $pairs Old => new prefixes.
	 */
	private function assertPlainIdentifiers( array $pairs ): void {
		foreach ( $pairs as $old => $new ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+\z/', (string) $old );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+\z/', $new );
		}
	}

	/**
	 * @param array<string, mixed> $extra   package.json additions.
	 * @param int|null             $subsite Sub-site id.
	 */
	private function plan( array $extra, ?int $subsite ): ImportPlan {
		$package = PackageConfig::fromArray( $extra + [ 'SiteURL' => 'https://old.test', 'HomeURL' => 'https://old.test' ] );

		return ImportPlanFactory::build( new FakeConnection(), $package, SiteFixture::make( '/s' ), 'lwtab12_', '/tmp/x.sql', $subsite, static fn( string $p ): bool => false, static fn( string $t ): bool => false );
	}
}
