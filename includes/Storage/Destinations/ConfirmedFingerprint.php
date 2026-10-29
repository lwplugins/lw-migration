<?php
/**
 * A type whose server shows a fingerprint the admin confirms once.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D31 and issue #5: an SFTP server's host key, an FTPS server's
 * certificate that no certificate authority vouches for. The first
 * connection check stops before any login with the fingerprint the server
 * shows (PermanentFailure::UNCONFIRMED, the fingerprint in the failure's
 * data under fingerprintField()); the admin compares it and trusts it
 * (Rest\DestinationTrust), which stores it in that non-secret setting; from
 * then on only that server is accepted. A moved server forgets it.
 */
interface ConfirmedFingerprint {

	/** The settings key (a non-secret field) that holds the confirmed fingerprint. */
	public function fingerprintField(): string;

	/**
	 * Whether a value has the shape of this type's fingerprint.
	 *
	 * @param string $value Fingerprint as sent.
	 */
	public function isFingerprint( string $value ): bool;
}
