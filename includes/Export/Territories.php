<?php
/**
 * Which root a folder belongs to.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Real paths of the folders that are roots of their own (uploads, plugins,
 * theme roots; also when an option leaves them out). The wp-content root is
 * not one: it only holds what no other root claims. A folder belongs to the
 * deepest territory that contains it.
 */
final class Territories {

	/** @var array<int, string> Real paths, deepest first. */
	private array $reals = [];

	/**
	 * @param array<int, string> $paths Folders (missing ones are ignored).
	 */
	public function __construct( array $paths ) {
		foreach ( $paths as $path ) {
			$real = '' === $path ? false : realpath( $path );
			if ( false !== $real && is_dir( $real ) ) {
				$this->reals[] = $real;
			}
		}
		$this->reals = array_values( array_unique( $this->reals ) );
		usort( $this->reals, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
	}

	/**
	 * @param string $real Resolved folder path.
	 * @return string|null Real path of the territory that owns it, or null.
	 */
	public function ownerOf( string $real ): ?string {
		foreach ( $this->reals as $territory ) {
			if ( $real === $territory || str_starts_with( $real, rtrim( $territory, '/' ) . '/' ) ) {
				return $territory;
			}
		}

		return null;
	}

	/**
	 * A folder may be walked from a root when no other territory owns it.
	 *
	 * @param string      $real     Resolved folder path.
	 * @param string|null $rootReal Resolved path of the root being walked.
	 * @return bool
	 */
	public function allows( string $real, ?string $rootReal ): bool {
		$owner = $this->ownerOf( $real );

		return null === $owner || $owner === $rootReal;
	}
}
