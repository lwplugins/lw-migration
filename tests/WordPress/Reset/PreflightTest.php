<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use LightweightPlugins\Migration\Reset\Preflight;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class PreflightTest extends MonkeyTestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = TempDir::create();
		foreach ( [ 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-content/themes', 'wp-content/uploads' ] as $dir ) {
			mkdir( $this->root . '/' . $dir, 0755, true );
		}
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		parent::tearDown();
	}

	private function preflight( array $site = [], bool $network = false, bool $mods = true, ?\Closure $writable = null ): Preflight {
		return new Preflight( SiteFixture::make( $this->root, $site ), $network, static fn(): bool => $mods, $writable ?? static fn( string $dir ): bool => true, null, new RootGuard( 33 ) );
	}

	public function test_every_type_runs_on_a_healthy_single_site(): void {
		foreach ( [ 'plugins', 'themes', 'media', 'database', 'full' ] as $type ) {
			$this->assertNull( $this->preflight()->reason( $type ), $type );
		}
	}

	public function test_a_network_allows_only_files_and_media(): void {
		$p = $this->preflight( [], true );
		$this->assertSame( [ null, null, null, 'network', 'network' ], array_map( [ $p, 'reason' ], [ 'plugins', 'themes', 'media', 'database', 'full' ] ) );
	}

	public function test_disallowed_file_mods_stop_code_resets_only(): void {
		$p = $this->preflight( [], false, false );
		$this->assertSame( [ 'file_mods', 'file_mods', null, null, 'file_mods' ], array_map( [ $p, 'reason' ], [ 'plugins', 'themes', 'media', 'database', 'full' ] ) );
	}

	public function test_a_folder_that_cannot_be_written_is_refused(): void {
		$p = $this->preflight( [], false, true, fn( string $dir ): bool => ! str_ends_with( $dir, '/uploads' ) );
		$this->assertSame( 'not_writable', $p->reason( 'media' ) );
		$this->assertNull( $p->reason( 'plugins' ) );
	}

	public function test_uploads_that_hold_a_core_folder_are_refused_by_identity(): void {
		// UPLOADS pointing at wp-content itself: emptying it would wipe plugins and themes.
		$this->assertSame( 'uploads_unsafe', $this->preflight( [ 'uploadsDir' => $this->root . '/wp-content' ] )->reason( 'media' ) );
		// Another spelling of the same folder (a link) is the same folder.
		symlink( $this->root . '/wp-content', $this->root . '/content-link' );
		$this->assertSame( 'uploads_unsafe', $this->preflight( [ 'uploadsDir' => $this->root . '/content-link' ] )->reason( 'full' ) );
		$this->assertSame( 'uploads_unsafe', $this->preflight( [ 'uploadsDir' => $this->root ] )->reason( 'media' ), 'ABSPATH.' );
		$this->assertNull( $this->preflight()->reason( 'media' ) );
	}

	public function test_missing_uploads_folder_needs_no_cleaning(): void {
		$this->assertNull( $this->preflight( [ 'uploadsDir' => $this->root . '/wp-content/none' ] )->reason( 'media' ) );
	}

	public function test_an_unknown_type_is_refused(): void {
		foreach ( [ 'all', 'Plugins', '' ] as $type ) {
			try {
				$this->preflight()->reason( $type );
				$this->fail( $type );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Choose what to reset.', $e->getMessage() );
			}
		}
	}

	public function test_an_uploads_entry_without_a_folder_identity_is_refused(): void {
		symlink( $this->root . '/nowhere', $this->root . '/dangling' );
		$this->assertSame( 'uploads_unsafe', $this->preflight( [ 'uploadsDir' => $this->root . '/dangling' ] )->reason( 'media' ), 'A link to nothing.' );
		file_put_contents( $this->root . '/a-file', 'x' );
		$this->assertSame( 'uploads_unsafe', $this->preflight( [ 'uploadsDir' => $this->root . '/a-file' ] )->reason( 'full' ), 'A file, not a folder.' );
	}

	public function test_a_core_view_is_found_the_way_the_server_compares_names(): void {
		$suffixes = [ 'posts', 'options', 'users' ];
		$this->assertSame( 'core_view:wp_options', Preflight::coreView( $suffixes, [ 'wp_options' ], 'wp_', false ) );
		$this->assertNull( Preflight::coreView( $suffixes, [ 'WP_Options', 'wp_other' ], 'wp_', false ), 'Case-sensitive names: another table.' );
		$this->assertSame( 'core_view:WP_Options', Preflight::coreView( $suffixes, [ 'WP_Options' ], 'wp_', true ), 'Folded names: the same table.' );
		$this->assertNull( Preflight::coreView( $suffixes, [], 'wp_', true ) );
	}

	public function test_a_database_reset_needs_a_plain_mysql_setup(): void {
		foreach ( [ 'sqlite', 'drop_in', 'custom_users', 'core_view' ] as $setup ) {
			$p = new Preflight( SiteFixture::make( $this->root ), false, static fn(): bool => true, static fn(): bool => true, static fn(): ?string => $setup, new RootGuard( 33 ) );
			$this->assertSame( [ null, null, null, 'database_setup', 'database_setup' ], array_map( [ $p, 'reason' ], [ 'plugins', 'themes', 'media', 'database', 'full' ] ), $setup );
		}
	}

	public function test_a_database_reset_needs_a_table_prefix_unlike_our_temporary_tables(): void {
		foreach ( [ '', 'lwtab12_', 'lw' ] as $prefix ) {
			$p = new Preflight( SiteFixture::make( $this->root, [ 'tablePrefix' => $prefix ] ), false, static fn(): bool => true, static fn(): bool => true, static fn(): ?string => null, new RootGuard( 33 ) );
			$this->assertSame( [ null, null, null, 'database_setup', 'database_setup' ], array_map( [ $p, 'reason' ], [ 'plugins', 'themes', 'media', 'database', 'full' ] ), $prefix );
			try {
				$p->assert( 'database' );
				$this->fail( 'Refused.' );
			} catch ( ResetRefused $e ) {
				$this->assertSame( 'This site\'s table prefix is empty or shaped like the temporary tables of LW Migration, so the database cannot be reset here.', $e->getMessage() );
			}
		}
	}

	public function test_a_database_reset_needs_an_address_to_type(): void {
		foreach ( [ '', 'https://' ] as $home ) {
			$p = $this->preflight( [ 'homeUrl' => $home ] );
			$this->assertSame( [ null, null, null, 'no_address', 'no_address' ], array_map( [ $p, 'reason' ], [ 'plugins', 'themes', 'media', 'database', 'full' ] ), $home );
			try {
				$p->assert( 'full' );
				$this->fail( 'Refused.' );
			} catch ( ResetRefused $e ) {
				$this->assertSame( 'This site has no address that can be typed to confirm (Settings > General), so the database cannot be reset here.', $e->getMessage() );
			}
		}
	}

	public function test_the_network_refusal_comes_before_the_database_setup(): void {
		$p = new Preflight( SiteFixture::make( $this->root ), true, static fn(): bool => true, static fn(): bool => true, static fn(): ?string => 'sqlite', new RootGuard( 33 ) );
		$this->assertSame( 'network', $p->reason( 'database' ) );
	}

	public function test_as_root_or_as_an_unknown_user_every_file_reset_is_refused(): void {
		foreach ( [ 0, null ] as $euid ) {
			$p = new Preflight( SiteFixture::make( $this->root ), false, static fn(): bool => true, static fn(): bool => true, static fn(): ?string => null, new RootGuard( $euid ), static fn(): string => 'www-data' );
			$this->assertSame( [ 'as_root', 'as_root', 'as_root', null, 'as_root' ], array_map( [ $p, 'reason' ], [ 'plugins', 'themes', 'media', 'database', 'full' ] ), 'The database reset is not affected.' );
		}
	}

	public function test_the_root_refusal_names_the_owner_only_when_it_is_known(): void {
		foreach ( [ 'www-data' => 'sudo -u www-data wp lw-migration reset', '' => 'sudo -u <owner> wp lw-migration reset' ] as $owner => $command ) {
			$p = new Preflight( SiteFixture::make( $this->root ), false, static fn(): bool => true, static fn(): bool => true, null, new RootGuard( 0 ), static fn(): string => $owner );
			try {
				$p->assert( 'plugins' );
				$this->fail( 'Refused.' );
			} catch ( ResetRefused $e ) {
				$this->assertSame( 'as_root', $e->reason );
				$this->assertStringContainsString( $command, $e->getMessage() );
				$this->assertStringNotContainsString( $this->root, $e->getMessage() );
			}
		}
	}

	public function test_assert_throws_the_reason_with_its_text(): void {
		$cases = [
			'sqlite'                => 'This site runs on SQLite; the database reset works with MySQL and MariaDB only.',
			'drop_in'               => 'A database drop-in (db.php) replaces the standard connection, so the database cannot be reset here.',
			'custom_users'          => 'This site shares its users table with another installation (CUSTOM_USER_TABLE), so the database cannot be reset here.',
			'core_view:wp_postmeta' => 'A core table of this site is a database view (wp_postmeta), so the database cannot be reset here.',
		];
		foreach ( $cases as $setup => $text ) {
			$p = new Preflight( SiteFixture::make( $this->root ), false, static fn(): bool => true, static fn(): bool => true, static fn(): ?string => $setup, new RootGuard( 33 ) );
			try {
				$p->assert( 'full' );
				$this->fail( $setup );
			} catch ( ResetRefused $e ) {
				$this->assertSame( [ 'database_setup', $text ], [ $e->reason, $e->getMessage() ], $setup );
			}
		}
		try {
			$this->preflight( [], false, false )->assert( 'plugins' );
			$this->fail( 'File mods.' );
		} catch ( ResetRefused $e ) {
			$this->assertSame( [ 'file_mods', 'This site does not allow plugins and themes to be changed (DISALLOW_FILE_MODS).' ], [ $e->reason, $e->getMessage() ] );
		}
		$this->preflight()->assert( 'media' );
		$this->addToAssertionCount( 1 );
	}
}
