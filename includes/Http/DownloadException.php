<?php
/**
 * Download failure.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Network error, refused address or an unexpected server answer.
 *
 * The message never carries the credentials or the query of the address.
 * A passing failure (network error, busy server, cut transfer) may be tried again.
 */
class DownloadException extends \RuntimeException {

	/**
	 * Whether trying again may help.
	 *
	 * @var bool
	 */
	private bool $passing = false;

	/**
	 * Whether the safe API refused the address itself (local, internal or malformed).
	 *
	 * @var bool
	 */
	private bool $addressRefused = false;

	/**
	 * The safe API refused the address; trying again cannot help.
	 *
	 * @param string $message Message.
	 */
	public static function addressRefused( string $message ): self {
		$e                 = new self( $message );
		$e->addressRefused = true;

		return $e;
	}

	public function isAddressRefused(): bool {
		return $this->addressRefused;
	}

	public static function passing( string $message ): self {
		$e          = new self( $message );
		$e->passing = true;

		return $e;
	}

	public function isPassing(): bool {
		return $this->passing;
	}
}
