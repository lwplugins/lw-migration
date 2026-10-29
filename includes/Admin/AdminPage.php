<?php
/**
 * The LW Migration admin page: menu entry, React mount point, assets.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Admin;

use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Rest\WebLimits;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;

defined( 'ABSPATH' ) || exit;

/**
 * One submenu page under the shared "LW Plugins" menu. The page is a single
 * mount point outside the core .wrap (its margins would break the full-height
 * shell); the React app in build/index.js renders every section and talks to
 * the lw-migration/v1 routes. window.lwMigration carries only what the app
 * needs before its first request, every value whitelisted here; the REST
 * nonce comes from core's wp-api-fetch, never from here.
 *
 * On a network the app lives under Network Admin > Settings > Migration
 * (backups and restores are network-wide); the main site's own admin keeps
 * its LW Plugins entry, which only points to the network page, and the other
 * sites get no entry (SubsiteNotice says so where it was activated alone).
 */
final class AdminPage {

	public const SLUG = 'lw-migration';

	/** Script and style handle of the React build. */
	private const HANDLE = 'lw-migration-admin';

	/** Hook suffix of the network page (Network Admin > Settings). */
	private const NETWORK_HOOK = 'settings_page_' . self::SLUG;

	/** Sections the app knows; the first is the default. */
	private const TABS = [ 'overview', 'export', 'import', 'backups', 'schedules', 'destinations', 'connections', 'reset', 'settings' ];

	/**
	 * Hook suffix from add_submenu_page(), '' before the menu exists.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Counts the backups for the menu entry: fn(): int (may throw).
	 *
	 * @var \Closure
	 */
	private \Closure $count;

	/**
	 * @param \Closure|null $count fn(): int — how many backups there are (null: both backups folders).
	 */
	public function __construct( ?\Closure $count = null ) {
		$this->count = $count ?? static fn(): int => count( Services::get()->backups()->all() );
	}

	public static function register(): void {
		$page = new self();
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $page, 'addNetworkMenu' ] );
			if ( MainSite::isContext() ) {
				add_action( 'admin_menu', [ $page, 'addMenu' ] ); // Points super admins to the network page.
			} else {
				SubsiteNotice::register(); // Activated on a subsite alone, it does nothing there.
			}
		} else {
			add_action( 'admin_menu', [ $page, 'addMenu' ] );
		}
		add_action( 'admin_enqueue_scripts', [ $page, 'enqueue' ] );
		add_filter( 'admin_body_class', [ $page, 'bodyClass' ] );
	}

	public function addNetworkMenu(): void {
		$capability = Permission::capability();
		$hook       = add_submenu_page(
			'settings.php',
			__( 'Migration', 'lw-migration' ),
			$this->menuTitle( $capability ),
			$capability,
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	public function addMenu(): void {
		ParentPage::maybe_register();
		$capability = Permission::capability();
		$hook       = add_submenu_page(
			ParentPage::SLUG,
			__( 'Migration', 'lw-migration' ),
			$this->menuTitle( $capability ),
			$capability,
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * "Migration" with the number of backups in core's menu bubble, read from
	 * the folders on each admin page (no badge at 0, for a user who cannot
	 * open the page, or when the folder cannot be read).
	 *
	 * @param string $capability The page's capability.
	 */
	private function menuTitle( string $capability ): string {
		$title = __( 'Migration', 'lw-migration' );
		if ( ! current_user_can( $capability ) ) {
			return $title;
		}
		try {
			$count = (int) ( $this->count )();
		} catch ( \Throwable $e ) {
			return $title;
		}
		if ( $count < 1 ) {
			return $title;
		}
		$number = number_format_i18n( $count );

		return sprintf(
			'%1$s <span class="awaiting-mod count-%2$d"><span class="pending-count" aria-hidden="true">%3$s</span><span class="screen-reader-text">%4$s</span></span>',
			$title,
			$count,
			esc_html( $number ),
			esc_html(
				sprintf(
					/* translators: %s: number of backups. */
					_n( '%s backup', '%s backups', $count, 'lw-migration' ),
					$number
				)
			)
		);
	}

	/**
	 * @param string $hook Current admin screen's hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( ! $this->isScreen( $hook ) || ! is_readable( LW_MIGRATION_PATH . 'build/index.js' ) ) {
			return;
		}
		$asset = self::asset();
		wp_enqueue_script( self::HANDLE, LW_MIGRATION_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'lw-migration', LW_MIGRATION_PATH . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.lwMigration = ' . wp_json_encode( self::scriptData() ) . ';', 'before' );
		if ( is_readable( LW_MIGRATION_PATH . 'build/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, LW_MIGRATION_URL . 'build/index.css', [ 'wp-components' ], self::fileVersion( 'build/index.css' ) );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * @param mixed $classes Space-separated admin body classes.
	 */
	public function bodyClass( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! $this->isScreen( (string) $screen->id ) ) {
			return (string) $classes;
		}

		return trim( $classes . ' lw-migration-screen' );
	}

	public function render(): void {
		if ( ! current_user_can( Permission::capability() ) ) {
			return;
		}
		if ( is_multisite() && ! is_network_admin() ) {
			self::networkNotice();
			return;
		}
		if ( ! is_readable( LW_MIGRATION_PATH . 'build/index.js' ) ) {
			self::notice( 'error', __( 'The LW Migration admin screen is missing (build/index.js). Reinstall the plugin from a release package, or run "npm run build" in a development copy.', 'lw-migration' ) );
			return;
		}
		echo '<div id="lw-migration-root" class="lw-migration-root"></div>';
	}

	/**
	 * Boot data printed as window.lwMigration.
	 *
	 * @return array{version: string, namespace: string, chunkBytes: int, tab: string, loginUrl: string, adminEmail: string, siteFolder: string, network: array{subdomain: bool, mainUrl: string, siteCount: int}|null}
	 */
	public static function scriptData(): array {
		return [
			'version'    => LW_MIGRATION_VERSION,
			'namespace'  => Routes::NAMESPACE,
			'chunkBytes' => WebLimits::chunkBytes(),
			'tab'        => self::tab(),
			// Back to this page after a logout mid-job; the app sets the tab of the job in redirect_to.
			'loginUrl'   => wp_login_url( self::pageUrl() ),
			// Schedule mails go here when no other address is set (shown as the placeholder).
			'adminEmail' => (string) get_option( 'admin_email', '' ),
			// This site's folder at every backup destination (the slug its backup names start with).
			'siteFolder' => SiteFolder::name(),
			// Null on a single site; on a network what the site picker and the import screen need first.
			'network'    => is_multisite() ? [
				'subdomain' => is_subdomain_install(),
				'mainUrl'   => network_home_url(),
				// A live count of the main network's sites (get_blog_count() is a cached figure, review M5).
				'siteCount' => (int) get_sites(
					[
						'network_id' => get_main_network_id(),
						'number'     => 0,
						'count'      => true,
					]
				),
			] : null,
		];
	}

	/**
	 * Address of the app: this site's admin page, on a network the network
	 * admin page (public since M7c: the reconnect mail links to it).
	 */
	public static function pageUrl(): string {
		return is_multisite() ? network_admin_url( 'settings.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * The main site's LW Plugins entry on a network: where the migrations are now.
	 */
	private static function networkNotice(): void {
		printf(
			'<div class="wrap"><div class="notice notice-info lw-notice"><p>%s <a href="%s">%s</a></p></div></div>',
			esc_html__( 'Migrations of this network are under Network Admin > Settings > Migration.', 'lw-migration' ),
			esc_url( self::pageUrl() ),
			esc_html__( 'Open the network page', 'lw-migration' )
		);
	}

	/**
	 * A core notice inside .wrap; `lw-notice` keeps NoticeManager's stylesheet from hiding it.
	 *
	 * @param string $type    info or error.
	 * @param string $message Plain text.
	 */
	private static function notice( string $type, string $message ): void {
		printf(
			'<div class="wrap"><div class="notice notice-%s lw-notice"><p>%s</p></div></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, whitelisted below.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $tab, self::TABS, true ) ? $tab : self::TABS[0];
	}

	/**
	 * On a network only the network admin page (its screen id ends in -network).
	 *
	 * @param string $hook Hook suffix or screen id.
	 */
	private function isScreen( string $hook ): bool {
		if ( is_multisite() ) {
			return '' !== $hook && is_network_admin() && in_array( $hook, [ $this->hook, self::NETWORK_HOOK, self::NETWORK_HOOK . '-network' ], true );
		}

		return '' !== $hook && ( $hook === $this->hook || ParentPage::SLUG . '_page_' . self::SLUG === $hook );
	}

	/**
	 * Dependencies and version from build/index.asset.php (written by @wordpress/scripts).
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private static function asset(): array {
		$file  = LW_MIGRATION_PATH . 'build/index.asset.php';
		$asset = is_readable( $file ) ? require $file : [];
		$asset = is_array( $asset ) ? $asset : [];

		return [
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? array_values( array_map( 'strval', $asset['dependencies'] ) ) : [],
			'version'      => isset( $asset['version'] ) ? (string) $asset['version'] : self::fileVersion( 'build/index.js' ),
		];
	}

	/**
	 * Cache-busting version from the file's modification time (the asset hash covers only the script).
	 *
	 * @param string $relative Plugin-relative path.
	 */
	private static function fileVersion( string $relative ): string {
		$path  = LW_MIGRATION_PATH . $relative;
		$mtime = is_file( $path ) ? filemtime( $path ) : false;

		return false !== $mtime ? (string) $mtime : LW_MIGRATION_VERSION;
	}
}
