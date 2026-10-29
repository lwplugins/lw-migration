<?php
/**
 * The password of an encrypted schedule, sealed at rest.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Support\SecretBox;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 17: stored encrypted, the key derived from the wp-config salts. It
 * protects a leaked database (or a copy of the option) as long as the salts
 * are defined in wp-config.php; it does not protect against a compromised
 * server. A value that does not open (salts changed, tampering) reads as no
 * password: the run fails and says to set it again.
 */
final class SealedPassword {

	/** Binds the derived key to this one use (the job transients use another context). */
	private const KEY_CONTEXT = 'lw-migration/schedule-password/v1';

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
	 * @param string $password Plain password.
	 * @return string Sealed value for the schedule row.
	 * @throws ScheduleException When the server cannot seal it.
	 */
	public function seal( string $password ): string {
		return $this->box()->seal( $password );
	}

	/**
	 * @param string $sealed Stored value.
	 * @return string|null The password, or null when there is none or it does not open.
	 */
	public function open( string $sealed ): ?string {
		if ( '' === $sealed || ! $this->sodium ) {
			return null;
		}

		return $this->box()->open( $sealed );
	}

	/**
	 * @throws ScheduleException When the sodium functions are missing.
	 */
	private function box(): SecretBox {
		if ( null === $this->box ) {
			if ( ! $this->sodium ) {
				throw new ScheduleException( __( 'This server cannot protect a stored password (the sodium functions are missing). Save the schedule without a password.', 'lw-migration' ) );
			}
			$this->box = new SecretBox( hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true ) );
		}

		return $this->box;
	}
}
