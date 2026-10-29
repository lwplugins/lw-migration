<?php
/**
 * The plugins and themes the sites of a network use.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\Export\Steps\NetworkStep;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * What a network backup keeps when it leaves inactive plugins and themes
 * out: the network-active plugins and every site's active plugins, theme and
 * parent theme (as NetworkStep collects them for state.network). Read with
 * the export's NetworkReader in slices of blog ids, with the runtime cache
 * let go after each slice; a site deleted meanwhile is skipped.
 */
final class NetworkSoftware {

	/**
	 * @param NetworkReader $reader    The network (read only).
	 * @param string        $ownPlugin This plugin's basename (never counted).
	 */
	public function __construct( private NetworkReader $reader, private string $ownPlugin ) {
	}

	/**
	 * @return array{after: int, plugins: array<int, string>, themes: array<int, string>, done: bool}
	 */
	public function start(): array {
		return [
			'after'   => 0,
			'plugins' => $this->reader->sitewidePlugins( $this->ownPlugin ),
			'themes'  => [],
			'done'    => false,
		];
	}

	/**
	 * @param array{after: int, plugins: array<int, string>, themes: array<int, string>, done: bool} $state    Progress.
	 * @param Deadline                                                                               $deadline Time budget.
	 * @return array{after: int, plugins: array<int, string>, themes: array<int, string>, done: bool}
	 */
	public function collect( array $state, Deadline $deadline ): array {
		while ( ! $state['done'] ) {
			$ids = $this->reader->ids( $state['after'], NetworkStep::SLICE );
			foreach ( $ids as $id ) {
				try {
					$entry = $this->reader->entry( $id, $this->ownPlugin );
				} catch ( \RuntimeException $e ) {
					continue;
				}
				$state['plugins'] = self::union( $state['plugins'], $entry->plugins );
				$state['themes']  = self::union( $state['themes'], [ $entry->template, $entry->stylesheet ] );
			}
			$state['after'] = [] === $ids ? $state['after'] : (int) end( $ids );
			$state['done']  = count( $ids ) < NetworkStep::SLICE;
			$this->reader->releaseMemory();
			if ( $deadline->reached() ) {
				break;
			}
		}

		return $state;
	}

	/**
	 * @param array<int, string> $list  Names so far.
	 * @param array<int, string> $names Names to add.
	 * @return array<int, string> Each once, without ''.
	 */
	private static function union( array $list, array $names ): array {
		return array_values( array_unique( array_filter( array_merge( $list, $names ), static fn( string $n ): bool => '' !== $n ) ) );
	}
}
