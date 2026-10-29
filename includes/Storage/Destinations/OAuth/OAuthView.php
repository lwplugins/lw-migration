<?php
/**
 * What the admin screens see of an OAuth type and of a connected profile.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5, moved out of OAuthDestinationType (no change in behaviour).
 * The schema block tells the admin where to register the redirect address
 * and whether this provider accepts it here (RedirectRules); the view block
 * tells whether a usable refresh token exists, as whom, and whether the
 * site's address changed since the connect (tokens keep working; a
 * reconnect needs the new address registered first). Never a token, a mark
 * or the client secret in either. In the LW-app mode the relay's rule
 * (RelayAddress) decides, and the address is never "changed": nothing is
 * registered for the LW app.
 */
final class OAuthView {

	/**
	 * The schema block of a type (REST `types[].oauth`).
	 *
	 * @param OAuthDestinationType $type        The type.
	 * @param array<string, mixed> $settings    Settings ([] = the type's defaults).
	 * @param string               $redirectUri This site's redirect address.
	 * @return array{provider: string, redirectUri: string, eligible: bool, reason: string, text: string, steps: array<int, string>, consoleUrl: string, relay: array{eligible: bool, reason: string, text: string}}
	 */
	public static function schema( OAuthDestinationType $type, array $settings, string $redirectUri ): array {
		$provider = $type->provider( $settings );
		$refusal  = RedirectRules::check( $provider, $redirectUri );

		return [
			'provider'    => $provider->key,
			'redirectUri' => $redirectUri,
			'eligible'    => null === $refusal,
			'reason'      => $refusal['code'] ?? '',
			'text'        => $refusal['text'] ?? '',
			'steps'       => $type->steps(),
			'consoleUrl'  => $type->consoleUrl(),
			'relay'       => self::relayBlock( $redirectUri ),
		];
	}

	/**
	 * The view block of a profile (REST `items[].connection`): connected
	 * means a refresh token is stored, the connection was made with the
	 * settings the profile has now, and the stored tokens are the ones of
	 * this connection (the vault is opened for that; nothing of it is shown).
	 *
	 * @param OAuthDestinationType $type        The profile's type.
	 * @param Profile              $profile     Profile.
	 * @param array<int, string>   $setFields   SecretVault::fields() of it.
	 * @param string               $redirectUri This site's redirect address now.
	 * @return array{connected: bool, account: string, connectedAt: int, redirectChanged: bool, eligible: bool, reason: string, text: string}
	 */
	public static function connection( OAuthDestinationType $type, Profile $profile, array $setFields, string $redirectUri ): array {
		$connected = in_array( TokenKeeper::REFRESH, $setFields, true ) && $type->bound( $profile ) && self::stored( $type, $profile );
		$used      = (string) ( $profile->connection['redirectUri'] ?? '' );
		$own       = OAuthMode::ownApp( $profile->settings );
		$refusal   = $own ? RedirectRules::check( $type->provider( $profile->settings ), $redirectUri ) : RelayAddress::check( $redirectUri );

		return [
			'connected'       => $connected,
			'account'         => (string) ( $profile->connection['account'] ?? '' ),
			'connectedAt'     => (int) ( $profile->connection['connectedAt'] ?? 0 ),
			'redirectChanged' => $own && $connected && '' !== $used && $used !== $redirectUri, // Nothing is registered for the LW app.
			'eligible'        => null === $refusal,
			'reason'          => $refusal['code'] ?? '',
			'text'            => $refusal['text'] ?? '',
		];
	}

	/**
	 * Whether the LW sign-in service accepts this site's callback address.
	 *
	 * @param string $redirectUri This site's callback address.
	 * @return array{eligible: bool, reason: string, text: string}
	 */
	private static function relayBlock( string $redirectUri ): array {
		$refusal = RelayAddress::check( $redirectUri );

		return [
			'eligible' => null === $refusal,
			'reason'   => $refusal['code'] ?? '',
			'text'     => $refusal['text'] ?? '',
		];
	}

	/**
	 * Whether the vault holds the tokens of the profile's connection (its id
	 * is the mark of the stored grant; Task 7 review round 2 of M7c).
	 *
	 * @param OAuthDestinationType $type    The profile's type (its vault).
	 * @param Profile              $profile Profile.
	 */
	private static function stored( OAuthDestinationType $type, Profile $profile ): bool {
		$id      = (string) ( $profile->connection['id'] ?? '' );
		$secrets = '' === $id ? null : $type->vault()->open( $profile->id );

		return null !== $secrets && hash_equals( $id, TokenKeeper::connectionId( (string) ( $secrets[ TokenKeeper::GRANT ] ?? '' ) ) );
	}
}
