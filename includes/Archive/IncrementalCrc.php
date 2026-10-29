<?php
/**
 * Resumable running CRC.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Hashes data in-process and folds in the CRC saved by an earlier request.
 */
final class IncrementalCrc {

	/**
	 * CRC of prior bytes.
	 *
	 * @var string
	 */
	private string $prior;

	/**
	 * Current hash context.
	 *
	 * @var \HashContext
	 */
	private \HashContext $context;

	/**
	 * Number of bytes updated so far.
	 *
	 * @var int
	 */
	private int $length = 0;

	public function __construct( string $prior = Crc32::EMPTY ) {
		$this->prior   = $prior;
		$this->context = hash_init( 'crc32b' );
	}

	public function update( string $data ): void {
		hash_update( $this->context, $data );
		$this->length += strlen( $data );
	}

	public function value(): string {
		$session = hash_final( hash_copy( $this->context ) );

		return Crc32::combine( $this->prior, $session, $this->length );
	}
}
