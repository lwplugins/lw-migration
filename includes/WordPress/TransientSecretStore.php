<?php
/**
 * Job secrets in a WordPress transient.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Support\SecretBox;

defined( 'ABSPATH' ) || exit;

/**
 * For drivers whose ticks are separate requests (browser, cron). The secret
 * array is sealed (SecretBox) under a key derived from the auth salts. When
 * the salts are kept in the database (not defined in wp-config.php) or the
 * transient lives in an object cache, sealing protects only against a copy
 * of the transient alone. A value that does not open (other salts,
 * tampering, an old plain array) is deleted; it, a missing (expired or
 * evicted) value and any value while sodium is missing read as no secret:
 * an import asks for the password again, an export fails and has to be
 * started again. The Runner deletes the secret when the job ends; the transient
 * expires after a day anyway. Never exported (Options::DUMP_EXCLUDED_PREFIXES).
 */
final class TransientSecretStore implements SecretStore {

	private const PREFIX = 'lw_migration_job_secret_';

	/** Binds the derived key to this one use. */
	private const KEY_CONTEXT = 'lw-migration/job-secret/v1';

	/**
	 * Built on first use.
	 *
	 * @var SecretBox|null
	 */
	private ?SecretBox $box;

	/**
	 * Whether the sodium functions exist.
	 *
	 * @var bool
	 */
	private bool $sodium;

	/**
	 * @param SecretBox|null $box    Sealing key (default: derived from wp_salt( 'auth' )).
	 * @param bool|null      $sodium Whether sealing is possible (default: SecretBox::available()).
	 */
	public function __construct( ?SecretBox $box = null, ?bool $sodium = null ) {
		$this->box    = $box;
		$this->sodium = $sodium ?? SecretBox::available();
	}

	/**
	 * @param string               $jobId  Job id.
	 * @param array<string, mixed> $values Secret values.
	 * @return void
	 * @throws JobException When the values cannot be encoded, sealed or stored.
	 */
	public function put( string $jobId, array $values ): void {
		$json = json_encode( $values );
		if ( false === $json ) {
			throw new JobException( 'The password could not be stored (invalid characters).' );
		}
		if ( ! set_transient( self::PREFIX . $jobId, $this->box()->seal( $json ), DAY_IN_SECONDS ) ) {
			throw new JobException( 'The job password could not be stored for the next steps (saving a WordPress transient failed).' );
		}
	}

	/**
	 * @param string $jobId Job id.
	 * @return array<string, mixed> Empty when nothing is stored, sealing is unavailable
	 *                              or the value does not open (then it is deleted).
	 */
	public function get( string $jobId ): array {
		$sealed = get_transient( self::PREFIX . $jobId );
		if ( false === $sealed || ! $this->sodium ) {
			return [];
		}
		$json   = is_string( $sealed ) ? $this->box()->open( $sealed ) : null;
		$values = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $values ) ) {
			$this->delete( $jobId );
			return [];
		}

		return $values;
	}

	public function delete( string $jobId ): void {
		delete_transient( self::PREFIX . $jobId );
	}

	/**
	 * @throws JobException When the sodium functions are missing.
	 */
	private function box(): SecretBox {
		if ( null === $this->box ) {
			if ( ! $this->sodium ) {
				throw new JobException( 'The job password cannot be protected: the sodium functions are missing.' );
			}
			$this->box = new SecretBox( hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true ) );
		}

		return $this->box;
	}
}
