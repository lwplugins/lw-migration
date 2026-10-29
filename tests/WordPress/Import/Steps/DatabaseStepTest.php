<?php
/**
 * Import Database step: progress is kept only after the importer returned.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\DatabaseStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DatabaseStepTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_cursor_is_kept_when_the_importer_throws(): void {
		$job         = $this->job( "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\n" );
		$saved       = [
			'import' => [
				'offset'       => 0,
				'statements'   => 0,
				'warnings'     => [],
				'warningCount' => 0,
			],
			'warned' => 0,
		];
		$job->cursor = $saved;
		$db          = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t`', 1213 );

		try {
			$this->step( $db )->run( $job, new NoDeadline() );
			$this->fail( 'A deadlock must stop the import.' );
		} catch ( DatabaseException $e ) {
			$this->assertSame( $saved, $job->cursor );
		}
	}

	public function test_one_run_per_call_is_kept_before_a_later_call_throws(): void {
		$first = "CREATE TABLE `SERVMASK_PREFIX_a` (`a` int) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_a` VALUES (1);\n";
		$job   = $this->job( $first . "CREATE TABLE `SERVMASK_PREFIX_b` (`b` int) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_b` VALUES (2);\n" );
		$db    = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_b`', 1213 );
		$step  = $this->step( $db );

		$result = $step->run( $job, new NoDeadline() );

		$this->assertFalse( $result->isDone(), 'The run returned at the table boundary; the Runner calls again.' );
		$this->assertNotContains( 'CREATE TABLE `lwtab12_b` (`b` int) ENGINE=InnoDB;', $db->log, 'One importer run per call.' );
		$kept = $job->cursor;
		$this->assertSame( strlen( $first ), $kept['import']['offset'] );
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'A deadlock must stop the import.' );
		} catch ( DatabaseException $e ) {
			$this->assertSame( $kept, $job->cursor );
		}
	}

	public function test_stops_at_the_deadline_and_resumes_from_the_saved_cursor(): void {
		$job    = $this->job( "CREATE TABLE `SERVMASK_PREFIX_a` (`a` int);\nINSERT INTO `SERVMASK_PREFIX_a` VALUES (1);\nINSERT INTO `SERVMASK_PREFIX_a` VALUES (2);\n" );
		$db     = new FakeConnection();
		$step   = $this->step( $db );
		$result = $step->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 1, $job->cursor['import']['statements'] );
		$this->assertGreaterThan( 0, $result->progress() );
		$this->assertLessThan( 100, $result->progress() );

		$runs = 1;
		while ( ! $result->isDone() && $runs < 10 ) {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		}
		$this->assertTrue( $result->isDone() );
		$this->assertSame( 1, count( array_keys( $db->log, 'INSERT INTO `lwtab12_a` VALUES (2);', true ) ), 'Each row runs once.' );
	}

	public function test_without_database_nothing_runs(): void {
		$job  = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$step = new DatabaseStep(
			$this->store,
			static function () {
				throw new \LogicException( 'No connection expected.' );
			},
			static fn( string $p ): bool => false,
			static fn( string $t ): bool => false,
			self::registerAny()
		);

		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
	}

	public function test_first_run_only_picks_a_free_prefix(): void {
		$job = $this->job( "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		unset( $job->state['tmpPrefix'] );
		$db = new FakeConnection();

		$result = $this->step( $db )->run( $job, new NoDeadline() );

		$this->assertFalse( $result->isDone() );
		$this->assertTrue( PrefixRules::isValidTempPrefix( (string) $job->state['tmpPrefix'] ) );
		$tmp = (string) $job->state['tmpPrefix'];
		$this->assertCount( 1, $db->log, 'Only the run marker is created before the prefix is saved.' );
		$this->assertMatchesRegularExpression( '/^CREATE TABLE `lwy' . substr( $tmp, 3 ) . 'lwm_run` \(id TINYINT NOT NULL PRIMARY KEY\) COMMENT=\'[0-9a-f]{16}\'\z/', $db->log[0], 'The marker carries the job\'s token.' );
		$this->assertArrayNotHasKey( 'tmpCandidate', $job->state );
		$this->assertSame( [], $job->cursor );
	}

	public function test_no_free_prefix_fails_with_a_translated_message(): void {
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$job = $this->job( "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		unset( $job->state['tmpPrefix'] );
		$taken = [];
		for ( $i = 0; $i < 65536; $i++ ) {
			$taken[] = [ 'Tables_in_fake' => sprintf( 'lwt%04x_options', $i ) ];
		}
		$db                                = new FakeConnection();
		$db->rows['SHOW FULL TABLES'] = $taken;

		try {
			$this->step( $db )->run( $job, new NoDeadline() );
			$this->fail( 'Expected an ImportException.' );
		} catch ( ImportException $e ) {
			$this->assertStringStartsWith( 'T:No free temporary table prefix was found', $e->getMessage() );
			$this->assertStringNotContainsString( 'Remove', $e->getMessage(), 'Tables with these prefixes may belong to other sites in the database.' );
			$this->assertStringContainsString( 'The site was not changed.', $e->getMessage() );
		}
	}

	public function test_importer_warnings_reach_the_job_once_with_a_count_of_the_rest(): void {
		$sql = "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" . str_repeat( "INSERT INTO `elsewhere` VALUES (1);\n", 103 );
		$job = $this->job( $sql );

		$step   = $this->step( new FakeConnection() );
		$runs   = 0;
		$result = null;
		do {
			$result = $step->run( $job, new CountdownDeadline( 10 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( 2, $runs, 'The warnings were collected over several ticks.' );
		$this->assertSame( 101, $job->warningCount, '100 importer warnings and one summary line.' );
		$this->assertSame( '...and 3 more database warnings.', end( $job->newWarnings ) );
		$this->assertStringContainsString( 'does not target the import tables', $job->warnings[99] );
	}

	private function job( string $sql ): Job {
		$job                       = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->state['package']     = [
			'SiteURL' => 'https://old.test',
			'HomeURL' => 'https://old.test',
		];
		$job->state['hasDatabase'] = true;
		$job->state['tmpPrefix']   = 'lwtab12_';
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DATABASE, $sql );

		return $job;
	}

	private function step( FakeConnection $db ): DatabaseStep {
		return new DatabaseStep( $this->store, static fn() => $db, static fn( string $p ): bool => false, static fn( string $t ): bool => false, self::registerAny() );
	}

	private static function registerAny(): \Closure {
		return static fn( string $prefix ): bool => true;
	}
}
