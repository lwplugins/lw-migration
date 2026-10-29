<?php
/**
 * Who turns a code or a refresh token into an access token.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5: DirectTokenEndpoint now, a RelayTokenEndpoint (holding the
 * client secret) later. Callers never know which one runs. A transient
 * failure is retried by the caller, never here.
 */
interface TokenEndpoint {

	/**
	 * @param OAuthApp $app         App.
	 * @param string   $code        Authorization code from the callback.
	 * @param string   $verifier    PKCE verifier ('' when the provider gets none).
	 * @param string   $redirectUri The redirect address the authorize URL used.
	 * @throws CloudFailure On any refusal (a used or expired code: PermanentFailure INVALID).
	 */
	public function exchange( OAuthApp $app, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $redirectUri ): TokenSet;

	/**
	 * @param OAuthApp $app          App.
	 * @param string   $refreshToken Stored refresh token.
	 * @throws CloudFailure On any refusal (a revoked grant: PermanentFailure REAUTH).
	 */
	public function refresh( OAuthApp $app, #[\SensitiveParameter] string $refreshToken ): TokenSet;
}
