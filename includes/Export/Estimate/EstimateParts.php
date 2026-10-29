<?php
/**
 * The parts of the size estimate, as GET /export/estimate answers them.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Sizes in bytes, counts as whole numbers; never a name or a path. The
 * active-only plugin and theme sizes subtract what ExportRoots leaves out
 * with the "inactive" options: the folder (or single file) of every
 * installed plugin that is not active, every theme that is neither the
 * active theme nor its parent; on a network "active" is what any site uses
 * plus the network-active plugins. This plugin and the SQLite database
 * plugin never travel, so they are not counted. A part not measured yet is
 * null: the database until its tables are sized (for good when it cannot be
 * read), the file parts until the file walk began (while it runs they grow).
 * The walk began when a slice ran it (the state names that slice's work).
 * The database's spam comment and revision counts are null until every
 * table is counted (a partial count is not shown as if final).
 */
final class EstimateParts {

	/** @var array<int, string> */
	public const KEYS = [ 'media', 'database', 'plugins', 'themes', 'muPlugins', 'cache', 'other' ];

	/** @var array<int, string> The parts the file walk measures. */
	public const FILE_KEYS = [ 'media', 'plugins', 'themes', 'muPlugins', 'cache', 'other' ];

	/**
	 * The parts a measuring stopped in this phase has not finished: the
	 * file parts during the walk, every part before it (the database phase
	 * comes before the walk, the sites phase before both).
	 *
	 * @param string $phase EstimateScan phase.
	 * @return array<int, string>
	 */
	public static function unfinished( string $phase ): array {
		if ( EstimateScan::DONE === $phase ) {
			return [];
		}

		return 'files' === $phase ? self::FILE_KEYS : self::KEYS;
	}

	/**
	 * @return array<string, null> Every part unknown.
	 */
	public static function none(): array {
		return array_fill_keys( self::KEYS, null );
	}

	/**
	 * @param array<string, mixed> $state EstimateScan state.
	 * @return array<string, array<string, int|null>|null>
	 */
	public static function of( array $state ): array {
		$site     = Site::fromArray( (array) $state['site'] );
		$network  = is_array( $state['network'] ?? null ) ? $state['network'] : null;
		$parts    = self::none();
		$database = is_array( $state['database'] ?? null ) ? $state['database'] : null;
		if ( null !== $database ) {
			$counted           = DatabaseSizes::done( $database );
			$parts['database'] = [
				'bytes'        => (int) $database['bytes'],
				'tables'       => (int) $database['tables'],
				'spamComments' => $counted ? (int) $database['spamComments'] : null,
				'revisions'    => $counted ? (int) $database['revisions'] : null,
			];
		}
		if ( in_array( $state['phase'], [ 'sites', 'database' ], true ) || ( 'files' === $state['phase'] && '' === (string) ( $state['work'] ?? '' ) ) ) {
			return $parts;
		}
		$tally              = ( new FileTally( (array) $state['tally'] ) )->toArray();
		$parts['media']     = $tally['media'];
		$parts['plugins']   = self::plugins( $site, $tally['plugins'], null === $network ? $site->activePlugins : array_map( 'strval', (array) $network['plugins'] ) );
		$parts['themes']    = self::themes( $site, $tally['themes'], null === $network ? [ $site->template, $site->stylesheet ] : array_map( 'strval', (array) $network['themes'] ) );
		$parts['muPlugins'] = $tally['muPlugins'];
		$parts['cache']     = [ 'bytes' => $tally['cache'] ];
		$parts['other']     = [ 'bytes' => $tally['other'] ];

		return $parts;
	}

	/**
	 * @param Site               $site   Snapshot.
	 * @param array<string, int> $sizes  Top-level name => bytes.
	 * @param array<int, string> $active Active plugin basenames.
	 * @return array{bytes: int, count: int, activeBytes: int, activeCount: int}
	 */
	private static function plugins( Site $site, array $sizes, array $active ): array {
		$installed = array_values(
			array_filter(
				$site->plugins,
				static fn( string $p ): bool => $p !== $site->ownPlugin && ! str_starts_with( $p, ExportRoots::SQLITE_PLUGIN . '/' )
			)
		);
		$left      = array_unique( array_map( static fn( string $p ): string => '.' === dirname( $p ) ? $p : dirname( $p ), array_diff( $site->plugins, $active ) ) );

		return self::counted( $sizes, count( $installed ), count( array_intersect( $installed, $active ) ), $left );
	}

	/**
	 * @param Site               $site   Snapshot.
	 * @param array<string, int> $sizes  Theme folder => bytes.
	 * @param array<int, string> $active Active theme and parent slugs.
	 * @return array{bytes: int, count: int, activeBytes: int, activeCount: int}
	 */
	private static function themes( Site $site, array $sizes, array $active ): array {
		$slugs = array_map( 'strval', array_keys( $site->themes ) );
		$kept  = array_intersect( $slugs, $active );

		return self::counted( $sizes, count( $slugs ), count( $kept ), array_diff( $slugs, $kept ) );
	}

	/**
	 * @param array<string, int> $sizes  Top-level name => bytes.
	 * @param int                $count  Installed.
	 * @param int                $active Active.
	 * @param array<int, string> $left   Top-level names the active-only backup leaves out.
	 * @return array{bytes: int, count: int, activeBytes: int, activeCount: int}
	 */
	private static function counted( array $sizes, int $count, int $active, array $left ): array {
		$bytes = array_sum( $sizes );

		return [
			'bytes'       => $bytes,
			'count'       => $count,
			'activeBytes' => $bytes - array_sum( array_intersect_key( $sizes, array_flip( $left ) ) ),
			'activeCount' => $active,
		];
	}
}
