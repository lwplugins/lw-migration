<?php
/**
 * What a restore into a network brings and where it goes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Plain value: the archive kind, the old-to-new site map, the target network
 * and the archive's network-wide plugins and super admins from multisite.json
 * (ImportState::network()). Pure.
 */
final class NetworkImport {

	/**
	 * @param string             $kind    ArchiveKind value.
	 * @param BlogMap            $map     Old-to-new site map.
	 * @param TargetNetwork      $target  The network restored into.
	 * @param array<int, string> $plugins Network-active plugins of the archive.
	 * @param array<int, string> $admins  Super admin logins of the archive.
	 */
	public function __construct( public string $kind, public BlogMap $map, public TargetNetwork $target, public array $plugins = [], public array $admins = [] ) {
	}
}
