<?php
/**
 * The confirm summary as it leaves the server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * A whitelist of ImportSummary::build() (JobView's input of kind confirm),
 * field by field with fixed types, so nothing else the data may carry (a
 * path, a plugin name) is passed on. A field an older summary lacks reads as
 * unknown: '' in the version pairs, null in the comparison fields.
 */
final class ConfirmSummaryView {

	/** Parts of the archive, in the order the screen lists them. */
	private const PARTS = [ 'database', 'media', 'plugins', 'themes', 'muPlugins' ];

	/**
	 * @param array<mixed> $s ImportSummary::build() data.
	 * @return array<string, mixed>
	 */
	public static function of( array $s ): array {
		$pair  = static function ( $value ): array {
			$value = (array) $value;

			return [
				'archive' => (string) ( $value['archive'] ?? '' ),
				'site'    => (string) ( $value['site'] ?? '' ),
			];
		};
		$over  = (array) ( $s['overwrites'] ?? [] );
		$view  = [
			'from'         => (string) ( $s['from'] ?? '' ),
			'to'           => (string) ( $s['to'] ?? '' ),
			'wordpress'    => $pair( $s['wordpress'] ?? [] ),
			'php'          => $pair( $s['php'] ?? [] ),
			'archiveSize'  => (int) ( $s['archiveSize'] ?? 0 ),
			'encrypted'    => ! empty( $s['encrypted'] ),
			'compression'  => (string) ( $s['compression'] ?? '' ),
			'overwrites'   => [
				'database' => ! empty( $over['database'] ),
				'media'    => ! empty( $over['media'] ),
				'plugins'  => ! empty( $over['plugins'] ),
				'themes'   => ! empty( $over['themes'] ),
			],
			'subsite'      => isset( $s['subsite'] ) ? (int) $s['subsite'] : null,
			'safetyBackup' => ! empty( $s['safetyBackup'] ),
			'safetyBytes'  => (int) ( $s['safetyBytes'] ?? 0 ),
			'diskNeeded'   => (int) ( $s['diskNeeded'] ?? 0 ),
			'diskFree'     => isset( $s['diskFree'] ) ? (int) $s['diskFree'] : null,
			'lowDiskSpace' => ! empty( $s['lowDiskSpace'] ),
		];
		$view += self::comparison( $s );
		if ( is_array( $s['network'] ?? null ) ) {
			$view['network'] = self::network( $s['network'] ); // A restore into a network only: single sites keep their shape.
		}

		return $view;
	}

	/**
	 * What the check view compares besides the versions: the table prefixes,
	 * when the archive was made, its files' own size, each part it holds,
	 * whether the address changes. This plugin is always kept (never written).
	 *
	 * @param array<mixed> $s ImportSummary::build() data.
	 * @return array<string, mixed>
	 */
	private static function comparison( array $s ): array {
		$prefix = (array) ( $s['prefix'] ?? [] );
		$given  = (array) ( $s['parts'] ?? [] );
		$parts  = [];
		foreach ( self::PARTS as $key ) {
			$part          = is_array( $given[ $key ] ?? null ) ? $given[ $key ] : [];
			$parts[ $key ] = [
				'included' => isset( $part['included'] ) ? (bool) $part['included'] : null,
				'bytes'    => self::number( $part['bytes'] ?? null ),
				'count'    => self::number( $part['count'] ?? null ),
			];
		}

		return [
			'prefix'          => [
				'archive' => isset( $prefix['archive'] ) ? (string) $prefix['archive'] : null,
				'site'    => (string) ( $prefix['site'] ?? '' ),
			],
			'createdAt'       => self::number( $s['createdAt'] ?? null ),
			'expandedSize'    => self::number( $s['expandedSize'] ?? null ),
			'parts'           => $parts,
			'replacesAddress' => isset( $s['replacesAddress'] ) ? (bool) $s['replacesAddress'] : null,
			'keepsThisPlugin' => true,
		];
	}

	/**
	 * @param mixed $value A number or null.
	 */
	private static function number( $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * The network part of a confirmation (NetworkSummary::build() plus the
	 * answer's error): each site's archive id and address, the proposed and
	 * the chosen address, the site already there and why an address is
	 * refused; the network's sites the archive leaves out.
	 *
	 * @param array<mixed> $n Network part.
	 * @return array<string, mixed>
	 */
	private static function network( array $n ): array {
		$sites = [];
		foreach ( (array) ( $n['sites'] ?? [] ) as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}
			$from    = (array) ( $site['from'] ?? [] );
			$sites[] = [
				'index'    => (int) ( $site['index'] ?? 0 ),
				'from'     => [
					'blogId' => (int) ( $from['blogId'] ?? 0 ),
					'url'    => (string) ( $from['url'] ?? '' ),
				],
				'proposal' => (string) ( $site['proposal'] ?? '' ),
				'address'  => (string) ( $site['address'] ?? '' ),
				'existing' => isset( $site['existing'] ) ? (int) $site['existing'] : null,
				'error'    => isset( $site['error'] ) ? (string) $site['error'] : null,
			];
		}
		$unlisted = [];
		foreach ( (array) ( $n['unlisted'] ?? [] ) as $site ) {
			if ( is_array( $site ) ) {
				$unlisted[] = [
					'id'  => (int) ( $site['id'] ?? 0 ),
					'url' => (string) ( $site['url'] ?? '' ),
				];
			}
		}

		return [
			'kind'     => (string) ( $n['kind'] ?? '' ),
			'sites'    => $sites,
			'unlisted' => $unlisted,
			'error'    => isset( $n['error'] ) ? (string) $n['error'] : null,
		];
	}
}
