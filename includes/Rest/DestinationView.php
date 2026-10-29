<?php
/**
 * A destination as the admin screens see it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthView;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectUri;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Whitelisted fields only (spec 21.3: "REST soha nem ad vissza titkot,
 * csak { set: true }"): the settings are the type's non-secret ones; every
 * secret field of the type is listed with set true/false and nothing else;
 * a stored key the type does not name is never shown. `insecure` flags a
 * profile that sends backups unencrypted.
 * `connection` is an OAuth profile's state (OAuthView::connection(),
 * never a token: the token fields are no form fields), null for other types.
 * An OAuth profile always shows its mode (OAuthMode::ownApp()), so one saved
 * before the switch existed opens as its own app in the form.
 */
final class DestinationView {

	/**
	 * @param Profile              $p         Profile.
	 * @param DestinationType|null $type      Its type (null: unknown here).
	 * @param array<int, string>   $setFields Secret fields with a stored value (SecretVault::fields()).
	 * @param int                  $usedBy    Schedules that name it.
	 * @return array<string, mixed>
	 */
	public static function of( Profile $p, ?DestinationType $type, array $setFields, int $usedBy ): array {
		$secrets = [];
		$shown   = [];
		foreach ( null === $type ? [] : $type->fields() as $field ) {
			if ( $field->isSecret() ) {
				$secrets[ $field->key ] = [ 'set' => in_array( $field->key, $setFields, true ) ];
			} elseif ( Field::OAUTH !== $field->kind ) {
				$shown[ $field->key ] = true;
			}
		}
		$settings = array_intersect_key( $p->settings, $shown ); // Only the type's own non-secret fields; an unknown type shows none.
		if ( $type instanceof OAuthDestinationType ) {
			$settings = [ OAuthMode::OWN_APP => OAuthMode::ownApp( $p->settings ) ] + $settings;
		}

		return [
			'id'           => $p->id,
			'type'         => $p->type,
			'typeLabel'    => null === $type ? $p->type : $type->label(),
			'name'         => $p->name,
			'where'        => null === $type ? '' : $type->describe( $p ),
			'settings'     => $settings,
			'secrets'      => $secrets,
			'retention'    => $p->retention,
			'status'       => $p->status,
			'message'      => $p->message,
			'lastUploadAt' => $p->lastUploadAt,
			'insecure'     => null !== $type && $type->insecure( $p ),
			'available'    => null !== $type && null === $type->unavailable(),
			'usedBy'       => $usedBy,
			'connection'   => $type instanceof OAuthDestinationType ? OAuthView::connection( $type, $p, $setFields, RedirectUri::current() ) : null,
		];
	}
}
