<?php
/**
 * Import step: the subsites a sites or single-site archive is restored into.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteAddress;
use LightweightPlugins\Migration\Multisite\SiteFactory;
use LightweightPlugins\Migration\Multisite\SiteNotCreatedException;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * After the safety backup, before any file (ruling R7): each confirmed
 * address gets its site, one per unit of work. An existing subsite there is
 * reused (the swap replaces its tables); otherwise an empty one is created.
 * The create is recorded before and after wp_insert_site (SiteFactory), so a
 * replayed step never creates a second site. The main site is never a target
 * (decision 13). Once every site is ready, what the network's plugins folder
 * and theme root hold is listed (state.networkCode, SharedCode). A network
 * archive keeps its ids: nothing to do.
 */
final class NetworkSitesStep implements StepInterface {

	public const KEY = 'network_sites';

	/** State key of the SharedCode snapshot. */
	public const CODE = 'networkCode';

	/**
	 * @param JobStore    $store Job store (the create is saved around wp_insert_site).
	 * @param SiteFactory $sites The network's sites.
	 * @param \Closure    $code  fn( Job $job ): array, SharedCode::snapshot() of the network's plugins folder and theme root.
	 */
	public function __construct( private JobStore $store, private SiteFactory $sites, private \Closure $code ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ArchiveKind::NETWORK === ImportState::kind( $job ) ) {
			return StepResult::done();
		}
		$to  = ImportState::target( $job );
		$map = ImportState::blogMap( $job );
		for ( $i = self::next( $map ); null !== $i; $i = self::next( $map ) ) {
			$map  = $this->resolve( $job, $map, $i, $to );
			$next = self::next( $map );
			if ( null !== $next && $deadline->reached() ) {
				/* translators: 1: number of the site being prepared, 2: number of sites in the archive */
				return StepResult::more( (int) ( 100 * $next / $map->count() ), sprintf( __( 'Preparing site %1$d of %2$d...', 'lw-migration' ), $next + 1, $map->count() ) );
			}
		}
		if ( ! isset( $job->state[ self::CODE ] ) ) {
			$job->state[ self::CODE ] = ( $this->code )( $job );
		}

		return StepResult::done( __( 'The sites of the archive are ready.', 'lw-migration' ) );
	}

	/**
	 * Finds, or creates, the site of mapping $i and puts it into the map (and the job).
	 *
	 * @param Job           $job Import job.
	 * @param BlogMap       $map The blog map.
	 * @param int           $i   Index of the first unresolved mapping.
	 * @param TargetNetwork $to  Target network.
	 * @throws ImportException For an address that is not usable, or the main site.
	 */
	private function resolve( Job $job, BlogMap $map, int $i, TargetNetwork $to ): BlogMap {
		try {
			$address = SiteAddress::check( (string) $map->get( $i )['url'], $to );
		} catch ( \InvalidArgumentException $e ) {
			throw new ImportException( $e->getMessage(), 0, $e );
		}
		$creating = $job->state[ SiteFactory::CREATING ] ?? null;
		$replayed = is_array( $creating ) && ( $creating['index'] ?? null ) === $i ? $this->sites->createdAt( $creating, $to->networkId ) : null;
		$id       = $replayed ?? $this->sites->find( $address['domain'], $address['path'], $to->networkId );
		if ( null !== $id && ( $id <= 1 || $id === $to->mainSiteId ) ) {
			throw new ImportException( SiteAddress::mainSiteRefusal() );
		}
		$new = null !== $replayed || null === $id || in_array( $id, SiteFactory::created( $job ), true );
		if ( null === $id ) {
			$id = $this->create( $job, $i, $address, $to );
		} elseif ( $new ) {
			// A replay after the create: the request may have died while WordPress set the site up.
			$this->record( $job, $id );
			$this->sites->finishSetup( $id, self::title( $address ), self::adminId( $job ) );
		}
		unset( $job->state[ SiteFactory::CREATING ] );
		$site = $this->sites->describe( $id );
		$map  = $map->resolved(
			$i,
			[
				'newId'            => $id,
				'created'          => $new,
				'newSiteUrl'       => $site['siteUrl'],
				'newHomeUrl'       => $site['homeUrl'],
				'newUploadsDir'    => $site['uploadsDir'],
				'newUploadsUrl'    => $site['uploadsUrl'],
				'newUploadPath'    => $site['uploadPath'],
				'newUploadUrlPath' => $site['uploadUrlPath'],
			]
		);

		$job->state['blogs'] = $map->toArray();

		return $map;
	}

	/**
	 * Saves the intent, creates the site, saves its id from the insert action
	 * and again when done; a refusal before the row exists forgets the intent.
	 *
	 * @param Job                                              $job     Import job.
	 * @param int                                              $i       Index of the mapping.
	 * @param array{domain: string, path: string, url: string} $address Checked address.
	 * @param TargetNetwork                                    $to      Target network.
	 * @throws SiteNotCreatedException When WordPress refused the site before its row existed.
	 */
	private function create( Job $job, int $i, array $address, TargetNetwork $to ): int {
		$job->state[ SiteFactory::CREATING ] = [
			'index'       => $i,
			'domain'      => $address['domain'],
			'path'        => $address['path'],
			'maxIdBefore' => $this->sites->maxId(),
		];
		$this->store->save( $job );
		$inserted = function ( int $id ) use ( $job ): void {
			$this->record( $job, $id );
			$this->store->save( $job ); // Right after the row insert, before WordPress sets the site up.
		};
		try {
			$id = $this->sites->create( $address['domain'], $address['path'], $to->networkId, self::title( $address ), self::adminId( $job ), $inserted );
		} catch ( SiteNotCreatedException $e ) {
			unset( $job->state[ SiteFactory::CREATING ] ); // Nothing was made: a later site at the address is not ours.
			$this->store->save( $job );
			throw $e;
		}
		$this->record( $job, $id );
		unset( $job->state[ SiteFactory::CREATING ] );
		$this->store->save( $job );

		return $id;
	}

	private function record( Job $job, int $id ): void {
		if ( ! in_array( $id, SiteFactory::created( $job ), true ) ) {
			$job->state[ SiteFactory::CREATED ] = array_merge( SiteFactory::created( $job ), [ $id ] );
		}
	}

	/**
	 * @param array{domain: string, path: string, url: string} $address Checked address.
	 */
	private static function title( array $address ): string {
		return $address['domain'] . rtrim( $address['path'], '/' );
	}

	private static function adminId( Job $job ): int {
		$admin = $job->state['admin'] ?? null;

		return is_array( $admin ) ? (int) ( $admin['id'] ?? 0 ) : 0;
	}

	private static function next( BlogMap $map ): ?int {
		foreach ( $map->all() as $i => $m ) {
			if ( null === $m['newId'] ) {
				return $i;
			}
		}

		return null;
	}
}
