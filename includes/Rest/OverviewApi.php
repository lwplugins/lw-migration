<?php
/**
 * The admin home page's data in one read.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Repository as Schedules;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * Read only: nothing is written, no destination or connected site is
 * contacted and no archive is opened. Each source is read once — the backups
 * folder listing, the upload record, the destinations, the schedules and
 * their logs, the connections, the job folders — and every block is built
 * from those (OverviewBackups, OverviewHistory, OverviewDestinations,
 * OverviewSchedule, OverviewAttention). Like the other views it carries
 * names, kinds, states and times only: never a secret, a setting, a path of
 * the server or the backups folder's random name (BackupDirApi::display()).
 * The file and table counts of the newest backup come from the job that
 * made it while its folder is kept (the daily sweep removes it after a day),
 * else they are null; the database size is recorded nowhere, so it is null.
 * On a network the connections do not exist (single sites only): `links` is
 * null; everything else is the network's (the backups are network-wide).
 */
final class OverviewApi {

	/**
	 * @param Repository                         $backups      Backups on disk.
	 * @param JobStore                           $jobs         Jobs.
	 * @param Destinations                       $destinations Destinations.
	 * @param Schedules                          $schedules    Schedules.
	 * @param Links|null                         $links        Connections (null on a network).
	 * @param \Closure                           $folder       fn(): array{path: string, problem: string|null, free: int|null}.
	 * @param \Closure                           $holder       fn(): ?string — the job holding the site lock.
	 * @param \Closure                           $nextRun      fn( string $id ): ?int — a schedule's WP-Cron event.
	 * @param \Closure                           $clock        fn(): array{now: int, tz: \DateTimeZone} — the site's time zone.
	 * @param array{host: string, network: bool} $site This site.
	 */
	public function __construct(
		private Repository $backups,
		private JobStore $jobs,
		private Destinations $destinations,
		private Schedules $schedules,
		private ?Links $links,
		private \Closure $folder,
		private \Closure $holder,
		private \Closure $nextRun,
		private \Closure $clock,
		private array $site
	) {
	}

	public static function fromServices( Services $services ): self {
		$network = is_multisite();

		return new self(
			$services->backups(),
			$services->jobs(),
			Destinations::fromWordPress(),
			$services->schedules(),
			$network ? null : new Links(),
			static function () use ( $services ): array {
				$view = BackupDirApi::fromServices( $services )->view();

				return [
					'path'    => $view['custom'],
					'problem' => $view['problem'],
					'free'    => DiskSpace::free( $services->storage()->backupsDir() ),
				];
			},
			static fn(): ?string => $services->lock()->holder( time() ),
			static fn( string $id ): ?int => Events::nextRun( $id ),
			static fn(): array => [
				'now' => time(),
				'tz'  => wp_timezone(),
			],
			[
				'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'network' => $network,
			]
		);
	}

	/**
	 * @return array<string, mixed> The payload of GET /overview (docs/react-admin-api.md).
	 */
	public function view(): array {
		$clock    = ( $this->clock )();
		$profiles = $this->destinations->profiles()->all();
		$names    = [];
		foreach ( $profiles as $profile ) {
			$names[ $profile->id ] = $profile->name;
		}
		$copies    = CloudCopies::all();
		$schedules = $this->schedules->existing();
		$logs      = [];
		foreach ( $schedules as $schedule ) {
			$logs[ $schedule->id ] = Log::all( $schedule->id );
		}
		$items  = OverviewBackups::collect( $this->backups->all(), $copies, array_fill_keys( array_keys( $names ), true ), array_merge( [], ...array_values( $logs ) ) );
		$newest = OverviewBackups::newest( $items );
		$folder = ( $this->folder )();

		return [
			'site'         => $this->site,
			'last'         => null === $newest ? null : $this->last( $newest, $items, $names ),
			'running'      => $this->running(),
			'days'         => OverviewHistory::coverage( $items, $clock['now'], $clock['tz'] ),
			'history'      => OverviewHistory::days( $items, $clock['now'], $clock['tz'] ),
			'attention'    => OverviewAttention::of( $profiles, $this->destinations->registry(), $folder['problem'], $folder['free'], $newest['size'] ?? null, $schedules, $logs ),
			'destinations' => OverviewDestinations::rows( $profiles, $this->destinations->registry(), $copies ),
			'schedule'     => OverviewSchedule::of( $schedules, $logs, $names, $this->nextRun ),
			'links'        => null === $this->links ? null : self::links( $this->links->all( $clock['now'] ) ),
			'folder'       => [
				'path'      => $folder['path'],
				'protected' => null === $folder['problem'],
				'free'      => $folder['free'],
			],
		];
	}

	/**
	 * @param array<string, mixed>             $newest OverviewBackups::newest().
	 * @param array<int, array<string, mixed>> $items  OverviewBackups::collect().
	 * @param array<string, string>            $names  Destination names by id.
	 * @return array<string, mixed>
	 */
	private function last( array $newest, array $items, array $names ): array {
		$counts = $this->counts( (string) $newest['name'] );
		$status = [
			CloudCopies::UP      => 'ok',
			CloudCopies::PENDING => 'pending',
			CloudCopies::FAILED  => 'failed',
		];
		$cloud  = [];
		foreach ( $newest['cloud'] as $copy ) {
			$cloud[] = [
				'id'     => $copy['id'],
				'name'   => $names[ $copy['id'] ] ?? '',
				'status' => $status[ $copy['status'] ] ?? 'failed',
			];
		}
		$outcome = $newest['partial'] ? 'partial' : 'ok';

		return [
			'name'     => $newest['name'],
			'time'     => $newest['time'],
			'size'     => $newest['size'],
			'files'    => $counts['files'],
			'dbSize'   => null,
			'dbTables' => $counts['tables'],
			'cloud'    => $cloud,
			'local'    => $newest['local'],
			'outcome'  => OverviewBackups::failedSince( $items, (int) $newest['time'] ) ? 'failed' : $outcome,
		];
	}

	/**
	 * The file and table counts of the export that made this backup, while
	 * its job folder is still kept.
	 *
	 * @param string $name Backup name.
	 * @return array{files: int|null, tables: int|null}
	 */
	private function counts( string $name ): array {
		foreach ( $this->jobs->ids() as $id ) {
			try {
				$job = $this->jobs->load( $id );
			} catch ( JobException $e ) {
				continue;
			}
			if ( 'export' !== $job->type || ( $job->state['backup']['name'] ?? null ) !== $name ) {
				continue;
			}
			$tables = $job->state['tables']['tables'] ?? null;

			return [
				'files'  => isset( $job->state['files']['count'] ) ? (int) $job->state['files']['count'] : null,
				'tables' => is_array( $tables ) ? count( $tables ) : null,
			];
		}

		return [
			'files'  => null,
			'tables' => null,
		];
	}

	/**
	 * The unfinished job (ActiveJob, like GET /jobs/active), with its share
	 * of the whole work done (0–1).
	 *
	 * @return array{id: string, type: string, progress: float}|null
	 */
	private function running(): ?array {
		$job = ActiveJob::find( $this->jobs, ( $this->holder )() );
		if ( ! $job instanceof Job ) {
			return null;
		}
		$steps = max( 1, count( $job->steps ) );

		return [
			'id'       => $job->id,
			'type'     => $job->type,
			'progress' => round( min( 1, max( 0, ( $job->stepIndex + $job->progress / 100 ) / $steps ) ), 2 ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $links Links::all() (no secret).
	 * @return array<int, array{id: string, host: string, direction: string, incoming: bool, secure: bool, status: string}>
	 */
	private static function links( array $links ): array {
		return array_map(
			static function ( array $link ): array {
				$allow = (array) $link['allow'];
				$host  = wp_parse_url( (string) $link['address'], PHP_URL_HOST );
				$push  = in_array( Links::PUSH, $allow, true );
				$pull  = in_array( Links::PULL, $allow, true );

				return [
					'id'        => (string) $link['id'],
					'host'      => is_string( $host ) && '' !== $host ? $host : (string) $link['name'],
					'direction' => $push && $pull ? 'both' : ( $push ? 'push' : 'pull' ),
					'incoming'  => Links::IN === $link['direction'],
					'secure'    => ! $link['insecure'],
					'status'    => (string) $link['state'],
				];
			},
			$links
		);
	}
}
