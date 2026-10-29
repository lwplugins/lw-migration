<?php
/**
 * A settings value a destination type refuses.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * The message is for the user (translated); $field names the refused key,
 * so REST answers `data.field` and the CLI names the flag.
 */
final class FieldException extends \InvalidArgumentException {

	/**
	 * @param string $field   Settings key.
	 * @param string $message Message for the user.
	 */
	public function __construct( public string $field, string $message ) {
		parent::__construct( $message );
	}
}
