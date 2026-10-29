<?php
/**
 * Import step: finishes every restored site of a network on its own.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\PluginMessages;
use LightweightPlugins\Migration\Import\PluginPolicy;
use LightweightPlugins\Migration\Import\SharedCode;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 21: after the swap and the user merge, each mapped site gets the
 * per-site fixes inside its own switch_to_blog() (caches, address-bound
 * settings with its own address and uploads folder, rewrite rules), and its
 * cached data is dropped after the switch. The main site is never finished
 * here: a network archive's was finished by the post-import step (ruling R12),
 * and no other archive kind can target it (U2). A sites or single-site
 * archive first adds its network-active plugins that can run here to the
 * network's (a network archive's were decided before the swap). One site per
 * unit of work; cursor {blog: index of the next site, -1 before the plugins}.
 * Nothing of ours is read or written while switched.
 */
final class NetworkDoneStep implements StepInterface {

	public const KEY = 'network_done';

	/** The per-site fixes, in order. */
	public const TASKS = [ 'elementor', 'builderCaches', 'jetpack', 'woocommerce', 'dbVersion' ];

	/**
	 * @param \Closure $fix              fn( string $task, Job $job, string $homeUrl, string $uploadsDir ): void (Fixes::runOnBlog()).
	 * @param \Closure $installedPlugins fn(): array<string, string> — basename => version, on disk now.
	 * @param \Closure $now              fn(): int — the activation time stored for added network plugins.
	 */
	public function __construct( private \Closure $fix, private \Closure $installedPlugins, private \Closure $now ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$network = ImportState::network( $job );
		if ( null === $network || ! ImportState::hasDatabase( $job ) ) {
			return StepResult::done();
		}
		$i = (int) ( $job->cursor['blog'] ?? -1 );
		if ( $i < 0 ) {
			$i                   = 0;
			$job->cursor['blog'] = 0;
			if ( ArchiveKind::NETWORK !== $network->kind ) {
				$this->mergeNetworkPlugins( $job, $network->plugins );

				return StepResult::more( 0, __( 'Finishing the sites...', 'lw-migration' ) );
			}
		}
		$all   = $network->map->all();
		$total = count( $all );
		while ( $i < $total ) {
			$id = (int) $all[ $i ]['newId'];
			if ( $id > 0 && $id !== $network->target->mainSiteId ) {
				$this->finishBlog( $job, $id, $all[ $i ] );
			}
			$job->cursor['blog'] = ++$i;
			if ( $i < $total && $deadline->reached() ) {
				/* translators: 1: sites finished, 2: number of sites */
				return StepResult::more( (int) ( 100 * $i / $total ), sprintf( __( 'Finishing the sites: %1$d of %2$d...', 'lw-migration' ), $i, $total ) );
			}
		}

		return StepResult::done( __( 'Every site is finished.', 'lw-migration' ) );
	}

	/**
	 * The fixes run on a scratch copy of the job, so each warning they add is
	 * passed on with the site's address in front.
	 *
	 * @param Job                  $job Import job.
	 * @param int                  $id  The site's id on this network.
	 * @param array<string, mixed> $map Its BlogMap mapping.
	 */
	private function finishBlog( Job $job, int $id, array $map ): void {
		$home    = '' !== (string) $map['newHomeUrl'] ? (string) $map['newHomeUrl'] : (string) $map['url'];
		$scratch = new Job( $job->id, $job->type, [], 0, [], $job->options, $job->state );
		switch_to_blog( $id );
		try {
			foreach ( self::TASKS as $task ) {
				( $this->fix )( $task, $scratch, $home, (string) $map['newUploadsDir'] );
			}
			delete_option( 'rewrite_rules' );
		} finally {
			restore_current_blog();
		}
		clean_blog_cache( $id );
		foreach ( $scratch->newWarnings as $warning ) {
			/* translators: 1: a site's address, 2: what was changed on that site */
			$job->addWarning( sprintf( __( '%1$s: %2$s', 'lw-migration' ), $home, $warning ) );
		}
	}

	/**
	 * Adds the archive's network-active plugins that can run here and that
	 * the network had before the import (final review I1: the ones the
	 * archive brought stay inactive, NetworkPreserve named them); the
	 * network's own entries (this plugin among them) stay as they are.
	 *
	 * @param Job                $job     Import job (sites or single-site archive).
	 * @param array<int, string> $plugins Network-active plugins of the archive.
	 */
	private function mergeNetworkPlugins( Job $job, array $plugins ): void {
		if ( [] === $plugins ) {
			return;
		}
		$site      = ImportState::site( $job );
		$installed = ( $this->installedPlugins )();
		$plugins   = array_values( array_diff( $plugins, SharedCode::fromArray( $job->state[ NetworkSitesStep::CODE ] ?? null )->newPlugins( $plugins, $installed ) ) );
		$https     = 'https' === strtolower( (string) parse_url( $site->homeUrl, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of a stored address.
		$policy    = PluginPolicy::decide( $plugins, $installed, $https, $site->ownPlugin );
		$current   = get_site_option( 'active_sitewide_plugins', [] );
		$current   = is_array( $current ) ? $current : [];
		$added     = array_values( array_diff( $policy['active'], [ $site->ownPlugin ], array_map( 'strval', array_keys( $current ) ) ) );
		if ( [] !== $added ) {
			update_site_option( 'active_sitewide_plugins', $current + array_fill_keys( $added, ( $this->now )() ) );
		}
		foreach ( $policy['removed'] as $plugin => $reason ) {
			$warning = PluginMessages::removed( (string) $plugin, $reason );
			if ( ! in_array( $warning, $job->warnings, true ) ) {
				$job->addWarning( $warning );
			}
		}
	}
}
