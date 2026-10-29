<?php
/**
 * Another job holds the site lock.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Job\JobException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by JobStarter when a new job cannot take the site lock. The message is
 * built from the other job's lock expiry (describe()), so the REST error can be
 * rebuilt from the data instead of passing exception text along.
 */
final class SiteBusyException extends JobException {

	/**
	 * @param int|null $expiresAt When the other job's lock expires (null: unknown).
	 */
	public function __construct( public ?int $expiresAt ) {
		parent::__construct( self::describe( $expiresAt ) );
	}

	/**
	 * @param int|null $expires When the other job's lock expires (null: unknown).
	 * @return string Refusal message, with the expiry in the site's time zone.
	 */
	public static function describe( ?int $expires ): string {
		if ( null === $expires ) {
			return __( 'Another backup or restore is running on this site. Wait until it finishes.', 'lw-migration' );
		}

		return sprintf(
			/* translators: %s: date and time in the site's time zone. */
			__( 'Another backup or restore is running on this site. Wait until it finishes; if it has stopped, its lock expires at %s.', 'lw-migration' ),
			(string) wp_date( 'Y-m-d H:i T', $expires )
		);
	}
}
