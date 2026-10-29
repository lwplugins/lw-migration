<?php
/**
 * The folder setting of a destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D38: the rule M7a's S3Type::prefix() had, shared by every type —
 * trimmed, doubled slashes merged, no "." or ".." part, a short safe
 * character set. FTP and SFTP may keep one leading "/" (a path from the
 * server's root instead of the login folder).
 */
final class FolderSetting {

	private const PATTERN = '#^[A-Za-z0-9._ /-]{0,200}\z#';

	/**
	 * @param mixed  $value    Raw folder.
	 * @param string $field    Settings key named by a refusal.
	 * @param bool   $absolute Whether a leading "/" is kept.
	 * @throws FieldException On a folder the rule refuses.
	 */
	public static function clean( $value, string $field, bool $absolute = false ): string {
		$text   = is_string( $value ) ? trim( $value ) : '';
		$root   = $absolute && str_starts_with( $text, '/' );
		$folder = trim( (string) preg_replace( '#/+#', '/', $text ), '/' );
		$parts  = '' === $folder ? [] : explode( '/', $folder );
		if ( 1 !== preg_match( self::PATTERN, $folder ) || in_array( '..', $parts, true ) || in_array( '.', $parts, true ) ) {
			throw new FieldException( $field, __( 'A folder has letters, digits, spaces, dots, hyphens, underscores and slashes, and no "." or ".." part.', 'lw-migration' ) );
		}

		return ( $root ? '/' : '' ) . $folder;
	}
}
