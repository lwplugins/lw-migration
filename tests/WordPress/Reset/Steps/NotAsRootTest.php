<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\Steps\NotAsRoot;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NotAsRootTest extends MonkeyTestCase {

	/** @var array<int, string> */
	private array $ran = [];

	private function inner(): StepInterface {
		return new class( $this->ran ) implements StepInterface {
			/** @param array<int, string> $ran */
			public function __construct( private array &$ran ) {
			}

			public function run( Job $job, Deadline $deadline ): StepResult {
				$this->ran[] = (string) $job->options['type'];
				return StepResult::done();
			}
		};
	}

	private static function job( string $type ): Job {
		return new Job( str_repeat( 'c', 16 ), 'reset', ResetType::steps( $type, true ), 0, [], [ 'type' => $type, 'backup' => true ] );
	}

	public function test_every_step_of_a_file_reset_refuses_root_before_it_runs(): void {
		foreach ( [ 0, null ] as $euid ) {
			foreach ( [ ResetType::PLUGINS, ResetType::THEMES, ResetType::MEDIA, ResetType::FULL ] as $type ) {
				try {
					( new NotAsRoot( $this->inner(), new RootGuard( $euid ) ) )->run( self::job( $type ), new NoDeadline() );
					$this->fail( 'Refused: ' . $type );
				} catch ( JobException $e ) {
					$this->assertStringContainsString( 'never runs as root', $e->getMessage() );
				}
			}
		}
		$this->assertSame( [], $this->ran, 'Not even the backup step ran.' );
	}

	public function test_a_database_reset_may_run_as_root_and_anyone_else_runs_every_reset(): void {
		( new NotAsRoot( $this->inner(), new RootGuard( 0 ) ) )->run( self::job( ResetType::DATABASE ), new NoDeadline() );
		( new NotAsRoot( $this->inner(), new RootGuard( 33 ) ) )->run( self::job( ResetType::FULL ), new NoDeadline() );
		$this->assertSame( [ ResetType::DATABASE, ResetType::FULL ], $this->ran );
	}

	public function test_the_real_root_process_is_refused_a_file_reset(): void {
		if ( 0 !== ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : -1 ) ) {
			$this->markTestSkipped( 'runs as root only (Docker)' );
		}
		$this->expectException( JobException::class );
		( new NotAsRoot( $this->inner() ) )->run( self::job( ResetType::MEDIA ), new NoDeadline() );
	}
}
