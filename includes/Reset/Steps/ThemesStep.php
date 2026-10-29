<?php
/**
 * Reset step: switches to the default theme and deletes every other theme.
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
use LightweightPlugins\Migration\Reset\DefaultTheme;
use LightweightPlugins\Migration\Reset\NetworkSites;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\Translations;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\TreeEraser;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * The default theme is made sure of first (state.defaultTheme), then made
 * active (network-enabled and switched on every site of a network) before
 * anything is deleted. Every other theme of the site's theme folder goes
 * (except the default's parent) through TreeEraser, never following a link;
 * a linked theme folder, a theme of another theme folder and a name that is
 * no plain folder are left with a warning. Last, the deleted themes' mods
 * rows are forgotten (on every site of a network) and they are network-
 * disabled.
 */
final class ThemesStep implements StepInterface {

	public const KEY = ResetType::THEMES_STEP;

	/** Names kept in state.removed.themes.names. */
	private const MAX_NAMES = 50;

	/** @var array<string, string>|null Sealed folder ids of this run. */
	private ?array $sealedIds = null;

	/**
	 * @param JobStore       $store      Job store (the chosen theme is saved once installed).
	 * @param DefaultTheme   $default    The theme to switch to.
	 * @param \Closure       $themes     fn(): array<string, array{root: string, parent: string, name?: string}> by stylesheet.
	 * @param \Closure       $sealed     fn( Site $site ): array<string, string> folders never entered, by id.
	 * @param string         $langDir    The languages folder.
	 * @param \Closure       $activate   fn( string $slug, ?int $siteId ): void; null = this site, network-enable on a network.
	 * @param \Closure       $forgetMods fn( array $stylesheets, ?int $siteId, array $names ): void; null = this site, network-disable on a network.
	 * @param RootGuard|null $root     Null = this process.
	 */
	public function __construct(
		private JobStore $store,
		private DefaultTheme $default,
		private \Closure $themes,
		private \Closure $sealed,
		private string $langDir,
		private \Closure $activate,
		private \Closure $forgetMods,
		private ?RootGuard $root = null
	) {
	}

	/**
	 * @param Services $services Services.
	 * @param Site     $site     The site as it is now.
	 */
	public static function fromWordPress( Services $services, Site $site ): self {
		return new self(
			$services->jobs(),
			DefaultTheme::fromWordPress( $site ),
			static function (): array {
				wp_clean_themes_cache( false );
				$out = [];
				foreach ( wp_get_themes( [ 'errors' => null ] ) as $stylesheet => $theme ) {
					$template                    = (string) $theme->get_template();
					$out[ (string) $stylesheet ] = [
						'root'   => (string) $theme->get_theme_root(),
						'parent' => (string) $stylesheet === $template ? '' : $template,
						'name'   => (string) $theme->get( 'Name' ),
					];
				}
				return $out;
			},
			static fn( Site $site ): array => FolderIdentity::ids( ExportRoots::sealedFolders( $site, $services->storage()->root() ) ),
			WP_LANG_DIR,
			static fn( string $slug, ?int $siteId ) => self::switchTo( $slug, $siteId ),
			static fn( array $stylesheets, ?int $siteId, array $names = [] ) => self::onSite(
				$siteId,
				static function () use ( $stylesheets, $names ): void {
					foreach ( $stylesheets as $stylesheet ) {
						delete_option( 'theme_mods_' . $stylesheet );
						if ( '' !== (string) ( $names[ $stylesheet ] ?? '' ) ) {
							delete_option( 'mods_' . $names[ $stylesheet ] ); // The legacy row, named by the theme's name.
						}
					}
				},
				static fn() => \WP_Theme::network_disable_theme( $stylesheets )
			)
		);
	}

	/**
	 * Makes the theme active on a site (network-enables it for null on a
	 * network) and fails when the switch did not take, before anything is
	 * deleted.
	 *
	 * @param string   $slug   Theme stylesheet.
	 * @param int|null $siteId A site of the network, or null for this site (the network on a network).
	 * @throws JobException When the site's active theme is not $slug afterwards.
	 */
	public static function switchTo( string $slug, ?int $siteId ): void {
		self::onSite(
			$siteId,
			static function () use ( $slug ): void {
				switch_theme( $slug );
				if ( get_stylesheet() !== $slug ) {
					/* translators: %s: theme folder name, e.g. twentytwentyfive */
					throw new JobException( sprintf( __( 'The default theme %s could not be switched on, so no theme was deleted.', 'lw-migration' ), $slug ) );
				}
			},
			static fn() => \WP_Theme::network_enable_theme( $slug )
		);
	}

	/**
	 * @param int|null $siteId    A site of the network, or null for this site (the network on a network).
	 * @param \Closure $onSite    What to do on one site.
	 * @param \Closure $onNetwork What to do once for the network.
	 */
	private static function onSite( ?int $siteId, \Closure $onSite, \Closure $onNetwork ): void {
		if ( null === $siteId ) {
			is_multisite() ? $onNetwork() : $onSite();
			return;
		}
		switch_to_blog( $siteId );
		try {
			$onSite();
		} finally {
			restore_current_blog();
		}
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$site            = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
		$this->sealedIds = null;
		if ( ( $this->root ?? RootGuard::current() )->isRoot() ) { // Every request, fail closed: a file reset never runs as root.
			throw new JobException( ResetTexts::refusal( ResetRefused::AS_ROOT ) );
		}
		$slug = $this->defaultTheme( $job );
		if ( 'activate' === ( $job->cursor['phase'] ?? 'activate' ) && ! $this->activateAll( $job, $slug, $deadline ) ) {
			return StepResult::more( 0, __( 'Switching to the default theme...', 'lw-migration' ) );
		}
		$list  = array_values( array_map( 'strval', (array) ( $job->cursor['list'] ?? [] ) ) );
		$total = count( $list );
		while ( (int) ( $job->cursor['next'] ?? 0 ) < $total ) {
			$next = (int) $job->cursor['next'];
			$done = $this->deleteTheme( $job, $list[ $next ], $site, $deadline );
			if ( $done ) {
				$job->cursor['next'] = ++$next;
				unset( $job->cursor['started'], $job->cursor[ ScanPass::CURSOR ] );
			}
			if ( ! $done || ( $next < $total && $deadline->reached() ) ) {
				/* translators: 1: themes deleted so far, 2: all themes to delete */
				return StepResult::more( (int) floor( 100 * $next / max( 1, $total ) ), sprintf( __( 'Deleting the themes: %1$d of %2$d...', 'lw-migration' ), $next, $total ) );
			}
		}
		if ( ! $this->forgetAll( $job, $deadline ) ) {
			return StepResult::more( 100, __( 'Forgetting the settings of the deleted themes...', 'lw-migration' ) );
		}
		$this->assertStillInstalled( $slug );
		$job->state['removed']['themes'] ??= [
			'count' => 0,
			'names' => [],
		];
		$count                             = (int) $job->state['removed']['themes']['count'];

		/* translators: 1: number of themes, 2: theme folder name, e.g. twentytwentyfive */
		return StepResult::done( sprintf( _n( '%1$d theme deleted; %2$s is active.', '%1$d themes deleted; %2$s is active.', $count, 'lw-migration' ), $count, $slug ) );
	}

	/**
	 * The default theme, made sure of again on every request: one that went
	 * missing before its activation is chosen again; one that went missing
	 * later stops the reset (fail closed: never done without a theme).
	 *
	 * @param Job $job Reset job.
	 * @throws JobException When it went missing after its activation.
	 */
	private function defaultTheme( Job $job ): string {
		$chosen = $job->state['defaultTheme'] ?? null;
		if ( is_array( $chosen ) && ! $this->default->installed( (string) $chosen['slug'] ) && 'activate' === ( $job->cursor['phase'] ?? 'activate' ) ) {
			$chosen = null; // Nothing was deleted yet: choose again.
		}
		if ( ! is_array( $chosen ) ) {
			$this->chooseDefault( $job );
		}
		$slug = (string) $job->state['defaultTheme']['slug'];
		$this->assertStillInstalled( $slug );

		return $slug;
	}

	/**
	 * @param string $slug The default theme.
	 * @throws JobException When it is not installed (or broken) now.
	 */
	private function assertStillInstalled( string $slug ): void {
		if ( ! $this->default->installed( $slug ) ) {
			/* translators: %s: theme folder name, e.g. twentytwentyfive */
			throw new JobException( sprintf( __( 'The default theme %s is no longer installed, so no more themes were deleted. Install a theme and activate it.', 'lw-migration' ), $slug ) );
		}
	}

	/**
	 * Installs the default theme when it is missing (once: saved right away).
	 *
	 * @param Job $job Reset job.
	 */
	private function chooseDefault( Job $job ): void {
		$chosen = $this->default->ensure();
		if ( isset( $chosen['note'] ) ) {
			$job->addWarning( (string) $chosen['note'] );
		}
		$job->state['defaultTheme'] = [
			'slug'   => (string) $chosen['slug'],
			'source' => (string) $chosen['source'],
		];
		$job->cursor                = [ 'phase' => 'activate' ];
		$this->store->save( $job );
	}

	/**
	 * @param Job      $job      Reset job.
	 * @param string   $slug     The default theme.
	 * @param Deadline $deadline Time budget.
	 * @return bool False when the network's sites are not all done yet.
	 */
	private function activateAll( Job $job, string $slug, Deadline $deadline ): bool {
		if ( empty( $job->cursor['wide'] ) ) {
			( $this->activate )( $slug, null );
			$job->cursor['wide'] = true;
		}
		if ( is_multisite() && ! NetworkSites::advance( $job, 'sites', fn( int $id ) => ( $this->activate )( $slug, $id ), $deadline ) ) {
			return false;
		}
		$themes = ( $this->themes )();
		$keep   = [ $slug, (string) ( $themes[ $slug ]['parent'] ?? '' ) ];
		$list   = array_values( array_diff( array_map( 'strval', array_keys( $themes ) ), $keep ) );
		sort( $list, SORT_STRING );
		$info = [];
		foreach ( $list as $stylesheet ) {
			$info[ $stylesheet ] = [
				'root' => (string) ( $themes[ $stylesheet ]['root'] ?? '' ),
				'name' => (string) ( $themes[ $stylesheet ]['name'] ?? '' ),
			];
		}
		$job->cursor = [
			'phase'   => 'delete',
			'list'    => $list,
			'info'    => $info,
			'keep'    => array_values( array_filter( $keep ) ),
			'next'    => 0,
			'deleted' => [],
		];

		return true;
	}

	/**
	 * @param Job      $job      Reset job.
	 * @param string   $theme    Stylesheet.
	 * @param Site     $site     Site snapshot.
	 * @param Deadline $deadline Time budget.
	 * @return bool False when the deadline cut its folder.
	 */
	private function deleteTheme( Job $job, string $theme, Site $site, Deadline $deadline ): bool {
		$info = (array) ( $job->cursor['info'][ $theme ] ?? [] );
		$path = CodeFolder::theme( $site->themeRoot, $theme );
		$skip = $this->refusal( $path, (string) ( $info['root'] ?? '' ), $site, $job, $theme );
		if ( null !== $skip ) {
			$job->addWarning( $skip );
			return true;
		}
		if ( ( $job->cursor['started'] ?? null ) !== $theme ) {
			$job->cursor['started'] = $theme;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, fired as delete_theme() fires it.
			do_action( 'delete_theme', $theme );
		}
		$failed = ScanPass::run( $job, $theme, (string) $path, TreeEraser::forSite( $this->sealedIds( $site ), $site ), $deadline );
		if ( null === $failed ) {
			return false;
		}
		Translations::remove( $this->langDir, 'themes', $theme );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, fired as delete_theme() fires it.
		do_action( 'deleted_theme', $theme, 0 === $failed );
		$job->cursor['deleted'][] = $theme;
		if ( '' !== (string) ( $info['name'] ?? '' ) ) {
			$job->cursor['names'][ $theme ] = (string) $info['name'];
		}
		$removed = is_array( $job->state['removed']['themes'] ?? null ) ? $job->state['removed']['themes'] : [
			'count' => 0,
			'names' => [],
		];
		++$removed['count'];
		$removed['leftovers'] = (int) ( $removed['leftovers'] ?? 0 ) + ( $failed > 0 ? 1 : 0 ); // Deleted, but with files left.
		if ( count( $removed['names'] ) < self::MAX_NAMES ) {
			$removed['names'][] = $theme;
		}
		$job->state['removed']['themes'] = $removed;
		if ( $failed > 0 ) {
			/* translators: 1: theme folder name, 2: number of files */
			$job->addWarning( sprintf( _n( '%1$s: %2$d file could not be removed (permissions).', '%1$s: %2$d files could not be removed (permissions).', $failed, 'lw-migration' ), $theme, $failed ) );
		}

		return true;
	}

	/**
	 * @param string|null $path  The theme's folder.
	 * @param string      $root  The theme folder WordPress found it in.
	 * @param Site        $site  Site snapshot.
	 * @param Job         $job   Reset job (the kept themes).
	 * @param string      $theme Stylesheet.
	 * @return string|null Why it is left, or null.
	 */
	private function refusal( ?string $path, string $root, Site $site, Job $job, string $theme ): ?string {
		if ( null === $path ) {
			/* translators: %s: theme folder name */
			return sprintf( __( 'The theme %s was left: its folder name is not a plain theme folder.', 'lw-migration' ), $theme );
		}
		$home = FolderIdentity::of( $site->themeRoot );
		if ( null === $home || FolderIdentity::of( $root ) !== $home ) {
			/* translators: %s: theme folder name */
			return sprintf( __( 'The theme %s was left: it lives in another theme folder.', 'lw-migration' ), $theme );
		}
		if ( CodeFolder::isLink( $path ) ) {
			/* translators: %s: theme folder name */
			return sprintf( __( 'The theme %s was left: its folder is a link to another place.', 'lw-migration' ), $theme );
		}
		$id = FolderIdentity::of( $path );
		foreach ( (array) ( $job->cursor['keep'] ?? [] ) as $kept ) {
			$keptPath = CodeFolder::theme( $site->themeRoot, (string) $kept );
			if ( null !== $id && null !== $keptPath && FolderIdentity::of( $keptPath ) === $id ) {
				/* translators: %s: theme folder name */
				return sprintf( __( 'The theme %s was left: it is the active theme under another name.', 'lw-migration' ), $theme );
			}
		}

		return null;
	}

	/**
	 * @param Job      $job      Reset job.
	 * @param Deadline $deadline Time budget.
	 * @return bool False when the network's sites are not all done yet.
	 */
	private function forgetAll( Job $job, Deadline $deadline ): bool {
		$deleted = array_values( array_map( 'strval', (array) ( $job->cursor['deleted'] ?? [] ) ) );
		$names   = (array) ( $job->cursor['names'] ?? [] );
		if ( [] === $deleted ) {
			return true;
		}
		if ( empty( $job->cursor['modsWide'] ) ) {
			( $this->forgetMods )( $deleted, null, $names );
			$job->cursor['modsWide'] = true;
		}

		return ! is_multisite() || NetworkSites::advance( $job, 'modSites', fn( int $id ) => ( $this->forgetMods )( $deleted, $id, $names ), $deadline );
	}

	/**
	 * Our storage and the backups folders.
	 *
	 * @param Site $site Site snapshot.
	 * @return array<string, string>
	 */
	private function sealedIds( Site $site ): array {
		return $this->sealedIds ??= ( $this->sealed )( $site );
	}
}
