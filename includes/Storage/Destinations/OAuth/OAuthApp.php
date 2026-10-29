<?php
/**
 * The admin's own OAuth app at a provider.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E5: what a TokenEndpoint needs to speak for the app. The secret may
 * be '' for a later endpoint that holds it itself (the relay); it is never
 * put in a message, a log line or a URL.
 */
final class OAuthApp {

	/**
	 * @param Provider $provider     Provider.
	 * @param string   $clientId     Client ID (Dropbox: app key).
	 * @param string   $clientSecret Client secret (Dropbox: app secret).
	 */
	public function __construct( public Provider $provider, public string $clientId, #[\SensitiveParameter] public string $clientSecret ) {
	}
}
