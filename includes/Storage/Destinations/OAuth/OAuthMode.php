<?php
/**
 * Which app an OAuth destination connects through.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Spec auth-lwplugins-com §7 (rulings R2, R3 of plan M7d). `ownApp` false
 * (the default) is the LW app through the LW sign-in service; true is the
 * admin's own app with its Client ID and secret. A settings array without
 * the switch (saved before it existed, or --client-id alone on the command
 * line) is the own app exactly when it holds a Client ID. An LW-app connect
 * is bound to "\0lw-relay:<provider>": a Client ID never holds a NUL, so no
 * own app can share the binding.
 */
final class OAuthMode {

	public const OWN_APP = 'ownApp';

	private const RELAY_KEY = "\0lw-relay:";

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function ownApp( array $settings ): bool {
		$own = $settings[ self::OWN_APP ] ?? null;
		$id  = $settings[ OAuthDestinationType::CLIENT_ID ] ?? null;

		return is_bool( $own ) ? $own : is_string( $id ) && '' !== $id;
	}

	/**
	 * @param string $provider Provider key (Provider::GOOGLE …).
	 */
	public static function relayKey( string $provider ): string {
		return self::RELAY_KEY . $provider;
	}
}
