<?php
/**
 * Base class of the restores into real networks.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * Network n is seeded once; n, s and a are backed up before the first test
 * of a class, and each test lists the sites it changes, which tearDown()
 * restores from those backups.
 */
abstract class NetworkE2ETestCase extends E2ETestCase {

	protected const FIXTURES = __DIR__ . '/../Fixtures/wpress/';

	protected const S = 'http://net-s.test';

	/** @var array<string, string> Site => its backup made in setUpBeforeClass. */
	protected static array $resets = [];

	/** @var array<string, array<int, string>> Network => its site ids before the class (the reset keeps only these). */
	protected static array $siteIds = [];

	/** @var array<int, string> Sites the current test changes. */
	protected array $changed = [];

	public static function setUpBeforeClass(): void {
		if ( ! WpSites::hasNetworks() ) {
			return;
		}
		NetworkSeed::ensure();
		foreach ( [ 'n', 's', 'a' ] as $site ) {
			static::$resets[ $site ] = self::export( $site )[1];
		}
		foreach ( [ 'n', 's' ] as $network ) {
			static::$siteIds[ $network ] = self::lines( WpSites::ok( $network, 'site', 'list', '--field=blog_id' ) );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh to execute the network tests.' );
		}
		$this->changed = [];
	}

	/**
	 * Every changed site is restored, also when one restore fails; a restore
	 * failure fails a passing test and is printed after a failing test's own
	 * failure (never instead of it).
	 */
	protected function tearDown(): void {
		$errors = [];
		foreach ( array_unique( $this->changed ) as $site ) {
			try {
				WpSites::ok( $site, 'lw-migration', 'import', static::$resets[ $site ], '--yes', '--user=admin', '--no-safety-backup' );
				self::dropAddedSites( $site );
			} catch ( \Throwable $e ) {
				$errors[] = $site . ': ' . $e->getMessage();
			}
		}
		parent::tearDown();
		if ( [] === $errors ) {
			return;
		}
		$message = "Restoring the changed sites after the test failed:\n" . implode( "\n", $errors );
		if ( $this->status()->isSuccess() ) {
			throw new \RuntimeException( $message );
		}
		fwrite( STDERR, "\n" . $message . "\n" );
	}

	/**
	 * A network restore keeps the sites its archive leaves out (as deleted
	 * sites); the sites a test added are removed, so the next test starts
	 * from the same network.
	 */
	private static function dropAddedSites( string $site ): void {
		if ( ! isset( static::$siteIds[ $site ] ) ) {
			return;
		}
		foreach ( array_diff( self::lines( WpSites::ok( $site, 'site', 'list', '--field=blog_id' ) ), static::$siteIds[ $site ] ) as $id ) {
			WpSites::ok( $site, 'site', 'delete', $id, '--yes' );
		}
	}

	/**
	 * `wp lw-migration import <archive> --yes --user=admin`, failing on any error.
	 *
	 * @return string Output and warnings.
	 */
	protected static function import( string $site, string $archive, string ...$flags ): string {
		[ $code, $out, $err ] = WpSites::wp( $site, 'lw-migration', 'import', $archive, '--yes', '--user=admin', ...$flags );
		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( "Import of %s into %s failed (%d):\n%s\n%s", basename( $archive ), $site, $code, $out, $err ) );
		}

		return $out . $err;
	}

	/** Nothing of a finished or stopped restore is left: no lock, no temporary or staging table, no registered run. */
	protected function assertNoLeftovers( string $site ): void {
		$this->assertNull( WpSites::lock( $site ), 'The import released the site lock.' );
		$this->assertSame( '', self::value( $site, "SHOW TABLES LIKE 'lw%'" ), 'No temporary or staging tables are left.' );
		$this->assertSame( '[]', trim( WpSites::ok( $site, 'eval', 'echo wp_json_encode( ( new LightweightPlugins\\Migration\\WordPress\\TmpRunRegistry() )->ids() );' ) ), 'No run is left registered.' );
	}

	/** First column of the first row, on the main site. */
	protected static function value( string $site, string $sql ): string {
		return trim( WpSites::ok( $site, 'eval', 'global $wpdb; echo (string) $wpdb->get_var( base64_decode( "' . base64_encode( $sql ) . '" ) );' ) );
	}

	/**
	 * PHP run on a site of a network (or a single site with a null address).
	 */
	protected static function evalOn( string $site, ?string $url, string $code ): string {
		$args = [ 'eval', $code ];
		if ( null === $url ) {
			return WpSites::ok( $site, ...$args );
		}
		[ $exit, $out, $err ] = WpSites::wpUrl( $site, $url, ...$args );
		if ( 0 !== $exit ) {
			throw new \RuntimeException( sprintf( "wp --url=%s eval failed (%d):\n%s\n%s", $url, $exit, $out, $err ) );
		}

		return $out;
	}

	/**
	 * The id the network's next site gets (its blogs table's AUTO_INCREMENT, read live).
	 */
	protected static function nextSiteId( string $site ): int {
		$code = 'global $wpdb; $wpdb->suppress_errors( true ); $wpdb->query( "SET SESSION information_schema_stats_expiry = 0" ); echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $wpdb->blogs ) );';

		return (int) trim( WpSites::ok( $site, 'eval', $code ) );
	}

	/** @return array<int, string> */
	protected static function urls( string $site ): array {
		return self::lines( WpSites::ok( $site, 'site', 'list', '--field=url' ) );
	}

	protected static function siteId( string $site, string $url ): int {
		foreach ( (array) json_decode( WpSites::ok( $site, 'site', 'list', '--fields=blog_id,url', '--format=json' ), true ) as $row ) {
			if ( $url === ( $row['url'] ?? null ) ) {
				return (int) $row['blog_id'];
			}
		}
		throw new \RuntimeException( 'No site at ' . $url );
	}

	/** @return array<int, string> */
	protected static function lines( string $out ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ), static fn( string $l ): bool => '' !== $l ) );
	}
}
