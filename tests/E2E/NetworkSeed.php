<?php
/**
 * The content of network n that the reference-made archives were built from.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

final class NetworkSeed {

	public const HOME = 'http://net-n.test';

	/**
	 * Seeds network n once per run (the fixture harness's prepare(), without
	 * the reference plugins): a later test class finds it already seeded.
	 */
	public static function ensure(): void {
		[ $code ] = WpSites::wp( 'n', 'user', 'get', 'editor2', '--field=ID' );
		if ( 0 !== $code ) {
			self::seed( WpSites::path( 'n' ) );
		}
	}

	/**
	 * WP-CLI on a site of network n, failing on any error.
	 */
	public static function okUrl( string $url, string ...$args ): string {
		[ $code, $out, $err ] = WpSites::wpUrl( 'n', $url, ...$args );
		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( "wp --url=%s %s failed (%d):\n%s\n%s", $url, implode( ' ', $args ), $code, $out, $err ) );
		}

		return $out;
	}

	private static function seed( string $net ): void {
		$files = [
			'/wp-content/plugins/lwm-net-sample/lwm-net-sample.php'   => "<?php\n/**\n * Plugin Name: LWM Net Sample\n * Network: true\n */\n",
			'/wp-content/plugins/lwm-site-sample/lwm-site-sample.php' => "<?php\n/**\n * Plugin Name: LWM Site Sample\n */\n",
			'/wp-content/themes/lwm-ref-theme/style.css'              => "/*\nTheme Name: LWM Ref Theme\n*/\n",
			'/wp-content/themes/lwm-ref-theme/index.php'              => "<?php\n// Silence.\n",
			'/wp-content/uploads/2026/10/main.txt'                    => "main upload\n",
			'/wp-content/uploads/sites/2/2026/10/b2.txt'              => "b2 upload\n",
			'/wp-content/uploads/sites/3/2026/10/b3.txt'              => "b3 upload\n",
		];
		foreach ( $files as $rel => $contents ) {
			if ( ! is_dir( dirname( $net . $rel ) ) ) {
				mkdir( dirname( $net . $rel ), 0755, true );
			}
			file_put_contents( $net . $rel, $contents );
		}
		$b2 = self::HOME . '/b2/';
		$b3 = self::HOME . '/b3/';
		WpSites::ok( 'n', 'plugin', 'activate', 'lwm-net-sample', '--network' );
		WpSites::ok( 'n', 'theme', 'enable', 'lwm-ref-theme', '--network' );
		foreach ( [ self::HOME . '/', $b2, $b3 ] as $url ) {
			self::okUrl( $url, 'theme', 'activate', 'lwm-ref-theme' );
		}
		self::okUrl( $b2, 'plugin', 'activate', 'lwm-site-sample' );
		WpSites::ok( 'n', 'post', 'create', '--post_title=Network Fixture Main', '--post_status=publish', '--post_content=<a href="' . self::HOME . '/hello/">main</a>' );
		self::okUrl( $b2, 'post', 'create', '--post_title=Network Fixture B2', '--post_status=publish', '--post_content=<a href="' . $b2 . 'hello/">b2</a>' );
		self::okUrl( $b3, 'post', 'create', '--post_title=Network Fixture B3', '--post_status=publish' );
		WpSites::ok( 'n', 'user', 'create', 'editor2', 'editor2@net-n.test', '--role=editor', '--user_pass=editor-pass', '--url=' . $b2 );
		WpSites::ok( 'n', 'user', 'create', 'author3', 'author3@net-n.test', '--role=author', '--user_pass=author-pass', '--url=' . $b3 );
		$editor = trim( WpSites::ok( 'n', 'user', 'get', 'editor2', '--field=ID' ) );
		self::okUrl( $b2, 'post', 'create', '--post_title=By editor2', '--post_status=publish', '--post_author=' . $editor );
		WpSites::ok( 'n', 'transient', 'delete', '--all', '--network' );
	}
}
