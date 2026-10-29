<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * Core deletes an attachment's sizes next to its main file, wherever that is:
 * an attachment whose file is an absolute path reaches a theme folder or our
 * backups. The media reset deletes through UploadsOnly, which keeps them.
 */
final class MediaFilesE2ETest extends E2ETestCase {

	/** Plants a folder (args[0], under wp-content) holding a file (args[1]) and an attachment whose sizes name it. */
	private const PLANT = <<<'PHP'
$dir = WP_CONTENT_DIR . '/' . $args[0];
wp_mkdir_p( $dir );
file_put_contents( $dir . '/' . $args[1], 'kept' );
file_put_contents( $dir . '/main.jpg', 'x' );
$id = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'x', 'post_mime_type' => 'image/jpeg' ] );
update_post_meta( $id, '_wp_attached_file', $dir . '/main.jpg' );
update_post_meta( $id, '_wp_attachment_metadata', [ 'file' => $dir . '/main.jpg', 'sizes' => [ 'thumbnail' => [ 'file' => $args[1] ] ], 'original_image' => $args[1] ] );
echo $id;
PHP;

	/** A genuine attachment: relative file, sizes and original next to it in uploads. */
	private const GENUINE = <<<'PHP'
$dir = wp_upload_dir( null, false )['basedir'] . '/lwm-e2e-genuine';
wp_mkdir_p( $dir );
foreach ( [ 'real.jpg', 'real-150x150.jpg', 'real-scaled.jpg' ] as $f ) {
	file_put_contents( $dir . '/' . $f, 'x' );
}
$id = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'real', 'post_mime_type' => 'image/jpeg' ] );
update_post_meta( $id, '_wp_attached_file', 'lwm-e2e-genuine/real-scaled.jpg' );
update_post_meta( $id, '_wp_attachment_metadata', [ 'file' => 'lwm-e2e-genuine/real-scaled.jpg', 'sizes' => [ 'thumbnail' => [ 'file' => 'real-150x150.jpg' ] ], 'original_image' => 'real.jpg' ] );
echo $id;
PHP;

	/** The folders the media step keeps: our storage, the backups folders and the site's own folders. */
	private const KEPT = 'LightweightPlugins\\Migration\\Storage\\FolderIdentity::ids( LightweightPlugins\\Migration\\Export\\ExportRoots::sealedFolders( LightweightPlugins\\Migration\\WordPress\\SiteReader::read(), ( new LightweightPlugins\\Migration\\Services() )->storage()->root() ) ) + LightweightPlugins\\Migration\\Reset\\CoreFolders::ids( LightweightPlugins\\Migration\\WordPress\\SiteReader::read() )';

	private const CLEAN = 'foreach ( [ "themes/lwm-e2e-control", "themes/lwm-e2e-theme", "uploads/lwm-e2e-control", "uploads/lwm-e2e-backups", "uploads/lwm-e2e-genuine" ] as $d ) { array_map( "unlink", glob( WP_CONTENT_DIR . "/$d/*" ) ?: [] ); @rmdir( WP_CONTENT_DIR . "/$d" ); }';

	private static function plant( string $folder, string $file ): int {
		return (int) WpSites::ok( 'a', 'eval', '$args = ' . var_export( [ $folder, $file ], true ) . ';' . self::PLANT );
	}

	public function test_an_attachment_pointing_at_a_theme_or_our_backups_deletes_nothing_there(): void {
		$content = WpSites::path( 'a' ) . '/wp-content';
		try {
			$controls = [ self::plant( 'themes/lwm-e2e-control', 'functions.php' ), self::plant( 'uploads/lwm-e2e-control', 'site.wpress' ) ];
			WpSites::ok( 'a', 'eval', 'foreach ( [ ' . implode( ', ', $controls ) . ' ] as $id ) { wp_delete_attachment( $id, true ); }' );
			$this->assertFileDoesNotExist( $content . '/themes/lwm-e2e-control/functions.php', 'Core alone deletes the theme file (the threat is real).' );
			$this->assertFileDoesNotExist( $content . '/uploads/lwm-e2e-control/site.wpress', 'And a backup next to a planted main file.' );

			$ids  = [ self::plant( 'themes/lwm-e2e-theme', 'functions.php' ), self::plant( 'uploads/lwm-e2e-backups', 'site.wpress' ), (int) WpSites::ok( 'a', 'eval', self::GENUINE ) ];
			$kept = self::KEPT . ' + LightweightPlugins\Migration\Storage\FolderIdentity::ids( [ WP_CONTENT_DIR . "/uploads/lwm-e2e-backups" ] )';
			WpSites::ok( 'a', 'eval', 'foreach ( [ ' . implode( ', ', $ids ) . ' ] as $id ) { LightweightPlugins\Migration\Reset\UploadsOnly::delete( $id, ' . $kept . ' ); }' );
			$this->assertSame( '', trim( WpSites::ok( 'a', 'post', 'list', '--post_type=attachment', '--post_status=any', '--post__in=' . implode( ',', $ids ), '--format=ids' ) ), 'The attachments are gone.' );
			$this->assertFileExists( $content . '/themes/lwm-e2e-theme/functions.php' );
			$this->assertFileExists( $content . '/themes/lwm-e2e-theme/main.jpg' );
			$this->assertFileExists( $content . '/uploads/lwm-e2e-backups/site.wpress' );
			$this->assertFileDoesNotExist( $content . '/uploads/lwm-e2e-genuine/real.jpg', 'A genuine upload loses its files through the real kept set.' );
			$this->assertFileDoesNotExist( $content . '/uploads/lwm-e2e-genuine/real-150x150.jpg' );
			$this->assertFileDoesNotExist( $content . '/uploads/lwm-e2e-genuine/real-scaled.jpg' );
		} finally {
			WpSites::ok( 'a', 'eval', self::CLEAN );
		}
	}
}
