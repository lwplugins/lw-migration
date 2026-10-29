<?php
/**
 * Export step: writes package.json as the first entry.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Export\ArchiveCodec;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\PackageBuilder;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Multisite\MultisiteJson;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Starts the archive from scratch on every run, so a retry is clean. A
 * network backup gets multisite.json right after package.json.
 */
final class ConfigStep implements StepInterface {

	/** The largest multisite.json an importer reads (ours reads 16 MB). */
	public const MULTISITE_MAX_BYTES = 16777216;

	/**
	 * @param JobStore $store     Job store.
	 * @param \Closure $dbVersion fn(): string — database server version.
	 * @param \Closure $now       fn(): int — Unix time.
	 */
	public function __construct( private JobStore $store, private \Closure $dbVersion, private \Closure $now ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$site    = Site::fromArray( (array) $job->state['site'] );
		$options = ExportOptions::fromArray( $job->options );
		$cipher  = $options->encrypt ? new Cipher( ArchiveCodec::password( $job ) ) : null;
		$package = PackageBuilder::build( $site, $options, $cipher, self::read( $site->abspath . '.htaccess' ), self::read( $site->abspath . 'web.config' ), (string) ( $this->dbVersion )() );
		$path    = WorkDir::path( $this->store, $job ) . '/' . ExportFiles::ARCHIVE;
		Files::privateFile( $path );
		$writer = new Writer( $path, ArchiveCodec::forJob( $job ) );
		try {
			$writer->truncateTo( 0 );
			$writer->addString( Format::PACKAGE, $package->toJson(), (int) ( $this->now )() );
			if ( isset( $job->state['network'] ) ) {
				$writer->addString( Format::MULTISITE, $this->multisiteJson( $job ), (int) ( $this->now )() );
			}
			$job->state['archiveLength'] = $writer->length();
		} finally {
			$writer->close();
		}
		$job->state['package'] = $package->toArray();

		return StepResult::done( __( 'Configuration prepared.', 'lw-migration' ) );
	}

	/**
	 * The multisite.json of a network backup, from the site list the network step wrote.
	 *
	 * @param Job $job Export job with state.network.
	 * @return string
	 * @throws ExportException When the list cannot be read or the file would be too big for an importer.
	 */
	private function multisiteJson( Job $job ): string {
		$state = (array) $job->state['network'];
		$path  = WorkDir::path( $this->store, $job ) . '/' . ExportFiles::NETWORK_SITES;
		$lines = is_file( $path ) && is_readable( $path ) ? file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : false;
		if ( false === $lines ) {
			throw new ExportException( __( 'The list of the network\'s sites cannot be read. Start the backup again.', 'lw-migration' ) );
		}
		$sites = [];
		foreach ( $lines as $line ) {
			$sites[] = SiteEntry::fromArray( (array) json_decode( $line, true ) );
		}
		try {
			$json = MultisiteJson::encode( 'network' === ( $state['mode'] ?? '' ), (array) ( $state['networks'] ?? [] ), $sites, LW_MIGRATION_VERSION, (array) ( $state['plugins'] ?? [] ), (array) ( $state['admins'] ?? [] ) );
		} catch ( \RuntimeException $e ) {
			throw new ExportException( __( 'The list of the network\'s sites cannot be read. Start the backup again.', 'lw-migration' ), 0, $e );
		}
		if ( strlen( $json ) > self::MULTISITE_MAX_BYTES ) {
			throw new ExportException( __( 'This network has too many sites for one archive: choose sites.', 'lw-migration' ) );
		}

		return $json;
	}

	private static function read( string $path ): string {
		return is_file( $path ) && is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}
}
