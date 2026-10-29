<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Reset\PasswordGuard;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\Tries;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class PasswordGuardTest extends MonkeyTestCase {

	private const NOW = 1791363600;

	private function user(): \WP_User {
		$user            = \Mockery::mock( \WP_User::class );
		$user->ID        = 7;
		$user->user_pass = '$wp$2y$10$hash';

		return $user;
	}

	private function guard( Tries $tries ): PasswordGuard {
		return new PasswordGuard( $tries, static fn( string $password, \WP_User $user ): bool => 'right' === $password && 7 === $user->ID );
	}

	private function reason( \Closure $call ): string {
		try {
			$call();
		} catch ( ResetRefused $e ) {
			return $e->reason . ':' . $e->retryAfter;
		}

		return 'passed';
	}

	public function test_the_right_password_passes_and_clears_earlier_failures(): void {
		FakeWpdb::install();
		$tries = new Tries();
		$tries->reserve( 7, self::NOW );
		$this->assertSame( 'passed', $this->reason( fn() => $this->guard( $tries )->check( $this->user(), 'right', self::NOW ) ) );
		$this->assertSame( 0, $tries->lockedFor( 7, self::NOW ) );
	}

	public function test_five_failures_lock_the_user_for_fifteen_minutes(): void {
		FakeWpdb::install();
		$guard = $this->guard( new Tries() );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 'password:0', $this->reason( fn() => $guard->check( $this->user(), 'wrong', self::NOW ) ) );
		}
		$this->assertSame( 'locked:900', $this->reason( fn() => $guard->check( $this->user(), 'wrong', self::NOW ) ), 'The fifth failure locks.' );
		$this->assertSame( 'locked:600', $this->reason( fn() => $guard->check( $this->user(), 'right', self::NOW + 300 ) ), 'Even the right password waits.' );
		$this->assertSame( 'passed', $this->reason( fn() => $guard->check( $this->user(), 'right', self::NOW + 900 ) ) );
	}

	public function test_a_failure_that_cannot_be_recorded_refuses(): void {
		$db             = FakeWpdb::install( [ 'lw_migration_reset_tries' => '{}' ] );
		$db->failWrites = true;
		$this->assertSame( 'unrecorded:0', $this->reason( fn() => $this->guard( new Tries() )->check( $this->user(), 'wrong', self::NOW ) ) );
	}

	public function test_parallel_guesses_get_at_most_five_password_checks(): void {
		FakeWpdb::install();
		$checks   = 0;
		$requests = 1;
		$answers  = [];
		$guard    = null;
		// Each check starts the next request while this one is still checking: all of them
		// passed their lock read before any failure was counted (requests in parallel).
		$guard = new PasswordGuard(
			new Tries(),
			function () use ( &$checks, &$requests, &$answers, &$guard ): bool {
				++$checks;
				if ( $requests < 12 ) {
					++$requests;
					$answers[] = $this->reason( fn() => $guard->check( $this->user(), 'wrong', self::NOW ) );
				}

				return false;
			}
		);
		$answers[] = $this->reason( fn() => $guard->check( $this->user(), 'wrong', self::NOW ) );

		$this->assertSame( Tries::MAX_FAILURES, $checks, 'Only the reserved attempts reach the password check.' );
		$this->assertSame( 900, ( new Tries() )->lockedFor( 7, self::NOW ) );
		$this->assertNotContains( 'passed', $answers );
	}

	public function test_an_attempt_reserved_while_another_request_counts_is_counted_too(): void {
		$db = FakeWpdb::install( [ 'lw_migration_reset_tries' => '{"7":{"n":3,"first":' . self::NOW . ',"until":0}}' ] );
		// A concurrent request reserves the fourth attempt between this one's read and its write.
		$db->beforeWrite = static function () use ( $db ): void {
			$db->rows['lw_migration_reset_tries']['value'] = '{"7":{"n":4,"first":' . self::NOW . ',"until":0}}';
		};
		$this->assertSame( 'locked:900', $this->reason( fn() => $this->guard( new Tries() )->check( $this->user(), 'wrong', self::NOW ) ), 'The compare-and-swap retries: this is the fifth.' );
	}

	public function test_a_right_password_whose_attempt_cannot_be_reserved_is_refused(): void {
		$db             = FakeWpdb::install( [ 'lw_migration_reset_tries' => '{}' ] );
		$db->failWrites = true;
		$this->assertSame( 'unrecorded:0', $this->reason( fn() => $this->guard( new Tries() )->check( $this->user(), 'right', self::NOW ) ) );
	}

	public function test_the_lock_is_kept_per_user_id(): void {
		FakeWpdb::install();
		$guard = $this->guard( new Tries() );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->reason( fn() => $guard->check( $this->user(), 'wrong', self::NOW ) );
		}
		$other     = \Mockery::mock( \WP_User::class );
		$other->ID = 8;
		$this->assertSame( 'password:0', $this->reason( fn() => $guard->check( $other, 'wrong', self::NOW ) ), 'Another user is not locked.' );
	}

	public function test_the_wordpress_check_never_shows_the_password_in_a_trace(): void {
		FakeWpdb::install();
		$previous = ini_set( 'zend.exception_ignore_args', '0' );
		Functions\when( 'wp_check_password' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'hash library failed' );
			}
		);
		try {
			PasswordGuard::fromWordPress()->check( $this->user(), 'guess-1234', self::NOW );
			$this->fail( 'The checker throws.' );
		} catch ( \RuntimeException $e ) {
			// Core's wp_check_password() marks its own parameter #[\SensitiveParameter]; the stand-in here does not.
			$ours = array_filter(
				$e->getTrace(),
				static fn( array $frame ): bool => 'wp_check_password' !== ( $frame['function'] ?? '' )
					&& ( str_contains( (string) ( $frame['file'] ?? '' ), '/includes/Reset/' ) || str_starts_with( (string) ( $frame['class'] ?? '' ), 'LightweightPlugins\\Migration\\Reset\\' ) )
			);
			$this->assertNotEmpty( $ours );
			$this->assertStringNotContainsString( 'guess-1234', print_r( array_map( static fn( array $frame ): array => $frame['args'] ?? [], $ours ), true ) );
		} finally {
			ini_set( 'zend.exception_ignore_args', false === $previous ? '0' : $previous );
		}
	}

	public function test_a_user_without_an_id_is_refused_before_anything_is_counted(): void {
		$db        = FakeWpdb::install();
		$user      = \Mockery::mock( \WP_User::class );
		$user->ID  = 0;
		$guard     = new PasswordGuard( new Tries(), static function (): bool {
			throw new \LogicException( 'never called' );
		} );
		$this->assertSame( 'no_user:0', $this->reason( fn() => $guard->check( $user, 'right', self::NOW ) ) );
		$this->assertNull( $db->value( 'lw_migration_reset_tries' ) );
	}

	public function test_an_empty_password_is_a_failure_without_calling_the_check(): void {
		FakeWpdb::install();
		$guard = new PasswordGuard( new Tries(), static function (): bool {
			throw new \LogicException( 'never called' );
		} );
		$this->assertSame( 'password:0', $this->reason( fn() => $guard->check( $this->user(), '', self::NOW ) ) );
	}
}
