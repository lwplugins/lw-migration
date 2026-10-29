<?php
/**
 * The reservation of a temporary-table run on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Database\TmpRunException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class TmpRunTest extends DatabaseTestCase {

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

	private function job(): Job {
		return $this->store->create( 'reset', [ 'reset_db_build' ], [ 'type' => 'database' ], 1000 );
	}

	/**
	 * @param array<int, string> $candidates Prefixes handed out in order.
	 */
	private static function prefixes( array $candidates ): \Closure {
		return static function () use ( &$candidates ): string {
			return (string) array_shift( $candidates );
		};
	}

	/** @return array<int, string> */
	private function names(): array {
		return array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
	}

	public function test_a_free_prefix_is_reserved_and_registered(): void {
		$job        = $this->job();
		$registered = [];
		$run        = new TmpRun(
			$this->store,
			static function ( string $prefix ) use ( &$registered ): bool {
				$registered[] = $prefix;
				return true;
			},
			self::prefixes( [ 'lwtaaaa_' ] )
		);

		$this->assertSame( 'lwtaaaa_', $run->reserve( self::db(), 'wp_', $job ) );
		$this->assertSame( [ 'lwtaaaa_' ], $registered );
		$this->assertSame( [ 'lwyaaaa_lwm_run' ], $this->names() );
		$this->assertSame( 'lwtaaaa_', TmpRun::candidate( $this->store->load( $job->id ) )['prefix'] ?? null, 'The candidate was saved before the reservation.' );
	}

	public function test_a_prefix_another_run_holds_is_skipped(): void {
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_', RunMarker::newToken() ) );
		$this->exec( 'CREATE TABLE lwobbbb_options (v INT)' );
		$run = new TmpRun( $this->store, static fn( string $prefix ): bool => true, self::prefixes( [ 'lwtaaaa_', 'lwtbbbb_', 'lwtcccc_' ] ) );

		$this->assertSame( 'lwtcccc_', $run->reserve( self::db(), 'wp_', $this->job() ) );
	}

	public function test_a_replay_takes_the_reservation_its_own_token_holds(): void {
		$job                               = $this->job();
		$token                             = RunMarker::newToken();
		$job->state[ TmpRun::CANDIDATE ] = [
			'prefix' => 'lwtaaaa_',
			'token'  => $token,
		];
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_', $token ) ); // The request died right after this.
		$run = new TmpRun( $this->store, static fn( string $prefix ): bool => true, self::prefixes( [ 'lwtbbbb_' ] ) );

		$this->assertSame( 'lwtaaaa_', $run->reserve( self::db(), 'wp_', $job ) );
		$this->assertSame( [ 'lwyaaaa_lwm_run' ], $this->names(), 'No second marker.' );
	}

	public function test_another_sites_marker_under_the_saved_candidate_is_never_taken_over(): void {
		$job                               = $this->job();
		$job->state[ TmpRun::CANDIDATE ] = [
			'prefix' => 'lwtaaaa_',
			'token'  => RunMarker::newToken(),
		];
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_', RunMarker::newToken() ) );
		$run = new TmpRun( $this->store, static fn( string $prefix ): bool => true, self::prefixes( [ 'lwtaaaa_', 'lwtbbbb_' ] ) );

		$this->assertSame( 'lwtbbbb_', $run->reserve( self::db(), 'wp_', $job ) );
	}

	public function test_a_run_that_cannot_be_registered_leaves_no_marker(): void {
		$job = $this->job();
		$run = new TmpRun( $this->store, static fn( string $prefix ): bool => false, self::prefixes( [ 'lwtaaaa_' ] ) );
		try {
			$run->reserve( self::db(), 'wp_', $job );
			$this->fail( 'Unregistered.' );
		} catch ( TmpRunException $e ) {
			$this->assertSame( TmpRunException::UNREGISTERED, $e->reason );
		}
		$this->assertSame( [], $this->names() );
		$this->assertArrayNotHasKey( TmpRun::CANDIDATE, $job->state );
	}

	public function test_no_free_prefix_after_the_tries(): void {
		$this->exec( 'CREATE TABLE lwtaaaa_options (v INT)' );
		$run = new TmpRun( $this->store, static fn( string $prefix ): bool => true, static fn(): string => 'lwtaaaa_' );
		try {
			$run->reserve( self::db(), 'wp_', $this->job() );
			$this->fail( 'Busy.' );
		} catch ( TmpRunException $e ) {
			$this->assertSame( TmpRunException::NO_PREFIX, $e->reason );
		}
	}
}
