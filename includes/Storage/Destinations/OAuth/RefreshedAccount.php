<?php
/**
 * The account check of a refresh through the LW app.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #12, for TokenKeeper::store(). Without a check (own app) nothing is
 * asked. Through the LW app the refreshed access token is asked at the
 * provider (AccountCheck) and the answer compared with the account
 * recorded at connect (hash_equals): another account, or an answer that
 * confirms none (401, 403, a 200 without the id), is REAUTH. A connection
 * without a recorded account ('' or TokenKeeper::NO_IDENTITY) has nothing to
 * compare with, so it is REAUTH without asking: every connect records one,
 * and a sign-in is never trusted on its first refresh. A TransientFailure of
 * the check (a timeout, a network error, 408, 429, 5xx) passes through:
 * nothing is stored and the refresh is tried again later, like any
 * temporary refresh failure, never a new connect.
 */
final class RefreshedAccount {

	/**
	 * @param string        $recorded The account recorded at connect.
	 * @param \Closure|null $verify   fn( string $accessToken ): string; null: not asked.
	 * @param string        $label    Provider name.
	 */
	public function __construct( private string $recorded, private ?\Closure $verify, private string $label ) {
	}

	/**
	 * @param string $accessToken The refreshed access token.
	 * @throws PermanentFailure REAUTH for another account, none confirmed, or none recorded.
	 * @throws TransientFailure The check's temporary failure (nothing stored).
	 */
	public function confirm( #[\SensitiveParameter] string $accessToken ): void {
		if ( null === $this->verify ) {
			return;
		}
		if ( '' === $this->recorded || TokenKeeper::NO_IDENTITY === $this->recorded ) {
			throw $this->unconfirmed();
		}
		try {
			$seen = (string) ( $this->verify )( $accessToken );
		} catch ( TransientFailure $e ) {
			throw $e; // An outage at the provider: nothing stored, tried again later (never a new connect).
		} catch ( CloudFailure $e ) {
			$seen = '';
		}
		if ( '' === $seen ) {
			throw $this->unconfirmed();
		}
		if ( ! hash_equals( $this->recorded, $seen ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'The new sign-in to %s named another account than the one connected, so it was not kept. Choose Connect again.', 'lw-migration' ), $this->label ), PermanentFailure::REAUTH );
		}
	}

	private function unconfirmed(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( '%s did not confirm that the new sign-in belongs to the connected account, so it was not kept. Choose Connect again.', 'lw-migration' ), $this->label ), PermanentFailure::REAUTH );
	}
}
