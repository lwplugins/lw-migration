<?php
/**
 * Sends a push's archive to the other site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link\Steps;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Link\Client;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the finished archive to the other site: the first run starts the
 * restore there (POST /link/push, idempotent by this job's id), then the
 * archive goes in signed raw parts until the deadline. The other site's
 * idea of how much arrived wins (409 with data.received); a part too large
 * for its web server (413) is halved, never below MIN_CHUNK; "not waiting
 * for an upload" is checked with a GET (a pending import is ticked first,
 * review C2), never taken for "everything arrived". The parts are spaced at
 * least MIN_INTERVAL apart, so the signed requests stay well below the
 * other site's replay cache (Link\Nonces::MAX per signature window, shared
 * by all its links); a busy answer is a passing failure that waits (Retry).
 */
final class PushUploadStep implements StepInterface {

	public const KEY = 'link_upload';

	public const MAX_CHUNK = 8388608;

	public const MIN_CHUNK = 262144;

	/** Seconds between the starts of two parts: at most 5 signed requests a second. */
	public const MIN_INTERVAL = 0.2;

	private const MAX_PAUSE = 10;

	/**
	 * Pause: fn( float $seconds ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $pause;

	/**
	 * @param JobStore      $store   Job store.
	 * @param LinkClients   $clients Clients of the outgoing links.
	 * @param \Closure      $now     fn(): int.
	 * @param \Closure|null $pause   fn( float $seconds ): void (default usleep).
	 */
	public function __construct( private JobStore $store, private LinkClients $clients, private \Closure $now, ?\Closure $pause = null ) {
		$this->pause = $pause ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
		};
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$client  = $this->clients->forJob( $job );
		$archive = WorkDir::path( $this->store, $job ) . '/' . ExportFiles::ARCHIVE;
		$size    = is_file( $archive ) ? (int) filesize( $archive ) : 0;
		if ( $size < 2 * Format::HEADER_SIZE ) {
			throw new ExportException( __( 'The archive to send is missing from the job folder. Start the push again.', 'lw-migration' ) );
		}
		$start = (int) ( $job->cursor['offset'] ?? 0 );
		try {
			$remote = $this->remote( $job, $client, $size );
			$offset = $this->send( $job, $client, $archive, $remote, $size, $deadline );
			Retry::clear( $job );
		} catch ( LinkException $e ) {
			$offset = (int) ( $job->cursor['offset'] ?? 0 );
			if ( $offset > $start ) {
				Retry::clear( $job ); // Parts arrived in this tick: the failures in a row start again.
			}
			$count = Retry::note( $job, $e, (int) ( $this->now )() );
			Retry::wait( $this->pause, $deadline, (float) min( self::MAX_PAUSE, 2 * $count ) );
		}
		if ( $offset < $size ) {
			return StepResult::more(
				(int) floor( 100 * $offset / $size ),
				/* translators: 1: the other site's name, 2: bytes sent, 3: archive size */
				sprintf( __( 'Sending to %1$s: %2$s of %3$s…', 'lw-migration' ), RemoteJobStep::peer( $job ), size_format( $offset ), size_format( $size ) )
			);
		}

		/* translators: %s: the other site's name */
		return StepResult::done( sprintf( __( 'Sent to %s.', 'lw-migration' ), RemoteJobStep::peer( $job ) ) );
	}

	/**
	 * The other site's job: started on the first run, its id kept after.
	 *
	 * @param Job    $job    Job.
	 * @param Client $client The link's client.
	 * @param int    $size   Archive size.
	 * @throws LinkException On a failed start.
	 */
	private function remote( Job $job, Client $client, int $size ): string {
		$id = (string) ( $job->state['remote']['job'] ?? '' );
		if ( '' !== $id ) {
			return $id;
		}
		$view = $client->json(
			'POST',
			'/push',
			[
				'ref'          => $job->id,
				'name'         => BackupName::forSite( Site::fromArray( (array) ( $job->state['site'] ?? [] ) )->homeUrl, $job->createdAt, 0 ),
				'size'         => $size,
				'safetyBackup' => false !== ( $job->state['remote']['safetyBackup'] ?? true ),
			]
		)['json'];
		$id   = is_string( $view['id'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{16}\z/', $view['id'] ) ? $view['id'] : '';
		if ( '' === $id ) {
			throw new LinkException( LinkException::PROTOCOL, __( 'The other site did not start the restore.', 'lw-migration' ) );
		}
		$input                       = is_array( $view['input'] ?? null ) ? $view['input'] : [];
		$chunk                       = is_int( $input['chunkBytes'] ?? null ) && $input['chunkBytes'] > 0 ? $input['chunkBytes'] : self::MAX_CHUNK;
		$job->state['remote']['job'] = $id;
		$job->cursor['chunk']        = max( self::MIN_CHUNK, min( self::MAX_CHUNK, $chunk ) );
		$job->cursor['offset']       = is_int( $input['received'] ?? null ) ? max( 0, min( $size, $input['received'] ) ) : 0;

		return $id;
	}

	/**
	 * @param Job      $job      Job.
	 * @param Client   $client   The link's client.
	 * @param string   $archive  Archive path.
	 * @param string   $remote   The other site's job id.
	 * @param int      $size     Archive size.
	 * @param Deadline $deadline Deadline.
	 * @return int Bytes the other site has.
	 * @throws ExportException When the archive cannot be opened (adjust() rethrows a LinkException other
	 *                         than a resumable offset, a smaller part or a finished upload).
	 */
	private function send( Job $job, Client $client, string $archive, string $remote, int $size, Deadline $deadline ): int {
		$offset = (int) ( $job->cursor['offset'] ?? 0 );
		$chunk  = (int) ( $job->cursor['chunk'] ?? self::MAX_CHUNK );
		$in     = fopen( $archive, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming our own archive in parts.
		if ( false === $in ) {
			throw new ExportException( __( 'The archive to send cannot be read.', 'lw-migration' ) );
		}
		try {
			while ( $offset < $size ) {
				$started = microtime( true );
				$bytes   = (string) stream_get_contents( $in, min( $chunk, $size - $offset ), $offset );
				try {
					$client->raw( 'POST', '/jobs/' . $remote . '/upload/' . $offset, $bytes );
					$offset += strlen( $bytes );
				} catch ( LinkException $e ) {
					[ $offset, $chunk ] = self::adjust( $e, $client, $remote, $offset, $chunk, $size );
				}
				$job->cursor['offset'] = $offset;
				$job->cursor['chunk']  = $chunk;
				if ( $offset >= $size || $deadline->reached() ) {
					break;
				}
				Retry::wait( $this->pause, $deadline, self::MIN_INTERVAL - ( microtime( true ) - $started ) );
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
		}

		return $offset;
	}

	/**
	 * @param LinkException $e      The failed part.
	 * @param Client        $client The link's client.
	 * @param string        $remote The other site's job id.
	 * @param int           $offset Where the part started.
	 * @param int           $chunk  Part size.
	 * @param int           $size   Archive size.
	 * @return array{0: int, 1: int} Offset and part size to go on with.
	 * @throws LinkException When the failure is none of these.
	 */
	private static function adjust( LinkException $e, Client $client, string $remote, int $offset, int $chunk, int $size ): array {
		if ( LinkException::PROTOCOL === $e->reason && $e->status >= 200 && $e->status < 300 ) {
			// An empty or garbled answer to a part: tried again (the offset protocol finds out what arrived).
			throw new LinkException( LinkException::UNREACHABLE, $e->getMessage(), $e->status );
		}
		$received = $e->data['received'] ?? null;
		if ( 'lw_migration_upload_offset' === $e->remoteCode && $received === $offset ) {
			throw new LinkException( LinkException::PROTOCOL, __( 'The other site refuses the archive at the very place it names. Update LW Migration on both sites.', 'lw-migration' ), $e->status );
		}
		if ( 'lw_migration_upload_offset' === $e->remoteCode && is_int( $received ) && $received >= 0 && $received <= $size ) {
			return [ $received, $chunk ];
		}
		if ( 'lw_migration_not_uploading' === $e->remoteCode ) {
			return [ self::uploadState( $client, $remote, $size ), $chunk ]; // Never assumed complete (review C2).
		}
		if ( 413 === $e->status && $chunk > self::MIN_CHUNK ) {
			$limit = is_int( $e->data['chunkBytes'] ?? null ) ? $e->data['chunkBytes'] : $chunk;

			return [ $offset, max( self::MIN_CHUNK, min( intdiv( $chunk, 2 ), $limit ) ) ];
		}
		throw $e;
	}

	/**
	 * Where the other site's import stands when it says it does not wait for
	 * an upload: still pending → tick it once and read again; waiting → its
	 * received bytes; anything else → the upload is over (it has every byte,
	 * or it ended — link_import sees which).
	 *
	 * @param Client $client The link's client.
	 * @param string $remote The other site's job id.
	 * @param int    $size   Archive size.
	 * @throws LinkException On a failed call.
	 */
	private static function uploadState( Client $client, string $remote, int $size ): int {
		$view = $client->json( 'GET', '/jobs/' . $remote )['json'];
		if ( Job::PENDING === ( $view['status'] ?? null ) ) {
			$view = $client->json( 'POST', '/jobs/' . $remote . '/tick', null, Client::TICK_TIMEOUT )['json'];
		}
		$input = is_array( $view['input'] ?? null ) ? $view['input'] : [];
		if ( Job::NEEDS_INPUT === ( $view['status'] ?? null ) && 'upload' === ( $input['kind'] ?? null ) && is_int( $input['received'] ?? null ) ) {
			return max( 0, min( $size, $input['received'] ) );
		}

		return $size;
	}
}
