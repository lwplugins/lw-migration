<?php
/**
 * The database export step on a real MySQL/MariaDB.
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
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class DatabaseStepTest extends DatabaseTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'size_format' )->alias( static fn( $bytes, $decimals = 0 ) => $bytes . ' B/' . $decimals );
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->exec(
			"CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')",
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://old.test'),('lw_migration_lock','a:0:{}'),('_transient_lw_migration_job_secret_0123456789abcdef','a:1:{s:8:\"password\";s:7:\"hunter2\";}'),('ai1wm_secret_key','k')",
			'CREATE TABLE wp_posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title TEXT NOT NULL)',
			"INSERT INTO wp_posts (post_title) VALUES ('Hello'),('World')",
			'CREATE TABLE other_t (id INT PRIMARY KEY)',
			'INSERT INTO other_t VALUES (7)'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $options   Export options.
	 * @param bool                 $resumable Stop at every deadline check.
	 */
	private function dump( array $options, bool $resumable = false ): Job {
		$job  = JobFixture::export( $this->store, $this->dir, $options );
		$step = new DatabaseStep( $this->store, static fn() => self::db() );
		$runs = 0;
		do {
			$result = $step->run( $job, $resumable ? new CountdownDeadline( 0 ) : new NoDeadline() );
			++$runs;
		} while ( ! $result->isDone() && $runs < 100 );
		$this->assertTrue( $result->isDone() );

		return $job;
	}

	private function sql( Job $job ): string {
		return (string) file_get_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE );
	}

	public function test_dump_skips_own_options_and_secret_transients(): void {
		$sql = $this->sql( $this->dump( [], true ) );

		$this->assertStringContainsString( 'CREATE TABLE `SERVMASK_PREFIX_options`', $sql );
		$this->assertStringContainsString( "'siteurl'", $sql );
		$this->assertStringContainsString( "'Hello'", $sql );
		$this->assertStringNotContainsString( 'lw_migration_', $sql );
		$this->assertStringNotContainsString( 'hunter2', $sql );
		$this->assertStringNotContainsString( 'ai1wm_secret_key', $sql );
		$this->assertStringNotContainsString( 'other_t', $sql );
	}

	public function test_the_dump_is_owner_only_whatever_the_umask(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'POSIX permissions only.' );
		}
		$umask = umask( 0 );
		try {
			$path = $this->store->dir( $this->dump( [] )->id ) . '/' . ExportFiles::DATABASE;
		} finally {
			umask( $umask );
		}
		clearstatcache( true, $path );

		$this->assertSame( 0600, fileperms( $path ) & 0777 );
	}

	public function test_include_and_exclude_tables(): void {
		$job = $this->dump(
			[
				'includeTables' => [ 'other_t', 'missing_t' ],
				'excludeTables' => [ 'wp_posts' ],
			]
		);
		$sql = $this->sql( $job );

		$this->assertStringContainsString( 'CREATE TABLE `other_t`', $sql );
		$this->assertStringNotContainsString( 'SERVMASK_PREFIX_posts', $sql );
		$this->assertSame( [ 'wp_options', 'other_t' ], $job->state['tables']['tables'] );
		$this->assertStringContainsString( 'missing_t', implode( ' ', $job->warnings ) );
	}

	public function test_no_database_option_skips_the_step(): void {
		$job = $this->dump( [ 'noDatabase' => true ] );
		$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE );
	}

	public function test_every_tick_within_one_table_shows_movement(): void {
		$this->exec( "INSERT INTO wp_posts (post_title) VALUES ('a'),('b'),('c'),('d'),('e')" );
		$job  = JobFixture::export( $this->store, $this->dir, [] );
		$step = new DatabaseStep( $this->store, static fn() => self::db() );
		$seen = [];
		do {
			$before = (int) ( $job->cursor['index'] ?? 0 );
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			if ( ! $result->isDone() && $before === (int) $job->cursor['index'] ) {
				$seen[ $before ][] = $result->message();
			}
		} while ( ! $result->isDone() && count( $seen, COUNT_RECURSIVE ) < 200 );

		$posts = (int) array_search( 'wp_posts', $job->state['tables']['tables'], true );
		$this->assertGreaterThanOrEqual( 3, count( $seen[ $posts ] ?? [] ), 'The posts table takes several ticks.' );
		foreach ( $seen as $messages ) {
			$this->assertSame( $messages, array_values( array_unique( $messages ) ), 'A tick within the same table never repeats the last text (a connected site would see a stall).' );
		}
		// One decimal: "1.2 GB" moves where "1 GB" would stay for a whole GiB of one table.
		$this->assertMatchesRegularExpression( '@^Exporting the database: table \d+ of \d+ \(\d+ B/1 written\)\.\.\.$@', $seen[ $posts ][0] );
	}
}
