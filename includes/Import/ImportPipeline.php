<?php
/**
 * Steps of an import job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Database\OrphanTables;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\Steps\CleanStep;
use LightweightPlugins\Migration\Import\Steps\ConfirmStep;
use LightweightPlugins\Migration\Import\Steps\ContentStep;
use LightweightPlugins\Migration\Import\Steps\CrcStep;
use LightweightPlugins\Migration\Import\Steps\DatabaseFileStep;
use LightweightPlugins\Migration\Import\Steps\DatabaseStep;
use LightweightPlugins\Migration\Import\Steps\DecryptStep;
use LightweightPlugins\Migration\Import\Steps\DoneStep;
use LightweightPlugins\Migration\Import\Steps\MuPluginsStep;
use LightweightPlugins\Migration\Import\Steps\NetworkDoneStep;
use LightweightPlugins\Migration\Import\Steps\NetworkSitesStep;
use LightweightPlugins\Migration\Import\Steps\NetworkUsersStep;
use LightweightPlugins\Migration\Import\Steps\PostImportStep;
use LightweightPlugins\Migration\Import\Steps\PreserveStep;
use LightweightPlugins\Migration\Import\Steps\SafetyBackupStep;
use LightweightPlugins\Migration\Import\Steps\SourceStep;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Import\Steps\UploadStep;
use LightweightPlugins\Migration\Import\Steps\UrlDownloadStep;
use LightweightPlugins\Migration\Import\Steps\UrlResolveStep;
use LightweightPlugins\Migration\Import\Steps\ValidateStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Link\LinkPipeline;
use LightweightPlugins\Migration\Link\Steps\PullDownloadStep;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\SiteFactory;
use LightweightPlugins\Migration\Multisite\TargetNetworkReader;
use LightweightPlugins\Migration\PostImport\Fixes;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

/**
 * Step keys in order (decision 1) and their WordPress-wired implementations.
 * Every database step gets a connect closure, so each tick builds a fresh
 * connection.
 */
final class ImportPipeline {

	public const COMMON = [ 'validate', 'crc', 'decrypt', 'confirm', 'safety_backup', 'content', 'database_file', 'database', 'preserve', SwapStep::KEY, PostImportStep::KEY, MuPluginsStep::KEY, 'done', 'clean' ];

	/** Network steps (registered by later milestone tasks) and the step each follows (decision 21, ruling R7). */
	private const NETWORK_AFTER = [
		'safety_backup'     => 'network_sites',
		SwapStep::KEY       => NetworkUsersStep::KEY,
		PostImportStep::KEY => NetworkDoneStep::KEY,
	];

	/**
	 * @param string $source  ImportOptions source.
	 * @param bool   $network Restore into a network (adds the network steps).
	 * @return array<int, string>
	 */
	public static function stepsFor( string $source, bool $network = false ): array {
		$first = [
			ImportOptions::URL    => [ 'url_resolve', 'url_download' ],
			ImportOptions::UPLOAD => [ UploadStep::KEY ],
			ImportOptions::LINK   => [ RemoteJobStep::PULL_KEY, PullDownloadStep::KEY ],
			ImportOptions::CLOUD  => [ CloudDownloadStep::KEY ],
		];
		$steps = [];
		foreach ( self::COMMON as $key ) {
			$steps[] = $key;
			if ( $network && isset( self::NETWORK_AFTER[ $key ] ) ) {
				$steps[] = self::NETWORK_AFTER[ $key ];
			}
		}

		return array_merge( $first[ $source ] ?? [ 'source' ], $steps );
	}

	/**
	 * @param Services $services WordPress wiring.
	 * @return array<string, StepInterface>
	 */
	public static function steps( Services $services ): array {
		$http      = new RangeDownloader();
		$jobs      = $services->jobs();
		$root      = $services->storage()->root();
		$connect   = static fn() => $services->connection();
		$plugin    = static fn( string $basename ): bool => self::pluginExists( $basename, WP_PLUGIN_DIR );
		$theme     = static fn( string $slug ): bool => self::themeExistsIn( $slug, self::themeRoots() );
		$freeSpace = static fn( string $dir ): ?int => DiskSpace::free( $dir );

		return LinkPipeline::pullSteps( $services, $freeSpace ) + [
			'source'              => new SourceStep( $services->backups(), $services->storage()->jobsDir() ),
			UploadStep::KEY       => new UploadStep( $jobs ),
			'url_resolve'         => new UrlResolveStep( $http ),
			'url_download'        => new UrlDownloadStep( $jobs, $http, $freeSpace ),
			'validate'            => new ValidateStep(),
			'crc'                 => new CrcStep(),
			'decrypt'             => new DecryptStep(),
			'confirm'             => new ConfirmStep( $freeSpace, static fn(): int => $services->databaseBytes(), static fn( string $domain, string $path, int $network ): ?int => TargetNetworkReader::siteAt( $domain, $path, $network ) ),
			// No retention limits: pruning could delete the backup being restored (the store step skips it for pre-import too).
			'safety_backup'       => new SafetyBackupStep( ExportPipeline::steps( $services, new Retention() ) ),
			'network_sites'       => new NetworkSitesStep( $jobs, new SiteFactory(), static fn( Job $job ): array => SharedCode::snapshot( ImportState::site( $job )->pluginsDir, ImportState::site( $job )->themeRoot ) ),
			'content'             => new ContentStep( $jobs, static fn( Job $job, string $staging ): ContentRules => self::rules( $job, $staging, $root ), $freeSpace ),
			'database_file'       => new DatabaseFileStep( $jobs, $freeSpace ),
			'database'            => new DatabaseStep( $jobs, $connect, $plugin, $theme, static fn( string $tmp ): bool => ( new TmpRunRegistry() )->register( OrphanTables::runId( $tmp ), time() ) ),
			'preserve'            => new PreserveStep( $connect, static fn(): array => self::installedPlugins(), $theme ),
			SwapStep::KEY         => new SwapStep( $jobs, $connect, static fn() => Fixes::flushCaches(), $services->lock(), static fn(): int => time(), static fn( string $run ): bool => ( new TmpRunRegistry() )->forget( $run ) ),
			NetworkUsersStep::KEY => new NetworkUsersStep( $jobs, $connect, static fn( string $run ): bool => ( new TmpRunRegistry() )->forget( $run ) ),
			PostImportStep::KEY   => new PostImportStep( new Fixes() ),
			NetworkDoneStep::KEY  => new NetworkDoneStep( static fn( string $task, Job $job, string $home, string $uploads ) => ( new Fixes() )->runOnBlog( $task, $job, $home, $uploads ), static fn(): array => self::installedPlugins(), static fn(): int => time() ),
			MuPluginsStep::KEY    => new MuPluginsStep( $jobs ),
			'done'                => new DoneStep(),
			'clean'               => new CleanStep( $jobs ),
		];
	}

	/**
	 * Where the archive's entries go on this site, and what is never written.
	 * On a network the uploads go by blog once the sites are resolved (ruling
	 * R21; the cleanups only need the folders before), and a sites or
	 * single-site archive leaves the network's code alone (ruling R14).
	 *
	 * @param Job    $job         Import job (its saved site snapshot: the main site's on a network).
	 * @param string $staging     Staging folder of the must-use plugins.
	 * @param string $storageRoot Our storage root.
	 * @return ContentRules
	 */
	public static function rules( Job $job, string $staging, string $storageRoot ): ContentRules {
		self::loadPluginApi();
		$site    = ImportState::site( $job );
		$network = ImportState::onNetwork( $job );
		$kind    = ImportState::kind( $job );

		return new ContentRules(
			[
				'content'                  => $site->contentDir,
				'plugins'                  => $site->pluginsDir,
				'themes'                   => $site->themeRoot,
				'uploads'                  => $site->uploadsDir,
				ContentRules::STAGING_BASE => $staging,
			],
			array_map( 'strval', array_keys( _get_dropins() ) ),
			$network ? self::networkUploads( $job, $kind ) : UploadRoutes::forSubsite( ImportState::subsiteId( $job ) ),
			ProtectedTargets::forSite( $site, $storageRoot ),
			$network && ArchiveKind::NETWORK !== $kind ? SharedCode::fromArray( $job->state[ NetworkSitesStep::CODE ] ?? null, $site->contentDir ) : null
		);
	}

	/**
	 * @param Job    $job  Import job on a network.
	 * @param string $kind ArchiveKind value ('' before Validate).
	 */
	private static function networkUploads( Job $job, string $kind ): UploadRoutes {
		$map = is_array( $job->state['blogs'] ?? null ) ? ImportState::blogMap( $job ) : null;

		return null !== $map && $map->isResolved() ? UploadRoutes::forNetwork( $kind, $map, ImportState::target( $job )->uploadBlogsDir ) : UploadRoutes::forSubsite( null );
	}

	/**
	 * Plugins on disk now (after the files were restored).
	 *
	 * @return array<string, string> Basename => version.
	 */
	public static function installedPlugins(): array {
		self::loadPluginApi();
		wp_clean_plugins_cache( false );
		$installed = [];
		foreach ( get_plugins() as $file => $data ) {
			$installed[ (string) $file ] = (string) ( $data['Version'] ?? '' );
		}

		return $installed;
	}

	/**
	 * Read from disk, not from WordPress' caches (they predate the restore).
	 *
	 * @param string $basename   Plugin basename from the archive's database.
	 * @param string $pluginsDir Plugins folder.
	 */
	public static function pluginExists( string $basename, string $pluginsDir ): bool {
		return PathGuard::isSafeEntryName( $basename ) && is_file( rtrim( $pluginsDir, '/' ) . '/' . $basename );
	}

	/**
	 * Read from disk, not from WordPress' theme cache (it predates the restore).
	 *
	 * @param string $slug      Theme folder name from the archive's database.
	 * @param string $themeRoot Theme root.
	 */
	public static function themeExists( string $slug, string $themeRoot ): bool {
		return PathGuard::isSafeEntryName( $slug ) && ! str_contains( $slug, '/' ) && is_file( rtrim( $themeRoot, '/' ) . '/' . $slug . '/style.css' );
	}

	/**
	 * Every theme root WordPress knows (register_theme_directory()), the default first.
	 *
	 * @return array<int, string>
	 */
	public static function themeRoots(): array {
		$roots = [ (string) get_theme_root() ];
		foreach ( (array) ( $GLOBALS['wp_theme_directories'] ?? [] ) as $root ) {
			$roots[] = (string) $root;
		}

		return array_values( array_unique( array_filter( $roots, static fn( string $root ): bool => '' !== $root ) ) );
	}

	/**
	 * @param string             $slug  Theme folder name.
	 * @param array<int, string> $roots Theme roots.
	 */
	public static function themeExistsIn( string $slug, array $roots ): bool {
		foreach ( $roots as $root ) {
			if ( self::themeExists( $slug, $root ) ) {
				return true;
			}
		}

		return false;
	}

	private static function loadPluginApi(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
