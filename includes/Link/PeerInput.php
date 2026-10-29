<?php
/**
 * The bodies a connected site sends to start a push or a pull.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Rest\RestException;

defined( 'ABSPATH' ) || exit;

/**
 * Allowlists of exact keys and types (like Rest\JobInput for the browser):
 * an unknown key is refused, never dropped, and every refusal is
 * RestException::invalidField() naming the first bad key.
 */
final class PeerInput {

	/** The other site's reference of its own job: 16 lower-case hex digits. */
	public const REF_PATTERN = '/^[a-f0-9]{16}\z/';

	/**
	 * @param array<string, mixed> $body {ref, name, size, safetyBackup}.
	 * @return array{ref: string, name: string, size: int, safetyBackup: bool}
	 * @throws RestException 400.
	 */
	public static function push( array $body ): array {
		$valid = [
			'ref'          => self::isRef( $body['ref'] ?? null ),
			'name'         => is_string( $body['name'] ?? null ) && BackupName::isValid( $body['name'] ),
			'size'         => is_int( $body['size'] ?? null ) && $body['size'] >= 2 * Format::HEADER_SIZE,
			'safetyBackup' => is_bool( $body['safetyBackup'] ?? null ),
		];
		$bad   = array_merge( array_keys( $valid, false, true ), array_diff( array_keys( $body ), array_keys( $valid ) ) );
		if ( [] !== $bad ) {
			throw RestException::invalidField( (string) $bad[0] );
		}

		return [
			'ref'          => (string) $body['ref'],
			'name'         => (string) $body['name'],
			'size'         => (int) $body['size'],
			'safetyBackup' => (bool) $body['safetyBackup'],
		];
	}

	/**
	 * @param array<string, mixed> $body {ref}.
	 * @return string The ref.
	 * @throws RestException 400.
	 */
	public static function pull( array $body ): string {
		if ( [ 'ref' ] !== array_keys( $body ) || ! self::isRef( $body['ref'] ) ) {
			throw RestException::invalidField( 'ref' );
		}

		return (string) $body['ref'];
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function isRef( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::REF_PATTERN, $value );
	}
}
