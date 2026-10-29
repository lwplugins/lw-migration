<?php
/**
 * Where a scan pass of TreeEraser is, and where the next one resumes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * A position is the path of the last entry a scan pass finished, relative to
 * the root, with "/" between the names. Folders are listed in byte order
 * (order()), so the next pass skips every entry that sorts up to it by its
 * name alone, and goes down the folders the position lies in. WordPress-free.
 */
final class ScanPosition {

	/** @var array<int, string> Names from the root down to the folder being emptied. */
	private array $trail = [];

	/** @var string The last entry finished ('' = none). */
	private string $last = '';

	/**
	 * @param string $after A position ('' = from the start).
	 * @return array<int, string> Its names, root first.
	 */
	public static function names( string $after ): array {
		return '' === $after ? [] : explode( '/', $after );
	}

	/**
	 * @param array<int, string> $names A folder's names.
	 * @return array<int, string> In byte order, as positions are compared.
	 */
	public static function order( array $names ): array {
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Where a pass resumes at an entry of a folder listed in byte order.
	 *
	 * @param string             $name   An entry of the folder.
	 * @param array<int, string> $resume The position below the folder; cleared once the entry passed it.
	 * @return array<int, string>|null Null: an earlier pass finished the entry; else the position inside it ([] = none).
	 */
	public static function resumeAt( string $name, array &$resume ): ?array {
		if ( [] === $resume ) {
			return [];
		}
		$order = strcmp( $name, $resume[0] );
		if ( $order < 0 || ( 0 === $order && 1 === count( $resume ) ) ) {
			return null;
		}
		$below  = 0 === $order ? array_slice( $resume, 1 ) : [];
		$resume = [];

		return $below;
	}

	/**
	 * @param string $name The folder the pass goes into.
	 */
	public function enter( string $name ): void {
		$this->trail[] = $name;
	}

	public function leave(): void {
		array_pop( $this->trail );
	}

	/**
	 * @param string $name An entry of the current folder the pass is done with.
	 */
	public function finished( string $name ): void {
		$this->last = implode( '/', array_merge( $this->trail, [ $name ] ) );
	}

	/**
	 * @return string The last entry finished, relative to the root ('' = none).
	 */
	public function last(): string {
		return $this->last;
	}
}
