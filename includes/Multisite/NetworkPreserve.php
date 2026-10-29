<?php
/**
 * What each site and the network keep before the swap of a restore into a network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Import\PluginMessages;
use LightweightPlugins\Migration\Import\PluginPolicy;
use LightweightPlugins\Migration\Import\SharedCode;
use LightweightPlugins\Migration\Import\Steps\MuPluginsStep;
use LightweightPlugins\Migration\Import\Steps\NetworkSitesStep;
use LightweightPlugins\Migration\Import\Steps\PreserveStep;
use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Only the temporary tables are written (TmpTables). A whole network: the
 * archive's super admins plus the importing one, this plugin network-active,
 * the network's ms_files_rewriting, new site ids above every live and archive
 * one (ruling R16; the left-out sites stay as deleted sites), each subsite's upload paths, plugins and theme, and the
 * network's sites the archive leaves out are named. Chosen sites or a single
 * site: each new site keeps its address and upload paths and gets its plugins
 * and theme; the network's plugins and users are merged after the swap.
 * There only plugins and themes the network had before the import are
 * activated (final review I1, SharedCode): the archive's new ones are
 * restored but stay inactive, and one warning names them.
 * Reference dumps leave active_plugins/template/stylesheet blank in every
 * options table, so plugins and theme always come from multisite.json. Every
 * write replaces by key and warnings are added once: safe to run again.
 */
final class NetworkPreserve {

	/** @var array<string, string>|null Installed plugins, read once per run. */
	private ?array $installed = null;

	/** @var SharedCode|null What the network had before a sites or single-site restore (null: a whole network). */
	private ?SharedCode $code = null;

	/** @var array{plugins: array<int, string>, themes: array<int, string>} Restored but not activated. */
	private array $held = [
		'plugins' => [],
		'themes'  => [],
	];

	/**
	 * @param TmpTables     $tables           Temporary tables of the import.
	 * @param NetworkImport $network          The restore.
	 * @param Site          $site             The network's main site (snapshot from before the import).
	 * @param \Closure      $installedPlugins fn(): array<string, string> — basename => version, on disk now.
	 * @param \Closure      $themeExists      fn( string $slug ): bool.
	 * @param \Closure      $now              fn(): int — the activation time stored for network plugins.
	 */
	public function __construct( private TmpTables $tables, private NetworkImport $network, private Site $site, private \Closure $installedPlugins, private \Closure $themeExists, private \Closure $now ) {
	}

	/**
	 * @param Job                       $job   Import job.
	 * @param array<string, mixed>|null $admin The importing admin when their login was kept (id, login).
	 */
	public function run( Job $job, ?array $admin ): void {
		$whole      = ArchiveKind::NETWORK === $this->network->kind;
		$this->code = $whole ? null : SharedCode::fromArray( $job->state[ NetworkSitesStep::CODE ] ?? null );
		$this->held = [
			'plugins' => [],
			'themes'  => [],
		];
		if ( $whole ) {
			$this->network( $job, $admin );
		}
		foreach ( $this->network->map->all() as $m ) {
			$id = $m['newId'];
			if ( ! is_int( $id ) || ( $whole && SiteEntry::fromArray( $m['old'] )->isMain() ) ) {
				continue; // The main site is kept by PreserveStep's single-site part.
			}
			$this->site( $job, $m, $id, $whole );
		}
		if ( $whole ) {
			$this->unlisted( $job );
		} else {
			$this->notActivated( $job );
		}
	}

	/**
	 * @param Job                       $job   Import job.
	 * @param array<string, mixed>|null $admin The importing admin.
	 */
	private function network( Job $job, ?array $admin ): void {
		if ( $this->tables->has( 'blogs' ) ) {
			$this->tables->leftOutSites()->keep();
			$this->tables->raiseAutoIncrement( 'blogs', 'blog_id' );
		}
		if ( ! $this->tables->has( 'sitemeta' ) ) {
			return; // The network's own settings stay as they are.
		}
		$networkId = $this->network->target->networkId;
		$archiveId = $this->tables->has( 'site' ) ? $this->tables->networkId() : null;
		if ( null !== $archiveId && $this->tables->has( 'blogs' ) ) {
			$this->tables->moveNetwork( $archiveId, $networkId ); // Review I1: the archive's network becomes this one.
		}
		$login  = is_string( $admin['login'] ?? null ) ? $admin['login'] : '';
		$admins = array_values( array_unique( array_merge( $this->network->admins, '' === $login ? [] : [ $login ] ) ) );
		if ( [] !== $admins ) { // Never an empty list: the archive's own row is better than a network without super admins.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a list of logins from multisite.json, stored the way WordPress stores site_admins.
			$this->tables->setSiteMeta( $networkId, 'site_admins', serialize( $admins ) );
		}
		$policy = $this->policy( $job, $this->network->plugins, $this->site->homeUrl );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- basename => activation time, the way WordPress stores active_sitewide_plugins.
		$this->tables->setSiteMeta( $networkId, 'active_sitewide_plugins', serialize( array_fill_keys( $policy, ( $this->now )() ) ) );
		$this->tables->copySiteMeta( $networkId, [ 'ms_files_rewriting' ] );
	}

	/**
	 * @param Job                  $job   Import job.
	 * @param array<string, mixed> $m     The site's mapping (BlogMap).
	 * @param int                  $id    Its id on this network.
	 * @param bool                 $whole A whole-network archive.
	 */
	private function site( Job $job, array $m, int $id, bool $whole ): void {
		$table = $id . '_options';
		$url   = (string) $m['url'];
		if ( ! $this->tables->has( $table ) ) {
			/* translators: %s: address of a site of the archive */
			$this->warn( $job, sprintf( __( 'Site %s has no options table in the archive.', 'lw-migration' ), $url ) );
			return;
		}
		if ( $whole ) {
			$this->tables->setOption( 'upload_path', (string) $m['newUploadPath'], $table );
			$this->tables->setOption( 'upload_url_path', (string) $m['newUploadUrlPath'], $table );
		} else {
			$this->tables->copyOptions( PreserveStep::KEEP_OPTIONS, $table ); // Created or reused by network_sites: its live table exists.
			$this->roles( $job, $id, $table, $url );
		}
		$entry  = SiteEntry::fromArray( $m['old'] );
		$active = array_values( array_diff( $this->policy( $job, $this->ownCode( $entry->plugins ), '' !== $m['newHomeUrl'] ? (string) $m['newHomeUrl'] : $url ), [ $this->site->ownPlugin ] ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- our own list of plugin basenames, stored the way WordPress stores active_plugins.
		$this->tables->setOption( 'active_plugins', serialize( $active ), $table );
		$this->theme( $job, $entry, $url, $table );
	}

	/**
	 * Review N1/N3: a restored site's roles never carry a network right;
	 * roles that are missing, stored more than once (any letter case) or
	 * cannot be read become WordPress's default roles.
	 *
	 * @param Job    $job   Import job.
	 * @param int    $id    The site's id on this network.
	 * @param string $table Its options table suffix.
	 * @param string $url   Its address (for the warning).
	 */
	private function roles( Job $job, int $id, string $table, string $url ): void {
		$name                = $this->site->tablePrefix . $id . '_user_roles';
		$options             = $this->tables->roleOptions( $table );
		[ $clean, $changed ] = SiteRoles::fromRows( $options->rows( $name ) );
		$options->replace( $name, $clean ); // Always (review N4): one exact, autoloaded row is what WordPress reads.
		if ( $changed ) {
			/* translators: %s: address of a site of the archive */
			$this->warn( $job, sprintf( __( 'The roles of site %s were checked: rights over the whole network were removed, and missing, repeated or unreadable roles were replaced by the WordPress default roles.', 'lw-migration' ), $url ) );
		}
	}

	private function theme( Job $job, SiteEntry $entry, string $url, string $table ): void {
		$template   = $entry->template;
		$stylesheet = $entry->stylesheet;
		$installed  = '' !== $template && '' !== $stylesheet && ( $this->themeExists )( $template ) && ( $this->themeExists )( $stylesheet );
		$new        = $installed && null !== $this->code ? array_values( array_filter( array_unique( [ $template, $stylesheet ] ), fn( string $t ): bool => ! $this->code->hadTheme( $t ) ) ) : [];
		if ( [] !== $new ) {
			$this->held['themes'] = array_merge( $this->held['themes'], $new );
			$template             = $this->site->template;
			$stylesheet           = $this->site->stylesheet;
		} elseif ( ! $installed ) {
			if ( '' !== $template || '' !== $stylesheet ) {
				/* translators: 1: address of a site, 2: theme folder name of the archive, 3: theme folder name it uses instead */
				$this->warn( $job, sprintf( __( 'The theme of site %1$s (%2$s) is not installed, so it uses %3$s.', 'lw-migration' ), $url, '' !== $stylesheet ? $stylesheet : $template, $this->site->stylesheet ) );
			}
			$template   = $this->site->template;
			$stylesheet = $this->site->stylesheet;
		}
		$this->tables->setOption( 'template', $template, $table );
		$this->tables->setOption( 'stylesheet', $stylesheet, $table );
	}

	/**
	 * The archive's plugins without the ones it brought (they are held back
	 * and named once); all of them for a whole network.
	 *
	 * @param array<int, string> $plugins Plugins of the archive.
	 * @return array<int, string>
	 */
	private function ownCode( array $plugins ): array {
		if ( null === $this->code ) {
			return $plugins;
		}
		$new                   = $this->code->newPlugins( $plugins, $this->installed() );
		$this->held['plugins'] = array_merge( $this->held['plugins'], $new );

		return array_values( array_diff( $plugins, $new ) );
	}

	/**
	 * Names, once, what a sites or single-site archive brought and what
	 * therefore stays inactive (the network plugins are merged after the
	 * swap by NetworkDoneStep, which leaves the same ones out).
	 *
	 * @param Job $job Import job.
	 */
	private function notActivated( Job $job ): void {
		$this->ownCode( $this->network->plugins );
		$names = array_values( array_unique( array_merge( $this->held['plugins'], $this->held['themes'] ) ) );
		if ( [] === $names ) {
			return;
		}
		/* translators: %s: list of plugin basenames and theme folder names */
		$this->warn( $job, sprintf( __( 'Restored but not activated, because the network did not have them before this import: %s. Activate them in Network Admin if you trust the archive.', 'lw-migration' ), MuPluginsStep::nameList( $names ) ) );
	}

	/** @return array<string, string> Installed plugins, read once per run. */
	private function installed(): array {
		$this->installed = $this->installed ?? ( $this->installedPlugins )();

		return $this->installed;
	}

	/**
	 * @param Job                $job     Import job.
	 * @param array<int, string> $plugins Plugins of the archive.
	 * @param string             $home    Home URL they run under.
	 * @return array<int, string> The ones that stay active (this plugin first).
	 */
	private function policy( Job $job, array $plugins, string $home ): array {
		$https  = 'https' === strtolower( (string) parse_url( $home, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of a stored address.
		$policy = PluginPolicy::decide( $plugins, $this->installed(), $https, $this->site->ownPlugin );
		foreach ( $policy['removed'] as $plugin => $reason ) {
			$this->warn( $job, PluginMessages::removed( (string) $plugin, $reason ) );
		}

		return $policy['active'];
	}

	/**
	 * Decision 18: the network's sites a network archive does not contain are
	 * named; their tables and files stay, nothing of them is dropped, and
	 * they stay listed as deleted sites (final review I2, keepLeftOutSites()).
	 *
	 * @param Job $job Import job.
	 */
	private function unlisted( Job $job ): void {
		$unlisted = array_values( array_filter( (array) ( $job->state['unlisted'] ?? [] ), static fn( $s ): bool => is_array( $s ) && is_string( $s['url'] ?? null ) ) );
		if ( [] === $unlisted ) {
			return;
		}
		/* translators: %s: list of site addresses */
		$this->warn( $job, sprintf( __( 'These sites are not in the archive: %s. They stay on the network marked as deleted (Network Admin > Sites), with their tables and files kept; new sites will not reuse their IDs.', 'lw-migration' ), MuPluginsStep::nameList( array_column( $unlisted, 'url' ) ) ) );
		$urls  = array_column( $unlisted, 'url', 'id' );
		$moved = [];
		foreach ( $this->tables->has( 'blogs' ) ? $this->tables->leftOutSites()->movedAside( array_keys( $urls ) ) : [] as $id => $address ) {
			$moved[] = $urls[ $id ] . ' -> ' . $address;
		}
		if ( [] !== $moved ) {
			/* translators: %s: list of "old address -> new address" pairs */
			$this->warn( $job, sprintf( __( 'These sites are not in the archive, but the archive has a site at their address, so they were kept under a new address: %s', 'lw-migration' ), MuPluginsStep::nameList( $moved ) ) );
		}
	}

	/**
	 * Adds a warning once, so a replay does not repeat it.
	 *
	 * @param Job    $job     Import job.
	 * @param string $warning Translated warning.
	 */
	private function warn( Job $job, string $warning ): void {
		if ( ! in_array( $warning, $job->warnings, true ) ) {
			$job->addWarning( $warning );
		}
	}
}
