<?php
/**
 * File roots of a network backup of chosen sites.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * A whole-network backup walks the folders a single site does (uploads holds
 * sites/<id>, wp-content holds blogs.dir). A backup of chosen sites leaves
 * uploads/sites and blogs.dir out of those roots and walks each chosen
 * subsite's folders as roots of their own; the main site's uploads only when
 * the main site is chosen.
 */
final class NetworkRoots {

	/**
	 * @param string               $uploadsDir The main site's uploads folder.
	 * @param string               $contentDir wp-content.
	 * @param array<string, mixed> $state      state.network.
	 * @return array<int, array{dir: string, entry: string}>
	 */
	public static function extraRoots( string $uploadsDir, string $contentDir, array $state ): array {
		if ( ! self::isSites( $state ) ) {
			return [];
		}
		$uploads = rtrim( $uploadsDir, '/' );
		$content = rtrim( $contentDir, '/' );
		$roots   = [];
		foreach ( self::subsites( $state ) as $id ) {
			$roots[] = [
				'dir'   => $uploads . '/sites/' . $id,
				'entry' => 'uploads/sites/' . $id,
			];
			$roots[] = [
				'dir'   => $content . '/blogs.dir/' . $id,
				'entry' => 'blogs.dir/' . $id,
			];
		}

		return $roots;
	}

	/**
	 * Folders the main uploads root and the wp-content root never walk in a
	 * backup of chosen sites (never handed to the extra roots: R11).
	 *
	 * @param string               $uploadsDir The main site's uploads folder.
	 * @param string               $contentDir wp-content.
	 * @param array<string, mixed> $state      state.network.
	 * @return array<int, string>
	 */
	public static function exclusions( string $uploadsDir, string $contentDir, array $state ): array {
		return self::isSites( $state ) ? [ rtrim( $uploadsDir, '/' ) . '/sites', rtrim( $contentDir, '/' ) . '/blogs.dir' ] : [];
	}

	/**
	 * @param array<string, mixed> $state state.network.
	 */
	public static function skipsMainUploads( array $state ): bool {
		return self::isSites( $state ) && ! in_array( 1, array_map( 'intval', (array) ( $state['chosen'] ?? [] ) ), true );
	}

	/**
	 * @param array<string, mixed> $state state.network.
	 */
	private static function isSites( array $state ): bool {
		return 'sites' === ( $state['mode'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $state state.network.
	 * @return array<int, int> Chosen subsites (not blog 1), in the given order.
	 */
	private static function subsites( array $state ): array {
		return array_values( array_filter( array_map( 'intval', (array) ( $state['chosen'] ?? [] ) ), static fn( int $id ): bool => $id > 1 ) );
	}
}
