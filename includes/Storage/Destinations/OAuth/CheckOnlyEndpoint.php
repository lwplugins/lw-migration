<?php
/**
 * The token endpoint of a connect's account check: it buys no token.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * M7c preflight P-C1 / Task 7. While the connect callback asks the provider
 * who is connected (and sets up the Drive folder), the fresh tokens live
 * only in memory: the TokenKeeper of that check has no grant, so it never
 * writes, and its endpoint is this one, so it never refreshes either — a
 * 401 during the check (which would need a new access token) ends the
 * connect as failed, with nothing sent and nothing stored. The exchange is
 * done before the check, never through here.
 */
final class CheckOnlyEndpoint implements TokenEndpoint {

	/**
	 * @param string $label Provider name for the message.
	 */
	public function __construct( private string $label ) {
	}

	/**
	 * @param OAuthApp $app         App.
	 * @param string   $code        Authorization code.
	 * @param string   $verifier    PKCE verifier.
	 * @param string   $redirectUri Redirect address.
	 * @throws \LogicException Always (a programming error: the exchange is done before the check).
	 */
	public function exchange( OAuthApp $app, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $redirectUri ): TokenSet {
		throw new \LogicException( 'The account check does not exchange a code.' );
	}

	/**
	 * @param OAuthApp $app          App.
	 * @param string   $refreshToken Refresh token.
	 * @throws PermanentFailure INVALID, always (no request).
	 */
	public function refresh( OAuthApp $app, #[\SensitiveParameter] string $refreshToken ): TokenSet {
		/* translators: %s: provider name */
		throw new PermanentFailure( sprintf( __( '%s did not accept the new sign-in while the account was being checked. Choose Connect again.', 'lw-migration' ), $this->label ), PermanentFailure::INVALID );
	}
}
