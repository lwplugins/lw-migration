<?php
/**
 * Builds a fresh WordPress install into temporary tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\PrefixRules;

defined( 'ABSPATH' ) || exit;

/**
 * Decision D12: core's own install functions run with $wpdb pointed at the
 * run's temporary prefix, so the live tables are never emptied; the live site
 * keeps working until the one RENAME. The sandbox (ruling I1): the object
 * cache is flushed and the kept user's meta entry dropped before the prefix
 * changes, options are read and written past the alloptions/notoptions
 * caches (wp_installing), no new cache entries are added, and WP_Roles is
 * rebuilt for the temporary prefix. Everything is restored in finally, the
 * prefix to the value read before set_prefix() (ruling M14), and the cache
 * is flushed again (rows cached under live keys while the prefix pointed
 * elsewhere). Never on a network (decision D2).
 */
final class FreshInstall {

	/** @var \Closure fn( array $options, int $userId ): void, the core install calls. */
	private \Closure $install;

	/**
	 * @param \Closure $install fn( array<string, mixed> $options, int $userId ): void.
	 */
	public function __construct( \Closure $install ) {
		$this->install = $install;
	}

	/**
	 * The install calls of core: the schema, the default options (with ours
	 * given), the roles and the default content for the user.
	 */
	public static function fromWordPress(): self {
		return new self(
			static function ( array $options, int $userId ): void {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				// populate_options() guesses an address from the request even though ours is given;
				// on the command line there is no host to read, so it gets the one of the given address.
				$host = isset( $_SERVER['HTTP_HOST'] ) ? null : (string) wp_parse_url( (string) ( $options['siteurl'] ?? '' ), PHP_URL_HOST );
				if ( null !== $host ) {
					$_SERVER['HTTP_HOST'] = $host;
				}
				try {
					make_db_current_silent();
					populate_options( $options );
					populate_roles();
					wp_install_defaults( $userId );
				} finally {
					if ( null !== $host ) {
						unset( $_SERVER['HTTP_HOST'] );
					}
				}
			}
		);
	}

	/**
	 * @param object               $wpdb    The global $wpdb.
	 * @param string               $tmp     The run's temporary prefix (lwtXXXX_).
	 * @param array<string, mixed> $options Options populate_options() gets.
	 * @param int                  $userId  The administrator who keeps their account.
	 * @throws \LogicException On a network, or for a prefix that is not a temporary one.
	 * @throws \RuntimeException When $wpdb refuses the prefix (nothing ran).
	 */
	public function build( object $wpdb, string $tmp, array $options, int $userId ): void {
		if ( is_multisite() ) {
			throw new \LogicException( 'A network database is never rebuilt (decision D2).' );
		}
		if ( ! PrefixRules::isValidTempPrefix( $tmp ) ) {
			throw new \LogicException( 'A fresh install is only ever built under a temporary prefix.' );
		}
		$before = (string) $wpdb->prefix; // Ruling M14: after set_prefix() base_prefix is the temporary one.
		wp_cache_flush(); // Ruling I1: keys an earlier, dead sandbox may have left.
		wp_cache_delete( $userId, 'user_meta' );
		$old = $wpdb->set_prefix( $tmp );
		if ( ! is_string( $old ) ) {
			throw new \RuntimeException( 'The temporary table prefix was refused.' );
		}
		$roles               = $GLOBALS['wp_roles'] ?? null;
		$installing          = wp_installing( true ); // Ruling I1: options read and written past the alloptions/notoptions caches.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- populate_roles() builds a fresh WP_Roles keyed to <tmp>user_roles; restored in finally.
		wp_suspend_cache_addition( true );
		try {
			( $this->install )( $options, $userId );
		} finally {
			$wpdb->set_prefix( $before );
			$GLOBALS['wp_roles'] = $roles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the live WP_Roles back.
			wp_installing( $installing );
			wp_suspend_cache_addition( false );
			wp_cache_flush(); // Rows cached under live keys while the prefix pointed elsewhere (as the import's swap does).
		}
	}
}
