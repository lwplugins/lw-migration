<?php
/**
 * What the reset routes do.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Reset\PasswordGuard;
use LightweightPlugins\Migration\Reset\Preflight;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\ResetSummary;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * A reset starts only after, in this order: a known type; the checks that
 * hold before any password (a network, the database setup, file changes,
 * root — 409 lw_migration_reset_refused with the reason); for the database
 * and a full reset the typed site address; a password at all (an empty one
 * is a 400 that is never counted, ruling I9); the password itself, checked
 * once and counted per user (403 wrong, 429 locked, 409 when the attempt
 * cannot be recorded, ruling M4); the backups folder when a backup runs
 * first. Only then is the job made (the site lock: 409 when busy). The
 * password is never kept: not in the job, not in a message.
 */
final class ResetApi {

	/** @var \Closure fn( Site $site ): Preflight */
	private \Closure $preflight;

	/** @var \Closure fn(): Site */
	private \Closure $site;

	/**
	 * @param Services      $services  Services (jobs, lock, storage).
	 * @param PasswordGuard $guard     The password check.
	 * @param \Closure      $user      fn(): \WP_User, the logged-in user.
	 * @param \Closure      $now       fn(): int.
	 * @param \Closure|null $preflight fn( Site $site ): Preflight (null = Preflight::fromWordPress()).
	 * @param \Closure|null $site      fn(): Site (null = SiteReader::read()).
	 */
	public function __construct(
		private Services $services,
		private PasswordGuard $guard,
		private \Closure $user,
		private \Closure $now,
		?\Closure $preflight = null,
		?\Closure $site = null
	) {
		$this->preflight = $preflight ?? static fn( Site $site ): Preflight => Preflight::fromWordPress( $site );
		$this->site      = $site ?? static fn(): Site => SiteReader::read();
	}

	public static function fromServices( Services $services ): self {
		return new self( $services, PasswordGuard::fromWordPress(), static fn(): \WP_User => wp_get_current_user(), static fn(): int => time() );
	}

	/**
	 * @return array<string, mixed> ResetSummary::of() for the logged-in user.
	 */
	public function summary(): array {
		return QuietOutput::run( fn(): array => ResetSummary::of( $this->services, ( $this->site )(), ( $this->user )(), null, $this->preflight ) );
	}

	/**
	 * @param array<string, mixed> $body {type, password, backup?, confirm?}.
	 * @throws RestException For every refusal before the job exists.
	 */
	public function start( array $body ): Job {
		return QuietOutput::run(
			function () use ( $body ): Job {
				self::shape( $body );
				$type = is_string( $body['type'] ?? null ) ? $body['type'] : '';
				if ( ! ResetType::isValid( $type ) ) {
					throw RestException::invalid( __( 'Choose what to reset.', 'lw-migration' ), 'type' );
				}
				$site = ( $this->site )();
				$this->refuseUnavailable( $type, $site );
				$phrase = ResetSummary::phrase( $site->homeUrl );
				if ( ResetType::touchesDatabase( $type ) && ( '' === $phrase || trim( (string) ( $body['confirm'] ?? '' ) ) !== $phrase ) ) {
					throw RestException::invalid( ResetTexts::refusal( ResetRefused::CONFIRM ), 'confirm' );
				}
				if ( ! is_string( $body['password'] ?? null ) || '' === $body['password'] ) { // Ruling I9: not counted.
					throw RestException::invalid( __( 'Type your password.', 'lw-migration' ), 'password' );
				}
				$this->refuseBusy();
				$user = ( $this->user )();
				$this->checkPassword( $user, $body['password'] );
				$backup = (bool) ( $body['backup'] ?? true );
				if ( $backup ) {
					$this->backupsReady();
				}
				$admin = [
					'id'    => (int) $user->ID,
					'login' => (string) $user->user_login,
				];

				return JobInput::checked( fn(): Job => ResetJobs::create( $this->services->jobs(), $this->services->lock(), $site, $type, $backup, $admin, (int) ( $this->now )() ) );
			}
		);
	}

	/**
	 * Only the four keys, `backup` a JSON boolean and `confirm` a string: a
	 * loosely typed value ("false", 0) never decides whether a backup runs.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @throws RestException 400 lw_migration_invalid naming the field.
	 */
	private static function shape( array $body ): void {
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, [ 'type', 'password', 'backup', 'confirm' ], true ) ) {
				throw RestException::invalidField( (string) $key );
			}
		}
		if ( array_key_exists( 'backup', $body ) && ! is_bool( $body['backup'] ) ) {
			throw RestException::invalidField( 'backup' );
		}
		if ( array_key_exists( 'confirm', $body ) && ! is_string( $body['confirm'] ) ) {
			throw RestException::invalidField( 'confirm' );
		}
	}

	/**
	 * @param string $type One of ResetType::ALL.
	 * @param Site   $site Site snapshot.
	 * @throws RestException 409 lw_migration_reset_refused with data.reason.
	 */
	private function refuseUnavailable( string $type, Site $site ): void {
		try {
			( $this->preflight )( $site )->assert( $type );
		} catch ( ResetRefused $e ) {
			throw new RestException( 'lw_migration_reset_refused', $e->getMessage(), 409, [ 'reason' => $e->reason ] );
		}
	}

	/**
	 * Another job holding the site refuses before the password, so a busy
	 * site never costs a try (the job's own start would refuse it anyway).
	 *
	 * @throws SiteBusyException 409 lw_migration_site_busy.
	 */
	private function refuseBusy(): void {
		$now  = (int) ( $this->now )();
		$lock = $this->services->lock();
		if ( null !== $lock->holder( $now ) ) {
			throw new SiteBusyException( $lock->expiresAt( $now ) );
		}
	}

	/**
	 * @param \WP_User $user     The logged-in user.
	 * @param string   $password What they typed (never kept).
	 * @throws RestException 403 wrong, 429 locked, 409 not recorded.
	 */
	private function checkPassword( \WP_User $user, #[\SensitiveParameter] string $password ): void {
		try {
			$this->guard->check( $user, $password, (int) ( $this->now )() );
		} catch ( ResetRefused $e ) {
			if ( ResetRefused::LOCKED === $e->reason ) {
				throw new RestException( 'lw_migration_reset_locked', $e->getMessage(), 429, [ 'retryAfter' => $e->retryAfter ] );
			}
			if ( ResetRefused::PASSWORD === $e->reason ) {
				throw new RestException( ErrorMap::INVALID, $e->getMessage(), 403, [ 'field' => 'password' ] );
			}
			throw new RestException( ResetRefused::NO_USER === $e->reason ? 'lw_migration_reset_refused' : 'lw_migration_reset_busy', $e->getMessage(), 409, ResetRefused::NO_USER === $e->reason ? [ 'reason' => $e->reason ] : [] );
		}
	}

	/**
	 * A backup starts only when the backups folder can take it.
	 *
	 * @throws RestException 409 lw_migration_backup_dir_unusable.
	 */
	private function backupsReady(): void {
		try {
			$this->services->storage()->ensureBackups();
		} catch ( FolderRefusedException $e ) {
			throw RestException::backupDirUnusable( $e );
		}
	}
}
