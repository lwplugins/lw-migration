<?php
/**
 * Import step: writes what this site keeps into the temporary tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Database\SessionMode;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\PluginMessages;
use LightweightPlugins\Migration\Import\PluginPolicy;
use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPreserve;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Before the swap: this site's address and upload options, our own options
 * (site lock, job secret), the active plugin list and theme, and the importing
 * admin's password, session and role. After the swap the site is consistent
 * from the first request. Only the temporary tables are written (TmpTables),
 * with an empty session sql_mode so escaping does not depend on the server's
 * mode; every write replaces by key and warnings are added once, so a replay
 * after a crash is safe. On a network NetworkPreserve keeps the rest: after
 * this site's part for a whole-network archive, instead of it for chosen
 * sites or a single site (the main site is not imported then).
 */
final class PreserveStep implements StepInterface {

	public const KEEP_OPTIONS = [ 'siteurl', 'home', 'upload_path', 'upload_url_path' ];

	/**
	 * @param \Closure $connect          fn(): Connection.
	 * @param \Closure $installedPlugins fn(): array<string, string> — basename => version, on disk now.
	 * @param \Closure $themeExists      fn( string $slug ): bool.
	 */
	public function __construct( private \Closure $connect, private \Closure $installedPlugins, private \Closure $themeExists ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! ImportState::hasDatabase( $job ) ) {
			$job->state['loginKept'] = true; // No database in the archive: this site's users stay as they are.
			return StepResult::done();
		}
		$site = ImportState::site( $job );
		$db   = ( $this->connect )();

		return SessionMode::relaxed(
			$db,
			function () use ( $job, $db, $site ): StepResult {
				$tables  = new TmpTables( $db, (string) ( $job->state['tmpPrefix'] ?? '' ), $site->tablePrefix );
				$network = ImportState::network( $job );
				if ( null !== $network && ArchiveKind::NETWORK !== $network->kind ) {
					$this->networkPreserve( $tables, $network, $site )->run( $job, null );
					$job->state['loginKept'] = true; // Users are merged after the swap; this network's users stay.
					return StepResult::done( __( 'Kept each site\'s address and settings.', 'lw-migration' ) );
				}
				if ( ! $tables->has( 'options' ) ) {
					self::warn( $job, __( 'The archive has no options table, so this site\'s address and settings could not be carried over.', 'lw-migration' ) );
					return StepResult::done();
				}
				$tables->copyOptions( self::KEEP_OPTIONS );
				$tables->copyOptionPrefixes( Options::DUMP_EXCLUDED_PREFIXES, Options::NOT_CARRIED );
				$this->plugins( $job, $tables, $site );
				$this->theme( $job, $tables, $site );
				// Read by JobView (result.loginKept): the browser never matches warning text.
				$job->state['loginKept'] = $this->admin( $job, $tables );
				if ( null !== $network ) {
					// A whole network: the super admins, network plugins and every subsite (only a kept login becomes super admin).
					$this->networkPreserve( $tables, $network, $site )->run( $job, $job->state['loginKept'] ? ( $job->state['admin'] ?? null ) : null );
				}

				return $job->state['loginKept']
					? StepResult::done( __( 'Kept this site\'s address, settings and your login.', 'lw-migration' ) )
					: StepResult::done( __( 'Kept this site\'s address and settings.', 'lw-migration' ) );
			}
		);
	}

	private function networkPreserve( TmpTables $tables, NetworkImport $network, Site $site ): NetworkPreserve {
		return new NetworkPreserve( $tables, $network, $site, $this->installedPlugins, $this->themeExists, static fn(): int => time() );
	}

	private function plugins( Job $job, TmpTables $tables, Site $site ): void {
		$listed = array_values( array_map( 'strval', (array) ImportState::package( $job )->get( 'Plugins', [] ) ) );
		$https  = 'https' === strtolower( (string) parse_url( $site->homeUrl, PHP_URL_SCHEME ) );
		$policy = PluginPolicy::decide( $listed, ( $this->installedPlugins )(), $https, $site->ownPlugin );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- our own list of plugin basenames, stored the way WordPress stores active_plugins.
		$tables->setOption( 'active_plugins', serialize( $policy['active'] ) );
		$job->state['plugins'] = $policy;
		foreach ( $policy['removed'] as $plugin => $reason ) {
			self::warn( $job, PluginMessages::removed( (string) $plugin, $reason ) );
		}
	}

	private function theme( Job $job, TmpTables $tables, Site $site ): void {
		$package    = ImportState::package( $job );
		$template   = (string) $package->get( 'Template', '' );
		$stylesheet = (string) $package->get( 'Stylesheet', '' );
		$installed  = '' !== $template && '' !== $stylesheet && ( $this->themeExists )( $template ) && ( $this->themeExists )( $stylesheet );
		if ( ! $installed ) {
			if ( '' !== $template || '' !== $stylesheet ) {
				/* translators: %s: theme folder name */
				self::warn( $job, sprintf( __( 'The theme of the archive (%s) is not installed, so this site keeps its current theme.', 'lw-migration' ), '' !== $stylesheet ? $stylesheet : $template ) );
			}
			$template   = $site->template;
			$stylesheet = $site->stylesheet;
		}
		$tables->setOption( 'template', $template );
		$tables->setOption( 'stylesheet', $stylesheet );
	}

	/**
	 * @param Job       $job    Import job.
	 * @param TmpTables $tables Temporary tables.
	 * @return bool Whether the importing admin's login works after the swap.
	 */
	private function admin( Job $job, TmpTables $tables ): bool {
		$admin = $job->state['admin'] ?? null;
		if ( ! is_array( $admin ) || empty( $admin['id'] ) ) {
			self::warn( $job, __( 'No logged-in user started this import, so no login was carried over: log in with a user of the imported site.', 'lw-migration' ) );
			return false;
		}
		if ( ! $tables->has( 'users' ) || ! $tables->has( 'usermeta' ) ) {
			return true; // The archive brings no users: this site's users table stays.
		}
		$login = (string) ( $admin['login'] ?? '' );
		$id    = $tables->keepAdmin( (int) $admin['id'] );
		if ( null === $id ) {
			/* translators: %s: user login */
			self::warn( $job, sprintf( __( 'Your user (%s) no longer exists on this site, so no login was carried over: log in with a user of the imported site.', 'lw-migration' ), $login ) );
			return false;
		}
		foreach ( $tables->sharedIdentity( $id ) as $other => $columns ) {
			self::warn( $job, self::clash( $login, (string) $other, $columns ) );
		}
		// The swap points this admin's incoming links at its id after the import (TmpLinkOwners).
		$job->state['keptAdmin'] = [
			'from'  => (int) $admin['id'],
			'id'    => $id,
			'login' => $login,
		];

		return true;
	}

	/**
	 * @param string             $login   The importing admin's login.
	 * @param string             $other   Login of the imported user with the same value.
	 * @param array<int, string> $columns Clashing columns (user_email, user_nicename).
	 */
	private static function clash( string $login, string $other, array $columns ): string {
		if ( in_array( 'user_email', $columns, true ) ) {
			/* translators: 1: the importing admin's login, 2: login of an imported user */
			return sprintf( __( 'Your login (%1$s) and the imported user %2$s have the same e-mail address. Change one of them after the import, so password resets and e-mail logins reach the right user.', 'lw-migration' ), $login, $other );
		}

		/* translators: 1: the importing admin's login, 2: login of an imported user */
		return sprintf( __( 'Your login (%1$s) and the imported user %2$s have the same author slug. Change one of them after the import, so author pages show the right user.', 'lw-migration' ), $login, $other );
	}

	/**
	 * Adds a warning once, so a replay of this step does not repeat it.
	 *
	 * @param Job    $job     Import job.
	 * @param string $warning Translated warning.
	 */
	private static function warn( Job $job, string $warning ): void {
		if ( ! in_array( $warning, $job->warnings, true ) ) {
			$job->addWarning( $warning );
		}
	}
}
