<?php
/**
 * A small wp-content tree that matches SiteFixture.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

final class SiteTree {

	public const FILES = [
		'wp-content/index.php'                                => "<?php\n",
		'wp-content/mu-plugins/host.php'                      => "<?php // mu\n",
		'wp-content/uploads/2026/10/photo.txt'                => "photo\n",
		'wp-content/plugins/sample/sample.php'                => "<?php // sample\n",
		'wp-content/plugins/inactive/inactive.php'            => "<?php // inactive\n",
		'wp-content/plugins/lw-migration/lw-migration.php'    => "<?php // own\n",
		'wp-content/themes/twentytwentyfive/style.css'        => "/* theme */\n",
		'wp-content/themes/old-theme/style.css'               => "/* old */\n",
		'wp-content/lw-migration-0123456789ab/jobs/index.php' => "<?php\n",
	];

	public static function create( string $root ): void {
		foreach ( self::FILES as $rel => $data ) {
			$path = $root . '/' . $rel;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}
			file_put_contents( $path, $data );
		}
	}
}
