<?php
/**
 * The roles of a restored site, without network rights.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Review N1 (sites or single-site archive into a network): a site's roles
 * (<prefix>user_roles) and a user's roles there (<prefix>capabilities) come
 * from the archive. No role of a restored site may carry a network right,
 * and a user's value there may only name roles of that site. Values are read
 * without creating any object. Pure.
 */
final class SiteRoles {

	/** Capabilities that act on the whole network; never granted by a restored site's role. */
	public const NETWORK_CAPS = [ 'create_sites', 'delete_sites', 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network', 'setup_network' ];

	/**
	 * WordPress's default roles: what populate_roles() (wp-admin/includes/schema.php,
	 * populate_roles_160() … populate_roles_300()) stores, generated from WordPress
	 * 7.1.2; used when a site's roles cannot be read.
	 */
	public const CORE_DEFAULT = 'a:5:{s:13:"administrator";a:2:{s:4:"name";s:13:"Administrator";s:12:"capabilities";a:61:{s:13:"switch_themes";b:1;s:11:"edit_themes";b:1;s:16:"activate_plugins";b:1;s:12:"edit_plugins";b:1;s:10:"edit_users";b:1;s:10:"edit_files";b:1;s:14:"manage_options";b:1;s:17:"moderate_comments";b:1;s:17:"manage_categories";b:1;s:12:"manage_links";b:1;s:12:"upload_files";b:1;s:6:"import";b:1;s:15:"unfiltered_html";b:1;s:10:"edit_posts";b:1;s:17:"edit_others_posts";b:1;s:20:"edit_published_posts";b:1;s:13:"publish_posts";b:1;s:10:"edit_pages";b:1;s:4:"read";b:1;s:8:"level_10";b:1;s:7:"level_9";b:1;s:7:"level_8";b:1;s:7:"level_7";b:1;s:7:"level_6";b:1;s:7:"level_5";b:1;s:7:"level_4";b:1;s:7:"level_3";b:1;s:7:"level_2";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:17:"edit_others_pages";b:1;s:20:"edit_published_pages";b:1;s:13:"publish_pages";b:1;s:12:"delete_pages";b:1;s:19:"delete_others_pages";b:1;s:22:"delete_published_pages";b:1;s:12:"delete_posts";b:1;s:19:"delete_others_posts";b:1;s:22:"delete_published_posts";b:1;s:20:"delete_private_posts";b:1;s:18:"edit_private_posts";b:1;s:18:"read_private_posts";b:1;s:20:"delete_private_pages";b:1;s:18:"edit_private_pages";b:1;s:18:"read_private_pages";b:1;s:12:"delete_users";b:1;s:12:"create_users";b:1;s:17:"unfiltered_upload";b:1;s:14:"edit_dashboard";b:1;s:14:"update_plugins";b:1;s:14:"delete_plugins";b:1;s:15:"install_plugins";b:1;s:13:"update_themes";b:1;s:14:"install_themes";b:1;s:11:"update_core";b:1;s:10:"list_users";b:1;s:12:"remove_users";b:1;s:13:"promote_users";b:1;s:18:"edit_theme_options";b:1;s:13:"delete_themes";b:1;s:6:"export";b:1;}}s:6:"editor";a:2:{s:4:"name";s:6:"Editor";s:12:"capabilities";a:34:{s:17:"moderate_comments";b:1;s:17:"manage_categories";b:1;s:12:"manage_links";b:1;s:12:"upload_files";b:1;s:15:"unfiltered_html";b:1;s:10:"edit_posts";b:1;s:17:"edit_others_posts";b:1;s:20:"edit_published_posts";b:1;s:13:"publish_posts";b:1;s:10:"edit_pages";b:1;s:4:"read";b:1;s:7:"level_7";b:1;s:7:"level_6";b:1;s:7:"level_5";b:1;s:7:"level_4";b:1;s:7:"level_3";b:1;s:7:"level_2";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:17:"edit_others_pages";b:1;s:20:"edit_published_pages";b:1;s:13:"publish_pages";b:1;s:12:"delete_pages";b:1;s:19:"delete_others_pages";b:1;s:22:"delete_published_pages";b:1;s:12:"delete_posts";b:1;s:19:"delete_others_posts";b:1;s:22:"delete_published_posts";b:1;s:20:"delete_private_posts";b:1;s:18:"edit_private_posts";b:1;s:18:"read_private_posts";b:1;s:20:"delete_private_pages";b:1;s:18:"edit_private_pages";b:1;s:18:"read_private_pages";b:1;}}s:6:"author";a:2:{s:4:"name";s:6:"Author";s:12:"capabilities";a:10:{s:12:"upload_files";b:1;s:10:"edit_posts";b:1;s:20:"edit_published_posts";b:1;s:13:"publish_posts";b:1;s:4:"read";b:1;s:7:"level_2";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:12:"delete_posts";b:1;s:22:"delete_published_posts";b:1;}}s:11:"contributor";a:2:{s:4:"name";s:11:"Contributor";s:12:"capabilities";a:5:{s:10:"edit_posts";b:1;s:4:"read";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:12:"delete_posts";b:1;}}s:10:"subscriber";a:2:{s:4:"name";s:10:"Subscriber";s:12:"capabilities";a:2:{s:4:"read";b:1;s:7:"level_0";b:1;}}}';

	/**
	 * The roles value without network rights. Only {name, capabilities} of
	 * each role with scalar grants are kept, so no object or stray data
	 * survives either.
	 *
	 * @param string $value Stored <prefix>user_roles value.
	 * @return array{0: string, 1: bool} The value to store, whether it changed (an unreadable value becomes CORE_DEFAULT).
	 */
	public static function clean( string $value ): array {
		$roles = self::read( $value );
		if ( null === $roles ) {
			return [ self::CORE_DEFAULT, true ];
		}
		$clean = [];
		foreach ( $roles as $name => $role ) {
			if ( ! self::isRoleKey( $name ) || ! is_array( $role ) || ! is_string( $role['name'] ?? null ) || ! is_array( $role['capabilities'] ?? null ) ) {
				continue; // Not a role WordPress can read, or one named after a network right (review N2).
			}
			$caps = [];
			foreach ( $role['capabilities'] as $cap => $grant ) {
				if ( is_scalar( $grant ) && ! in_array( strtolower( (string) $cap ), self::NETWORK_CAPS, true ) ) {
					$caps[ $cap ] = $grant;
				}
			}
			$clean[ $name ] = [
				'name'         => $role['name'],
				'capabilities' => $caps,
			];
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stored the way WordPress stores user_roles.
		return $clean === $roles ? [ $value, false ] : [ serialize( $clean ), true ];
	}

	/**
	 * Review N3: WordPress keeps the last of several stored rows, so only a
	 * single row is read; none or several become CORE_DEFAULT.
	 *
	 * @param array<int, string> $rows Every stored row of <prefix>user_roles (any letter case).
	 * @return array{0: string, 1: bool} The value to store, whether it must be written.
	 */
	public static function fromRows( array $rows ): array {
		return 1 === count( $rows ) ? self::clean( (string) reset( $rows ) ) : [ self::CORE_DEFAULT, true ];
	}

	/**
	 * @param string $value Stored <prefix>user_roles value.
	 * @return array<int, string> Its role names (none when unreadable).
	 */
	public static function names( string $value ): array {
		return array_map( 'strval', array_keys( self::read( $value ) ?? [] ) );
	}

	/**
	 * Whether a user's <prefix>capabilities value only names roles of the site, each granted (true).
	 *
	 * @param string             $value Stored value.
	 * @param array<int, string> $roles Role names of the site.
	 */
	public static function grantsOnly( string $value, array $roles ): bool {
		$caps = self::read( $value );
		if ( null === $caps ) {
			return false;
		}
		foreach ( $caps as $name => $grant ) {
			if ( true !== $grant || ! self::isRoleKey( $name ) || ! in_array( (string) $name, $roles, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A plain role name that is not a network right (review N2).
	 *
	 * @param int|string $name Role key.
	 */
	private static function isRoleKey( $name ): bool {
		return is_string( $name ) && 1 === preg_match( '/^[a-z0-9_-]{1,64}\z/', $name ) && ! in_array( strtolower( $name ), self::NETWORK_CAPS, true );
	}

	/**
	 * @param string $value Serialized array.
	 * @return array<int|string, mixed>|null Null when it is not a serialized array.
	 */
	private static function read( string $value ): ?array {
		if ( ! str_starts_with( $value, 'a:' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- imported data: allowed_classes=false creates no objects; a broken value is refused.
		$data = @unserialize( $value, [ 'allowed_classes' => false ] );

		return is_array( $data ) ? $data : null;
	}
}
