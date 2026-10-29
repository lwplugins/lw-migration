<?php
/**
 * Creates, describes and removes the subsites a restore needs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The only live write of an import before the swap (decision 18 / spec 6):
 * empty subsites, recorded in state.createdSites, removed again when the
 * import fails, is cancelled or abandoned while it cannot have swapped
 * (ruling R2: ImportCleanup::maySwapped(), never state.swapped alone).
 * state.creating {index, domain, path, maxIdBefore} is saved before a site is
 * created; a site found at that address with an id above maxIdBefore was
 * created by this import (ruling R3). The main site and id 1 are never
 * deleted. Called in the main site's context; describe() switches blogs and
 * always switches back. Not final: step tests replace it with a recording fake.
 */
class SiteFactory {

	/** State key: ids of the sites this import created. */
	public const CREATED = 'createdSites';

	/** State key: the create in progress. */
	public const CREATING = 'creating';

	/**
	 * @param string $domain    Domain as wp_blogs holds it (with the port).
	 * @param string $path      Path with slashes on both ends.
	 * @param int    $networkId Network id.
	 * @return int|null Id of the site at that address.
	 */
	public function find( string $domain, string $path, int $networkId ): ?int {
		return TargetNetworkReader::siteAt( $domain, $path, $networkId );
	}

	/** The largest site id of the install now (0 when there is none). */
	public function maxId(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must read the live table, not a cache; no value in the query.
		return (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" );
	}

	/**
	 * @param string        $domain    Checked domain as wp_blogs holds it (SiteAddress::check()).
	 * @param string        $path      Checked path with slashes on both ends.
	 * @param int           $networkId Network id.
	 * @param string        $title     Site title (the swap replaces it with the archive's).
	 * @param int           $userId   The site's administrator (0: the first super admin).
	 * @param \Closure|null $inserted fn( int $id ): void, run right after the row is inserted, before WordPress sets the site up.
	 * @return int The new site's id.
	 * @throws SiteNotCreatedException When WordPress refuses the site before its row exists.
	 * @throws ImportException When it fails after that (the row may exist).
	 */
	public function create( string $domain, string $path, int $networkId, string $title, int $userId, ?\Closure $inserted = null ): int {
		$report = static function ( $site ) use ( $inserted ): void {
			if ( null !== $inserted && is_object( $site ) && isset( $site->id ) ) {
				$inserted( (int) $site->id );
			}
		};
		add_action( 'wp_insert_site', $report, 1 );
		try {
			$id = wp_insert_site(
				[
					'domain'     => $domain,
					'path'       => $path,
					'network_id' => $networkId,
					'title'      => $title,
					'user_id'    => $userId > 0 ? $userId : self::firstSuperAdmin(),
				]
			);
		} finally {
			remove_action( 'wp_insert_site', $report, 1 );
		}
		if ( is_wp_error( $id ) ) {
			/* translators: 1: the new site's address, 2: the reason WordPress gave */
			$message = sprintf( __( 'The new site %1$s could not be created: %2$s', 'lw-migration' ), $domain . rtrim( $path, '/' ), $id->get_error_message() );
			// Only get_site_error comes after the row was inserted.
			throw 'get_site_error' === $id->get_error_code() ? new ImportException( $message ) : new SiteNotCreatedException( $message );
		}

		return (int) $id;
	}

	/**
	 * Sets up a site whose create was interrupted after its row was inserted (review I2).
	 *
	 * @param int    $id     Site id.
	 * @param string $title  Site title.
	 * @param int    $userId The site's administrator (0: the first super admin).
	 * @throws ImportException When WordPress cannot set it up.
	 */
	public function finishSetup( int $id, string $title, int $userId ): void {
		if ( wp_is_site_initialized( $id ) ) {
			return;
		}
		$done = wp_initialize_site(
			$id,
			[
				'title'   => $title,
				'user_id' => $userId > 0 ? $userId : self::firstSuperAdmin(),
			]
		);
		if ( is_wp_error( $done ) ) {
			/* translators: 1: site id, 2: the reason WordPress gave */
			throw new ImportException( sprintf( __( 'The new site %1$d could not be set up: %2$s', 'lw-migration' ), $id, $done->get_error_message() ) );
		}
	}

	/**
	 * The site's addresses and uploads, as its own options and WordPress give them.
	 *
	 * @param int $id Site id.
	 * @return array{siteUrl: string, homeUrl: string, uploadsDir: string, uploadsUrl: string, uploadPath: string, uploadUrlPath: string}
	 * @throws ImportException When the site has no address (its setup never finished).
	 */
	public function describe( int $id ): array {
		switch_to_blog( $id );
		try {
			$uploads = wp_upload_dir( null, false );
			if ( '' === (string) get_option( 'siteurl' ) || '' === (string) get_option( 'home' ) ) {
				/* translators: %d: site id */
				throw new ImportException( sprintf( __( 'The site %d is not set up completely (it has no address), so the restore stopped. A site this restore created is removed again; start the restore again.', 'lw-migration' ), $id ) );
			}

			return [
				'siteUrl'       => untrailingslashit( (string) get_option( 'siteurl' ) ),
				'homeUrl'       => untrailingslashit( (string) get_option( 'home' ) ),
				'uploadsDir'    => untrailingslashit( (string) $uploads['basedir'] ),
				'uploadsUrl'    => trailingslashit( (string) $uploads['baseurl'] ),
				'uploadPath'    => (string) get_option( 'upload_path' ),
				'uploadUrlPath' => (string) get_option( 'upload_url_path' ),
			];
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * @param int $id Site id (never the main site or id 1).
	 * @return bool Whether WordPress deleted it.
	 */
	public function delete( int $id ): bool {
		if ( $id <= 1 || (int) get_main_site_id() === $id ) {
			return false;
		}

		return ! is_wp_error( wp_delete_site( $id ) );
	}

	/**
	 * Ruling R3: the site at the address an interrupted create named, when it
	 * is newer than every site that existed before the create.
	 *
	 * @param array<mixed> $creating  state.creating.
	 * @param int          $networkId Network id.
	 */
	public function createdAt( array $creating, int $networkId ): ?int {
		$before = $creating['maxIdBefore'] ?? null;
		$id     = $this->find( (string) ( $creating['domain'] ?? '' ), (string) ( $creating['path'] ?? '' ), $networkId );

		return is_int( $before ) && null !== $id && $id > $before ? $id : null;
	}

	/**
	 * Removes the sites a failed, cancelled or abandoned import created, while
	 * it cannot have swapped (rulings R2, R4). An interrupted create is
	 * reconciled first. The ids that could not be removed stay recorded.
	 *
	 * @param Job    $job Import job on a network.
	 * @param string $dir The job's work folder (the swap marker).
	 * @return array<int, string> Warnings for the job.
	 */
	public function undoCreated( Job $job, string $dir ): array {
		$ids      = self::created( $job );
		$creating = $job->state[ self::CREATING ] ?? null;
		if ( ( [] === $ids && ! is_array( $creating ) ) || ImportCleanup::maySwapped( $job, $dir ) ) {
			return [];
		}
		if ( is_array( $creating ) ) {
			$id = $this->createdAt( $creating, ImportState::target( $job )->networkId );
			if ( null !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
			unset( $job->state[ self::CREATING ] );
		}
		$warnings = [];
		$left     = [];
		foreach ( $ids as $id ) {
			try {
				$deleted = $this->delete( $id );
			} catch ( \Throwable $e ) {
				$deleted = false; // The next sites still go; this one stays recorded.
			}
			if ( $deleted ) {
				self::removeEmptyUploads( $job, $id );
			} else {
				$left[] = $id;
				/* translators: %s: address of a site the failed restore created */
				$warnings[] = sprintf( __( 'The new site %s could not be removed: delete it under Network Admin > Sites.', 'lw-migration' ), self::address( $job, $id ) );
			}
		}
		$job->state[ self::CREATED ] = $left;
		if ( count( $left ) < count( $ids ) ) {
			array_unshift( $warnings, __( 'The new sites this restore had created were removed again.', 'lw-migration' ) );
		}

		return $warnings;
	}

	/**
	 * @param Job $job Import job.
	 * @return array<int, int> Ids in state.createdSites (whole numbers above 1 only).
	 */
	public static function created( Job $job ): array {
		$ids = array_filter( (array) ( $job->state[ self::CREATED ] ?? [] ), static fn( $id ): bool => is_int( $id ) && $id > 1 );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * WordPress empties a deleted site's uploads folder but keeps the folder
	 * itself; the folder of a site this import created goes too when nothing
	 * is left in it (rmdir() never removes a folder with content).
	 *
	 * @param Job $job Import job.
	 * @param int $id  Id of the removed site.
	 */
	private static function removeEmptyUploads( Job $job, int $id ): void {
		foreach ( (array) ( $job->state['blogs'] ?? [] ) as $map ) {
			$dir = is_array( $map ) && ( $map['newId'] ?? null ) === $id ? (string) ( $map['newUploadsDir'] ?? '' ) : '';
			if ( 1 === preg_match( '#/(?:sites/' . $id . '|blogs\.dir/' . $id . '/files)\z#', $dir ) && is_dir( $dir ) ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only an empty folder goes; a folder with content stays without a PHP warning.
			}
		}
	}

	/**
	 * @param Job $job Import job.
	 * @param int $id  Site id.
	 * @return string The planned address of that site, or its id.
	 */
	private static function address( Job $job, int $id ): string {
		foreach ( (array) ( $job->state['blogs'] ?? [] ) as $map ) {
			if ( is_array( $map ) && ( $map['newId'] ?? null ) === $id && is_string( $map['url'] ?? null ) ) {
				return $map['url'];
			}
		}

		/* translators: %d: site id */
		return sprintf( __( 'ID %d', 'lw-migration' ), $id );
	}

	/** The user id of the first super admin (0 when none is found). */
	private static function firstSuperAdmin(): int {
		foreach ( (array) get_super_admins() as $login ) {
			$user = get_user_by( 'login', (string) $login );
			if ( false !== $user ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}
}
