<?php
/**
 * The network part of an import's confirmation.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data for every driver (decision 12): each archive site with its old
 * and new address, whether a site of the network is already at that address,
 * and the network's sites a network archive leaves out.
 */
final class NetworkSummary {

	/**
	 * @param string                                  $kind     ArchiveKind value.
	 * @param BlogMap                                 $map      The planned map.
	 * @param array<int, array{id: int, url: string}> $unlisted Target sites a network archive does not contain.
	 * @param array<int, int|null>                    $existing Index => id of the network's site at that address.
	 * @param array<int, string>                      $problems Index => why that site's address is refused (BlogMap::problems()).
	 * @return array{kind: string, sites: array<int, array<string, mixed>>, unlisted: array<int, array{id: int, url: string}>}
	 */
	public static function build( string $kind, BlogMap $map, array $unlisted, array $existing = [], array $problems = [] ): array {
		$sites = [];
		foreach ( $map->all() as $i => $m ) {
			$old     = SiteEntry::fromArray( $m['old'] );
			$sites[] = [
				'index'    => $i,
				'from'     => [
					'blogId' => $old->blogId,
					'url'    => self::printable( '' !== $old->homeUrl ? $old->homeUrl : $old->siteUrl ),
				],
				'proposal' => $m['proposal'],
				'address'  => $m['url'],
				'existing' => $existing[ $i ] ?? null,
				'error'    => $problems[ $i ] ?? null,
			];
		}

		return [
			'kind'     => $kind,
			'sites'    => $sites,
			'unlisted' => array_values( $unlisted ),
		];
	}

	/**
	 * Text from an archive or an answer without control code points (C0,
	 * DEL, C1), so a terminal or a log shows it as text; every other
	 * character stays (final review M1). Only a byte that is not part of a
	 * valid UTF-8 sequence becomes a question mark, except a lone C1 byte
	 * (0x80-0x9F, a control in single-byte encodings), which is dropped.
	 *
	 * @param string $text Text to show.
	 */
	public static function printable( string $text ): string {
		$valid = (string) preg_replace_callback(
			'/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
			static fn( array $m ): string => '' !== ( $m[1] ?? '' ) ? $m[1] : ( 1 === preg_match( '/^[\x80-\x9F]$/', $m[0] ) ? '' : '?' ),
			$text
		);

		return (string) preg_replace( '/\p{Cc}/u', '', $valid );
	}

	/**
	 * @param array<string, mixed> $network The network part from build() (plus an optional error).
	 * @return array<int, string>
	 */
	public static function lines( array $network ): array {
		$sites = (array) ( $network['sites'] ?? [] );
		$lines = [];
		$keeps = ArchiveKind::NETWORK === ( $network['kind'] ?? '' );
		if ( $keeps ) {
			/* translators: %d: number of sites in the archive */
			$lines[] = sprintf( _n( 'Network archive: %d site keeps its ID.', 'Network archive: %d sites keep their IDs.', count( $sites ), 'lw-migration' ), count( $sites ) );
		}
		foreach ( $sites as $site ) {
			$lines[] = self::siteLine( (array) $site, $keeps );
		}
		$unlisted = array_column( (array) ( $network['unlisted'] ?? [] ), 'url' );
		if ( [] !== $unlisted ) {
			/* translators: %s: list of site addresses */
			$lines[] = sprintf( __( 'Not in the archive: %s. These sites stay on the network marked as deleted (Network Admin > Sites), with their tables and files kept.', 'lw-migration' ), implode( ', ', array_map( 'strval', $unlisted ) ) );
		}
		if ( is_string( $network['error'] ?? null ) ) {
			$lines[] = self::answerLine( $network['error'] );
		}

		return $lines;
	}

	/**
	 * Every refusal in a network part: one line per refused site address, and
	 * the answer's general problem. Read from the error data, never from texts.
	 *
	 * @param array<string, mixed> $network The network part from build() (plus an optional error).
	 * @return array<int, string> Empty when nothing is refused.
	 */
	public static function refusals( array $network ): array {
		$lines = [];
		foreach ( (array) ( $network['sites'] ?? [] ) as $site ) {
			if ( is_string( $site['error'] ?? null ) ) {
				$lines[] = self::siteLine( (array) $site, false );
			}
		}
		if ( is_string( $network['error'] ?? null ) ) {
			$lines[] = self::answerLine( $network['error'] );
		}

		return $lines;
	}

	private static function answerLine( string $error ): string {
		/* translators: %s: why the answer was refused */
		return sprintf( __( 'Answer refused: %s', 'lw-migration' ), $error );
	}

	/**
	 * @param array<string, mixed> $site  One site of the summary.
	 * @param bool                 $keeps A network archive (ids and sites are kept).
	 */
	private static function siteLine( array $site, bool $keeps ): string {
		$from    = (string) ( $site['from']['url'] ?? '' );
		$address = (string) ( $site['address'] ?? '' );
		if ( is_string( $site['error'] ?? null ) ) {
			/* translators: 1: old address, 2: new address, 3: why the new address is refused */
			return sprintf( __( 'Site %1$s -> %2$s is refused: %3$s', 'lw-migration' ), $from, $address, $site['error'] );
		}
		if ( $keeps ) {
			/* translators: 1: old address, 2: new address */
			return sprintf( __( 'Site %1$s -> %2$s', 'lw-migration' ), $from, $address );
		}

		return null === ( $site['existing'] ?? null )
			/* translators: 1: old address, 2: new address */
			? sprintf( __( 'Site %1$s -> %2$s (new site)', 'lw-migration' ), $from, $address )
			/* translators: 1: old address, 2: new address */
			: sprintf( __( 'Site %1$s -> %2$s (replaces the site there)', 'lw-migration' ), $from, $address );
	}
}
