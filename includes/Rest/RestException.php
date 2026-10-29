<?php
/**
 * A REST request that cannot be served, with its error code and status.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the API classes; Routes turns it into a WP_Error through ErrorMap.
 * The message is shown to the user (translated by the thrower).
 */
final class RestException extends \RuntimeException {

	/**
	 * The CloudFailure facts a REST answer carries (the SFTP host key and FTPS certificate checks).
	 */
	private const FACTS = [ 'hostKey', 'hostKeyType', 'certFingerprint', 'expected' ];

	/**
	 * @param string               $errorCode Machine-readable code (lw_migration_…).
	 * @param string               $message   Message for the user.
	 * @param int                  $status    HTTP status.
	 * @param array<string, mixed> $data      Extra fields for the client (e.g. received bytes).
	 */
	public function __construct( public string $errorCode, string $message, public int $status, public array $data = [] ) {
		parent::__construct( $message );
	}

	/**
	 * A request value our validation refused; its (translated) text is shown.
	 * Every validation refusal has this one code; the refused field, when one
	 * is known, is in data['field']. Any other invalid-argument failure gets
	 * ErrorMap's generic text.
	 *
	 * @param string      $message Message for the user (translated by the caller).
	 * @param string|null $field   The refused request field.
	 */
	public static function invalid( string $message, ?string $field = null ): self {
		return new self( ErrorMap::INVALID, $message, 400, null === $field ? [] : [ 'field' => $field ] );
	}

	/**
	 * A request field our validation refused, named in the message. Its text
	 * says nothing specific, so data['generic'] tells the browser to show its
	 * own hint for the field instead (it never compares translated text).
	 *
	 * @param string $field Field name.
	 */
	public static function invalidField( string $field ): self {
		/* translators: %s: name of the field */
		$e                  = self::invalid( sprintf( __( 'This value is not accepted: %s', 'lw-migration' ), $field ), $field );
		$e->data['generic'] = true;

		return $e;
	}

	/**
	 * The named backup is not in the named folder (any more).
	 */
	public static function backupMissing(): self {
		return new self( 'lw_migration_backup_missing', __( 'This backup does not exist (any more).', 'lw-migration' ), 404 );
	}

	/**
	 * The named backup is there, but it is no .wpress archive (or a damaged one).
	 */
	public static function notReadable(): self {
		return new self( 'lw_migration_not_readable', __( 'This file cannot be read as a .wpress archive.', 'lw-migration' ), 422 );
	}

	/**
	 * The backups folder cannot take a backup now (missing, replaced, no longer
	 * ours): refused before any work starts, with the folder's own text.
	 *
	 * @param FolderRefusedException $e Why the folder cannot be used.
	 */
	public static function backupDirUnusable( FolderRefusedException $e ): self {
		return new self( 'lw_migration_backup_dir_unusable', FolderMessages::of( $e->reason ), 409 );
	}

	/**
	 * The named destination profile does not exist (any more).
	 */
	public static function destinationMissing(): self {
		return new self( 'lw_migration_destination_missing', __( 'This destination does not exist (any more).', 'lw-migration' ), 404 );
	}

	/**
	 * A destination refused or failed a request: our own text (CloudFailure
	 * messages never carry a secret or the server's words), its facts (e.g.
	 * the host key an SFTP server or the certificate an FTPS server shows; only the FACTS keys pass), its reason
	 * code and whether trying again later may help; a fact never replaces the
	 * reason or the flag.
	 *
	 * @param CloudFailure $e What the destination answered.
	 */
	public static function destinationFailed( CloudFailure $e ): self {
		return new self(
			'lw_migration_destination_failed',
			$e->getMessage(),
			422,
			array_merge(
				array_intersect_key( $e->data, array_flip( self::FACTS ) ),
				[
					'reason'    => $e->reason,
					'transient' => $e instanceof TransientFailure,
				]
			)
		);
	}
}
