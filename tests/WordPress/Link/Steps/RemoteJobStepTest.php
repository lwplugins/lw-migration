<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link\Steps;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\LinkPipeline;
use LightweightPlugins\Migration\Link\Steps\Retry;
use LightweightPlugins\Migration\Link\Steps\PushUploadStep;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TimeDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\ScriptedPeer;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

/**
 * Site A drives site B's job through a scripted B (ScriptedPeer).
 */
final class RemoteJobStepTest extends MonkeyTestCase {

	private const JOB = 'aaaaaaaaaaaaaaaa';

	private const REMOTE = 'bbbbbbbbbbbbbbbb';

	private const STEPS = [ 'upload', 'summary', 'content', 'database', 'swap', 'clean' ];

	private ScriptedPeer $peer;

	private int $now = 1790000000;

	/** @var array<int, float> Seconds the step paused for. */
	private array $paused = [];

	private ?string $dir = null;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::stubCache();
		ScriptedPeer::stubHttp();
		$this->peer = new ScriptedPeer();
	}

	protected function tearDown(): void {
		if ( null !== $this->dir ) {
			TempDir::remove( $this->dir );
		}
		parent::tearDown();
	}

	/**
	 * A job view of B.
	 *
	 * @param array<string, mixed> $extra Keys to add or replace.
	 * @return array<string, mixed>
	 */
	private static function view( string $status, string $step, int $progress, array $extra = [] ): array {
		return array_merge(
			[
				'id'             => self::REMOTE,
				'type'           => 'import',
				'status'         => $status,
				'step'           => $step,
				'steps'          => self::STEPS,
				'stepIndex'      => (int) array_search( $step, self::STEPS, true ),
				'progress'       => $progress,
				'message'        => 'B is at ' . $step,
				'warnings'       => [],
				'warningCount'   => 0,
				'error'          => '',
				'input'          => null,
				'pastNoReturn'   => false,
				'cancelDeferred' => false,
				'result'         => [ 'safetyBackup' => null ],
				'archiveBytes'   => null,
				'archiveSha256'  => null,
			],
			$extra
		);
	}

	/**
	 * @param array<int, mixed> $answers Views (200) or FakeWpError / ScriptedPeer::reply().
	 */
	private function script( array $answers ): void {
		$this->peer->answers = $answers;
		$this->peer->sent    = [];
	}

	/**
	 * @param array<string, mixed> $remote state.remote.
	 * @param array<string, mixed> $input  state.input.
	 */
	private function pushJob( array $remote, array $input = [] ): Job {
		$state = [
			'link'   => [
				'id'      => ScriptedPeer::OUT,
				'name'    => 'Site B',
				'address' => ScriptedPeer::ADDRESS,
			],
			'remote' => $remote + [ 'safetyBackup' => true ],
		];
		if ( [] !== $input ) {
			$state['input'] = $input;
		}

		return new Job( self::JOB, 'push', [ RemoteJobStep::PUSH_KEY ], 0, [], [], $state, Job::RUNNING );
	}

	private function pullJob(): Job {
		return new Job( self::JOB, 'pull', [ RemoteJobStep::PULL_KEY ], 0, [], [], [ 'link' => [ 'id' => ScriptedPeer::OUT, 'name' => 'Site B', 'address' => ScriptedPeer::ADDRESS ] ], Job::RUNNING );
	}

	private function step( bool $pull ): RemoteJobStep {
		return new RemoteJobStep(
			$this->peer->clients( static fn(): int => time() ), // The scripted answers carry today's Date.
			$pull,
			fn(): int => $this->now,
			function ( float $seconds ): void {
				$this->paused[] = $seconds;
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function routes(): array {
		return $this->peer->routes();
	}

	private function assertLinkFailure( \Closure $call, string $reason, string $contains, string $label ): LinkException {
		try {
			$call();
		} catch ( LinkException $e ) {
			$this->assertSame( $reason, $e->reason, $label . ': ' . $e->getMessage() );
			$this->assertStringContainsString( $contains, $e->getMessage(), $label );
			return $e;
		}
		$this->fail( 'A LinkException was expected: ' . $label );
	}

	public function test_push_mode_ticks_until_the_other_site_is_done(): void {
		$this->script( [ self::view( 'running', 'content', 40 ), self::view( 'running', 'swap', 10, [ 'pastNoReturn' => true ] ), self::view( 'done', 'clean', 100, [ 'result' => [ 'safetyBackup' => 'pre-import-20261009-120000.wpress' ] ] ) ] );
		$job    = $this->pushJob( [ 'job' => self::REMOTE ] );
		$result = $this->step( false )->run( $job, new CountdownDeadline( 10 ) );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( 'Site B is restored.', $result->message() );
		$this->assertSame( [ '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/tick', '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/tick', '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/tick' ], $this->routes() );
		$this->assertSame( [ 'POST', 'POST', 'POST' ], array_column( $this->peer->sent, 'method' ) );
		$this->assertFalse( $job->state['remote']['pastNoReturn'], 'Shown as the other site last said it; never sticky.' );
		$this->assertSame( 'pre-import-20261009-120000.wpress', $job->state['remote']['safetyBackupName'] );
		$this->assertSame( [ Job::DONE, 100 ], [ $job->state['remote']['status'], $job->state['remote']['percent'] ] );
	}

	public function test_the_progress_and_text_of_the_other_site_are_kept(): void {
		$this->script( [ self::view( 'running', 'content', 40, [ 'message' => '<b>Restoring</b> files from https://x.example/?token=abcdefabcdefabcdef' ] ) ] );
		$job    = $this->pushJob( [ 'job' => self::REMOTE ] );
		$result = $this->step( false )->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 40, $result->progress(), 'Two of six steps done and 40 % of the third: (200 + 40) / 6.' );
		$this->assertSame( 40, $job->state['remote']['percent'] );
		$this->assertStringNotContainsString( 'abcdefabcdefabcdef', $job->state['remote']['message'] );
		$this->assertStringNotContainsString( '<b>', $job->state['remote']['message'] );
		$this->assertStringStartsWith( 'Site B: Restoring files from https://x.example', $result->message() );
	}

	public function test_the_other_sites_confirmation_is_relayed_with_its_summary(): void {
		$summary = [
			'from'      => 'https://a.example',
			'to'        => 'https://b.example',
			'wordpress' => [ 'archive' => '7.1.2', 'site' => '7.1.2' ],
		];
		$this->script( [ self::view( 'needs_input', 'summary', 0, [ 'input' => [ 'kind' => 'confirm', 'summary' => $summary ] ] ) ] );
		$job = $this->pushJob( [ 'job' => self::REMOTE ] );

		$result = $this->step( false )->run( $job, new CountdownDeadline( 10 ) );

		$this->assertSame( 'confirm', $result->needsInputKind() );
		$this->assertSame( $summary, $result->inputData() );
		$this->assertCount( 1, $this->peer->sent );
	}

	public function test_the_relayed_summary_loses_control_characters_and_long_texts(): void {
		$summary = [
			'from'      => "https://a.example\e[2J\r\n",
			'to'        => str_repeat( 'b', 3000 ),
			'wordpress' => [ 'archive' => "7.1\x00.2", 'site' => 7 ],
			'flag'      => true,
		];
		$this->script( [ self::view( 'needs_input', 'summary', 0, [ 'input' => [ 'kind' => 'confirm', 'summary' => $summary ] ] ) ] );

		$data = $this->step( false )->run( $this->pushJob( [ 'job' => self::REMOTE ] ), new CountdownDeadline( 10 ) )->inputData();

		$this->assertSame( 'https://a.example[2J', $data['from'] );
		$this->assertLessThanOrEqual( 500, strlen( $data['to'] ) );
		$this->assertSame( [ 'archive' => '7.1.2', 'site' => 7 ], $data['wordpress'] );
		$this->assertTrue( $data['flag'] );
	}

	public function test_calls_within_a_tick_are_spaced(): void {
		$this->script( [ self::view( 'running', 'content', 1 ), self::view( 'running', 'content', 2 ), self::view( 'done', 'clean', 100 ) ] );

		$this->assertTrue( $this->step( false )->run( $this->pushJob( [ 'job' => self::REMOTE ] ), new NoDeadline() )->isDone() );

		$this->assertCount( 3, $this->routes() );
		$this->assertCount( 2, $this->paused, 'The next call waits its turn (the nonce cap of the other site).' );
		foreach ( $this->paused as $seconds ) {
			$this->assertGreaterThan( 0.0, $seconds );
			$this->assertLessThanOrEqual( PushUploadStep::MIN_INTERVAL, $seconds );
		}
	}

	public function test_a_wait_never_runs_past_the_tick(): void {
		$job = $this->pushJob( [ 'job' => self::REMOTE ] );
		$this->script( [ new FakeWpError( 'cURL error 7: refused' ) ] );

		$this->step( false )->run( $job, new TimeDeadline( 0.5 ) );

		$this->assertCount( 1, $this->paused );
		$this->assertGreaterThan( 0.0, $this->paused[0] );
		$this->assertLessThanOrEqual( 0.5, $this->paused[0] );
	}

	public function test_a_confirmation_is_forwarded_only_while_the_other_site_waits(): void {
		$waiting = self::view( 'needs_input', 'summary', 0, [ 'input' => [ 'kind' => 'confirm', 'summary' => [ 'from' => 'x' ] ] ] );
		$this->script( [ $waiting, self::view( 'running', 'content', 0 ) ] );
		$job = $this->pushJob( [ 'job' => self::REMOTE ], [ 'confirm' => true, 'safetyBackup' => false ] );

		$this->assertFalse( $this->step( false )->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$this->assertSame( [ [ 'GET', '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb' ], [ 'POST', '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/input' ] ], array_map( static fn( array $r ): array => [ $r['method'], $r['route'] ], $this->peer->sent ) );
		$this->assertSame( [ 'confirm' => true, 'safetyBackup' => false ], json_decode( $this->peer->sent[1]['body'], true ) );
		$this->assertArrayNotHasKey( 'confirm', $job->state['input'] );
		$this->assertArrayNotHasKey( 'safetyBackup', $job->state['input'] );

		$this->script( [ self::view( 'running', 'content', 5 ) ] );
		$job = $this->pushJob( [ 'job' => self::REMOTE ], [ 'confirm' => true ] );
		$this->step( false )->run( $job, new CountdownDeadline( 0 ) );
		$this->assertSame( [ 'GET' ], array_column( $this->peer->sent, 'method' ), 'The earlier answer arrived: nothing is sent again.' );
		$this->assertArrayNotHasKey( 'confirm', $job->state['input'] );

		$this->script( [ $waiting, new FakeWpError( 'cURL error 28: timed out' ) ] );
		$job    = $this->pushJob( [ 'job' => self::REMOTE ], [ 'confirm' => true ] );
		$result = $this->step( false )->run( $job, new CountdownDeadline( 10 ) );
		$this->assertFalse( $result->isDone() );
		$this->assertTrue( $job->state['input']['confirm'], 'Sent again on the next run.' );
		$this->assertArrayNotHasKey( 'confirmed', $job->state['remote'] );
	}

	public function test_the_forwarded_confirmation_marks_the_push(): void {
		$this->script( [ self::view( 'needs_input', 'summary', 0, [ 'input' => [ 'kind' => 'confirm', 'summary' => [] ] ] ), self::view( 'running', 'content', 0 ) ] );
		$job = $this->pushJob( [ 'job' => self::REMOTE ], [ 'confirm' => true ] );
		$this->assertFalse( LinkPipeline::pastNoReturn( $job, $this->now ) );

		$this->step( false )->run( $job, new CountdownDeadline( 0 ) );

		$this->assertTrue( $job->state['remote']['confirmed'] );
		$this->assertTrue( LinkPipeline::pastNoReturn( $job, $this->now ) );
		foreach ( [ Job::DONE, Job::FAILED, Job::CANCELLED ] as $ended ) {
			$job->state['remote']['status'] = $ended;
			$this->assertFalse( LinkPipeline::pastNoReturn( $job, $this->now ), $ended );
		}
		$job->state['remote']['status'] = Job::RUNNING;
		$job->type                      = 'pull';
		$this->assertFalse( LinkPipeline::pastNoReturn( $job, $this->now ), 'Only a push defers.' );
	}

	public function test_a_forged_point_of_no_return_never_defers_this_sites_cancel(): void {
		$this->dir  = TempDir::create();
		$store      = new JobStore( $this->dir );
		$job        = $store->create( 'push', [ RemoteJobStep::PUSH_KEY ], [], $this->now );
		$job->state = $this->pushJob( [ 'job' => self::REMOTE ] )->state;
		$store->save( $job );
		$forged = [ 'pastNoReturn' => true ];
		$this->script(
			[
				self::view( 'running', 'content', 5, $forged ),
				self::view( 'needs_input', 'summary', 0, $forged + [ 'input' => [ 'kind' => 'confirm', 'summary' => [] ] ] ),
			]
		);
		$runner = new Runner( $store, [ RemoteJobStep::PUSH_KEY => $this->step( false ) ], null, [], null, fn( Job $j ): bool => LinkPipeline::pastNoReturn( $j, $this->now ) );

		$this->assertSame( Job::RUNNING, $runner->tick( $job->id, new CountdownDeadline( 0 ), $this->now )->status );
		$this->assertFalse( $runner->defersCancel( $store->load( $job->id ) ), 'Running, with the other site claiming its point of no return.' );
		$this->assertSame( Job::NEEDS_INPUT, $runner->tick( $job->id, new CountdownDeadline( 0 ), $this->now )->status );

		$this->assertSame( Job::CANCELLED, $runner->cancel( $job->id, $this->now )->status, 'Only this site\'s own forwarded confirmation defers a cancel.' );
	}

	public function test_a_deferral_holds_only_after_this_sites_forward_and_while_the_other_site_moves(): void {
		$job = $this->pushJob( [ 'job' => self::REMOTE, 'pastNoReturn' => true, 'status' => Job::RUNNING ] );
		$this->assertFalse( LinkPipeline::pastNoReturn( $job, $this->now ) );

		$this->script( [ self::view( 'needs_input', 'summary', 0, [ 'input' => [ 'kind' => 'confirm', 'summary' => [] ] ] ), self::view( 'running', 'content', 0 ) ] );
		$job->state['input'] = [ 'confirm' => true ];
		$this->step( false )->run( $job, new CountdownDeadline( 0 ) );

		$this->assertTrue( LinkPipeline::pastNoReturn( $job, $this->now ) );
		$this->assertTrue( LinkPipeline::pastNoReturn( $job, $this->now + LinkPipeline::STALL_SECONDS - 1 ) );
		$this->assertFalse( LinkPipeline::pastNoReturn( $job, $this->now + LinkPipeline::STALL_SECONDS ), 'A cancel is honoured once the other site stalled past the bound.' );
	}

	public function test_an_other_site_that_stops_moving_fails_the_push(): void {
		$this->assertSame( 2 * SiteLock::TTL, LinkPipeline::STALL_SECONDS );
		$job = $this->pushJob( [ 'job' => self::REMOTE ] );
		$this->script( [ self::view( 'running', 'database', 50 ) ] );
		$this->step( false )->run( $job, new CountdownDeadline( 0 ) );

		for ( $i = 1; $i < 8; $i++ ) { // Every ten minutes the same answer.
			$this->now += 600;
			$this->script( [ self::view( 'running', 'database', 50 ) ] );
			if ( $this->now - 1790000000 >= LinkPipeline::STALL_SECONDS ) {
				$this->assertLinkFailure( fn() => $this->step( false )->run( $job, new CountdownDeadline( 0 ) ), LinkException::REMOTE, 'The other site stopped making progress.', 'stalled' );
				break;
			}
			$this->assertFalse( $this->step( false )->run( $job, new CountdownDeadline( 0 ) )->isDone(), 'Still within the bound.' );
		}
		$this->assertSame( 1790000000 + LinkPipeline::STALL_SECONDS, $this->now );

		$job = $this->pushJob( [ 'job' => self::REMOTE ] );
		foreach ( range( 0, 12 ) as $i ) { // The other site moves: never stalled.
			$this->now += 600;
			$this->script( [ self::view( 'running', 'database', $i ) ] );
			$this->assertFalse( $this->step( false )->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		}
	}

	public function test_a_failed_or_cancelled_remote_job_fails_this_one_with_its_text(): void {
		$cases = [
			'failed'    => [ self::view( 'failed', 'database', 0, [ 'error' => 'Disk full at https://x.example/?token=abcdefabcdefabcdef' ] ), 'The other site stopped: Disk full at https://x.example' ],
			'cancelled' => [ self::view( 'cancelled', 'database', 0 ), 'The run was cancelled on the other site.' ],
			'password'  => [ self::view( 'needs_input', 'summary', 0, [ 'input' => [ 'kind' => 'password', 'reason' => 'encrypted' ] ] ), 'asked a question this site cannot answer' ],
			'upload'    => [ self::view( 'needs_input', 'upload', 0, [ 'input' => [ 'kind' => 'upload', 'received' => 0, 'size' => 9, 'chunkBytes' => 9 ] ] ), 'asked a question this site cannot answer' ],
		];
		foreach ( $cases as $label => [ $view, $text ] ) {
			$this->script( [ $view ] );
			$e = $this->assertLinkFailure( fn() => $this->step( false )->run( $this->pushJob( [ 'job' => self::REMOTE ] ), new CountdownDeadline( 10 ) ), LinkException::REMOTE, $text, $label );
			$this->assertStringNotContainsString( 'abcdefabcdefabcdef', $e->getMessage(), $label );
			$this->assertFalse( $e->isPassing(), $label );
		}
	}

	public function test_passing_failures_wait_then_fail(): void {
		$job = $this->pushJob( [ 'job' => self::REMOTE ] );
		$this->script( [ new FakeWpError( 'cURL error 7: refused' ) ] );
		$result = $this->step( false )->run( $job, new CountdownDeadline( 10 ) );
		$this->assertFalse( $result->isDone() );
		$this->assertSame( 1, $job->cursor['failures'] );
		$this->assertSame( [ 2.0 ], $this->paused );
		$this->assertStringStartsWith( 'Waiting for Site B: ', $result->message() );

		for ( $i = 1; $i <= 9; $i++ ) {
			$this->now += 99 + ( 9 === $i ? 8 : 0 ); // 899 s after the first failure at the ninth.
			$this->script( [ new FakeWpError( 'cURL error 7: refused' ) ] );
			$this->assertFalse( $this->step( false )->run( $job, new CountdownDeadline( 10 ) )->isDone(), 'Failure ' . ( $i + 1 ) );
		}
		$this->assertSame( 10, $job->cursor['failures'] );
		$this->assertSame( 10.0, max( $this->paused ), 'Never more than ten seconds.' );

		$this->now += 1;
		$this->script( [ new FakeWpError( 'cURL error 7: refused' ) ] );
		$this->assertLinkFailure( fn() => $this->step( false )->run( $job, new CountdownDeadline( 10 ) ), LinkException::UNREACHABLE, 'cannot be reached', 'patience spent' );

		$job = $this->pushJob( [ 'job' => self::REMOTE ] );
		$this->script( [ new FakeWpError( 'cURL error 7: refused' ) ] );
		$this->step( false )->run( $job, new CountdownDeadline( 10 ) );
		$this->script( [ self::view( 'running', 'content', 1 ) ] );
		$this->step( false )->run( $job, new CountdownDeadline( 0 ) );
		$this->assertArrayNotHasKey( 'failures', $job->cursor, 'A success clears the count.' );
		$this->assertArrayNotHasKey( 'failingSince', $job->cursor );

		$this->script( [ ScriptedPeer::reply( 401, [ 'code' => 'lw_migration_link_refused', 'message' => 'This request is not accepted.' ] ) ] );
		$this->assertLinkFailure( fn() => $this->step( false )->run( $job, new CountdownDeadline( 10 ) ), LinkException::REFUSED, 'no longer accepts this connection', 'refused at once' );
	}

	public function test_a_busy_other_site_is_a_passing_failure(): void {
		$job = $this->pushJob( [ 'job' => self::REMOTE ] );
		$this->script( [ ScriptedPeer::reply( 503, [ 'code' => 'lw_migration_link_busy', 'message' => 'Busy.' ] ) ] );

		$this->assertFalse( $this->step( false )->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$this->assertSame( 1, $job->cursor['failures'] );
		$this->assertSame( [], $this->paused, 'No pause once the deadline is reached.' );
	}

	public function test_many_ticks_do_not_trip_the_attempt_guard(): void {
		$this->dir = TempDir::create();
		$store     = new JobStore( $this->dir );
		$job       = $store->create( 'push', [ RemoteJobStep::PUSH_KEY ], [], $this->now );
		$job->state = $this->pushJob( [ 'job' => self::REMOTE ] )->state;
		$store->save( $job );
		$this->script( array_fill( 0, 8, self::view( 'running', 'database', 50 ) ) );
		$runner = new Runner( $store, [ RemoteJobStep::PUSH_KEY => $this->step( false ) ] );

		for ( $i = 0; $i < 8; $i++ ) {
			$ticked = $runner->tick( $job->id, new CountdownDeadline( 0 ), $this->now + $i );
			$this->assertSame( Job::RUNNING, $ticked->status, 'Tick ' . ( $i + 1 ) . ': ' . $ticked->error );
		}

		$saved = $store->load( $job->id );
		$this->assertSame( Job::RUNNING, $saved->status );
		$this->assertSame( '', $saved->error );
		$this->assertSame( 8, $saved->cursor['calls'] );
		$this->assertCount( 8, $this->peer->sent );
	}

	public function test_a_malformed_view_is_a_protocol_failure(): void {
		$cases = [
			'id'        => self::view( 'running', 'content', 0, [ 'id' => 'BBBBBBBBBBBBBBBB' ] ),
			'status'    => self::view( 'sleeping', 'content', 0 ),
			'steps'     => self::view( 'running', 'content', 0, [ 'steps' => [ 'a' => 'upload' ] ] ),
			'step type' => self::view( 'running', 'content', 0, [ 'steps' => [ 'upload', 5 ] ] ),
			'index'     => self::view( 'running', 'content', 0, [ 'stepIndex' => '2' ] ),
			'message'   => self::view( 'running', 'content', 0, [ 'message' => [ 'x' ] ] ),
			'other job' => self::view( 'running', 'content', 0, [ 'id' => 'cccccccccccccccc' ] ),
		];
		foreach ( $cases as $label => $view ) {
			$this->script( [ $view ] );
			$this->assertLinkFailure( fn() => $this->step( false )->run( $this->pushJob( [ 'job' => self::REMOTE ] ), new CountdownDeadline( 10 ) ), LinkException::PROTOCOL, 'Update LW Migration on both sites.', $label );
		}
		$this->script( [] );
		$this->assertLinkFailure( fn() => $this->step( false )->run( $this->pushJob( [] ), new CountdownDeadline( 10 ) ), LinkException::PROTOCOL, 'never started the restore', 'no remote job' );
	}

	public function test_pull_mode_starts_the_export_once_and_hands_over_the_download(): void {
		$export = static fn( string $status, string $step, int $progress, array $extra = [] ): array => self::view( $status, $step, $progress, array_merge( [ 'type' => 'export', 'steps' => [ 'init', 'content', 'finalize' ], 'stepIndex' => [ 'init' => 0, 'content' => 1, 'finalize' => 2 ][ $step ] ], $extra ) );
		$sha    = hash( 'sha256', 'the archive' );
		$this->script( [ $export( 'pending', 'init', 0 ), $export( 'running', 'content', 50 ), $export( 'done', 'finalize', 100, [ 'archiveBytes' => 123456, 'archiveSha256' => $sha ] ) ] );
		$job = $this->pullJob();

		$result = $this->step( true )->run( $job, new CountdownDeadline( 10 ) );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( [ '/lw-migration/v1/link/pull', '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/tick', '/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/tick' ], $this->routes() );
		$this->assertSame( [ 'ref' => self::JOB ], json_decode( $this->peer->sent[0]['body'], true ) );
		$this->assertSame( self::REMOTE, $job->state['remote']['job'] );
		$this->assertSame(
			[
				'url'    => 'https://b.example/index.php?rest_route=/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/archive',
				'size'   => 123456,
				'ranges' => true,
				'sha256' => $sha,
			],
			$job->state['download']
		);

		$cases = [
			'no size'     => [ null, $sha ],
			'too small'   => [ 2 * Format::HEADER_SIZE - 1, $sha ],
			'no checksum' => [ 123456, null ],
			'bad form'    => [ 123456, strtoupper( $sha ) ],
		];
		foreach ( $cases as $label => [ $bytes, $checksum ] ) {
			$this->script( [ $export( 'done', 'finalize', 100, [ 'archiveBytes' => $bytes, 'archiveSha256' => $checksum ] ) ] );
			$this->assertLinkFailure( fn() => $this->step( true )->run( $this->pullJob(), new CountdownDeadline( 10 ) ), LinkException::PROTOCOL, 'without an archive', $label );
		}
		$this->script( [ $export( 'needs_input', 'init', 0, [ 'input' => [ 'kind' => 'confirm', 'summary' => [] ] ] ) ] );
		$this->assertLinkFailure( fn() => $this->step( true )->run( $this->pullJob(), new CountdownDeadline( 10 ) ), LinkException::REMOTE, 'cannot answer', 'pull never answers' );
	}

	public function test_a_removed_link_says_pair_again(): void {
		$this->peer->links->remove( ScriptedPeer::OUT );

		$this->assertLinkFailure( fn() => $this->step( false )->run( $this->pushJob( [ 'job' => self::REMOTE ] ), new CountdownDeadline( 10 ) ), LinkException::GONE, 'Pair the two sites again.', 'gone' );
		$this->assertSame( [], $this->peer->sent );
	}

	public function test_retry_counts_only_passing_failures(): void {
		$job = $this->pushJob( [] );
		$this->assertSame( 1, Retry::note( $job, new LinkException( LinkException::UNREACHABLE, 'x' ), 100 ) );
		$this->assertSame( 2, Retry::note( $job, new LinkException( LinkException::REMOTE, 'x', 502 ), 150 ) );
		$this->assertSame( 100, $job->cursor['failingSince'] );
		Retry::clear( $job );
		$this->assertSame( [], $job->cursor );
		$this->assertLinkFailure( static fn() => Retry::note( $job, new LinkException( LinkException::PROTOCOL, 'final' ), 1 ), LinkException::PROTOCOL, 'final', 'lasting' );
		$this->assertSame( [], $job->cursor, 'A lasting failure is not counted.' );
	}
}
