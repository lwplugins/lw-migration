<?php
/**
 * Reset step: deactivates, uninstalls and deletes every other plugin.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\CodeFolder;
use LightweightPlugins\Migration\Reset\LoadedElsewhere;
use LightweightPlugins\Migration\Reset\NetworkSites;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\Translations;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\TreeEraser;
use LightweightPlugins\Migration\Reset\UninstallRoutine;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Phases in cursor.phase: list (every plugin but this one and those loaded
 * outside the plugin list), deactivate (network-wide, then every site of the
 * network in pages), delete (per plugin: its uninstall routine at most once,
 * then its folder through TreeEraser, never following a link, then its
 * translations). The uninstall routines run through UninstallRoutine (at
 * most once each, one per web request).
 */
final class PluginsStep implements StepInterface {

	public const KEY = ResetType::PLUGINS_STEP;

	/** Names kept in state.removed.plugins.names. */
	private const MAX_NAMES = 50;

	/** @var \Closure fn( Site $site, array $plugins ): array<string, string> */
	private \Closure $loaded;

	/** @var UninstallRoutine The uninstall routines, at most once each. */
	private UninstallRoutine $routine;

	/** @var array<string, string>|null Sealed folder ids of this run. */
	private ?array $sealedIds = null;

	/**
	 * @param JobStore       $store        Job store (the started plugin is saved before its routine).
	 * @param \Closure       $installed    fn(): array<int, string> plugin basenames.
	 * @param \Closure       $sealed       fn( Site $site ): array<string, string> folders never entered, by id.
	 * @param string         $langDir      The languages folder.
	 * @param \Closure       $deactivate   fn( array $plugins, ?int $siteId ): void; null = this site, network-wide on a network.
	 * @param \Closure       $uninstall    fn( string $basename ): void.
	 * @param \Closure|null  $loaded       fn( Site $site, array $plugins ): array<string, string> kept basename => loader; null = the SQLite integration only.
	 * @param \Closure|null  $cli          fn(): bool; null = WP_CLI.
	 * @param \Closure|null  $uninstallRan fn(): bool; null = whether WP_UNINSTALL_PLUGIN is defined in this process.
	 * @param RootGuard|null $root        Null = this process.
	 */
	public function __construct(
		JobStore $store,
		private \Closure $installed,
		private \Closure $sealed,
		private string $langDir,
		private \Closure $deactivate,
		\Closure $uninstall,
		?\Closure $loaded = null,
		?\Closure $cli = null,
		?\Closure $uninstallRan = null,
		private ?RootGuard $root = null
	) {
		$this->loaded  = $loaded ?? static fn( Site $site, array $plugins ): array => LoadedElsewhere::find( $plugins, [] );
		$this->routine = new UninstallRoutine( $store, $uninstall, $cli, $uninstallRan );
	}

	/**
	 * @param Services $services Services.
	 */
	public static function fromWordPress( Services $services ): self {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return new self(
			$services->jobs(),
			static fn(): array => array_map( 'strval', array_keys( get_plugins() ) ),
			static fn( Site $site ): array => FolderIdentity::ids( ExportRoots::sealedFolders( $site, $services->storage()->root() ) ),
			WP_LANG_DIR,
			static function ( array $plugins, ?int $siteId ): void {
				if ( null === $siteId ) {
					deactivate_plugins( $plugins, true, is_multisite() );
					return;
				}
				switch_to_blog( $siteId );
				try {
					deactivate_plugins( $plugins, true, false );
				} finally {
					restore_current_blog();
				}
			},
			static function ( string $plugin ): void {
				if ( is_uninstallable_plugin( $plugin ) ) {
					uninstall_plugin( $plugin );
				}
			},
			static fn( Site $site, array $plugins ): array => LoadedElsewhere::find( $plugins, LoadedElsewhere::loaders( $site->contentDir, $site->muPluginsDir, array_keys( _get_dropins() ), (string) ini_get( 'auto_prepend_file' ) ) )
		);
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$site            = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
		$this->sealedIds = null;
		if ( ( $this->root ?? RootGuard::current() )->isRoot() ) { // Every request, fail closed: a file reset never runs as root.
			throw new JobException( ResetTexts::refusal( ResetRefused::AS_ROOT ) );
		}
		if ( 'list' === ( $job->cursor['phase'] ?? 'list' ) ) {
			$this->listPlugins( $job, $site );
		}
		if ( 'deactivate' === ( $job->cursor['phase'] ?? '' ) && ! $this->deactivateAll( $job, $deadline ) ) {
			return StepResult::more( 0, __( 'Deactivating the plugins...', 'lw-migration' ) );
		}
		$list  = array_values( array_map( 'strval', (array) ( $job->cursor['list'] ?? [] ) ) );
		$total = count( $list );
		while ( (int) ( $job->cursor['next'] ?? 0 ) < $total ) {
			$next    = (int) ( $job->cursor['next'] ?? 0 );
			$outcome = $this->deleteOne( $job, $list[ $next ], $site, $deadline );
			if ( true === $outcome ) {
				$job->cursor['next'] = ++$next;
				unset( $job->cursor['started'], $job->cursor['uninstalled'], $job->cursor[ ScanPass::CURSOR ] );
			}
			if ( true !== $outcome || ( $next < $total && $deadline->reached() ) ) {
				$progress = (int) floor( 100 * $next / max( 1, $total ) );
				/* translators: 1: plugins deleted so far, 2: all plugins to delete */
				$text = sprintf( __( 'Deleting the plugins: %1$d of %2$d...', 'lw-migration' ), $next, $total );

				return UninstallRoutine::NEXT_REQUEST === $outcome ? StepResult::nextRequest( $progress, $text ) : StepResult::more( $progress, $text );
			}
		}

		return $this->finish( $job, $site );
	}

	/**
	 * Every installed plugin but this one; those WordPress loads outside the
	 * plugin list are kept (not even deactivated), each with a warning.
	 *
	 * @param Job  $job  Reset job.
	 * @param Site $site Site snapshot.
	 */
	private function listPlugins( Job $job, Site $site ): void {
		$all = array_values( array_diff( array_map( 'strval', ( $this->installed )() ), [ $site->ownPlugin ] ) );
		sort( $all, SORT_STRING );
		$kept  = ( $this->loaded )( $site, $all );
		$state = [];
		foreach ( $kept as $plugin => $by ) {
			/* translators: 1: plugin file, 2: what loads it, e.g. db.php */
			$job->addWarning( sprintf( __( '%1$s was kept: %2$s loads it directly.', 'lw-migration' ), $plugin, $by ) );
			$state[] = [
				'plugin' => (string) $plugin,
				'by'     => (string) $by,
			];
		}
		$job->state['keptPlugins'] = $state;
		$job->cursor               = [
			'phase' => 'deactivate',
			'list'  => array_values( array_diff( $all, array_map( 'strval', array_keys( $kept ) ) ) ),
		];
	}

	/**
	 * @param Job      $job      Reset job.
	 * @param Deadline $deadline Time budget.
	 * @return bool False when the network's sites are not all done yet.
	 */
	private function deactivateAll( Job $job, Deadline $deadline ): bool {
		$list = array_values( array_map( 'strval', (array) ( $job->cursor['list'] ?? [] ) ) );
		if ( [] !== $list && empty( $job->cursor['wide'] ) ) {
			( $this->deactivate )( $list, null );
			$job->cursor['wide'] = true;
		}
		if ( [] !== $list && is_multisite() && ! NetworkSites::advance( $job, 'sites', fn( int $id ) => ( $this->deactivate )( $list, $id ), $deadline ) ) {
			return false;
		}
		$job->cursor = [
			'phase' => 'delete',
			'list'  => $list,
			'next'  => 0,
		];

		return true;
	}

	/**
	 * @param Job      $job      Reset job.
	 * @param string   $plugin   Plugin basename.
	 * @param Site     $site     Site snapshot.
	 * @param Deadline $deadline Time budget.
	 * @return bool|string True when it is done (or left with a warning), false when the deadline cut its folder, UninstallRoutine::NEXT_REQUEST.
	 */
	private function deleteOne( Job $job, string $plugin, Site $site, Deadline $deadline ): bool|string {
		$path = CodeFolder::plugin( $site->pluginsDir, $plugin );
		$skip = $this->refusal( $path, $site, $plugin );
		if ( null !== $skip ) {
			$job->addWarning( $skip );
			return true;
		}
		if ( UninstallRoutine::NEXT_REQUEST === $this->routine->once( $job, $plugin, self::ourCopy( $site, $plugin ) ) ) {
			return UninstallRoutine::NEXT_REQUEST;
		}
		if ( $this->sharedLater( $job, (string) $path ) ) {
			$this->record( $job, $plugin, 0 ); // Its folder goes with the last plugin in it, after every routine ran.
			return true;
		}
		$failed = ScanPass::run( $job, $plugin, (string) $path, TreeEraser::forSite( $this->sealedIds( $site ), $site ), $deadline );
		if ( null === $failed ) {
			return false;
		}
		Translations::remove( $this->langDir, 'plugins', preg_replace( '/\.php\z/', '', explode( '/', $plugin )[0] ) ?? '' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, fired as delete_plugins() fires it.
		do_action( 'deleted_plugin', $plugin, 0 === $failed );
		$this->record( $job, $plugin, $failed );

		return true;
	}

	/**
	 * @param string|null $path   The plugin's folder or file.
	 * @param Site        $site   Site snapshot.
	 * @param string      $plugin Plugin basename.
	 * @return string|null Why it is left (it was deactivated), or null.
	 */
	private function refusal( ?string $path, Site $site, string $plugin ): ?string {
		if ( null === $path ) {
			/* translators: %s: plugin file, e.g. my-plugin/my-plugin.php */
			return sprintf( __( 'The plugin %s was deactivated but not deleted: its file name is not a plain plugin file.', 'lw-migration' ), $plugin );
		}
		$own = CodeFolder::plugin( $site->pluginsDir, $site->ownPlugin );
		$id  = FolderIdentity::of( $path );
		if ( null !== $own && null !== $id && FolderIdentity::of( $own ) === $id ) {
			/* translators: %s: plugin file, e.g. my-plugin/my-plugin.php */
			return sprintf( __( 'The plugin %s was deactivated but not deleted: it is LW Migration itself under another name.', 'lw-migration' ), $plugin );
		}
		if ( CodeFolder::isLink( $path ) ) {
			/* translators: %s: plugin file, e.g. my-plugin/my-plugin.php */
			return sprintf( __( 'The plugin %s was deactivated but not deleted: its folder is a link to another place.', 'lw-migration' ), $plugin );
		}

		return null;
	}

	/**
	 * Whether a plugin later in the list lives in the same folder.
	 *
	 * @param Job    $job  Reset job.
	 * @param string $path The current plugin's folder or file.
	 */
	private function sharedLater( Job $job, string $path ): bool {
		$site = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
		$list = array_map( 'strval', (array) ( $job->cursor['list'] ?? [] ) );
		foreach ( array_slice( $list, (int) ( $job->cursor['next'] ?? 0 ) + 1 ) as $later ) {
			if ( CodeFolder::plugin( $site->pluginsDir, $later ) === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Another copy of LW Migration (its main file named like ours, or its
	 * header's text domain ours): its uninstall.php is ours, and running it
	 * would delete this reset's lock, job and settings.
	 *
	 * @param Site   $site   Site snapshot.
	 * @param string $plugin Plugin basename.
	 */
	private static function ourCopy( Site $site, string $plugin ): bool {
		if ( basename( $plugin ) === basename( LW_MIGRATION_FILE ) ) {
			return true;
		}
		$file = rtrim( $site->pluginsDir, '/' ) . '/' . $plugin;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file; a warning would carry the path.
		$head = is_file( $file ) ? (string) @file_get_contents( $file, false, null, 0, 8192 ) : '';
		$head = str_replace( "\r", "\n", $head ); // As get_file_data() does: a header saved with CR or CRLF line ends.

		return 1 === preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Text Domain:[ \t]*lw-migration[ \t]*(?:\*\/)?[ \t]*$/mi', $head );
	}

	/**
	 * Our storage, the backups folders and this plugin's own folder.
	 *
	 * @param Site $site Site snapshot.
	 * @return array<string, string>
	 */
	private function sealedIds( Site $site ): array {
		$own = CodeFolder::plugin( $site->pluginsDir, $site->ownPlugin );

		return $this->sealedIds ??= ( $this->sealed )( $site ) + FolderIdentity::ids( null === $own ? [] : [ $own ] );
	}

	/**
	 * @param Job    $job    Reset job.
	 * @param string $plugin Plugin basename.
	 * @param int    $failed Entries that could not be removed.
	 */
	private function record( Job $job, string $plugin, int $failed ): void {
		$removed = is_array( $job->state['removed']['plugins'] ?? null ) ? $job->state['removed']['plugins'] : [
			'count' => 0,
			'names' => [],
		];
		++$removed['count'];
		$removed['leftovers'] = (int) ( $removed['leftovers'] ?? 0 ) + ( $failed > 0 ? 1 : 0 ); // Deleted, but with files left.
		if ( count( $removed['names'] ) < self::MAX_NAMES ) {
			$removed['names'][] = $plugin;
		}
		$job->state['removed']['plugins'] = $removed;
		if ( $failed > 0 ) {
			/* translators: 1: plugin file, 2: number of files */
			$job->addWarning( sprintf( _n( '%1$s: %2$d file could not be removed (permissions).', '%1$s: %2$d files could not be removed (permissions).', $failed, 'lw-migration' ), $plugin, $failed ) );
		}
	}

	/**
	 * @param Job  $job  Reset job.
	 * @param Site $site Site snapshot.
	 */
	private function finish( Job $job, Site $site ): StepResult {
		wp_clean_plugins_cache();
		$job->state['removed']['plugins'] ??= [
			'count' => 0,
			'names' => [],
		];
		$dropIns                            = array_values(
			array_filter(
				array_map( 'strval', array_keys( _get_dropins() ) ),
				static fn( string $name ): bool => is_file( $site->contentDir . '/' . $name )
			)
		);
		$job->state['dropIns']              = $dropIns;
		$count                              = (int) $job->state['removed']['plugins']['count'];
		/* translators: %d: number of plugins */
		$text = sprintf( _n( '%d plugin deleted.', '%d plugins deleted.', $count, 'lw-migration' ), $count );
		if ( [] !== $dropIns ) {
			$text .= ' ' . __( 'A page cache may still serve the old pages: empty it.', 'lw-migration' );
		}

		return StepResult::done( $text );
	}
}
