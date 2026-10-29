<?php
/**
 * Runs WP-CLI against the E2E sites.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

final class WpSites {

	public static function path( string $site ): string {
		$path = getenv( 'LWM_E2E_' . strtoupper( $site ) );
		if ( false === $path || '' === $path ) {
			throw new \RuntimeException( 'Run bin/test-wp.sh.' );
		}

		return $path;
	}

	/**
	 * Runs WP-CLI without a terminal on stdin (like cron or a deploy script).
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	public static function wp( string $site, string ...$args ): array {
		return self::wpAt( self::path( $site ), ...$args );
	}

	/**
	 * WP-CLI on the WordPress folder at $path (also a site made by a test).
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	public static function wpAt( string $path, string ...$args ): array {
		return self::run( $path, '', $args );
	}

	/**
	 * WP-CLI with $stdin written to its standard input (no terminal), e.g. a password line.
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	public static function wpInput( string $site, string $stdin, string ...$args ): array {
		return self::run( self::path( $site ), $stdin, $args );
	}

	/**
	 * @param array<int, string> $args WP-CLI arguments.
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	private static function run( string $path, string $stdin, array $args ): array {
		$err  = (string) tempnam( sys_get_temp_dir(), 'lwm-e2e' );
		$cmd  = array_merge( [ (string) getenv( 'LWM_E2E_PHP' ), '-d', 'memory_limit=512M', (string) getenv( 'LWM_E2E_WP_CLI' ), '--path=' . $path ], $args );
		$proc = proc_open( $cmd, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new \RuntimeException( 'Cannot start WP-CLI.' );
		}
		if ( '' !== $stdin ) {
			fwrite( $pipes[0], $stdin );
		}
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$code   = proc_close( $proc );
		$stderr = (string) file_get_contents( $err );
		unlink( $err );

		return [ $code, $out, $stderr ];
	}

	/** Whether bin/test-wp.sh built the networks (LWM_E2E_NETWORK=1). */
	public static function hasNetworks(): bool {
		return false !== getenv( 'LWM_E2E_N' ) && '' !== getenv( 'LWM_E2E_N' );
	}

	/**
	 * WP-CLI on a site of a network (n or s) addressed by its URL.
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	public static function wpUrl( string $site, string $url, string ...$args ): array {
		return self::wpAt( self::path( $site ), '--url=' . $url, ...$args );
	}

	public static function ok( string $site, string ...$args ): string {
		[ $code, $out, $err ] = self::wp( $site, ...$args );
		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( "wp %s failed (%d):\n%s\n%s", implode( ' ', $args ), $code, $out, $err ) );
		}

		return $out;
	}

	/** Storage root of a site (wp-content/lw-migration-<hex>). */
	public static function storage( string $site ): string {
		return self::path( $site ) . '/wp-content/' . trim( self::ok( $site, 'option', 'get', 'lw_migration_storage_dir' ) );
	}

	/** Raw value of the site lock option, or null when no job holds the site. */
	public static function lock( string $site ): ?string {
		$out = self::ok( $site, 'eval', 'echo wp_json_encode( get_option( "lw_migration_lock", null ) );' );

		return json_decode( $out, true );
	}

	/** The plugin's own version, from its header. */
	public static function pluginVersion(): string {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lw-migration.php', false, null, 0, 4096 );
		if ( 1 !== preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $m ) ) {
			throw new \RuntimeException( 'No Version header in lw-migration.php.' );
		}

		return $m[1];
	}
}
