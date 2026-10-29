<?php
/**
 * Table-prefix pairs of a restore into a network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\PrefixRules;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 14. A whole-network archive keeps its ids: the plain placeholder
 * becomes the base prefix. A sites or single-site archive sends each site's
 * tables to its new id, and the archive's network tables (users, usermeta,
 * sitemeta…) to the held staging prefix <base>lwmsrc_ that the swap never
 * brings live. The network tables are matched as whole names (exact), so a
 * site table that merely starts with "site"/"users" stays with its site. A
 * table no mapped site claims is staged too (ruling R6): nothing of a subsite
 * import can ever land under the bare base prefix. Pure.
 */
final class NetworkPrefixPairs {

	/** Suffix of the held staging tables (after the base or temporary prefix). */
	public const STAGE = 'lwmsrc_';

	/** Network-wide tables of a WordPress network. */
	public const GLOBAL_TABLES = [ 'users', 'usermeta', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log', 'blog_versions', 'sitecategories' ];

	/**
	 * @param NetworkImport $n    The restore.
	 * @param string        $base Base table prefix of the network.
	 * @return array{data: array<string, string>, exact: array<string, string>} Ordered placeholder prefix pairs; whole placeholder table names => final names.
	 * @throws \LogicException When a site of a sites or single-site archive has no new id, or would become the main site.
	 */
	public static function build( NetworkImport $n, string $base ): array {
		$p = PrefixRules::PLACEHOLDER;
		if ( ArchiveKind::NETWORK === $n->kind ) {
			return [
				'data'  => [ $p => $base ],
				'exact' => [],
			];
		}
		if ( ArchiveKind::SITES !== $n->kind && ArchiveKind::SINGLE !== $n->kind ) {
			throw new \LogicException( 'Unknown archive kind of a network restore.' );
		}
		$data = [ $p . 'mainsite_' => $base . self::STAGE ];
		$main = null;
		foreach ( $n->map->all() as $m ) {
			$old = SiteEntry::fromArray( (array) $m['old'] );
			$new = $base . self::newId( $m, $n->target ) . '_';
			if ( $old->isMain() ) {
				$main = $new;
				continue;
			}
			$data[ $p . $old->blogId . '_' ] = $new;
		}
		$exact = [];
		foreach ( self::GLOBAL_TABLES as $table ) {
			$exact[ $p . $table ] = $base . self::STAGE . $table;
		}
		if ( null !== $main ) {
			$data[ $p . 'basesite_' ] = $main;
		}
		$data[ $p ] = $main ?? $base . self::STAGE;

		return [
			'data'  => $data,
			'exact' => $exact,
		];
	}

	/**
	 * Review C1: the only table suffixes (after the temporary or base prefix) a
	 * sites or single-site restore may write, swap in or create views under:
	 * "<newId>_" of every mapped site. Empty for a whole-network archive.
	 *
	 * @param NetworkImport $n The restore.
	 * @return array<int, string>
	 * @throws \LogicException For an unresolved mapping or the main site (decision 13).
	 */
	public static function siteSuffixes( NetworkImport $n ): array {
		if ( ArchiveKind::NETWORK === $n->kind ) {
			return [];
		}
		$out = [];
		foreach ( $n->map->all() as $m ) {
			$out[] = self::newId( $m, $n->target ) . '_';
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string, mixed> $m  A resolved mapping.
	 * @param TargetNetwork        $to Target network.
	 * @throws \LogicException For a missing id or the main site (decision 13).
	 */
	private static function newId( array $m, TargetNetwork $to ): int {
		$id = $m['newId'] ?? null;
		if ( ! is_int( $id ) || $id < 2 || $id === $to->mainSiteId ) {
			throw new \LogicException( 'A site of the archive has no new subsite yet, or would replace the main site.' );
		}

		return $id;
	}
}
