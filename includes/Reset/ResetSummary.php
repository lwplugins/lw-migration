<?php
/**
 * What each reset would remove and keep, for the confirmation.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\WpConnection;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap and read-only towards the site (decision D19), with one exception:
 * like every section that offers a backup, it may create our storage and
 * backups folders when they are missing (backupReady asks ensureBackups()).
 * Counts and names, never a server path
 * (the uploads folder is shown as [uploads]). Each type says whether it may
 * start and why not (the reason code for the client, its text for people);
 * a full reset is available only when its four parts are. The database part
 * names the tables that go (ruling I7) from the same plan the swap uses, on
 * a single site only; plugins another loader requires are listed as kept
 * (ruling C1).
 */
final class ResetSummary {

	/** Names shown in a list; the rest are counted. */
	private const NAMED = 10;

	/**
	 * @param Services          $services  Services (storage, schedules).
	 * @param Site              $site      Site snapshot.
	 * @param \WP_User          $user      The administrator who would reset.
	 * @param \Closure|null     $tables    fn( Site $site ): array, ResetTables::plan() of the site (null = the live database).
	 * @param \Closure|null     $preflight fn( Site $site ): Preflight (null = Preflight::fromWordPress()).
	 * @param DefaultTheme|null $default   The theme a theme reset activates (null = DefaultTheme::fromWordPress()).
	 * @return array<string, mixed> The summary (D19).
	 */
	public static function of( Services $services, Site $site, \WP_User $user, ?\Closure $tables = null, ?\Closure $preflight = null, ?DefaultTheme $default = null ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$check   = null === $preflight ? Preflight::fromWordPress( $site ) : $preflight( $site );
		$network = is_multisite();
		$reasons = [];
		foreach ( ResetType::ALL as $type ) {
			$reasons[ $type ] = self::refusal( $check, $type );
		}
		$plugins = self::plugins( $site );
		$kept    = LoadedElsewhere::find( $plugins, LoadedElsewhere::loaders( $site->contentDir, $site->muPluginsDir, array_keys( _get_dropins() ), (string) ini_get( 'auto_prepend_file' ) ) );

		return [
			'network'     => $network,
			'phrase'      => self::phrase( $site->homeUrl ),
			'backupReady' => self::backupReady( $services ),
			'dropIns'     => self::dropIns( $site ),
			'muPlugins'   => count( get_mu_plugins() ),
			'keptPlugins' => self::kept( $kept ),
			'types'       => [
				ResetType::PLUGINS  => $reasons[ ResetType::PLUGINS ] + self::listed( self::pluginNames( array_values( array_diff( $plugins, array_keys( $kept ) ) ) ) ),
				ResetType::THEMES   => $reasons[ ResetType::THEMES ] + self::themes( $default ?? DefaultTheme::fromWordPress( $site ) ),
				ResetType::MEDIA    => $reasons[ ResetType::MEDIA ] + [
					'attachments' => $network ? null : self::attachments(),
					'folder'      => '[uploads]',
				],
				ResetType::DATABASE => $reasons[ ResetType::DATABASE ] + self::database( $services, $site, $user, $reasons[ ResetType::DATABASE ]['available'] ? ( $tables ?? self::livePlan() ) : null ),
				ResetType::FULL     => self::full( $reasons ),
			],
		];
	}

	/**
	 * The site address as the confirmation asks for it: lower-case host (and
	 * port) and path, without the scheme and the trailing slash.
	 *
	 * @param string $homeUrl The home address.
	 */
	public static function phrase( string $homeUrl ): string {
		return ConfirmPhrase::of( $homeUrl );
	}

	/**
	 * The plan of the live database, with the core list the swap uses.
	 *
	 * @return \Closure fn( Site $site ): array
	 */
	private static function livePlan(): \Closure {
		return static fn( Site $site ): array => ResetTables::plan( WpConnection::fromWpdb(), $site->tablePrefix, ResetTables::coreSuffixes() );
	}

	/**
	 * Whether a backup could be written now (the backups folder is usable).
	 *
	 * @param Services $services Services.
	 */
	private static function backupReady( Services $services ): bool {
		try {
			$services->storage()->ensureBackups();
		} catch ( \Throwable $e ) {
			return false;
		}

		return true;
	}

	/**
	 * @param Site $site Site snapshot.
	 * @return array<int, string> The drop-ins present in the content folder.
	 */
	private static function dropIns( Site $site ): array {
		return array_values(
			array_filter(
				array_map( 'strval', array_keys( _get_dropins() ) ),
				static fn( string $name ): bool => is_file( $site->contentDir . '/' . $name )
			)
		);
	}

	/**
	 * @param Preflight $check Preflight of the site.
	 * @param string    $type  One of ResetType::ALL.
	 * @return array{available: bool, reason: string|null, reasonText: string}
	 */
	private static function refusal( Preflight $check, string $type ): array {
		try {
			$check->assert( $type );
		} catch ( ResetRefused $e ) {
			return [
				'available'  => false,
				'reason'     => $e->reason,
				'reasonText' => $e->getMessage(),
			];
		}

		return [
			'available'  => true,
			'reason'     => null,
			'reasonText' => '',
		];
	}

	/**
	 * A full reset needs every part; the first refusal is its reason.
	 *
	 * @param array<string, array{available: bool, reason: string|null, reasonText: string}> $reasons By type.
	 * @return array{available: bool, reason: string|null, reasonText: string}
	 */
	private static function full( array $reasons ): array {
		foreach ( [ ResetType::FULL, ResetType::PLUGINS, ResetType::THEMES, ResetType::MEDIA, ResetType::DATABASE ] as $type ) {
			if ( ! $reasons[ $type ]['available'] ) {
				return $reasons[ $type ];
			}
		}

		return $reasons[ ResetType::FULL ];
	}

	/**
	 * @param Site $site Site snapshot.
	 * @return array<int, string> Installed plugins but this one, sorted.
	 */
	private static function plugins( Site $site ): array {
		$all = array_values( array_diff( array_map( 'strval', array_keys( get_plugins() ) ), [ $site->ownPlugin ] ) );
		sort( $all, SORT_STRING );

		return $all;
	}

	/**
	 * The names the plugin list shows (the basename when a plugin has none), sorted.
	 *
	 * @param array<int, string> $plugins Basenames.
	 * @return array<int, string>
	 */
	private static function pluginNames( array $plugins ): array {
		$all   = get_plugins();
		$names = array_map( static fn( string $file ): string => '' !== trim( (string) ( $all[ $file ]['Name'] ?? '' ) ) ? trim( (string) $all[ $file ]['Name'] ) : $file, $plugins );
		natcasesort( $names );

		return array_values( $names );
	}

	/**
	 * @param array<string, string> $kept Basename => what loads it.
	 * @return array<int, array{plugin: string, by: string}>
	 */
	private static function kept( array $kept ): array {
		$out = [];
		foreach ( $kept as $plugin => $by ) {
			$out[] = [
				'plugin' => (string) $plugin,
				'by'     => (string) $by,
			];
		}

		return $out;
	}

	/**
	 * @param array<int, string> $names Sorted names.
	 * @return array{count: int, names: array<int, string>, more: int}
	 */
	private static function listed( array $names ): array {
		return [
			'count' => count( $names ),
			'names' => array_slice( $names, 0, self::NAMED ),
			'more'  => max( 0, count( $names ) - self::NAMED ),
		];
	}

	/**
	 * The theme a theme reset activates and the themes it deletes (every
	 * other theme but the target's parent, as ThemesStep lists them).
	 *
	 * @param DefaultTheme $default The default theme chooser (no side effect).
	 * @return array{target: array{slug: string, name: string, source: string}, count: int, names: array<int, string>, more: int}
	 */
	private static function themes( DefaultTheme $default ): array {
		$target = $default->choose();
		$themes = [];
		foreach ( wp_get_themes( [ 'errors' => null ] ) as $stylesheet => $theme ) {
			$template                       = (string) $theme->get_template();
			$themes[ (string) $stylesheet ] = (string) $stylesheet === $template ? '' : $template;
		}
		$keep  = [ $target['slug'], (string) ( $themes[ $target['slug'] ] ?? '' ) ];
		$names = array_values( array_diff( array_keys( $themes ), $keep ) );
		sort( $names, SORT_STRING );
		$name = $target['slug'];
		if ( 'installed' === $target['source'] ) {
			$name = (string) wp_get_theme( $target['slug'] )->get( 'Name' );
		} elseif ( 'stub' === $target['source'] ) {
			$name = StubTheme::NAME;
		}

		return [
			'target' => [
				'slug'   => $target['slug'],
				'name'   => '' !== $name ? $name : $target['slug'],
				'source' => $target['source'],
			],
		] + self::listed( $names );
	}

	/**
	 * Every attachment of this site, trashed ones included.
	 */
	private static function attachments(): int {
		return (int) array_sum( array_map( 'intval', (array) wp_count_attachments() ) );
	}

	/**
	 * @param Services      $services Services.
	 * @param Site          $site     Site snapshot.
	 * @param \WP_User      $user     The administrator who stays.
	 * @param \Closure|null $tables   Plan closure; null when the database part is not available.
	 * @return array<string, mixed>
	 */
	private static function database( Services $services, Site $site, \WP_User $user, ?\Closure $tables ): array {
		$plan = null === $tables ? [
			'core'   => [],
			'retire' => [],
			'kept'   => [],
			'views'  => [],
		] : $tables( $site );

		return [
			'user'          => (string) $user->user_login,
			'tables'        => ResetTables::named( $plan ),
			'prefixWarning' => ResetTables::prefixWarning( $site->tablePrefix ),
			'connections'   => count( ( new Links() )->all( time() ) ),
			'schedules'     => count( $services->schedules()->existing() ),
			'otherUsers'    => max( 0, (int) get_user_count() - 1 ),
		];
	}
}
