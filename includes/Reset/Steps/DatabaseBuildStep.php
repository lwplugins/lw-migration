<?php
/**
 * Reset step: builds a fresh install beside the live tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Database\OrphanTables;
use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Database\TmpRunException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\FreshInstall;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetSwap;
use LightweightPlugins\Migration\Reset\ResetTables;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\TmpCarry;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The first call only reserves and registers a run (TmpRun) and saves its
 * prefix; the second drops whatever an earlier, dead build left under that
 * prefix and builds the fresh install there (FreshInstall + TmpCarry through
 * the build closure). The live tables are only read here. The connection is
 * built per call.
 */
final class DatabaseBuildStep implements StepInterface {

	public const KEY = ResetType::DB_BUILD;

	/** @var \Closure fn(): void, flushes the object cache before a rebuild (ruling I1). */
	private \Closure $flush;

	/** @var \Closure fn(): array<int, string>, the core tables the build must make. */
	private \Closure $core;

	/**
	 * @param \Closure      $connect fn(): Connection.
	 * @param TmpRun        $run     Reserves the run.
	 * @param \Closure      $build   fn( string $tmpPrefix, Job $job ): void, the fresh install and the carried rows.
	 * @param \Closure|null $flush   fn(): void; default wp_cache_flush().
	 * @param \Closure|null $core    fn(): array<int, string>; default ResetTables::coreSuffixes().
	 */
	public function __construct( private \Closure $connect, private TmpRun $run, private \Closure $build, ?\Closure $flush = null, ?\Closure $core = null ) {
		$this->flush = $flush ?? static function (): void {
			wp_cache_flush();
		};
		$this->core  = $core ?? static fn(): array => ResetTables::coreSuffixes();
	}

	/**
	 * The build of a real site: core's install into the run's tables, then
	 * the kept rows, the user and the theme.
	 *
	 * @param Services $services Services.
	 */
	public static function fromWordPress( Services $services ): self {
		return new self(
			static fn() => $services->connection(),
			new TmpRun( $services->jobs(), static fn( string $tmp ): bool => ( new TmpRunRegistry() )->register( OrphanTables::runId( $tmp ), time() ) ),
			static function ( string $tmp, Job $job ) use ( $services ): void {
				self::install( $services, $tmp, $job );
			}
		);
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$db   = ( $this->connect )();
		$site = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
		if ( empty( $job->state['tmpPrefix'] ) ) {
			try {
				$job->state['tmpPrefix'] = $this->run->reserve( $db, $site->tablePrefix, $job );
			} catch ( TmpRunException $e ) {
				throw new JobException( __( 'No free temporary tables could be set up, so the database was not reset. The site was not changed. Try again later.', 'lw-migration' ) );
			}
			unset( $job->state[ TmpRun::CANDIDATE ] );
			$job->cursor = [ 'phase' => 'build' ]; // The build gets its own attempt fingerprint.

			return StepResult::more( 5, __( 'Preparing a fresh database...', 'lw-migration' ) );
		}
		if ( ! empty( $job->cursor['built'] ) ) {
			return StepResult::done();
		}
		$tmp  = (string) $job->state['tmpPrefix'];
		$swap = ResetSwap::forJob( $db, $tmp, $site->tablePrefix );
		( $this->flush )(); // Ruling I1: nothing a dead build cached survives into this one.
		$swap->dropFresh(); // A build that died halfway starts over.
		( $this->build )( $tmp, $job );
		$built = $swap->freshSuffixes();
		if ( [] !== array_diff( ( $this->core )(), $built ) ) {
			// dbDelta() never throws: a core table it did not make would leave the live one to be retired.
			throw new JobException( __( 'The fresh database could not be built completely, so the database was not reset. The site was not changed.', 'lw-migration' ) );
		}
		$job->state['built']  = $built; // Ruling m4: the swap requires exactly these (the cursor ends with this step).
		$job->cursor['built'] = true;

		return StepResult::done( __( 'A fresh database is ready beside the live one.', 'lw-migration' ) );
	}

	/**
	 * @param Services $services Services.
	 * @param string   $tmp      The run's temporary prefix.
	 * @param Job      $job      Reset job.
	 * @throws JobException When the user who started the reset is gone.
	 */
	private static function install( Services $services, string $tmp, Job $job ): void {
		global $wpdb;
		$admin = ResetJobs::admin( $job );
		$site  = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
		$user  = get_userdata( $admin['id'] );
		FreshInstall::fromWordPress()->build(
			$wpdb,
			$tmp,
			[
				'siteurl'     => $site->internalSiteUrl,
				'home'        => $site->internalHomeUrl,
				'blogname'    => get_option( 'blogname' ),
				'blog_public' => get_option( 'blog_public' ),
				'admin_email' => false === $user ? get_option( 'admin_email' ) : $user->user_email,
				'fresh_site'  => 1,
			],
			$admin['id']
		);
		if ( ! ( new TmpCarry( $services->connection(), $tmp, $site->tablePrefix ) )->carry( $admin['id'], $admin['login'], $site->ownPlugin, self::liveTheme() ) ) {
			throw new JobException( __( 'Your user no longer exists on this site, so the database was not reset. The site was not changed.', 'lw-migration' ) );
		}
	}

	/**
	 * Ruling U3: the live theme stays when both its folders are installed.
	 *
	 * @return array{template: string, stylesheet: string}|null
	 */
	private static function liveTheme(): ?array {
		$template   = (string) get_option( 'template' );
		$stylesheet = (string) get_option( 'stylesheet' );
		if ( '' === $template || '' === $stylesheet || ! wp_get_theme( $template )->exists() || ! wp_get_theme( $stylesheet )->exists() ) {
			return null;
		}

		return [
			'template'   => $template,
			'stylesheet' => $stylesheet,
		];
	}
}
