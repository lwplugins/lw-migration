<?php
/**
 * A server where ext-intl cannot convert a host name, with the IDNA encoder core bundles (load only in a separate process).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link {

	/**
	 * Shadows the global function for the Link namespace: intl gives up.
	 *
	 * @param mixed ...$args Arguments.
	 * @return false
	 */
	function idn_to_ascii( ...$args ) {
		unset( $args );

		return false;
	}
}

namespace WpOrg\Requests {

	/**
	 * Stand-in for core's encoder: knows one name, throws for the rest like the real one does for bad input.
	 */
	final class IdnaEncoder {

		/** @var array<int, string> Host names asked for. */
		public static array $asked = [];

		public static function encode( string $hostname ): string {
			self::$asked[] = $hostname;
			if ( 'bé.example' === $hostname ) {
				return 'xn--b-bga.example';
			}
			throw new \Exception( 'Invalid host name.' );
		}
	}
}
