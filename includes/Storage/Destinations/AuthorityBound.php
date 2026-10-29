<?php
/**
 * A type whose stored credentials belong to one server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * A password, key or secret saved for one server must never be sent to
 * another one the next time the settings are edited (the way
 * OAuthDestinationType::sameGrant() does it for the OAuth tokens). A type
 * says what the server is (its authority) and which of its fields are tied
 * to it: when an edit moves the authority, the secret ones are dropped from
 * the vault and asked for again, the non-secret ones (a pinned certificate,
 * say) are dropped from the settings. A type adds a pinned field to
 * authorityFields() and nothing else. The secrets are sealed together with
 * the authority they were saved for (SecretBinding), so a reader holding
 * settings that name another server never gets them.
 */
interface AuthorityBound {

	/**
	 * The server the settings reach, normalised: two settings reach the
	 * same server exactly when their authority() is identical (===).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<int, string|int>
	 */
	public function authority( array $settings ): array;

	/**
	 * Keys of the fields (secret or not) that belong to the authority.
	 *
	 * @return array<int, string>
	 */
	public function authorityFields(): array;
}
