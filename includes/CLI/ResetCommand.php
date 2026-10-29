<?php
/**
 * `wp lw-migration reset`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Reset\PasswordGuard;
use LightweightPlugins\Migration\Reset\Preflight;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\ResetSummary;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Rest\ActiveJob;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\ResetResult;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Resets part of the site, or all of it. Only orchestrates: the checks are
 * Preflight's and PasswordGuard's, the texts ResetOutput's, the work the
 * job's steps (driven here like an export or a restore).
 */
final class ResetCommand {

	/** @var \Closure fn(): Site */
	private \Closure $site;

	/** @var \Closure fn(): ?string, one line of standard input without its line end (null at end of input). */
	private \Closure $stdin;

	/**
	 * @param \Closure|null $site  fn(): Site (null = SiteReader::read()).
	 * @param \Closure|null $stdin fn(): ?string (null = one line of STDIN).
	 */
	public function __construct( ?\Closure $site = null, ?\Closure $stdin = null ) {
		$this->site  = $site ?? static fn(): Site => SiteReader::read();
		$this->stdin = $stdin ?? static function (): ?string {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- one line of the command's standard input.
			$line = defined( 'STDIN' ) ? fgets( STDIN ) : false;

			return false === $line ? null : rtrim( $line, "\r\n" );
		};
	}

	/**
	 * Resets plugins, themes, media, the database, or everything.
	 *
	 * Every other plugin is deleted (its uninstall routine runs first); every theme
	 * but the default one is deleted; the media library and the uploads
	 * folder are emptied; the database becomes a fresh install that keeps
	 * you (the --user), this plugin, its settings, connections and schedules.
	 * Your password is asked for (a hidden prompt on a terminal, otherwise
	 * one line of standard input; never as an option). On a network only
	 * plugins, themes and media can be reset, on every site.
	 *
	 * On the command line every plugin's uninstall routine runs in this one
	 * process (as WordPress' own bulk delete does). If one of them ends the
	 * process, run the same command again: it continues the reset.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : What to reset: plugins, themes, media, database or full.
	 *
	 * [--backup]
	 * : Back up the whole site first (the default). Use --no-backup to skip it.
	 *
	 * [--yes]
	 * : Reset without asking (required when not run from a terminal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration reset plugins --user=admin
	 *     printf '%s\n' "$PASSWORD" | wp lw-migration reset media --yes --user=admin
	 *
	 * @param array<int, string>   $args  The type.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$type = (string) ( $args[0] ?? '' );
		if ( ! ResetType::isValid( $type ) ) {
			Console::error( __( 'Choose one of: plugins, themes, media, database, full.', 'lw-migration' ) );
			return;
		}
		$user = wp_get_current_user();
		if ( (int) $user->ID <= 0 || ! current_user_can( Permission::capability() ) ) {
			Console::error( __( 'Pass --user=<admin login>: the reset checks that admin\'s password and keeps that user.', 'lw-migration' ) );
			return;
		}
		$site = ( $this->site )();
		try {
			Preflight::fromWordPress( $site )->assert( $type );
		} catch ( ResetRefused $e ) {
			Console::error( $e->getMessage() );
			return;
		} catch ( DatabaseException $e ) { // The table listing of the database setup check failed.
			Console::error( __( 'The database could not be checked, so nothing was reset.', 'lw-migration' ) );
			return;
		}
		$services = Services::get();
		if ( self::busy( $services->jobs(), $services->lock(), $type, (int) $user->ID, time() ) ) {
			Console::error( __( 'Another job is running on this site, so nothing was reset and no password was asked for. Run the reset again once it has finished.', 'lw-migration' ) );
			return;
		}
		$interactive = Confirmation::stdinIsInteractive();
		if ( Confirmation::requiresYes( $assoc, $interactive ) ) {
			Console::error( __( 'Without a terminal, pass --yes to confirm the reset.', 'lw-migration' ) );
			return;
		}
		$this->checkPassword( $user, $this->password( (string) $user->user_login, $interactive ) );
		$left = self::resumable( $services->jobs(), $services->lock(), $type, (int) $user->ID, time() );
		if ( null !== $left ) {
			Console::log( self::resumeText( $type ) );
			self::drive( $services, $left->id, true );
			return;
		}
		$this->confirm( $type, $site, $user, $assoc, $interactive );
		self::run( $services, $type, $site, $user, self::backup( $assoc ) );
	}

	/**
	 * --backup is the default; WP-CLI turns --no-backup into false.
	 *
	 * @param array<string, mixed> $assoc Flags.
	 */
	public static function backup( array $assoc ): bool {
		return false !== ( $assoc['backup'] ?? true );
	}

	/**
	 * Whether another job holds the site: anything but an unfinished reset of
	 * this kind by this admin, which the command continues (checked before
	 * the password is asked for, so a busy site costs no try).
	 *
	 * @param JobStore $jobs   Job store.
	 * @param SiteLock $lock   Site lock.
	 * @param string   $type   One of ResetType::ALL.
	 * @param int      $userId The admin running the command.
	 * @param int      $now    Unix time.
	 */
	public static function busy( JobStore $jobs, SiteLock $lock, string $type, int $userId, int $now ): bool {
		$holder = $lock->holder( $now );
		if ( null === $holder ) {
			return false;
		}
		$job = ActiveJob::find( $jobs, $holder );

		return null === $job || $job->id !== $holder || ! self::sameReset( $job, $type, $userId );
	}

	/**
	 * @param Job    $job    Unfinished job.
	 * @param string $type   One of ResetType::ALL.
	 * @param int    $userId The admin running the command.
	 */
	private static function sameReset( Job $job, string $type, int $userId ): bool {
		return ResetJobs::TYPE === $job->type && ( $job->options['type'] ?? '' ) === $type && ResetJobs::admin( $job )['id'] === $userId;
	}

	/**
	 * Only a reset that deletes plugins runs uninstall routines, which may end the process.
	 *
	 * @param string $type One of ResetType::ALL.
	 */
	public static function resumeText( string $type ): string {
		if ( in_array( $type, [ ResetType::PLUGINS, ResetType::FULL ], true ) ) {
			/* translators: %s: what the reset removes, e.g. "plugins" */
			return sprintf( __( 'Your earlier reset (%s) did not finish; a plugin\'s uninstall routine may have ended its process. It continues now.', 'lw-migration' ), ResetTexts::kind( $type ) );
		}

		/* translators: %s: what the reset removes, e.g. "media" */
		return sprintf( __( 'Your earlier reset (%s) did not finish. It continues now.', 'lw-migration' ), ResetTexts::kind( $type ) );
	}

	/**
	 * The unfinished reset of this kind this admin left (a request or a
	 * process that ended inside a step, e.g. an uninstall routine that
	 * exited), with the site lock taken for it; null when there is none, or
	 * another job holds the site, or another job ran after it stopped.
	 *
	 * @param JobStore $jobs   Job store.
	 * @param SiteLock $lock   Site lock.
	 * @param string   $type   One of ResetType::ALL.
	 * @param int      $userId The admin running the command.
	 * @param int      $now    Unix time.
	 */
	public static function resumable( JobStore $jobs, SiteLock $lock, string $type, int $userId, int $now ): ?Job {
		$job = ActiveJob::find( $jobs, $lock->holder( $now ) );
		if ( null === $job || ! self::sameReset( $job, $type, $userId ) ) {
			return null;
		}
		if ( ! ResetJobs::pastNoReturn( $job, $jobs->dir( $job->id ) ) && ActiveJob::ranAfter( $jobs, $job ) ) {
			return null;
		}

		return $lock->acquire( $job->id, $now ) ? $job : null;
	}

	/**
	 * @param string $login       The admin's login.
	 * @param bool   $interactive Whether STDIN is a terminal.
	 * @return string Never empty (an empty answer stops the command, uncounted: ruling I9).
	 */
	private function password( string $login, bool $interactive ): string {
		/* translators: %s: user login */
		$value = $interactive ? Prompt::hidden( sprintf( __( 'Password of %s', 'lw-migration' ), $login ) ) : ( $this->stdin )();
		if ( null === $value || '' === $value ) {
			/* translators: %s: user login */
			Console::error( sprintf( __( 'Give the password of %s on a terminal, or as one line on standard input.', 'lw-migration' ), $login ) );
			return '';
		}

		return $value;
	}

	/**
	 * @param \WP_User $user     The admin.
	 * @param string   $password What they typed (never kept).
	 */
	private function checkPassword( \WP_User $user, #[\SensitiveParameter] string $password ): void {
		try {
			PasswordGuard::fromWordPress()->check( $user, $password, time() );
		} catch ( ResetRefused $e ) {
			Console::error( $e->getMessage() );
		}
	}

	/**
	 * Prints what goes and what stays, then asks (or takes --yes).
	 *
	 * @param string               $type        One of ResetType::ALL.
	 * @param Site                 $site        Site snapshot.
	 * @param \WP_User             $user        The admin.
	 * @param array<string, mixed> $assoc       Flags.
	 * @param bool                 $interactive Whether STDIN is a terminal.
	 */
	private function confirm( string $type, Site $site, \WP_User $user, array $assoc, bool $interactive ): void {
		foreach ( ResetOutput::summary( $type, ResetSummary::of( Services::get(), $site, $user ) ) as $line ) {
			Console::log( $line );
		}
		if ( Confirmation::CONFIRMED !== Confirmation::decide( $assoc, $interactive, static fn(): ?string => Prompt::line( __( 'Reset now? [y/n]', 'lw-migration' ) ) ) ) {
			Console::error( __( 'Nothing was reset.', 'lw-migration' ) );
		}
	}

	/**
	 * @param Services $services Services.
	 * @param string   $type     One of ResetType::ALL.
	 * @param Site     $site     Site snapshot.
	 * @param \WP_User $user     The admin who stays.
	 * @param bool     $backup   Whether the backup runs first.
	 */
	private static function run( Services $services, string $type, Site $site, \WP_User $user, bool $backup ): void {
		try {
			$services->storage()->ensure();
			if ( $backup ) {
				$services->storage()->ensureBackups();
			}
			$admin = [
				'id'    => (int) $user->ID,
				'login' => (string) $user->user_login,
			];
			$job   = ResetJobs::create( $services->jobs(), $services->lock(), $site, $type, $backup, $admin, time() );
		} catch ( FolderRefusedException $e ) {
			Console::error( FolderMessages::of( $e->reason ) );
			return;
		} catch ( \RuntimeException | \InvalidArgumentException $e ) { // A busy site, a storage folder that cannot be written.
			Console::error( $e->getMessage() );
			return;
		}
		self::drive( $services, $job->id );
	}

	/**
	 * A continued job that stops for any reason but Ctrl-C is left as it is:
	 * another process may still be driving it (a second terminal), and it
	 * must not be failed under that process.
	 *
	 * @param Services $services Services.
	 * @param string   $id       Reset job.
	 * @param bool     $resumed  Whether this run continues a job it did not start.
	 */
	private static function drive( Services $services, string $id, bool $resumed = false ): void {
		$secrets = new MemorySecretStore();
		try {
			$done = Drive::run( $services, $id, $secrets );
		} catch ( \RuntimeException $e ) { // Ctrl-C, lost lock, busy job.
			Console::error( $resumed && ! $e instanceof InterruptedException ? $e->getMessage() : Drive::stop( $services, $id, $secrets, $e ) );
			return;
		}
		foreach ( ResetOutput::result( ResetResult::of( $done ) ) as $line ) {
			Console::log( $line );
		}
		Console::success( __( 'Reset finished.', 'lw-migration' ) );
	}
}
