<?php
/**
 * The plugin's own PSR-4 autoloader (used when vendor/ is not shipped).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `LightweightPlugins\Migration\` to `includes/`.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	public static function autoload( string $class ): void {
		$path = self::path_for( $class );

		if ( null !== $path && is_file( $path ) ) {
			require $path;
		}
	}

	/**
	 * PSR-4 path for a class in this namespace, or null.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return string|null
	 */
	public static function path_for( string $class ): ?string {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return null;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );

		return __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
	}
}
