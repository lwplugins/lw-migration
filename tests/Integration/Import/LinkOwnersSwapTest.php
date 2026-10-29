<?php
/**
 * Fix round 1 of Task 5 (Critical): the import keeps the importing admin by
 * login, usually under another id; the swap must bring the incoming links
 * that admin made along, or a pushed import would strand past its swap.
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

#[Group( 'monkey' )]
final class LinkOwnersSwapTest extends DatabaseTestCase {

	private const NOW = 5000;

	private string $dir;

	private MysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		FakeWpdb::stubCache();
		$this->dir  = TempDir::create();
		$this->wpdb = new MysqliWpdb();
		foreach ( [ 'wp_', 'lwtab12_' ] as $prefix ) {
			$this->exec( ...WpCoreTables::create( $prefix, 'InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' ) );
		}
	}

	protected function tearDown(): void {
		$this->wpdb->close();
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_the_kept_admins_incoming_links_follow_its_new_id(): void {
		$this->exec(
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://live.test'),('home','https://live.test')",
			"INSERT INTO wp_users (ID, user_login, user_pass) VALUES (3,'boss','\$P\$live'),(4,'other','\$P\$live')",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (3,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('siteurl','https://archive.test'),('home','https://archive.test')",
			"INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (2,'boss','\$P\$archive'),(3,'someone','\$P\$archive')"
		);
		$links = [
			self::link( '0000000000000001', 'in', 3, 'boss' ),
			self::link( '0000000000000002', 'in', 4, 'other' ),
			self::link( '0000000000000003', 'out', 3, 'boss' ),
			self::link( '0000000000000004', 'in', 99, 'boss' ),
		];
		$this->exec( sprintf( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('lw_migration_links', '%s', 'off')", addslashes( (string) json_encode( $links ) ) ) );

		$this->import();

		$rows = self::db()->query( "SELECT option_value, autoload FROM wp_options WHERE option_name = 'lw_migration_links'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'off', $rows[0]['autoload'], 'The row stays out of autoload.' );
		$after = json_decode( (string) $rows[0]['option_value'], true );
		$this->assertSame( [ 2, 4, 3, 99 ], array_map( static fn( array $row ): int => $row['createdBy']['id'], $after ), 'Only the kept admin\'s incoming link follows (boss is 2 now; 3 is someone else); a link cut off by deleting its admin (99) is never revived by a new boss.' );
		$this->assertSame( [ [ 'user_login' => 'boss' ] ], self::db()->query( 'SELECT user_login FROM wp_users WHERE ID = 2' ) );
	}

	public function test_without_links_nothing_is_written(): void {
		$this->exec(
			"INSERT INTO wp_users (ID, user_login, user_pass) VALUES (3,'boss','\$P\$live')",
			"INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (2,'boss','\$P\$archive')"
		);

		$this->import();

		$this->assertSame( [], self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = 'lw_migration_links'" ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function link( string $id, string $direction, int $owner, string $login ): array {
		return [
			'id'         => $id,
			'direction'  => $direction,
			'address'    => 'https://a.example',
			'name'       => 'A',
			'allow'      => [ 'push' ],
			'secret'     => 'sealed',
			'createdAt'  => 1,
			'createdBy'  => [
				'id'    => $owner,
				'login' => $login,
			],
			'confirmed'  => true,
			'lastUsedAt' => 0,
			'pairedFrom' => '',
		];
	}

	/**
	 * The preserve and swap steps of one import by boss (live id 3).
	 */
	private function import(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = ImportJobFixture::make( $store, $this->dir, '/unused.wpress' );
		$job->steps                       = [ 'preserve', 'swap' ];
		$job->state['package']            = [
			'Plugins'    => [],
			'Template'   => 'twentytwentyfive',
			'Stylesheet' => 'twentytwentyfive',
		];
		$job->state['hasDatabase']        = true;
		$job->state['tmpPrefix']          = 'lwtab12_';
		$job->state['admin']              = [
			'id'    => 3,
			'login' => 'boss',
		];
		$job->state['site']['template']   = 'twentytwentyfive';
		$job->state['site']['stylesheet'] = 'twentytwentyfive';
		$job->state['site']['homeUrl']    = 'https://live.test';
		$store->save( $job );
		$this->assertTrue( ( new SiteLock( $this->wpdb ) )->acquire( $job->id, 100 ) );

		$preserve = new PreserveStep( static fn() => self::db(), static fn(): array => [], static fn( string $slug ): bool => true );
		$this->assertTrue( $preserve->run( $job, new NoDeadline() )->isDone() );
		$swap = new SwapStep( $store, static fn() => self::db(), static function (): void {}, new SiteLock( $this->wpdb ), static fn(): int => self::NOW, static fn( string $run ): bool => true );
		for ( $i = 0; $i < 5 && ! $swap->run( $job, new NoDeadline() )->isDone(); $i++ ) {
			$this->assertNotTrue( 4 === $i, 'The swap finishes.' );
		}
		$this->assertTrue( $job->state['swapped'] ?? false );
	}
}
