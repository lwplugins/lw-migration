<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Export;

use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * ExportRoots + FileEnumerator on a real site tree with symlinks.
 */
final class ExportTreeTest extends TestCase {

	private const STORAGE = 'lw-migration-abcdef123456';

	private string $dir;

	private string $content;

	protected function setUp(): void {
		$this->dir     = TempDir::create();
		$this->content = $this->dir . '/wp-content';
		$files         = [
			'index.php',
			'uploads/2026/p.jpg',
			'plugins/foo/foo.php',
			'plugins/foo/sub/s.php',
			'themes/tw/style.css',
			'database/.ht.sqlite',
			self::STORAGE . '/backups/b.wpress',
			self::STORAGE . '/jobs/j1/job.json',
		];
		foreach ( $files as $rel ) {
			if ( ! is_dir( dirname( $this->content . '/' . $rel ) ) ) {
				mkdir( dirname( $this->content . '/' . $rel ), 0755, true );
			}
			file_put_contents( $this->content . '/' . $rel, 'x' );
		}
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @param array<string, mixed> $options Export options.
	 * @param array<string, mixed> $site    Site fields to change.
	 * @return array<int, string> Listed entries.
	 */
	private function export( array $options = [], array $site = [] ): array {
		$site  = SiteFixture::make( $this->dir, array_merge( [ 'sqliteDb' => $this->content . '/database/.ht.sqlite' ], $site ) );
		$roots = ExportRoots::build( $site, ExportOptions::fromArray( $options ), $this->content . '/' . self::STORAGE );
		$list  = $this->content . '/' . self::STORAGE . '/jobs/j1/files.list';
		$done  = ( new FileEnumerator( $roots, $list, ExportRoots::territories( $site ) ) )->run( new EnumCursor(), new NoDeadline(), static function (): void {} );
		$this->assertTrue( $done );

		$entries = [];
		foreach ( (array) file( $list ) as $line ) {
			$entries[] = FileEnumerator::parseLine( (string) $line )['entry'];
		}

		return $entries;
	}

	public function test_plain_tree(): void {
		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export() );
	}

	public function test_a_folder_link_into_another_root_stays_with_that_root(): void {
		symlink( '../plugins', $this->content . '/uploads/pl' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export() );
	}

	public function test_a_theme_linked_into_a_plugin_is_listed_under_themes_only(): void {
		symlink( '../../themes/tw', $this->content . '/plugins/foo/t' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export() );
	}

	public function test_a_root_left_out_by_an_option_is_not_archived_through_a_link(): void {
		symlink( '../plugins', $this->content . '/uploads/pl' );
		symlink( $this->content . '/plugins/foo', $this->content . '/foo-link' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'themes/tw/style.css' ], $this->export( [ 'noPlugins' => true ] ) );
	}

	public function test_content_folders_left_out_by_options_are_not_archived_through_a_link(): void {
		foreach ( [ 'mu-plugins/m.php', 'cache/c.html' ] as $rel ) {
			mkdir( dirname( $this->content . '/' . $rel ), 0755, true );
			file_put_contents( $this->content . '/' . $rel, 'x' );
		}
		symlink( '../mu-plugins', $this->content . '/uploads/mu' );
		symlink( '../../cache', $this->content . '/plugins/foo/cache' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export( [ 'noMustUsePlugins' => true, 'noCache' => true ] ) );
	}

	public function test_links_into_our_storage_and_the_database_file_are_never_listed(): void {
		symlink( $this->content . '/' . self::STORAGE . '/backups/b.wpress', $this->content . '/uploads/b.wpress' );
		symlink( $this->content . '/' . self::STORAGE . '/backups', $this->content . '/uploads/bk' );
		symlink( $this->content . '/' . self::STORAGE . '/jobs/j1/job.json', $this->content . '/job.json' );
		symlink( $this->content . '/database/.ht.sqlite', $this->content . '/plugins/foo/db.sqlite' );
		symlink( $this->content . '/database', $this->content . '/themes/tw/db' );

		$entries = $this->export();

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $entries );
	}

	public function test_excluded_files_reached_through_a_linked_folder_are_not_listed(): void {
		foreach ( [ 'w3tc-config/master.php', 'w3tc-config/other.php', 'big/b.bin' ] as $rel ) {
			if ( ! is_dir( dirname( $this->content . '/' . $rel ) ) ) {
				mkdir( dirname( $this->content . '/' . $rel ), 0755, true );
			}
			file_put_contents( $this->content . '/' . $rel, 'x' );
		}
		symlink( 'database', $this->content . '/aaa' );
		symlink( 'w3tc-config', $this->content . '/a2' );
		symlink( 'big', $this->content . '/a3' );
		symlink( '../../big', $this->content . '/uploads/2026/bb' );

		$entries = $this->export( [ 'excludeFiles' => [ 'big' ] ] );

		$this->assertSame( [ 'index.php', 'w3tc-config/other.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $entries );
	}

	public function test_a_link_to_the_site_root_never_lists_wp_config(): void {
		file_put_contents( $this->dir . '/wp-config.php', '<?php // secrets' );
		file_put_contents( $this->dir . '/root.php', 'r' );
		symlink( $this->dir, $this->content . '/site' );

		$entries = $this->export();

		$this->assertContains( 'site/root.php', $entries );
		$this->assertNotContains( 'site/wp-config.php', $entries );
		$this->assertSame( [], preg_grep( '#wp-config|\.ht\.sqlite|b\.wpress|job\.json|files\.list#', $entries ) );
	}

	/**
	 * Replaces one of our storage subfolders with a link to $target (a supported deploy setup).
	 */
	private function linkStorage( string $sub, string $target ): void {
		$path = $this->content . '/' . self::STORAGE . '/' . $sub;
		foreach ( (array) glob( $path . '/*/*' ) as $file ) {
			unlink( (string) $file );
		}
		foreach ( (array) glob( $path . '/*' ) as $item ) {
			is_dir( (string) $item ) ? rmdir( (string) $item ) : unlink( (string) $item );
		}
		rmdir( $path );
		symlink( $target, $path );
	}

	public function test_a_backups_folder_linked_into_uploads_is_never_listed(): void {
		mkdir( $this->content . '/uploads/backup-volume' );
		file_put_contents( $this->content . '/uploads/backup-volume/site-20260101-000000-001.wpress', 'secret-backup' );
		$this->linkStorage( 'backups', $this->content . '/uploads/backup-volume' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export() );
	}

	public function test_storage_folders_linked_outside_are_not_listed_through_a_second_link(): void {
		foreach ( [ 'shared/backups/site-20260101-000000-001.wpress', 'shared/jobs/abc/database.sql' ] as $rel ) {
			mkdir( dirname( $this->dir . '/' . $rel ), 0755, true );
			file_put_contents( $this->dir . '/' . $rel, 'secret' );
		}
		$this->linkStorage( 'backups', $this->dir . '/shared/backups' );
		$this->linkStorage( 'jobs', $this->dir . '/shared/jobs' );
		mkdir( $this->dir . '/shared/jobs/j1' );
		symlink( $this->dir . '/shared', $this->content . '/uploads/shared-link' );
		file_put_contents( $this->dir . '/shared/public.txt', 'p' );

		$entries = $this->export();

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'uploads/shared-link/public.txt', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $entries );
	}

	public function test_a_legacy_backups_folder_linked_into_uploads_is_not_listed(): void {
		mkdir( $this->content . '/uploads/old-tool' );
		file_put_contents( $this->content . '/uploads/old-tool/x.wpress', 'ext' );
		symlink( $this->content . '/uploads/old-tool', $this->content . '/ai1wm-backups' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export() );
	}

	public function test_a_custom_backups_folder_linked_into_uploads_is_not_listed(): void {
		mkdir( $this->content . '/uploads/custom' );
		file_put_contents( $this->content . '/uploads/custom/y.wpress', 'ext' );
		symlink( $this->content . '/uploads/custom', $this->content . '/my-backups' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export( [], [ 'externalBackupsPath' => $this->content . '/my-backups' ] ) );
	}

	public function test_a_backups_folder_linked_to_the_site_or_a_root_does_not_empty_the_backup(): void {
		symlink( $this->content, $this->content . '/my-backups' );
		symlink( $this->content . '/uploads', $this->content . '/ai1wm-backups' );

		$this->assertSame( [ 'index.php', 'uploads/2026/p.jpg', 'plugins/foo/foo.php', 'plugins/foo/sub/s.php', 'themes/tw/style.css' ], $this->export( [], [ 'externalBackupsPath' => $this->content . '/my-backups' ] ) );
	}
}
