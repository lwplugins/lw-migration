<?php
/**
 * A lasting failure: this destination stops.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2: authentication, permission, a full storage, a missing bucket
 * or folder. SESSION_GONE: the provider forgot an unfinished upload (it may
 * be started over); REAUTH: the stored secret no longer opens on this site.
 * HOST_KEY_*: the SFTP server's key is not the confirmed one (data: hostKey,
 * hostKeyType, expected); CERT_*: the FTPS server's certificate cannot be
 * checked and is not the confirmed one (data: certFingerprint, expected);
 * CUT_OFF: requests died twice on one part.
 */
final class PermanentFailure extends CloudFailure {

	public const AUTH = 'auth';

	public const DENIED = 'denied';

	public const MISSING = 'missing';

	public const FULL = 'full';

	public const SESSION_GONE = 'session_gone';

	public const TOO_LARGE = 'too_large';

	public const INVALID = 'invalid';

	public const REAUTH = 'reauth';

	public const CLOCK = 'clock';

	public const HOST_KEY_UNKNOWN = 'host_key_unknown';

	public const HOST_KEY_CHANGED = 'host_key_changed';

	public const CERT_UNKNOWN = 'cert_unknown';

	public const CERT_CHANGED = 'cert_changed';

	/** A fingerprint the admin confirms (DestinationTrust): the server's own, shown in the failure's data. */
	public const UNCONFIRMED = [ self::HOST_KEY_UNKNOWN, self::HOST_KEY_CHANGED, self::CERT_UNKNOWN, self::CERT_CHANGED ];

	public const CUT_OFF = 'cut_off';
}
