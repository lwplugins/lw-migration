<?php
/**
 * The database export step of a network backup on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Export;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\Steps\DatabaseStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ExportScope;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Multisite\NetworkTables;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class NetworkDatabaseStepTest extends DatabaseTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'size_format' )->alias( static fn( $bytes ) => $bytes . ' B' );
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->exec( ...NetworkTables::create( 'wp_', [ 1, 2, 3 ] ) );
		$this->exec( ...NetworkTables::seed( 'wp_' ) );
		$this->exec(
			'CREATE VIEW wp_2_titles AS SELECT post_title FROM wp_2_posts',
			'CREATE VIEW wp_3_titles AS SELECT post_title FROM wp_3_posts',
			'CREATE VIEW wp_titles AS SELECT post_title FROM wp_posts',
			"INSERT INTO wp_options (option_name, option_value) VALUES ('_transient_lw_migration_job_secret_0123456789abcdef','hunter-main'),('_transient_timeout_lw_migration_job_secret_0123456789abcdef','9')",
			"INSERT INTO wp_2_options (option_name, option_value) VALUES ('_transient_lw_migration_job_secret_fedcba9876543210','hunter-b2')",
			'CREATE TABLE other_t (id INT PRIMARY KEY)'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_sites_backup_dumps_like_the_scope_and_only_the_chosen_views(): void {
		$job = $this->dump( 'sites', [ 2 ], [ 'noSpamComments' => true ] );
		$sql = (string) file_get_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE );

		$this->assertSame( [ 'wp_2_titles' ], $job->state['tables']['views'] );
		$expected = NetworkTables::dump( self::db(), new ExportScope( 'wp_', [ 1, 2, 3 ], [ 2 ] ), $this->dir . '/expected.sql', true );
		$this->assertStringStartsWith( $expected, $sql, 'The tables are dumped as the scope dumps them; the view follows.' );
		$this->assertStringContainsString( 'SERVMASK_PREFIX_2_titles', $sql );
		$this->assertStringNotContainsString( '_3_titles', $sql );
		$this->assertStringNotContainsString( "'author3'", $sql );
		$this->assertStringNotContainsString( 'hunter-b2', $sql, 'Our transients stay out of every options table.' );
		$this->assertStringNotContainsString( '_transient_lw_migration_', $sql );
	}

	public function test_included_tables_outside_the_chosen_sites_are_left_out(): void {
		$job = $this->dump( 'sites', [ 2 ], [ 'includeTables' => [ 'wp_3_posts', 'wp_posts', 'other_t', 'wp_2_posts' ] ] );
		$sql = (string) file_get_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE );

		$this->assertNotContains( 'wp_3_posts', $job->state['tables']['tables'] );
		$this->assertNotContains( 'wp_posts', $job->state['tables']['tables'] );
		$this->assertNotContains( 'other_t', $job->state['tables']['tables'] );
		$this->assertStringNotContainsString( 'Post 3', $sql );
		$this->assertStringNotContainsString( 'other_t', $sql );
		$this->assertSame(
			[
				'The table to include is not part of the chosen sites: wp_3_posts',
				'The table to include is not part of the chosen sites: wp_posts',
				'The table to include is not part of the chosen sites: other_t',
			],
			$job->warnings
		);
	}

	public function test_a_whole_network_backup_dumps_every_table_and_view(): void {
		$job = $this->dump( 'network', [], [] );
		$sql = (string) file_get_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE );

		$this->assertSame( [ 'wp_2_titles', 'wp_3_titles', 'wp_titles' ], $job->state['tables']['views'] );
		$expected = NetworkTables::dump( self::db(), new ExportScope( 'wp_', [ 1, 2, 3 ], null ), $this->dir . '/expected.sql' );
		$this->assertStringStartsWith( $expected, $sql );
		$this->assertStringContainsString( 'INSERT INTO `SERVMASK_PREFIX_users` VALUES (3,\'author3\'', $sql );
		$this->assertStringNotContainsString( 'lw_migration_lock', $sql );
		$this->assertStringNotContainsString( 'hunter-main', $sql );
		$this->assertStringNotContainsString( 'hunter-b2', $sql );
		$this->assertStringNotContainsString( '_transient_lw_migration_', $sql );
		$this->assertStringNotContainsString( 'other_t', $sql, 'Unprefixed tables only when included.' );
	}

	/**
	 * @param string               $mode    'network' or 'sites'.
	 * @param array<int, int>      $chosen  Chosen blog ids.
	 * @param array<string, mixed> $options Export options.
	 */
	private function dump( string $mode, array $chosen, array $options ): Job {
		$job                   = JobFixture::export( $this->store, $this->dir, $options + [ 'sites' => $chosen ] );
		$job->state['network'] = [
			'mode'    => $mode,
			'chosen'  => $chosen,
			'blogIds' => [ 1, 2, 3 ],
		];
		$step                  = new DatabaseStep( $this->store, static fn() => self::db() );
		$runs                  = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 200 );
		$this->assertTrue( $result->isDone() );

		return $job;
	}
}
