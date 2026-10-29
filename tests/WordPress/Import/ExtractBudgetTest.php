<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Import\ExtractBudget;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ExtractBudgetTest extends MonkeyTestCase {

	private const GIB = 1073741824;

	private string $dir;

	private Job $job;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->job = ( new JobStore( $this->dir . '/jobs' ) )->create( 'import', [ 'test' ], [], 1 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_the_first_load_reads_the_smallest_free_space_once_and_saves_the_budget(): void {
		$asked = [];
		$free  = static function ( string $dir ) use ( &$asked ): ?int {
			$asked[] = $dir;
			return [ '/content' => 20 * self::GIB, '/work' => 10 * self::GIB, '/unknown' => null ][ $dir ];
		};

		$budget = ExtractBudget::load( $this->job, [ '/content', '/unknown', '/work' ], $free );
		$budget->spend( 100 );
		ExtractBudget::save( $this->job, $budget );
		$again = ExtractBudget::load( $this->job, [ '/content', '/unknown', '/work' ], $free );

		$this->assertSame( [ '/content', '/unknown', '/work' ], $asked, 'Asked once, on the first load only.' );
		$this->assertSame( 10 * self::GIB - WriteBudget::margin( 10 * self::GIB ), $again->limit() );
		$this->assertSame( 100, $again->written() );
		$this->assertSame( [ 'limit' => $again->limit(), 'written' => 100 ], $this->job->state[ ExtractBudget::STATE ] );
	}

	public function test_without_any_known_free_space_the_filter_sets_the_limit(): void {
		Filters\expectApplied( ExtractBudget::FILTER )->once()->with( WriteBudget::UNKNOWN_CEILING )->andReturn( 5000 );

		$budget = ExtractBudget::load( $this->job, [ '/a', '/b' ], static fn( string $dir ): ?int => null );

		$this->assertSame( 5000, $budget->limit() );
	}

	public function test_the_stop_message_names_the_limit(): void {
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$budget = new WriteBudget( 1234, 1000 );

		$this->assertSame( 'The import stopped: the archive holds more data than the 1234 B the free disk space allows it to write. Free up disk space and try again.', ExtractBudget::stopMessage( $budget ) );
	}
}
