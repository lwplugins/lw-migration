<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\Drive;
use LightweightPlugins\Migration\CLI\Interrupt;
use LightweightPlugins\Migration\CLI\InterruptedException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class DriveTest extends MonkeyTestCase {

	public function test_before_tick_refreshes_the_lock_of_the_job(): void {
		$db   = FakeWpdb::install();
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'job-a', 100 ) );

		( Drive::beforeTick( $lock, new Interrupt() ) )( 'job-a' );

		$value = json_decode( (string) $db->value( Options::LOCK ), true );
		$this->assertSame( 'job-a', $value['job'] );
		$this->assertGreaterThan( 100 + SiteLock::TTL, $value['expires'] );
	}

	public function test_before_tick_stops_when_the_lock_is_lost(): void {
		FakeWpdb::install();
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'job-b', time() ) );

		try {
			( Drive::beforeTick( $lock, new Interrupt() ) )( 'job-a' );
			$this->fail( 'Expected a lock-lost exception.' );
		} catch ( JobException $e ) {
			$this->assertNotInstanceOf( InterruptedException::class, $e, 'A lost lock fails the job, it is not an interrupt.' );
			$this->assertStringContainsString( 'lost the site lock', $e->getMessage() );
		}
	}

	public function test_before_tick_stops_after_an_interrupt(): void {
		FakeWpdb::install();
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'job-a', time() ) );
		$interrupt = new Interrupt();
		$interrupt->request();

		$this->expectException( InterruptedException::class );
		$this->expectExceptionMessage( 'Interrupted' );
		( Drive::beforeTick( $lock, $interrupt ) )( 'job-a' );
	}

	/**
	 * A runner whose jobs are past the point of no return once state.swapped is set.
	 *
	 * @param JobStore $store Store.
	 */
	private static function swapRunner( JobStore $store ): Runner {
		$step = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done();
			}
		};

		return new Runner( $store, [ 'a' => $step ], null, [], null, static fn( Job $job ): bool => ! empty( $job->state['swapped'] ) );
	}

	public function test_an_interrupt_after_the_swap_finishes_the_job_instead_of_cancelling_it(): void {
		FakeWpdb::install();
		$dir   = TempDir::create();
		$store = new JobStore( $dir );
		try {
			$runner                = self::swapRunner( $store );
			$job                   = $store->create( 'import', [ 'a' ], [], 1 );
			$job->state['swapped'] = true;
			$store->save( $job );
			$lock = new SiteLock();
			$this->assertTrue( $lock->acquire( $job->id, time() ) );
			$interrupt = new Interrupt();
			$interrupt->request();
			$said   = [];
			$before = Drive::beforeTick( $lock, $interrupt, Drive::deferredCancel( $runner, $store, static function ( string $line ) use ( &$said ): void {
				$said[] = $line;
			} ) );

			$before( $job->id ); // No exception: the drive goes on.
			$before( $job->id );

			$saved = $store->load( $job->id );
			$this->assertSame( Job::PENDING, $saved->status );
			$this->assertTrue( Runner::cancelDeferred( $saved ) );
			$this->assertSame( [ 'The database is already replaced; finishing the remaining steps (this takes a moment)…' ], $said, 'Said once.' );
			$this->assertSame( $job->id, $lock->holder( time() ), 'The lock is still refreshed.' );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_an_interrupt_before_the_swap_still_cancels(): void {
		FakeWpdb::install();
		$dir   = TempDir::create();
		$store = new JobStore( $dir );
		try {
			$runner = self::swapRunner( $store );
			$job    = $store->create( 'import', [ 'a' ], [], 1 );
			$lock   = new SiteLock();
			$this->assertTrue( $lock->acquire( $job->id, time() ) );
			$interrupt = new Interrupt();
			$interrupt->request();

			try {
				( Drive::beforeTick( $lock, $interrupt, Drive::deferredCancel( $runner, $store, static function (): void {} ) ) )( $job->id );
				$this->fail( 'Expected an interrupt.' );
			} catch ( InterruptedException $e ) {
				$this->assertSame( Job::PENDING, $store->load( $job->id )->status, 'The command cancels it (Drive::stop).' );
				$this->assertFalse( Runner::cancelDeferred( $store->load( $job->id ) ) );
			}
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_an_interrupted_push_says_the_other_site_may_be_replacing_its_content(): void {
		FakeWpdb::install();
		$dir   = TempDir::create();
		$store = new JobStore( $dir );
		try {
			$runner                = self::swapRunner( $store );
			$job                   = $store->create( 'push', [ 'a' ], [], 1 );
			$job->state['swapped'] = true; // swapRunner: past its point of no return.
			$store->save( $job );
			$lock = new SiteLock();
			$this->assertTrue( $lock->acquire( $job->id, time() ) );
			$interrupt = new Interrupt();
			$interrupt->request();
			$said = [];

			( Drive::beforeTick( $lock, $interrupt, Drive::deferredCancel( $runner, $store, static function ( string $line ) use ( &$said ): void {
				$said[] = $line;
			} ) ) )( $job->id );

			$this->assertSame( [ 'The other site may already be replacing its content; finishing the remaining steps (this takes a moment)…' ], $said );
			$this->assertSame( 'The other site may already be replacing its content, so sending was not cancelled; its remaining steps did not finish yet.', Drive::notCancelled( 'push' ) );
			$this->assertSame( 'The database is already replaced, so the import was not cancelled; its remaining steps did not finish yet.', Drive::notCancelled( 'import' ) );
			$this->assertSame( 'The database is already replaced, so the reset was not cancelled; its remaining steps did not finish yet.', Drive::notCancelled( 'reset' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}
}
