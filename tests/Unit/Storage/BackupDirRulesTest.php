<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage;

use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderRefusedException as R;
use LightweightPlugins\Migration\Storage\MarkedFolder;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class BackupDirRulesTest extends TestCase {

	private const OURS = "lw-migration-backups\naaaaaaaaaaaaaaaa\n";

	private string $base;
	private string $site;

	protected function setUp(): void {
		$this->base = (string) realpath( TempDir::create() );
		$this->site = $this->base . '/www/site';
		mkdir( $this->site . '/wp-content', 0755, true );
		mkdir( $this->base . '/home', 0755 );
	}

	protected function tearDown(): void {
		foreach ( [ '/home/open', '/home/pub', '/home/used-real', '/home/group', '/home/anc' ] as $dir ) {
			if ( is_dir( $this->base . $dir ) && ! is_link( $this->base . $dir ) ) {
				chmod( $this->base . $dir, 0755 );
			}
		}
		TempDir::remove( $this->base );
	}

	/**
	 * @param array<int, int> $owners Allowed owners.
	 */
	private function rules( string $marker = 'aaaaaaaaaaaaaaaa', string $basedir = '', array $owners = [] ): BackupDirRules {
		return new BackupDirRules(
			[ $this->site, $this->site . '/wp-content', $this->base . '/www' ],
			[ $this->site . '/wp-content/plugins', $this->site . '/wp-content/uploads', $this->site . '/wp-admin' ],
			[ $this->site . '/wp-content/lw-migration-0123456789ab', $this->base . '/home/other-tool' ],
			$basedir,
			$marker,
			[ $this->base . '/sys' ],
			[ '/', $this->base . '/home' ],
			static fn(): string => 'abcdef012345',
			[ $this->site . '/wp-content' ],
			[ $this->base . '/langs' ],
			$owners
		);
	}

	private function refused( string $reason, \Closure $call ): void {
		try {
			$call();
			$this->fail( 'Expected refusal: ' . $reason );
		} catch ( R $e ) {
			$this->assertSame( $reason, $e->reason );
		}
	}

	/** Whether the temp folder's file system ignores case (APFS and HFS+ by default). */
	private function caseInsensitive(): bool {
		clearstatcache();

		return is_dir( $this->base . '/WWW' );
	}

	private function skipAsRoot(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores folder modes.' );
		}
	}

	public function test_every_folder_but_one_already_marked_as_ours_makes_a_random_child(): void {
		mkdir( $this->base . '/home/own', 0755 );
		symlink( $this->site . '/wp-content', $this->base . '/home/content-link' );

		$this->assertTrue( $this->rules()->makesRandomChild( $this->site . '/wp-content' ) );
		$this->assertTrue( $this->rules()->makesRandomChild( $this->base . '/home/content-link' ), 'By identity, through a link.' );
		$this->assertTrue( $this->rules()->makesRandomChild( $this->base . '/home/own' ), 'Also outside a web root.' );
		mkdir( $this->base . '/home/chosen', 0750 );
		file_put_contents( $this->base . '/home/chosen/' . BackupDirRules::MARKER, self::OURS );
		$this->assertFalse( $this->rules()->makesRandomChild( $this->base . '/home/chosen' ), 'Chosen before: used as it is.' );
		$this->assertTrue( $this->rules( 'bbbbbbbbbbbbbbbb' )->makesRandomChild( $this->base . '/home/chosen' ) );
		$this->assertFalse( $this->rules()->makesRandomChild( $this->base . '/home/missing' ) );
	}

	public function test_paths_are_normalised(): void {
		$this->assertSame( '/a/b', BackupDirRules::normalise( ' //a//b/ ' ) );
		$this->assertSame( '/', BackupDirRules::normalise( '/' ) );
		$this->assertSame( '/a/.hidden/b.c', BackupDirRules::normalise( '/a/.hidden/b.c' ) );
		$this->assertSame( '/a/my backups', BackupDirRules::normalise( '/a/my backups' ) );
	}

	public function test_relative_dot_control_backslash_and_long_paths_are_refused(): void {
		$this->refused( R::NOT_ABSOLUTE, static fn() => BackupDirRules::normalise( 'backups' ) );
		$this->refused( R::NOT_ABSOLUTE, static fn() => BackupDirRules::normalise( 'C:/backups' ) );
		foreach ( [ '', '   ', '/a/../etc', '/a/./b', '/a/..', "/a\0b", "/a\nb", '/a\\b', 'C:\\backups', '\\\\server\\share', '/' . str_repeat( 'x', 1024 ), "/\xff" ] as $bad ) {
			$this->refused( R::INVALID, static fn() => BackupDirRules::normalise( $bad ) );
		}
	}

	public function test_control_characters_are_refused_before_any_trimming(): void {
		foreach ( [ "/a/b\0", "/a/b\n", "/a/b\t", "\t/a/b", "/a/b\r", "/a/b\x7f", "/a/b\u{0085}", "/a/\u{202E}b", "/a/b\u{200B}", "/a/b\u{00A0}", "/a/\u{3000}b", "/a\u{2009}/b" ] as $bad ) {
			$this->refused( R::INVALID, static fn() => BackupDirRules::normalise( $bad ) );
		}
	}

	public function test_segments_with_trailing_dots_or_spaces_are_refused(): void {
		foreach ( [ '/a/plugins./x', '/a/plugins /x', '/a/ plugins/x', '/a/b...', '/a/b /' ] as $bad ) {
			$this->refused( R::INVALID, static fn() => BackupDirRules::normalise( $bad ) );
		}
	}

	public function test_system_folders_are_refused(): void {
		mkdir( $this->base . '/sys/deep', 0755, true );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( $this->base . '/sys/deep' ) );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( $this->base . '/sys/not/there' ) );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( $this->base . '/home' ) );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( '/' ) );
		symlink( $this->base . '/sys/deep', $this->base . '/home/to-sys' );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( $this->base . '/home/to-sys/new' ) );
	}

	public function test_site_folders_are_refused_by_typed_and_real_path(): void {
		mkdir( $this->site . '/wp-content/plugins/x', 0755, true );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->site . '/wp-content/plugins' ) );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->site . '/wp-content/plugins/x' ) );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->site . '/wp-admin/new' ) ); // No such folder: refused by name before any file-system call.
		symlink( $this->site . '/wp-content/plugins', $this->base . '/home/alias' );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->base . '/home/alias/new' ) );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->base . '/home/alias/x' ) );
	}

	public function test_a_link_in_an_ancestor_into_the_web_root_counts_as_inside(): void {
		mkdir( $this->base . '/www/sub', 0755 );
		symlink( $this->base . '/www', $this->base . '/home/web' );
		$this->assertSame( $this->base . '/www/sub/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/home/web/sub' ) );
	}

	public function test_site_folders_web_roots_and_overlaps_are_found_by_identity_whatever_the_case(): void {
		if ( ! $this->caseInsensitive() ) {
			$this->markTestSkipped( 'The temp folder is on a case-sensitive file system.' );
		}
		mkdir( $this->site . '/wp-content/plugins/x', 0755, true );
		mkdir( $this->base . '/home/other-tool/x', 0755, true );
		mkdir( $this->base . '/sys/deep', 0755, true );
		mkdir( $this->base . '/www/sub', 0755 );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->site . '/WP-Content/Plugins/x' ) );
		mkdir( $this->site . '/wp-admin', 0755 );
		$this->refused( R::SITE_FOLDER, fn() => $this->rules()->check( $this->site . '/WP-Admin/new' ) );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->site . '/wp-content/PLUGINS/new' ) ); // Below wp-content by name: refused before the file system.
		$this->refused( R::OVERLAP, fn() => $this->rules()->check( $this->base . '/home/OTHER-TOOL/x' ) );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( $this->base . '/SYS/deep' ) );
		$this->refused( R::SYSTEM, fn() => $this->rules()->check( $this->base . '/HOME' ) );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->site . '/WP-CONTENT/cache-x' ) );
		$this->assertSame( $this->base . '/www/sub/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/WWW/sub' ), 'Stored in the web root\'s own spelling.' );
		$this->assertMatchesRegularExpression( '#/lw-migration-backups-abcdef012345\z#', ( new BackupDirRules( [], [], [ $this->base . '/home/other-tool/x' ], '', 'aaaaaaaaaaaaaaaa', [], [], static fn(): string => 'abcdef012345' ) )->check( $this->base . '/HOME/Other-Tool' ), 'A new folder beside another backup folder, not above it.' );
	}

	public function test_inside_a_web_root_the_folder_gets_a_random_suffix(): void {
		$this->assertSame( $this->site . '/wp-content/lw-migration-backups-abcdef012345', $this->rules()->check( $this->site . '/wp-content' ) );
		$this->assertSame( $this->base . '/www/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/www/' ) );
		$this->assertSame( $this->site . '/lw-migration-backups-abcdef012345', $this->rules()->check( $this->site ) );
		$this->refused( R::NO_PARENT, fn() => $this->rules()->check( $this->site . '/not-there' ) );
		symlink( $this->site, $this->base . '/home/to-site' ); // a link from outside into the web root counts as inside
		$this->assertSame( $this->site . '/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/home/to-site' ) );
		$this->assertMatchesRegularExpression( '#/lw-migration-backups-[a-f0-9]{12}\z#', ( new BackupDirRules( [ $this->site ], [], [], '', '', [], [] ) )->check( $this->site ) );
	}

	public function test_a_folder_that_serves_pages_counts_as_a_web_root(): void {
		foreach ( [ 'index.php', 'index.html', '.htaccess', 'wp-config.php', 'index.htm', 'index.shtml', 'default.htm', 'default.html', 'default.php', '.user.ini' ] as $i => $file ) {
			$vhost = $this->base . '/home/vhost' . $i;
			mkdir( $vhost . '/sub', 0755, true );
			touch( $vhost . '/' . $file );
			$this->assertSame( $vhost . '/sub/lw-migration-backups-abcdef012345', $this->rules()->check( $vhost . '/sub' ), $file );
			$this->refused( R::NO_PARENT, fn() => $this->rules()->check( $vhost . '/sub/new' ) );
		}
	}

	public function test_inside_wp_content_only_wp_content_itself_holds_the_folder(): void {
		mkdir( $this->site . '/wp-content/upgrade', 0755 );
		mkdir( $this->base . '/langs/hu', 0755, true );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->site . '/wp-content/upgrade' ) );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->site . '/wp-content/cache/new' ) ); // By name, before the file system.
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->base . '/langs' ) );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->base . '/langs/hu' ) );
		symlink( $this->site . '/wp-content/upgrade', $this->base . '/home/up' );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $this->base . '/home/up' ) );
		$this->assertSame( $this->site . '/wp-content/lw-migration-backups-abcdef012345', $this->rules()->check( $this->site . '/wp-content/' ) );
	}

	public function test_a_typed_suffix_name_that_is_not_ours_is_not_trusted(): void {
		$typed = $this->base . '/www/lw-migration-backups-000000000000';
		mkdir( $typed, 0750 );
		$this->assertSame( $typed . '/lw-migration-backups-abcdef012345', $this->rules()->check( $typed ) );
		file_put_contents( $typed . '/' . BackupDirRules::MARKER, self::OURS );
		$this->assertSame( $typed, $this->rules()->check( $typed ) ); // ours: reused as it is (finish / undo / choose again)
		$this->refused( R::FOREIGN, fn() => $this->rules( 'bbbbbbbbbbbbbbbb' )->check( $typed ) ); // Another site's folder never holds ours.

		$content = $this->site . '/wp-content/lw-migration-backups-000000000000';
		mkdir( $content, 0750 );
		$this->refused( R::CONTENT_SUBFOLDER, fn() => $this->rules()->check( $content ) );
		file_put_contents( $content . '/' . BackupDirRules::MARKER, self::OURS );
		$this->assertSame( $content, $this->rules()->check( $content ) );
		symlink( $content, $this->base . '/home/to-ours' );
		$this->refused( R::LINK, fn() => $this->rules()->check( $this->base . '/home/to-ours' ) );
	}

	public function test_overlapping_folders_are_refused_both_ways(): void {
		mkdir( $this->base . '/home/other-tool/x', 0755, true );
		$this->refused( R::OVERLAP, fn() => $this->rules()->check( $this->base . '/home/other-tool/x' ) );
		$this->refused( R::OVERLAP, fn() => $this->rules()->check( $this->base . '/home/other-tool/new' ) );
		mkdir( $this->base . '/home/u', 0755 );
		$beside = fn( string $other ): BackupDirRules => new BackupDirRules( [], [], [ $other ], '', 'aaaaaaaaaaaaaaaa', [], [], static fn(): string => 'abcdef012345' );
		$this->assertSame( $this->base . '/home/u/lw-migration-backups-abcdef012345', $beside( $this->base . '/home/u/inner' )->check( $this->base . '/home/u' ), 'The new folder is beside the other one, not above it.' );
		mkdir( $this->base . '/home/v', 0755 );
		symlink( $this->base . '/home/v', $this->base . '/home/v-link' );
		$this->assertSame( $this->base . '/home/v/lw-migration-backups-abcdef012345', $beside( $this->base . '/home/v-link/inner' )->check( $this->base . '/home/v' ) );
		$this->refused( R::OVERLAP, fn() => $this->rules()->check( $this->site . '/wp-content/lw-migration-0123456789ab/backups' ) );
	}

	public function test_open_basedir_limits_the_folder(): void {
		mkdir( $this->base . '/home/u', 0755 );
		$this->refused( R::OPEN_BASEDIR, fn() => $this->rules( 'aaaaaaaaaaaaaaaa', $this->base . '/home/u' . PATH_SEPARATOR . $this->site )->check( $this->base . '/home/other' ) );
		$this->refused( R::OPEN_BASEDIR, fn() => $this->rules( 'aaaaaaaaaaaaaaaa', $this->base . '/home/u' )->check( $this->base . '/elsewhere/no/such' ) ); // Not NO_PARENT: refused before the file system.
		mkdir( $this->base . '/home/u/b', 0755 );
		$this->assertSame( $this->base . '/home/u/b/lw-migration-backups-abcdef012345', $this->rules( 'aaaaaaaaaaaaaaaa', $this->base . '/home/u' )->check( $this->base . '/home/u/b' ) );
		symlink( $this->base . '/home', $this->base . '/home/u/out' );
		$this->refused( R::OPEN_BASEDIR, fn() => $this->rules( 'aaaaaaaaaaaaaaaa', $this->base . '/home/u' )->check( $this->base . '/home/u/out/new' ) );
	}

	public function test_a_missing_parent_a_link_and_a_file_are_refused(): void {
		$this->refused( R::NO_PARENT, fn() => $this->rules()->check( $this->base . '/home/no/such' ) );
		mkdir( $this->base . '/home/real', 0755 );
		symlink( $this->base . '/home/real', $this->base . '/home/link' );
		$this->refused( R::LINK, fn() => $this->rules()->check( $this->base . '/home/link' ) );
		symlink( $this->base . '/home/gone', $this->base . '/home/dangling' );
		$this->refused( R::LINK, fn() => $this->rules()->check( $this->base . '/home/dangling' ) );
		touch( $this->base . '/home/file' );
		$this->refused( R::NOT_FOLDER, fn() => $this->rules()->check( $this->base . '/home/file' ) );
		$this->refused( R::NO_PARENT, fn() => $this->rules()->check( $this->base . '/home/file/new' ) );
	}

	public function test_a_world_writable_folder_or_parent_is_refused(): void {
		mkdir( $this->base . '/home/open', 0755 );
		chmod( $this->base . '/home/open', 0777 );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules()->check( $this->base . '/home/open' ) );
		mkdir( $this->base . '/home/open/inner', 0755 );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules()->check( $this->base . '/home/open/inner' ) );
		mkdir( $this->base . '/home/open/inner/deeper', 0755 );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules()->check( $this->base . '/home/open/inner/deeper' ) );
	}

	public function test_a_sticky_ancestor_is_accepted_but_not_as_the_direct_parent(): void {
		mkdir( $this->base . '/home/pub/inner', 0755, true );
		chmod( $this->base . '/home/pub', 01777 );
		$this->assertSame( $this->base . '/home/pub/inner/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/home/pub/inner' ) );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules()->check( $this->base . '/home/pub' ) );
	}

	public function test_a_group_writable_folder_is_refused(): void {
		mkdir( $this->base . '/home/group', 0755 );
		chmod( $this->base . '/home/group', 0770 );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules()->check( $this->base . '/home/group' ) );
	}

	public function test_a_folder_with_another_sites_marker_is_refused(): void {
		mkdir( $this->base . '/home/full', 0755 );
		touch( $this->base . '/home/full/notes.txt' );
		$this->assertSame( $this->base . '/home/full/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/home/full' ), 'Only the parent: other files in it do not matter.' );
		mkdir( $this->base . '/home/theirs', 0755 );
		file_put_contents( $this->base . '/home/theirs/' . BackupDirRules::MARKER, "lw-migration-backups\nbbbbbbbbbbbbbbbb\n" );
		$this->refused( R::FOREIGN, fn() => $this->rules()->check( $this->base . '/home/theirs' ) );
		$this->refused( R::FOREIGN, fn() => $this->rules( '' )->check( $this->base . '/home/theirs' ) );
		mkdir( $this->base . '/home/odd', 0755 );
		file_put_contents( $this->base . '/home/odd/' . BackupDirRules::MARKER, 'something else' );
		$this->refused( R::FOREIGN, fn() => $this->rules()->check( $this->base . '/home/odd' ) );
	}

	public function test_an_empty_folder_holds_a_new_one_and_our_own_is_used_as_it_is(): void {
		$this->assertSame( $this->base . '/home/new/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/home//new/' ), 'A new typed folder holds the child.' );
		mkdir( $this->base . '/home/empty', 0750 );
		$this->assertSame( $this->base . '/home/empty/lw-migration-backups-abcdef012345', $this->rules()->check( $this->base . '/home/empty' ) );
		mkdir( $this->base . '/home/ours', 0750 );
		file_put_contents( $this->base . '/home/ours/' . BackupDirRules::MARKER, self::OURS );
		touch( $this->base . '/home/ours/site-20261009-120000-001.wpress' );
		$this->assertSame( $this->base . '/home/ours', $this->rules()->check( $this->base . '/home/ours' ) );
	}

	public function test_prepare_creates_one_level_marks_and_protects(): void {
		$dir = $this->base . '/home/made';
		$this->assertSame( [], BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( 0750, fileperms( $dir ) & 0777 );
		$this->assertSame( 'aaaaaaaaaaaaaaaa', BackupDirRules::markerOf( $dir ) );
		foreach ( [ '.htaccess', 'web.config', 'index.php', 'index.html', 'robots.txt' ] as $file ) {
			$this->assertFileExists( $dir . '/' . $file );
		}
		$this->assertSame( "User-agent: *\nDisallow: /\n", file_get_contents( $dir . '/robots.txt' ) );
		$this->assertNull( BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [], BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' ), 'Our own folder is prepared again without a change.' );
		$this->refused( R::NOT_WRITABLE, static fn() => BackupDirRules::prepare( $dir . '/a/b', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertDirectoryDoesNotExist( $dir . '/a' );
	}

	public function test_prepare_makes_the_new_typed_folder_with_its_child_and_removes_both_on_failure(): void {
		$child = $this->rules()->check( $this->base . '/home/typed' );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/typed', 'check() writes nothing.' );
		$this->assertSame( [], BackupDirRules::prepare( $child, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( 0750, fileperms( $this->base . '/home/typed' ) & 0777 );
		$this->assertSame( 0750, fileperms( $child ) & 0777 );
		$this->assertNull( BackupDirRules::inUse( $child, 'aaaaaaaaaaaaaaaa' ) );

		$this->refused( R::WORLD_WRITABLE, fn() => BackupDirRules::prepare( $this->base . '/home/gone/lw-migration-backups-abcdef012345', 'aaaaaaaaaaaaaaaa', [ 99999 ] ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/gone' );
	}

	public function test_prepare_does_not_make_a_missing_container_for_any_other_name(): void {
		$this->refused( R::NOT_WRITABLE, fn() => BackupDirRules::prepare( $this->base . '/home/a/typed-name', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/a' );
	}

	public function test_prepare_never_replaces_a_file_it_did_not_write(): void {
		$dir = $this->base . '/home/theirs';
		mkdir( $dir, 0750 );
		file_put_contents( $dir . '/' . BackupDirRules::MARKER, 'something else' );
		$this->refused( R::FOREIGN, static fn() => BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( 'something else', file_get_contents( $dir . '/' . BackupDirRules::MARKER ) );
		$this->assertFileDoesNotExist( $dir . '/.htaccess' );
	}

	public function test_prepare_checks_the_folder_again_and_never_follows_a_link(): void {
		$full = $this->base . '/home/full';
		mkdir( $full, 0750 );
		touch( $full . '/notes.txt' );
		$this->refused( R::NOT_EMPTY, static fn() => BackupDirRules::prepare( $full, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertFileDoesNotExist( $full . '/' . BackupDirRules::MARKER );

		file_put_contents( $this->base . '/home/target.txt', 'keep' );
		$planted = $this->base . '/home/planted';
		mkdir( $planted, 0750 );
		symlink( $this->base . '/home/target.txt', $planted . '/' . BackupDirRules::MARKER );
		$this->refused( R::FOREIGN, static fn() => BackupDirRules::prepare( $planted, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( 'keep', file_get_contents( $this->base . '/home/target.txt' ) );

		mkdir( $this->base . '/home/real', 0750 );
		symlink( $this->base . '/home/real', $this->base . '/home/link' );
		$this->refused( R::LINK, fn() => BackupDirRules::prepare( $this->base . '/home/link', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [ '.', '..' ], scandir( $this->base . '/home/real' ) );

		mkdir( $this->base . '/home/group', 0750 );
		chmod( $this->base . '/home/group', 0770 );
		$this->refused( R::WORLD_WRITABLE, fn() => BackupDirRules::prepare( $this->base . '/home/group', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [ '.', '..' ], scandir( $this->base . '/home/group' ) );
	}

	public function test_prepare_leaves_a_deny_file_it_did_not_write_and_names_it(): void {
		$dir = $this->base . '/home/ours';
		mkdir( $dir, 0750 );
		file_put_contents( $dir . '/' . BackupDirRules::MARKER, self::OURS );
		file_put_contents( $dir . '/.htaccess', 'Allow from all' );
		file_put_contents( $this->base . '/home/target.php', '<?php // theirs' );
		symlink( $this->base . '/home/target.php', $dir . '/index.php' );

		$this->assertSame( [ '.htaccess', 'index.php' ], BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( 'Allow from all', file_get_contents( $dir . '/.htaccess' ) );
		$this->assertSame( '<?php // theirs', file_get_contents( $this->base . '/home/target.php' ) );
		$this->assertTrue( is_link( $dir . '/index.php' ) );
		$this->assertFileExists( $dir . '/web.config' );
		$this->assertFileExists( $dir . '/robots.txt' );
	}

	public function test_our_prepared_folder_is_checked_again_as_the_same_folder(): void {
		mkdir( $this->base . '/home/mine', 0750 );
		$child = $this->rules()->check( $this->base . '/home/mine' );
		BackupDirRules::prepare( $child, 'aaaaaaaaaaaaaaaa' );
		$this->assertSame( $child, $this->rules()->check( $child ) );
		$path = $this->base . '/home/chosen-before';
		BackupDirRules::prepare( $path, 'aaaaaaaaaaaaaaaa' );
		$this->assertSame( $path, $this->rules()->check( $path ), 'Its own deny files do not make it a web root.' );
		file_put_contents( $path . '/index.php', '<?php echo 1;' );
		$this->assertSame( $path . '/lw-migration-backups-abcdef012345', $this->rules()->check( $path ), 'A page file of anyone else counts.' );
	}

	public function test_a_parent_swapped_for_a_link_after_the_check_writes_nothing(): void {
		mkdir( $this->base . '/home/par', 0750 );
		mkdir( $this->site . '/wp-content/uploads', 0755 );
		mkdir( $this->base . '/home/par/new', 0750 );
		mkdir( $this->site . '/wp-content/uploads/new', 0755 );
		$path = $this->rules()->check( $this->base . '/home/par/new' );
		rename( $this->base . '/home/par', $this->base . '/home/par-real' );
		symlink( $this->site . '/wp-content/uploads', $this->base . '/home/par' );
		$this->refused( R::LINK, static fn() => BackupDirRules::prepare( $path, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [ '.', '..' ], scandir( $this->site . '/wp-content/uploads/new' ) );

		mkdir( $this->base . '/home/e2', 0750 );
		mkdir( $this->site . '/wp-content/themes', 0755 );
		$path = $this->rules()->check( $this->base . '/home/e2' );
		rename( $this->base . '/home/e2', $this->base . '/home/e2-real' );
		symlink( $this->site . '/wp-content/themes', $this->base . '/home/e2' );
		$this->refused( R::LINK, static fn() => BackupDirRules::prepare( $path, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [ '.', '..' ], scandir( $this->site . '/wp-content/themes' ) );
	}

	public function test_a_group_writable_parent_of_a_new_folder_is_refused(): void {
		mkdir( $this->base . '/home/group', 0750 );
		chmod( $this->base . '/home/group', 0770 );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules()->check( $this->base . '/home/group' ) );
		$this->refused( R::WORLD_WRITABLE, fn() => BackupDirRules::prepare( $this->base . '/home/group/new', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/group/new' );
	}

	public function test_a_failed_prepare_removes_only_what_it_created(): void {
		mkdir( $this->base . '/home/vh', 0750 );
		touch( $this->base . '/home/vh/index.php' );
		$this->refused( R::UNPROTECTED, fn() => BackupDirRules::prepare( $this->base . '/home/vh/new', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/vh/new' );

		mkdir( $this->base . '/home/vh/kept', 0750 );
		file_put_contents( $this->base . '/home/vh/kept/.htaccess', 'theirs' );
		file_put_contents( $this->base . '/home/vh/kept/' . BackupDirRules::MARKER, self::OURS );
		$this->refused( R::UNPROTECTED, fn() => BackupDirRules::prepare( $this->base . '/home/vh/kept', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [ '.', '..', '.htaccess', BackupDirRules::MARKER ], scandir( $this->base . '/home/vh/kept' ) );

		$this->refused( R::WORLD_WRITABLE, fn() => BackupDirRules::prepare( $this->base . '/home/not-mine', 'aaaaaaaaaaaaaaaa', [ 99999 ] ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/not-mine' );
	}

	public function test_files_appear_whole_and_a_dead_temp_does_not_count(): void {
		$dir = $this->base . '/home/atomic';
		mkdir( $dir, 0750 );
		file_put_contents( $dir . '/.lwm-0123456789abcdef.part', 'half' );
		$this->assertSame( $dir . '/lw-migration-backups-abcdef012345', $this->rules()->check( $dir ) );
		BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' );
		$temps = array_values( preg_grep( '/^\.lwm-/', (array) scandir( $dir ) ) );
		$this->assertSame( [ '.lwm-0123456789abcdef.part' ], $temps, 'Only the dead temp is left; every new file was linked in whole.' );
		$this->assertSame( 1, fileinode( $dir . '/.htaccess' ) > 0 ? 1 : 0 );
		$this->assertSame( 1, stat( $dir . '/.htaccess' )['nlink'], 'The temp name is gone.' );
	}

	public function test_only_the_allowed_owners_may_own_the_folder(): void {
		mkdir( $this->base . '/home/owned', 0750 );
		BackupDirRules::prepare( $this->base . '/home/owned', 'aaaaaaaaaaaaaaaa' );
		$this->refused( R::WORLD_WRITABLE, fn() => $this->rules( 'aaaaaaaaaaaaaaaa', '', [ 99999 ] )->check( $this->base . '/home/owned' ) );
		$this->assertSame( $this->base . '/home/owned', $this->rules( 'aaaaaaaaaaaaaaaa', '', [ 99999, (int) fileowner( $this->base . '/home/owned' ) ] )->check( $this->base . '/home/owned' ) );
		$this->assertSame( R::WORLD_WRITABLE, BackupDirRules::inUse( $this->base . '/home/owned', 'aaaaaaaaaaaaaaaa', [ 99999 ] ) );
		$this->assertNull( BackupDirRules::inUse( $this->base . '/home/owned', 'aaaaaaaaaaaaaaaa', [ 99999, (int) fileowner( $this->base . '/home/owned' ) ] ) );
	}

	public function test_in_use_notices_a_loose_ancestor_a_page_file_above_and_a_missing_deny_file(): void {
		mkdir( $this->base . '/home/anc', 0750 );
		$dir = $this->base . '/home/anc/f';
		BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' );
		chmod( $this->base . '/home/anc', 0777 );
		$this->assertSame( R::WORLD_WRITABLE, BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		chmod( $this->base . '/home/anc', 0750 );
		touch( $this->base . '/home/anc/index.php' );
		$this->assertSame( R::UNPROTECTED, BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		unlink( $this->base . '/home/anc/index.php' );
		$this->assertNull( BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		unlink( $dir . '/.htaccess' );
		$this->assertSame( R::UNPROTECTED, BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( [], BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' ), 'Prepared again: the missing file is made.' );
		$this->assertNull( BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );

		$suffix = $this->rules()->check( $this->base . '/www' );
		BackupDirRules::prepare( $suffix, 'aaaaaaaaaaaaaaaa' );
		touch( $this->base . '/www/index.php' );
		$this->assertNull( BackupDirRules::inUse( $suffix, 'aaaaaaaaaaaaaaaa' ), 'Our random name is the protection in a web root.' );
	}

	public function test_a_link_out_of_open_basedir_is_refused_without_a_warning(): void {
		mkdir( $this->base . '/allowed/inside', 0750, true );
		mkdir( $this->base . '/outside/fz', 0750, true );
		symlink( $this->base . '/outside', $this->base . '/allowed/escape' );
		symlink( $this->base . '/outside/fz', $this->base . '/allowed/escape-fz' );
		$repo   = dirname( __DIR__, 3 );
		$script = 'define( "ABSPATH", $argv[2] . "/" ); $b = $argv[1]; require $argv[2] . "/vendor/autoload.php"; $out = [];'
			. ' $r = new LightweightPlugins\Migration\Storage\BackupDirRules( [], [], [], (string) ini_get( "open_basedir" ), "aaaaaaaaaaaaaaaa", [], [] );'
			. ' foreach ( [ "allowed/new", "allowed/inside", "allowed/escape/new", "allowed/escape-fz", "allowed/escape", "outside/x" ] as $p ) {'
			. ' try { $out[ $p ] = $r->check( "$b/$p" ); } catch ( LightweightPlugins\Migration\Storage\FolderRefusedException $e ) { $out[ $p ] = $e->reason; } }'
			. ' echo json_encode( $out );';
		$command = [ PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'open_basedir=' . $this->base . '/allowed' . PATH_SEPARATOR . $repo, '-r', $script, $this->base, $repo ];
		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes );
		$this->assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		proc_close( $process );

		$this->assertStringNotContainsString( 'Warning', $output );
		$this->assertStringNotContainsString( 'open_basedir restriction', $output );
		$this->assertSame(
			[
				'allowed/new'        => $this->base . '/allowed/new/lw-migration-backups-' . substr( (string) ( json_decode( $output, true )['allowed/new'] ?? '' ), -12 ),
				'allowed/inside'     => $this->base . '/allowed/inside/lw-migration-backups-' . substr( (string) ( json_decode( $output, true )['allowed/inside'] ?? '' ), -12 ),
				'allowed/escape/new' => R::OPEN_BASEDIR,
				'allowed/escape-fz'  => R::OPEN_BASEDIR,
				'allowed/escape'     => R::OPEN_BASEDIR,
				'outside/x'          => R::OPEN_BASEDIR,
			],
			json_decode( $output, true ),
			$output
		);
	}

	public function test_a_readable_link_is_a_link(): void {
		mkdir( $this->base . '/home/target', 0750 );
		symlink( $this->base . '/home/target', $this->base . '/home/plain-link' );
		$this->refused( R::LINK, fn() => $this->rules()->check( $this->base . '/home/plain-link' ) );
		$this->refused( R::LINK, fn() => $this->rules()->check( $this->base . '/home/plain-link/' ) );
	}

	public function test_a_swap_by_another_process_is_seen_through_the_realpath_cache(): void {
		$dir = $this->base . '/home/rc/A/bk';
		mkdir( dirname( $dir ), 0750, true );
		BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' );
		mkdir( $this->base . '/home/rc/evil', 0750 );
		$this->assertNull( BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( $dir, realpath( $dir ), 'The real path is cached now.' );

		$script  = '$rc = $argv[1]; rename( "$rc/A", "$rc/A.real" ); symlink( "$rc/A.real", "$rc/A" );';
		$process = proc_open( [ PHP_BINARY, '-r', $script, $this->base . '/home/rc' ], [], $pipes );
		$this->assertIsResource( $process );
		$this->assertSame( 0, proc_close( $process ) );

		$this->assertSame( R::CHANGED, BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertTrue( is_link( $this->base . '/home/rc/A' ) );
	}

	public function test_protect_hands_back_every_file_it_made(): void {
		$dir = $this->base . '/home/plain';
		mkdir( $dir, 0750 );
		file_put_contents( $dir . '/web.config', 'theirs' );
		$created = [];
		$left    = \LightweightPlugins\Migration\Storage\MarkedFolder::protect( $dir, (string) \LightweightPlugins\Migration\Storage\FolderIdentity::of( $dir ), $created );
		$this->assertSame( [ 'web.config' ], $left );
		$this->assertSame( [ '.htaccess', 'index.php', 'index.html', 'robots.txt' ], array_keys( $created ) );
		mkdir( $dir . '2', 0750 );
		$this->refused( R::CHANGED, static fn() => \LightweightPlugins\Migration\Storage\MarkedFolder::protect( $dir . '2', 'ffff:1' ) );
		$this->assertSame( [ '.', '..' ], scandir( $dir . '2' ), 'A file made into a folder that is not the checked one is removed again.' );
	}

	public function test_as_root_nothing_is_created_and_a_prepared_folder_stays_usable(): void {
		$owner = (int) fileowner( $this->base . '/home' );
		$this->assertTrue( MarkedFolder::asRoot( [ $owner ], 0 ) );
		$this->assertTrue( MarkedFolder::asRoot( [], 0 ), 'An unknown storage owner counts as another user.' );
		$this->assertFalse( MarkedFolder::asRoot( [ 0 ], 0 ), 'A site served by root itself.' );
		$this->assertFalse( MarkedFolder::asRoot( [ $owner ], $owner ) );

		$this->refused( R::RUN_AS_OWNER, fn() => MarkedFolder::prepare( $this->base . '/home/new', 'aaaaaaaaaaaaaaaa', [ $owner ], 0 ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/home/new' );
		mkdir( $this->base . '/home/empty', 0750 );
		$this->refused( R::RUN_AS_OWNER, fn() => MarkedFolder::prepare( $this->base . '/home/empty', 'aaaaaaaaaaaaaaaa', [ $owner ], 0 ) );
		$this->assertSame( [ '.', '..' ], scandir( $this->base . '/home/empty' ) );

		$ready = $this->base . '/home/ready';
		MarkedFolder::prepare( $ready, 'aaaaaaaaaaaaaaaa', [ $owner ] );
		$this->assertSame( [], MarkedFolder::prepare( $ready, 'aaaaaaaaaaaaaaaa', [ $owner ], 0 ), 'Using a prepared folder as root is fine.' );
		unlink( $ready . '/robots.txt' );
		$this->refused( R::RUN_AS_OWNER, fn() => MarkedFolder::prepare( $ready, 'aaaaaaaaaaaaaaaa', [ $owner ], 0 ) );
		$this->assertFileDoesNotExist( $ready . '/robots.txt' );
	}

	public function test_in_use_reports_missing_link_changed_foreign_and_unwritable(): void {
		$dir = $this->base . '/home/used';
		BackupDirRules::prepare( $dir, 'aaaaaaaaaaaaaaaa' );
		$this->assertSame( R::FOREIGN, BackupDirRules::inUse( $dir, 'cccccccccccccccc' ) );
		$this->assertSame( R::FOREIGN, BackupDirRules::inUse( $dir, '' ) );
		$this->assertSame( R::MISSING, BackupDirRules::inUse( $dir . '-gone', 'aaaaaaaaaaaaaaaa' ) );
		rename( $dir, $dir . '-real' );
		symlink( $dir . '-real', $dir );
		$this->assertSame( R::LINK, BackupDirRules::inUse( $dir, 'aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( R::CHANGED, BackupDirRules::inUse( $this->base . '/home/../home/used-real', 'aaaaaaaaaaaaaaaa' ) );
		chmod( $dir . '-real', 0770 );
		$this->assertSame( R::WORLD_WRITABLE, BackupDirRules::inUse( $dir . '-real', 'aaaaaaaaaaaaaaaa' ) );
		$this->skipAsRoot();
		chmod( $dir . '-real', 0550 );
		$this->assertSame( R::NOT_WRITABLE, BackupDirRules::inUse( $dir . '-real', 'aaaaaaaaaaaaaaaa' ) );
		chmod( $dir . '-real', 0750 );
	}
}
