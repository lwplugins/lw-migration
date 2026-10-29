<?php
/**
 * Pin (ruling R16): an archive never brings its own transfer links or backups
 * folder setting into this site, under any spelling the options collation
 * folds onto ours.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\Steps\PreserveStep;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shares row is a credential store and the backups folder row a write
 * target; the link rows hold credentials and the replay cache.
 * WordPress reads an option with `option_name = '<name>'` under the
 * options table's collation (utf8mb4_unicode_ci here, a common one), which
 * folds case, a trailing NBSP or ideographic space and full-width letters on
 * some servers; whatever the archive carries, the live rows (or their
 * absence) must be what this site reads after the import.
 */
#[Group( 'monkey' )]
final class OwnOptionsPinTest extends DatabaseTestCase {

	private const NOW = 5000;

	private const NAMES = [ 'lw_migration_shares', 'lw_migration_backup_dir', 'lw_migration_links', 'lw_migration_link_code', 'lw_migration_link_nonces', 'lw_migration_reset_tries', 'lw_migration_destinations', 'lw_migration_destination_secrets', 'lw_migration_cloud_copies' ];

	private string $dir;

	private MysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		FakeWpdb::stubCache();
		$this->dir  = TempDir::create();
		$this->wpdb = new MysqliWpdb();
	}

	protected function tearDown(): void {
		$this->wpdb->close();
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @return array<string, \Closure> Variant label => fn( string $name ): string.
	 */
	private static function variants(): array {
		return [
			'exact'                => static fn( string $name ): string => $name,
			'upper case'           => static fn( string $name ): string => strtoupper( $name ),
			'trailing NBSP'        => static fn( string $name ): string => $name . "\u{00A0}",
			'trailing U+3000'      => static fn( string $name ): string => $name . "\u{3000}",
			'full-width first one' => static fn( string $name ): string => "\u{FF4C}" . substr( $name, 1 ),
		];
	}

	public function test_archive_spellings_of_our_rows_never_replace_or_add_live_ones(): void {
		foreach ( self::variants() as $label => $spell ) {
			foreach ( [ true, false ] as $liveRows ) {
				$this->importArchiveWith( $spell, $liveRows );

				foreach ( self::NAMES as $name ) {
					$expected = $liveRows ? [ [ 'option_value' => 'live:' . $name ] ] : [];
					$this->assertSame(
						$expected,
						self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = '{$name}'" ),
						sprintf( '%s, %s, live rows %s', $label, $name, $liveRows ? 'present' : 'absent' )
					);
				}
				$this->assertSame( [], self::db()->query( "SELECT option_name FROM wp_options WHERE option_value LIKE 'archive:%'" ), $label );
			}
		}
	}

	/**
	 * Fresh live and archive tables, then the preserve and swap steps of one import.
	 *
	 * @param \Closure $spell    fn( string $name ): string, the archive's spelling.
	 * @param bool     $liveRows Whether this site has its own rows.
	 */
	private function importArchiveWith( \Closure $spell, bool $liveRows ): void {
		foreach ( self::db()->query( 'SHOW TABLES' ) as $row ) {
			$this->exec( sprintf( 'DROP TABLE `%s`', str_replace( '`', '``', (string) array_values( $row )[0] ) ) );
		}
		foreach ( [ 'wp_', 'lwtab12_' ] as $prefix ) {
			$this->exec( ...WpCoreTables::create( $prefix, 'InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' ) );
		}
		$this->exec(
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://live.test'),('home','https://live.test')",
			"INSERT INTO wp_users (ID, user_login, user_pass, user_email, user_nicename) VALUES (1,'admin','\$P\$live','admin@live.test','admin')",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'session_tokens','live-session')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('siteurl','https://archive.test'),('home','https://archive.test')",
			"INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (5,'admin','\$P\$archive')"
		);
		foreach ( self::NAMES as $name ) {
			if ( $liveRows ) {
				$this->insert( 'wp_options', $name, 'live:' . $name );
			}
			$this->insert( 'lwtab12_options', $spell( $name ), 'archive:' . $name );
		}
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store );
		$this->assertTrue( ( new SiteLock( $this->wpdb ) )->acquire( $job->id, 100 ) );

		$preserve = new PreserveStep( static fn() => self::db(), static fn(): array => [ 'lw-migration/lw-migration.php' => '1.0.0' ], static fn( string $slug ): bool => true );
		$this->assertTrue( $preserve->run( $job, new NoDeadline() )->isDone() );
		$swap = new SwapStep( $store, static fn() => self::db(), static function (): void {}, new SiteLock( $this->wpdb ), static fn(): int => self::NOW, static fn( string $run ): bool => true );
		for ( $i = 0; $i < 5 && ! $swap->run( $job, new NoDeadline() )->isDone(); $i++ ) {
			$this->assertNotTrue( 4 === $i, 'The swap finishes.' );
		}
		$this->assertTrue( $job->state['swapped'] ?? false );
	}

	private function insert( string $table, string $name, string $value ): void {
		$link = self::db();
		$link->execute( sprintf( "INSERT INTO `%s` (option_name, option_value) VALUES ('%s', '%s')", $table, addslashes( $name ), addslashes( $value ) ) );
	}

	private function job( JobStore $store ): Job {
		$job                              = ImportJobFixture::make( $store, $this->dir, '/unused.wpress' );
		$job->steps                       = [ 'preserve', 'swap' ];
		$job->state['package']            = [
			'Plugins'    => [],
			'Template'   => 'twentytwentyfive',
			'Stylesheet' => 'twentytwentyfive',
		];
		$job->state['hasDatabase']        = true;
		$job->state['tmpPrefix']          = 'lwtab12_';
		$job->state['admin']              = [
			'id'    => 1,
			'login' => 'admin',
		];
		$job->state['site']['template']   = 'twentytwentyfive';
		$job->state['site']['stylesheet'] = 'twentytwentyfive';
		$job->state['site']['homeUrl']    = 'https://live.test';
		$store->save( $job );

		return $job;
	}
}
