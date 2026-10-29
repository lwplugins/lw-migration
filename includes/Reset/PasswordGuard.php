<?php
/**
 * The password check before a reset.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * A stolen session alone must not reset the site: the person types their own
 * password, checked once per start before the job exists. The attempt is
 * counted (Tries::reserve(), compare-and-swap) before the password is
 * checked, so requests in parallel get at most MAX_FAILURES checks; a locked
 * user is refused before the check (even the right password waits), and an
 * attempt that cannot be recorded refuses. The counter is keyed by user id,
 * shared by the admin screen and the command line. The password is never
 * kept, logged or put into a message.
 */
final class PasswordGuard {

	/**
	 * @param Tries    $tries  Failure counter.
	 * @param \Closure $checks fn( string $password, \WP_User $user ): bool.
	 */
	public function __construct( private Tries $tries, private \Closure $checks ) {
	}

	public static function fromWordPress(): self {
		return new self(
			new Tries(),
			static fn( #[\SensitiveParameter] string $password, \WP_User $user ): bool => wp_check_password( $password, $user->user_pass, $user->ID )
		);
	}

	/**
	 * Runs once per start, before the job exists; the password is never kept.
	 *
	 * @param \WP_User $user     The logged-in user.
	 * @param string   $password What they typed.
	 * @param int      $now      Unix time.
	 * @return void
	 * @throws ResetRefused LOCKED (retryAfter), UNRECORDED, PASSWORD.
	 */
	public function check( \WP_User $user, #[\SensitiveParameter] string $password, int $now ): void {
		$id = (int) $user->ID;
		if ( $id <= 0 ) {
			throw new ResetRefused( ResetRefused::NO_USER, ResetTexts::refusal( ResetRefused::NO_USER ) );
		}
		$this->refuseWhileLocked( $id, $now );
		if ( ! $this->tries->reserve( $id, $now ) ) {
			$this->refuseWhileLocked( $id, $now ); // Another request's attempt set the lock.
			throw new ResetRefused( ResetRefused::UNRECORDED, ResetTexts::refusal( ResetRefused::UNRECORDED ) );
		}
		if ( '' !== $password && ( $this->checks )( $password, $user ) ) {
			$this->tries->clear( $id ); // If this write is lost, the reserved attempt stays counted (accepted).
			return;
		}
		$this->refuseWhileLocked( $id, $now ); // The reserved attempt stays counted; the fifth locks.
		throw new ResetRefused( ResetRefused::PASSWORD, ResetTexts::refusal( ResetRefused::PASSWORD ) );
	}

	/**
	 * @param int $id  User id.
	 * @param int $now Unix time.
	 * @throws ResetRefused LOCKED while the user is locked out.
	 */
	private function refuseWhileLocked( int $id, int $now ): void {
		$wait = $this->tries->lockedFor( $id, $now );
		if ( $wait > 0 ) {
			throw new ResetRefused( ResetRefused::LOCKED, ResetTexts::refusal( ResetRefused::LOCKED, $wait ), $wait );
		}
	}
}
