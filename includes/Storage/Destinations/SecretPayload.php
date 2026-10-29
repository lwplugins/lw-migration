<?php
/**
 * What one sealed destination secret holds.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * The plain text SecretVault seals for one field. Without a binding it is
 * "<profile id>\0<field>\0<value>", the layout every value had before the
 * binding existed; with one it is "<profile id>\0<field>\x01<binding>\0<value>"
 * (SecretBinding). A field name is [A-Za-z0-9_] and a binding is hex, so each
 * layout reads only one way. An unbound value opens whatever binding is
 * asked for (it was saved before bindings, or for a type without a server);
 * a bound one only for its own binding, or when the caller asks for none.
 */
final class SecretPayload {

	private const UNBOUND = "\0";

	private const BOUND = "\x01";

	/**
	 * @param string      $id      Profile id.
	 * @param string      $field   Field.
	 * @param string|null $binding SecretBinding (null: none).
	 * @param string      $plain   Plain value.
	 */
	public static function wrap( string $id, string $field, ?string $binding, #[\SensitiveParameter] string $plain ): string {
		return $id . "\0" . $field . ( null === $binding ? self::UNBOUND : self::BOUND . $binding . "\0" ) . $plain;
	}

	/**
	 * @param string      $id      Profile id.
	 * @param string      $field   Field.
	 * @param string|null $binding The binding the caller holds (null: not checked).
	 * @param string      $opened  The opened plain text.
	 * @return string|null The value; null when it belongs to another profile, field or server.
	 */
	public static function unwrap( string $id, string $field, ?string $binding, #[\SensitiveParameter] string $opened ): ?string {
		$prefix = $id . "\0" . $field;
		if ( ! str_starts_with( $opened, $prefix ) ) {
			return null;
		}
		$rest = substr( $opened, strlen( $prefix ) );
		if ( str_starts_with( $rest, self::UNBOUND ) ) {
			return substr( $rest, 1 );
		}
		$end = strpos( $rest, "\0" );
		if ( ! str_starts_with( $rest, self::BOUND ) || false === $end ) {
			return null;
		}
		$stored = substr( $rest, 1, $end - 1 );
		if ( null !== $binding && ! hash_equals( $stored, $binding ) ) {
			return null;
		}

		return substr( $rest, $end + 1 );
	}
}
