<?php
/**
 * Table-prefix placeholder rules of the .wpress database dump.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Export: real prefix → placeholder (table names, options.option_name, usermeta.meta_key).
 * Import: ordered placeholder → prefix pairs, applied with str_ireplace.
 */
final class PrefixRules implements PrefixRenamer {

	public const PLACEHOLDER = 'SERVMASK_PREFIX_';

	/** Keys a one-character prefix renames (a longer prefix renames every name it starts). */
	public const SHORT_PREFIX_KEYS = [ 'user_roles', 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' ];

	/** Option/meta names that start with "wp_" but are not prefix-derived. */
	public const RESERVED = [
		'wp_force_deactivated_plugins',
		'wp_page_for_privacy_policy',
		'wp_rocket_settings',
		'wp_rocket_dismiss_imagify_notice',
		'wp_rocket_no_licence',
		'wp_rocket_rocketcdn_old_url',
		'wp_rocket_hide_deactivation_form',
	];

	/** @var string */
	private string $basePrefix;

	public function __construct( string $basePrefix ) {
		$this->basePrefix = $basePrefix;
	}

	public function exportTableName( string $table ): string {
		if ( '' === $this->basePrefix ) {
			return self::PLACEHOLDER . $table;
		}
		if ( 0 === stripos( $table, $this->basePrefix ) ) {
			return self::PLACEHOLDER . substr( $table, strlen( $this->basePrefix ) );
		}

		return $table;
	}

	public function exportColumnValue( string $value ): string {
		foreach ( self::RESERVED as $reserved ) {
			if ( 0 === stripos( $value, $reserved ) ) {
				return $value;
			}
		}
		if ( strlen( $this->basePrefix ) > 1 ) {
			return 0 === stripos( $value, $this->basePrefix )
				? self::PLACEHOLDER . substr( $value, strlen( $this->basePrefix ) )
				: $value;
		}
		foreach ( self::SHORT_PREFIX_KEYS as $key ) {
			$old = $this->basePrefix . $key;
			if ( 0 === stripos( $value, $old ) ) {
				return self::PLACEHOLDER . $key . substr( $value, strlen( $old ) );
			}
		}

		return $value;
	}

	/**
	 * @param string             $basePrefix        Destination prefix.
	 * @param int|null           $oldBlogId         Subsite id of a multisite-subsite archive (> 1), or null.
	 * @param array<int, string> $extraGlobalTables Extra network-global tables (e.g. BuddyPress).
	 * @return array<string, string> Ordered old => new.
	 */
	public static function importPairs( string $basePrefix, ?int $oldBlogId = null, array $extraGlobalTables = [] ): array {
		$pairs = [];
		if ( null !== $oldBlogId && $oldBlogId > 1 ) {
			$pairs[ self::PLACEHOLDER . $oldBlogId . '_' ] = $basePrefix;
		}
		foreach ( array_merge( [ 'users', 'usermeta' ], $extraGlobalTables ) as $table ) {
			$pairs[ self::PLACEHOLDER . 'mainsite_' . $table ] = $basePrefix . $table;
		}
		$pairs[ self::PLACEHOLDER . 'basesite_' ] = $basePrefix;
		$pairs[ self::PLACEHOLDER ]               = $basePrefix;

		return $pairs;
	}

	/**
	 * Backtick-anchored variants that send table identifiers to the temporary prefix.
	 *
	 * @param array<string, string> $pairs      From importPairs().
	 * @param string                $basePrefix Destination prefix.
	 * @param string                $tmpPrefix  Temporary prefix.
	 * @return array<string, string>
	 */
	public static function identifierPairs( array $pairs, string $basePrefix, string $tmpPrefix ): array {
		$out = [];
		foreach ( $pairs as $old => $new ) {
			$suffix            = str_starts_with( $new, $basePrefix ) ? substr( $new, strlen( $basePrefix ) ) : $new;
			$out[ '`' . $old ] = '`' . $tmpPrefix . $suffix;
		}

		return $out;
	}

	/**
	 * Backtick-enclosed whole table names sent to the temporary prefix; they run
	 * before identifierPairs(), so they win over a shorter placeholder prefix.
	 *
	 * @param array<string, string> $exact      Placeholder table name => final name.
	 * @param string                $basePrefix Destination prefix.
	 * @param string                $tmpPrefix  Temporary prefix.
	 * @return array<string, string>
	 */
	public static function exactIdentifierPairs( array $exact, string $basePrefix, string $tmpPrefix ): array {
		$out = [];
		foreach ( $exact as $old => $new ) {
			$suffix                  = str_starts_with( $new, $basePrefix ) ? substr( $new, strlen( $basePrefix ) ) : $new;
			$out[ '`' . $old . '`' ] = '`' . $tmpPrefix . $suffix . '`';
		}

		return $out;
	}

	/**
	 * The table a DROP TABLE / CREATE TABLE / INSERT INTO names in its head when
	 * it starts with one of the prefixes (case-sensitive, as exported).
	 *
	 * @param string             $sql      Statement.
	 * @param array<int, string> $prefixes Prefixes to leave out.
	 * @return string|null The table name, or null.
	 */
	public static function leftOutTable( string $sql, array $prefixes ): ?string {
		if ( [] === $prefixes ) {
			return null;
		}
		$alternatives = implode( '|', array_map( static fn( string $p ): string => preg_quote( $p, '/' ), $prefixes ) );
		$head         = '/^(?:DROP\s+TABLE\s+IF\s+EXISTS\s+|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|INSERT\s+INTO\s+)`((?-i:' . $alternatives . ')[^`]*)`/i';

		return 1 === preg_match( $head, $sql, $m ) ? $m[1] : null;
	}

	/**
	 * Temporary prefix for extra-prefix tables (wbk_, civicrm_): same run id as the tmp prefix.
	 *
	 * @param string $tmpPrefix Temporary prefix (lwtXXXX_).
	 * @return string lwxXXXX_
	 */
	public static function extraTempPrefix( string $tmpPrefix ): string {
		return 'lwx' . substr( $tmpPrefix, 3 );
	}

	/**
	 * Temporary prefixes are "lwt" + four lower-case letters or digits + "_" (TableSwap::newTempPrefix()).
	 *
	 * @param string $prefix Candidate.
	 * @return bool
	 */
	public static function isValidTempPrefix( string $prefix ): bool {
		return 1 === preg_match( '/^lwt[a-z0-9]{4}_\z/', $prefix );
	}

	/**
	 * Extra prefixes are plain identifier prefixes that cannot collide with the temporary ones.
	 *
	 * @param string $prefix Candidate, e.g. wbk_.
	 * @return bool
	 */
	public static function isValidExtraPrefix( string $prefix ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]+_\z/', $prefix ) && 1 !== preg_match( '/^lw[otxy]/i', $prefix );
	}

	/**
	 * The one prefix rule shared by the import and the swap.
	 *
	 * @param string             $tmpPrefix     Temporary prefix.
	 * @param array<int, string> $extraPrefixes Extra prefixes.
	 * @throws DatabaseException When a prefix has the wrong shape.
	 * @return void
	 */
	public static function assertImportPrefixes( string $tmpPrefix, array $extraPrefixes ): void {
		if ( ! self::isValidTempPrefix( $tmpPrefix ) ) {
			throw new DatabaseException( 'Invalid temporary table prefix.' );
		}
		foreach ( $extraPrefixes as $prefix ) {
			if ( ! self::isValidExtraPrefix( $prefix ) ) {
				throw new DatabaseException( 'Invalid extra table prefix.' );
			}
		}
	}

	/**
	 * Sends a table named in the head of DROP TABLE / CREATE TABLE / INSERT INTO
	 * that starts with an extra prefix (case-sensitive, as exported) to the extra
	 * temporary prefix. Nothing after the table name is touched.
	 *
	 * @param string             $sql            Statement.
	 * @param array<int, string> $extraPrefixes  Extra prefixes.
	 * @param string             $extraTmpPrefix From extraTempPrefix().
	 * @return string
	 */
	public static function mapExtraTables( string $sql, array $extraPrefixes, string $extraTmpPrefix ): string {
		if ( [] === $extraPrefixes ) {
			return $sql;
		}
		$alternatives = implode( '|', array_map( static fn( string $p ): string => preg_quote( $p, '/' ), $extraPrefixes ) );
		$head         = '/^(DROP\s+TABLE\s+IF\s+EXISTS\s+|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|INSERT\s+INTO\s+)`(?=(?-i:' . $alternatives . '))/i';

		return (string) preg_replace( $head, '$1`' . $extraTmpPrefix, $sql, 1 );
	}
}
