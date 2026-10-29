<?php
/**
 * Builds the database import plan for this site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Database\BuilderValues;
use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\UrlPairs;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogUrlMove;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPrefixPairs;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * URL pairs, page-builder handling, prefix pairs (sub-site and network aware), schema fixes, packet limit.
 */
final class ImportPlanFactory {

	private const DEFAULT_PACKET = 16777216;

	/**
	 * @param Connection         $db              Destination database.
	 * @param PackageConfig      $package         Archive package.json.
	 * @param Site               $site            This site (the network's main site on a network).
	 * @param string             $tmpPrefix       Temporary prefix.
	 * @param string             $deferredPath    Deferred view statements file.
	 * @param int|null           $subsiteId       Blog id of a sub-site archive.
	 * @param \Closure           $pluginInstalled fn( string $basename ): bool.
	 * @param \Closure           $themeInstalled  fn( string $slug ): bool.
	 * @param NetworkImport|null $network         A restore into a network (ImportState::network()); null on a single site.
	 * @return ImportPlan
	 */
	public static function build( Connection $db, PackageConfig $package, Site $site, string $tmpPrefix, string $deferredPath, ?int $subsiteId, \Closure $pluginInstalled, \Closure $themeInstalled, ?NetworkImport $network = null ): ImportPlan {
		if ( null !== $network ) {
			return self::forNetwork( $db, $package, $site, $tmpPrefix, $deferredPath, $network, $pluginInstalled, $themeInstalled );
		}
		$pairs    = UrlPairs::build( $package, $site->siteTarget() );
		$replacer = self::replacer( $pairs, (array) $package->get( 'Plugins', [] ), $package, $pluginInstalled, $themeInstalled );
		$global   = null === $subsiteId ? [] : [ 'sitemeta' ];

		return new ImportPlan(
			$site->tablePrefix,
			$tmpPrefix,
			PrefixRules::importPairs( $site->tablePrefix, $subsiteId, $global ),
			$replacer,
			new SchemaFixer( $db->serverVersion() ),
			$deferredPath,
			self::packet( $db )
		);
	}

	/**
	 * Decisions 14 and 16: prefix pairs and URL pairs per site. A sites or
	 * single-site archive brings no extra-prefix table (ruling R6): those are
	 * left out and listed once; it writes only its mapped sites' and the staging
	 * tables (review C1).
	 *
	 * @param Connection    $db              Destination database.
	 * @param PackageConfig $package         Archive package.json.
	 * @param Site          $site            The network's main site.
	 * @param string        $tmpPrefix       Temporary prefix.
	 * @param string        $deferredPath    Deferred view statements file.
	 * @param NetworkImport $network         The restore.
	 * @param \Closure      $pluginInstalled fn( string $basename ): bool.
	 * @param \Closure      $themeInstalled  fn( string $slug ): bool.
	 * @return ImportPlan
	 */
	private static function forNetwork( Connection $db, PackageConfig $package, Site $site, string $tmpPrefix, string $deferredPath, NetworkImport $network, \Closure $pluginInstalled, \Closure $themeInstalled ): ImportPlan {
		$rel     = $network->target->uploadsRel();
		$moves   = array_map( static fn( array $m ): BlogUrlMove => BlogUrlMove::fromMapping( $m, $network->target, $rel ), $network->map->all() );
		$plugins = array_merge( (array) $package->get( 'Plugins', [] ), $network->plugins );
		foreach ( $network->map->all() as $m ) {
			$plugins = array_merge( $plugins, array_filter( (array) ( $m['old']['Plugins'] ?? [] ), 'is_string' ) );
		}
		$prefix = NetworkPrefixPairs::build( $network, $site->tablePrefix );
		$whole  = ArchiveKind::NETWORK === $network->kind;

		return new ImportPlan(
			$site->tablePrefix,
			$tmpPrefix,
			$prefix['data'],
			self::replacer( UrlPairs::forNetwork( $package, $site->siteTarget(), $moves ), array_unique( $plugins ), $package, $pluginInstalled, $themeInstalled ),
			new SchemaFixer( $db->serverVersion() ),
			$deferredPath,
			self::packet( $db ),
			$whole ? ImportPlan::DEFAULT_EXTRA_PREFIXES : [],
			$prefix['exact'],
			$whole ? [] : ImportPlan::DEFAULT_EXTRA_PREFIXES,
			NetworkPrefixPairs::siteSuffixes( $network ),
			$whole ? [] : [ NetworkPrefixPairs::STAGE ]
		);
	}

	/**
	 * @param UrlPairs      $pairs           URL pairs.
	 * @param array<mixed>  $plugins         Active plugins of the archive.
	 * @param PackageConfig $package         Archive package.json.
	 * @param \Closure      $pluginInstalled fn( string $basename ): bool.
	 * @param \Closure      $themeInstalled  fn( string $slug ): bool.
	 * @return Replacer
	 */
	private static function replacer( UrlPairs $pairs, array $plugins, PackageConfig $package, \Closure $pluginInstalled, \Closure $themeInstalled ): Replacer {
		$builders = BuilderValues::detect(
			array_values( array_map( 'strval', $plugins ) ),
			(string) $package->get( 'Template', '' ),
			(string) $package->get( 'Stylesheet', '' ),
			$pluginInstalled,
			$themeInstalled
		);

		return new Replacer( $pairs->pairs(), $pairs->raw(), $builders->isActive() ? $builders : null );
	}

	private static function packet( Connection $db ): int {
		$packet = (int) ( $db->variable( 'max_allowed_packet' ) ?? self::DEFAULT_PACKET );

		return $packet > 0 ? $packet : self::DEFAULT_PACKET;
	}
}
