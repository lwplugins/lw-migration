<?php
/**
 * Downloads the other site's archive of a pull.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link\Steps;

use LightweightPlugins\Migration\Archive\ArchiveDigest;
use LightweightPlugins\Migration\Http\DownloadException;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\Steps\UrlDownloadStep;
use LightweightPlugins\Migration\Import\Steps\UrlResolveStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Link\Client;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\LinkPipeline;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads the other site's archive into this job's folder with the
 * resumable range download of the URL import (UrlDownloadStep: parts,
 * retries, the disk-space checks, state.archive), every request signed
 * afresh (Client::signerFor), spaced (paced()) and never redirected; a failed part waits or
 * explains itself like every other call of a connection (review I2); then
 * checks the file against the SHA-256 of the other site's signed job view
 * (issue #1, resumable over ticks: check()) and releases the other site's
 * job, so its copy of the archive goes at once (best effort: its daily
 * clean-up removes it otherwise).
 */
final class PullDownloadStep implements StepInterface {

	public const KEY = 'link_download';

	private const MAX_PAUSE = 10;

	/**
	 * Pause: fn( float $seconds ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $pause;

	/**
	 * @param JobStore      $store     Job store.
	 * @param LinkClients   $clients   Clients of the connections.
	 * @param \Closure      $freeSpace fn( string $dir ): ?int.
	 * @param \Closure      $now       fn(): int.
	 * @param \Closure|null $pause     fn( float $seconds ): void (default usleep).
	 * @param int           $chunk     Bytes per part.
	 */
	public function __construct( private JobStore $store, private LinkClients $clients, private \Closure $freeSpace, private \Closure $now, ?\Closure $pause = null, private int $chunk = RangeDownloader::CHUNK ) {
		$this->pause = $pause ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
		};
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$client = $this->clients->forJob( $job );
		$remote = (string) ( $job->state['remote']['job'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{16}\z/', $remote ) ) {
			throw new LinkException( LinkException::PROTOCOL, __( 'The other site finished without an archive to download.', 'lw-migration' ) );
		}
		if ( ! isset( $job->cursor['sha256'] ) ) {
			$result = $this->download( $job, $client, $remote, $deadline );
			if ( ! $result->isDone() ) {
				return $result;
			}
			$job->cursor['sha256'] = []; // From here the downloaded file is only checked.
			if ( $deadline->reached() ) {
				return StepResult::more( 100, self::checking() );
			}
		}

		return $this->check( $job, $client, $remote, $deadline );
	}

	/**
	 * @param Job      $job      Job.
	 * @param Client   $client   The link's client.
	 * @param string   $remote   The other site's job id.
	 * @param Deadline $deadline The tick's deadline.
	 * @throws LinkException|ImportException A lasting failure.
	 */
	private function download( Job $job, Client $client, string $remote, Deadline $deadline ): StepResult {
		$path = '/jobs/' . $remote . '/archive';
		// The address always matches the signed route (a signature belongs to its address).
		$job->state['download']['url'] = $client->urlOf( $path );
		$sleep                         = fn( int $seconds ) => Retry::wait( $this->pause, $deadline, (float) $seconds );
		$http                          = new RangeDownloader( $this->chunk, 3, 60, $sleep, $this->paced( $client->signerFor( $path ), $deadline ) );
		$step                          = new UrlDownloadStep( $this->store, $http, $this->freeSpace, static fn( DownloadException $e ) => self::explain( $e, $client, $remote, RemoteJobStep::peer( $job ) ) );
		$before                        = (int) ( $job->cursor['offset'] ?? 0 );
		try {
			$result = $step->run( $job, $deadline );
			Retry::clear( $job );
		} catch ( LinkException $e ) {
			if ( (int) ( $job->cursor['offset'] ?? 0 ) > $before ) {
				Retry::clear( $job ); // Parts arrived in this tick: the other site is not stuck.
			}
			try {
				$count = Retry::note( $job, $e, (int) ( $this->now )() ); // Throws a lasting failure, or when patience is spent.
			} catch ( LinkException $final ) {
				throw self::named( $final, $job );
			}
			Retry::wait( $this->pause, $deadline, (float) min( self::MAX_PAUSE, 2 * $count ) );

			/* translators: 1: the other site's name, 2: why the download waits */
			return StepResult::more( (int) $job->progress, sprintf( __( 'Waiting for %1$s: %2$s', 'lw-migration' ), RemoteJobStep::peer( $job ), $e->getMessage() ) );
		}

		return $result;
	}

	/**
	 * Issue #1: the downloaded file must be the archive the other site's
	 * signed job view described (archiveSha256) before any import step
	 * reads it. The SHA-256 goes on from tick to tick (cursor.sha256) within
	 * the deadline; other bytes are deleted and the pull fails; the right
	 * ones release the other site's job.
	 *
	 * @param Job      $job      Job (state.archive, state.download.sha256, cursor.sha256).
	 * @param Client   $client   The link's client.
	 * @param string   $remote   The other site's job id.
	 * @param Deadline $deadline The tick's deadline.
	 * @throws ImportException When the file differs or cannot be read.
	 */
	private function check( Job $job, Client $client, string $remote, Deadline $deadline ): StepResult {
		$archive = (string) ( $job->state['archive'] ?? '' );
		try {
			$progress = ArchiveDigest::advance( $archive, (array) $job->cursor['sha256'], $deadline );
		} catch ( \RuntimeException $e ) {
			$progress = [ 'digest' => '' ]; // Unreadable: never the announced archive.
		}
		if ( null === $progress['digest'] ) {
			$job->cursor['sha256'] = [
				'offset'  => $progress['offset'],
				'context' => $progress['context'],
			];

			return StepResult::more( (int) ( 100 * $progress['offset'] / max( 1, (int) ( $job->state['download']['size'] ?? 0 ) ) ), self::checking() );
		}
		if ( ! ArchiveDigest::same( $job->state['download']['sha256'] ?? null, $progress['digest'] ) ) {
			unset( $job->state['archive'], $job->state['archiveOwned'] );
			Files::removeFile( $archive );

			/* translators: %s: the other site's name */
			throw new ImportException( sprintf( __( 'The archive downloaded from %s is not the one that site prepared: it was changed on its way, so nothing was imported. Use https on both sites and try again.', 'lw-migration' ), RemoteJobStep::peer( $job ) ) );
		}
		$this->release( $job, $client, $remote );

		return StepResult::done( __( 'Archive downloaded.', 'lw-migration' ) );
	}

	private static function checking(): string {
		return __( 'Checking the downloaded archive...', 'lw-migration' );
	}

	/**
	 * The signer, spaced like the parts of a push: the signed requests of
	 * one tick are at least PushUploadStep::MIN_INTERVAL apart (the other
	 * site caps signed requests), never waiting past the tick.
	 *
	 * @param \Closure $sign     fn(): array<string, string>.
	 * @param Deadline $deadline The tick's deadline.
	 */
	private function paced( \Closure $sign, Deadline $deadline ): \Closure {
		$last = null;

		return function () use ( &$last, $sign, $deadline ): array {
			if ( null !== $last ) {
				Retry::wait( $this->pause, $deadline, PushUploadStep::MIN_INTERVAL - ( microtime( true ) - $last ) );
			}
			$last = microtime( true );

			return $sign();
		};
	}

	/**
	 * The archive is here: the other site drops its copy (best effort).
	 *
	 * @param Job    $job    Job.
	 * @param Client $client The link's client.
	 * @param string $remote The other site's job id.
	 */
	private function release( Job $job, Client $client, string $remote ): void {
		try {
			$client->json( 'DELETE', '/jobs/' . $remote, null, LinkPipeline::RELEASE_TIMEOUT );
			$job->state['remote']['released'] = true;
		} catch ( LinkException $e ) {
			$job->addWarning( __( 'The other site keeps its copy of the archive until its daily clean-up.', 'lw-migration' ) );
		}
	}

	/**
	 * Turns a failed part into the connection's own failure (review I2): a
	 * passing one waits like every other call (Retry); a lasting one asks the
	 * other site with a signed GET, so a removed link or a wrong clock reads
	 * as such (Client::answer); otherwise the download's own text stays,
	 * under the other site's name instead of its archive address.
	 *
	 * @param DownloadException $e      The failed part.
	 * @param Client            $client The link's client.
	 * @param string            $remote The other site's job id.
	 * @param string            $peer   The other site's name.
	 * @throws LinkException The connection's failure (passing, refused, clock).
	 * @throws ImportException Otherwise: the download's own text under the other site's name.
	 */
	private static function explain( DownloadException $e, Client $client, string $remote, string $peer ): void {
		if ( $e->isPassing() ) {
			throw new LinkException( LinkException::UNREACHABLE, $e->getMessage() );
		}
		if ( ! $e->isAddressRefused() ) { // This site refusing the address itself: asking again cannot tell more.
			$client->json( 'GET', '/jobs/' . $remote ); // A refusal or a clock problem throws its own LinkException here.
		}

		/* translators: 1: where the download comes from (an address, or a connected site's name), 2: technical reason */
		throw new ImportException( sprintf( __( 'The download from %1$s stopped: %2$s', 'lw-migration' ), $peer, UrlResolveStep::reason( $e ) ) );
	}

	/**
	 * A passing failure that outlasted the patience, under the other site's
	 * name (its waiting text named it too); a lasting one as it is.
	 *
	 * @param LinkException $e   The final failure.
	 * @param Job           $job Job.
	 */
	private static function named( LinkException $e, Job $job ): LinkException {
		if ( ! $e->isPassing() ) {
			return $e;
		}

		/* translators: 1: the other site's name, 2: what went wrong */
		return new LinkException( $e->reason, sprintf( __( '%1$s: %2$s', 'lw-migration' ), RemoteJobStep::peer( $job ), $e->getMessage() ), $e->status, $e->data, $e->remoteCode );
	}
}
