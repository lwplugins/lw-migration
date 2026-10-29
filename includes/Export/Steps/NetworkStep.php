<?php
/**
 * Export step: lists the sites of a network backup.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Pages through every blog id in slices (a network may hold thousands of
 * sites) and appends one multisite.json Sites[] entry per exported site to a
 * work file. The file is cut back to the saved length at every start, so a
 * request that died after writing never leaves a line twice; the cursor goes
 * into the job only after the run returned normally. When every id is read,
 * state.network holds what the later steps need; a site deleted while it was
 * listed is left out with a warning. After each slice the sites' cached
 * options are let go (NetworkReader::releaseMemory()). This plugin (the snapshot's
 * ownPlugin) is never listed among a site's plugins.
 */
final class NetworkStep implements StepInterface {

	/** Blog ids read per slice. */
	public const SLICE = 50;

	/**
	 * @param JobStore      $store  Job store.
	 * @param NetworkReader $reader The network (read only).
	 */
	public function __construct( private JobStore $store, private NetworkReader $reader ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$options = ExportOptions::fromArray( $job->options );
		$own     = (string) ( ( (array) ( $job->state['site'] ?? [] ) )['ownPlugin'] ?? '' );
		$cursor  = self::cursor( $job->cursor );
		$handle  = self::open( WorkDir::path( $this->store, $job ) . '/' . ExportFiles::NETWORK_SITES, $cursor['bytes'] );
		try {
			do {
				$ids = $this->reader->ids( $cursor['after'], self::SLICE );
				foreach ( $ids as $id ) {
					$cursor['blogIds'][] = $id;
					if ( [] === $options->sites || in_array( $id, $options->sites, true ) ) {
						$this->append( $handle, $id, $own, $cursor );
					}
				}
				$cursor['after'] = [] === $ids ? $cursor['after'] : (int) end( $ids );
				$cursor['bytes'] = self::flush( $handle );
				$done            = count( $ids ) < self::SLICE;
				$this->reader->releaseMemory();
			} while ( ! $done && ! $deadline->reached() );
		} finally {
			fclose( $handle );
		}
		$job->cursor = $cursor;
		if ( ! $done ) {
			/* translators: %d: number of sites read so far */
			return StepResult::more( 0, sprintf( __( 'Listing the network\'s sites: %d so far...', 'lw-migration' ), count( $cursor['blogIds'] ) ) );
		}
		$job->state['network'] = $this->snapshot( $job, $options, $own, $cursor );

		return StepResult::done( __( 'Network sites listed.', 'lw-migration' ) );
	}

	/**
	 * Reads one site and appends its entry; a site deleted meanwhile is left out.
	 *
	 * @param resource             $handle Work file.
	 * @param int                  $id     Blog id.
	 * @param string               $own    This plugin's basename.
	 * @param array<string, mixed> $cursor Progress (receives the site and its plugins and themes).
	 * @throws ExportException When a value of the site is not valid UTF-8 or the write fails.
	 */
	private function append( $handle, int $id, string $own, array &$cursor ): void {
		try {
			$entry = $this->reader->entry( $id, $own );
		} catch ( \RuntimeException $e ) {
			$cursor['gone'][] = $id;
			return;
		}
		$line = json_encode( $entry->toArray() );
		if ( false === $line ) {
			/* translators: %d: site ID */
			throw new ExportException( sprintf( __( 'Site %d has a setting that is not valid UTF-8 text, so the network cannot be backed up.', 'lw-migration' ), $id ) );
		}
		if ( strlen( $line ) + 1 !== fwrite( $handle, $line . "\n" ) ) {
			throw new ExportException( __( 'Cannot write the list of the network\'s sites. The disk may be full.', 'lw-migration' ) );
		}
		$cursor['written'][] = $id;
		$cursor['plugins']   = self::union( $cursor['plugins'], $entry->plugins );
		$cursor['themes']    = self::union( $cursor['themes'], [ $entry->template, $entry->stylesheet ] );
	}

	/**
	 * @param Job                  $job     Export job (receives warnings).
	 * @param ExportOptions        $options Export options.
	 * @param string               $own     This plugin's basename.
	 * @param array<string, mixed> $cursor  Finished progress.
	 * @return array<string, mixed> state.network.
	 * @throws ExportException When none of the chosen sites exists.
	 */
	private function snapshot( Job $job, ExportOptions $options, string $own, array $cursor ): array {
		$chosen  = array_values( array_intersect( $options->sites, $cursor['written'] ) );
		$missing = [] === $options->sites ? $cursor['gone'] : array_diff( $options->sites, $cursor['written'] );
		foreach ( $missing as $gone ) {
			/* translators: %d: site ID */
			$job->addWarning( sprintf( __( 'Site %d no longer exists and was left out.', 'lw-migration' ), $gone ) );
		}
		if ( [] !== $options->sites && [] === $chosen ) {
			throw new ExportException( __( 'None of the chosen sites exist.', 'lw-migration' ) );
		}
		$sitewide = $this->reader->sitewidePlugins( $own );

		return [
			'mode'          => [] === $options->sites ? 'network' : 'sites',
			'chosen'        => $chosen,
			'blogIds'       => array_values( array_diff( $cursor['blogIds'], $cursor['gone'] ) ),
			'networks'      => $this->reader->networks(),
			'plugins'       => $sitewide,
			'admins'        => $this->reader->superAdmins(),
			'activePlugins' => self::union( $sitewide, $cursor['plugins'] ),
			'activeThemes'  => $cursor['themes'],
		];
	}

	/**
	 * @param array<string, mixed> $saved Job cursor.
	 * @return array{after: int, bytes: int, blogIds: array<int, int>, written: array<int, int>, gone: array<int, int>, plugins: array<int, string>, themes: array<int, string>}
	 */
	private static function cursor( array $saved ): array {
		return [
			'after'   => (int) ( $saved['after'] ?? 0 ),
			'bytes'   => (int) ( $saved['bytes'] ?? 0 ),
			'blogIds' => array_map( 'intval', (array) ( $saved['blogIds'] ?? [] ) ),
			'written' => array_map( 'intval', (array) ( $saved['written'] ?? [] ) ),
			'gone'    => array_map( 'intval', (array) ( $saved['gone'] ?? [] ) ),
			'plugins' => array_map( 'strval', (array) ( $saved['plugins'] ?? [] ) ),
			'themes'  => array_map( 'strval', (array) ( $saved['themes'] ?? [] ) ),
		];
	}

	/**
	 * @param array<int, string> $list  Names so far.
	 * @param array<int, string> $names Names to add.
	 * @return array<int, string> In first-seen order, each once, without ''.
	 */
	private static function union( array $list, array $names ): array {
		return array_values( array_unique( array_filter( array_merge( $list, $names ), static fn( string $n ): bool => '' !== $n ) ) );
	}

	/**
	 * @param string $path   Work file.
	 * @param int    $length Saved length (0 on the first run).
	 * @return resource Positioned at $length, everything after it cut off.
	 * @throws ExportException When it cannot be opened or is shorter than $length.
	 */
	private static function open( string $path, int $length ) {
		Files::privateFile( $path );
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new ExportException( __( 'Cannot write the list of the network\'s sites. The disk may be full.', 'lw-migration' ) );
		}
		$stat = fstat( $handle );
		if ( false === $stat || $stat['size'] < $length || ! ftruncate( $handle, $length ) || 0 !== fseek( $handle, $length ) ) {
			fclose( $handle );
			throw new ExportException( __( 'The list of the network\'s sites does not match the saved progress. Start the backup again.', 'lw-migration' ) );
		}

		return $handle;
	}

	/**
	 * @param resource $handle Work file.
	 * @return int Length after flushing.
	 * @throws ExportException When the flush fails.
	 */
	private static function flush( $handle ): int {
		if ( ! fflush( $handle ) ) {
			throw new ExportException( __( 'Cannot write the list of the network\'s sites. The disk may be full.', 'lw-migration' ) );
		}

		return (int) ftell( $handle );
	}
}
