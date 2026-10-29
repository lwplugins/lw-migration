<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class StorageDirTest extends MonkeyTestCase {

	/** @var array<int, string> */
	private array $cleanup = [];

	private bool $createdContentDir = false;

	protected function setUp(): void {
		parent::setUp();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $dir ) {
			TempDir::remove( $dir );
		}
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		}
		parent::tearDown();
	}

	public function test_from_wordpress_creates_a_random_name_once(): void {
		$db    = FakeWpdb::install();
		$first = StorageDir::fromWordPress();
		$name  = (string) $db->value( Options::STORAGE_DIR );

		$this->assertMatchesRegularExpression( StorageDir::NAME_PATTERN, $name );
		$this->assertSame( WP_CONTENT_DIR . '/' . $name, $first->root() );
		$this->assertSame( $first->root(), StorageDir::fromWordPress()->root() );
	}

	public function test_an_invalid_stored_name_is_replaced_and_persisted(): void {
		$db   = FakeWpdb::install( [ Options::STORAGE_DIR => '../../etc' ] );
		$root = StorageDir::fromWordPress()->root();
		$this->assertStringNotContainsString( '..', $root );
		$this->assertMatchesRegularExpression( StorageDir::NAME_PATTERN, basename( $root ) );
		$this->assertSame( basename( $root ), $db->value( Options::STORAGE_DIR ) );
	}

	public function test_concurrent_first_calls_converge_on_one_name(): void {
		$db              = FakeWpdb::install();
		$other           = null;
		$db->beforeWrite = static function () use ( &$other ): void {
			$other = StorageDir::fromWordPress()->root();
		};

		$mine = StorageDir::fromWordPress()->root();
		$this->assertNotNull( $other );
		$this->assertSame( $other, $mine );
		$this->assertSame( basename( $mine ), $db->value( Options::STORAGE_DIR ) );
	}

	public function test_a_failed_write_never_mints_an_unsaved_name(): void {
		$db             = FakeWpdb::install();
		$db->failWrites = true;
		$this->expectException( StorageException::class );
		StorageDir::fromWordPress();
	}

	public function test_a_missing_option_adopts_the_existing_folder(): void {
		$existing = $this->folder( 'lw-migration-aaaaaaaaaaaa' );
		$this->folder( 'lw-migration-not-a-match' );
		$db = FakeWpdb::install();

		$this->assertSame( $existing, StorageDir::fromWordPress()->root() );
		$this->assertSame( 'lw-migration-aaaaaaaaaaaa', $db->value( Options::STORAGE_DIR ) );
	}

	public function test_several_folders_adopt_the_newest_one_with_backups(): void {
		$this->folder( 'lw-migration-bbbbbbbbbbbb' );
		$older = $this->folder( 'lw-migration-cccccccccccc' );
		$newer = $this->folder( 'lw-migration-dddddddddddd' );
		mkdir( $older . '/backups' );
		mkdir( $newer . '/backups' );
		touch( $older . '/backups', 1000 );
		touch( $newer . '/backups', 2000 );
		touch( WP_CONTENT_DIR . '/lw-migration-bbbbbbbbbbbb', 3000 );
		FakeWpdb::install();

		$this->assertSame( $newer, StorageDir::fromWordPress()->root() );
	}

	public function test_ensure_creates_protected_folders(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		$storage         = new StorageDir( $base . '/lw-migration-0123456789ab' );
		$storage->ensure();

		foreach ( [ $storage->root(), $storage->jobsDir(), $storage->backupsDir() ] as $dir ) {
			$this->assertDirectoryExists( $dir );
			$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
			$this->assertStringContainsString( 'Require all denied', $htaccess );
			$this->assertStringContainsString( "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>", $htaccess );
			$webConfig = (string) file_get_contents( $dir . '/web.config' );
			$this->assertStringContainsString( 'accessType="Deny"', $webConfig );
			$this->assertStringContainsString( '<fileExtensions allowUnlisted="false" />', $webConfig );
			$this->assertFileExists( $dir . '/index.php' );
			$this->assertSame( '', file_get_contents( $dir . '/index.html' ) );
		}
		file_put_contents( $storage->backupsDir() . '/.htaccess', 'Allow from all' );
		$storage->ensure();
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $storage->backupsDir() . '/.htaccess' ) );
	}

	public function test_ensure_refuses_a_file_where_the_root_folder_belongs(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		file_put_contents( $base . '/lw-migration-0123456789ab', 'x' );

		$this->expectException( StorageException::class );
		( new StorageDir( $base . '/lw-migration-0123456789ab' ) )->ensure();
	}

	public function test_ensure_refuses_a_folder_named_like_a_protection_file(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		mkdir( $base . '/lw-migration-0123456789ab/.htaccess', 0755, true );

		$this->expectException( StorageException::class );
		( new StorageDir( $base . '/lw-migration-0123456789ab' ) )->ensure();
	}

	public function test_ensure_refuses_a_missing_parent_folder(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;

		$this->expectException( StorageException::class );
		( new StorageDir( $base . '/missing/lw-migration-0123456789ab' ) )->ensure();
	}

	public function test_the_default_and_a_chosen_backups_folder(): void {
		$default = new StorageDir( '/w/lw-migration-0123456789ab' );
		$this->assertSame( '/w/lw-migration-0123456789ab/backups', $default->backupsDir() );
		$this->assertSame( '/w/lw-migration-0123456789ab/backups', $default->defaultBackupsDir() );
		$this->assertNull( $default->movingDir() );
		$this->assertSame( [ '/w/lw-migration-0123456789ab/backups' ], $default->backupDirs() );
		$this->assertSame( '', $default->setting()->path );

		$moving = new StorageDir( '/w/lw-migration-0123456789ab', new BackupDir( '', '/h/new', '0123456789abcdef' ) );
		$this->assertSame( '/w/lw-migration-0123456789ab/backups', $moving->backupsDir() );
		$this->assertSame( '/h/new', $moving->movingDir() );
		$this->assertSame( [ '/w/lw-migration-0123456789ab/backups', '/h/new' ], $moving->backupDirs() );

		$chosen = new StorageDir( '/w/lw-migration-0123456789ab', new BackupDir( '/h/new', '', '0123456789abcdef' ) );
		$this->assertSame( '/h/new', $chosen->backupsDir() );
		$this->assertSame( '/w/lw-migration-0123456789ab/backups', $chosen->defaultBackupsDir() );
		$this->assertSame( '/w/lw-migration-0123456789ab/backups', $chosen->movingDir() );
		$this->assertSame( '/w/lw-migration-0123456789ab/backups', $chosen->resolve( '' ) );
		$this->assertSame( '/h/x', $chosen->resolve( '/h/x' ) );
		$this->assertSame( [ '/h/new', '/w/lw-migration-0123456789ab/backups' ], $chosen->backupDirs() );
	}

	public function test_from_wordpress_reads_the_backups_folder_setting(): void {
		FakeWpdb::install(
			[
				Options::STORAGE_DIR => 'lw-migration-0123456789ab',
				Options::BACKUP_DIR  => '{"path":"/h/new","moving":null,"marker":"0123456789abcdef"}',
			]
		);

		$this->assertSame( '/h/new', StorageDir::fromWordPress()->backupsDir() );
		$this->assertSame( '/h/new', StorageDir::stored()?->backupsDir() );
	}

	public function test_ensure_backups_creates_and_protects_only_the_default(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		$storage         = new StorageDir( $base . '/lw-migration-0123456789ab' );
		$storage->ensureBackups();

		$this->assertDirectoryExists( $storage->backupsDir() );
		$this->assertFileExists( $storage->backupsDir() . '/.htaccess' );
		$this->assertFileDoesNotExist( $storage->backupsDir() . '/robots.txt' );
		$this->assertFileDoesNotExist( $storage->backupsDir() . '/' . BackupDirRules::MARKER );

		$chosen = $base . '/chosen';
		BackupDirRules::prepare( $chosen, '0123456789abcdef' );
		( new StorageDir( $base . '/lw-migration-0123456789ab', new BackupDir( $chosen, null, '0123456789abcdef' ) ) )->ensureBackups();
		$this->expectException( FolderRefusedException::class );
		( new StorageDir( $base . '/lw-migration-0123456789ab', new BackupDir( $chosen, null, 'ffffffffffffffff' ) ) )->ensureBackups();
	}

	public function test_ensure_backups_never_creates_a_missing_chosen_folder(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		$storage         = new StorageDir( $base . '/lw-migration-0123456789ab', new BackupDir( $base . '/gone', null, '0123456789abcdef' ) );

		try {
			$storage->ensureBackups();
			$this->fail( 'A missing chosen folder is refused.' );
		} catch ( FolderRefusedException $e ) {
			$this->assertSame( FolderRefusedException::MISSING, $e->reason );
		}
		$this->assertSame( [ '.', '..' ], scandir( $base ) );
	}

	public function test_unprotect_removes_only_our_own_deny_files(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		$dir             = $base . '/chosen';
		BackupDirRules::prepare( $dir, '0123456789abcdef' );
		file_put_contents( $dir . '/.htaccess', 'Allow from all' );
		unlink( $dir . '/index.php' );
		file_put_contents( $base . '/theirs.php', "<?php\n// Silence is golden.\n" );
		symlink( $base . '/theirs.php', $dir . '/index.php' );

		StorageDir::unprotect( $dir );

		$this->assertSame( 'Allow from all', file_get_contents( $dir . '/.htaccess' ) );
		$this->assertTrue( is_link( $dir . '/index.php' ) );
		$this->assertFileExists( $base . '/theirs.php' );
		foreach ( [ 'web.config', 'index.html', 'robots.txt' ] as $name ) {
			$this->assertFileDoesNotExist( $dir . '/' . $name );
		}
		$this->assertSame( '0123456789abcdef', BackupDirRules::markerOf( $dir ) );
	}

	public function test_protect_writes_robots_only_when_asked(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		StorageDir::protect( $base );
		$this->assertFileDoesNotExist( $base . '/robots.txt' );
		StorageDir::protect( $base, true );
		$this->assertSame( "User-agent: *\nDisallow: /\n", file_get_contents( $base . '/robots.txt' ) );
	}

	public function test_owners_are_the_storage_root_owner_and_the_php_user(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		$storage         = new StorageDir( $base . '/lw-migration-0123456789ab' );
		$this->assertSame( [ posix_geteuid() ], $storage->owners(), 'No root folder yet: the PHP user only.' );
		$storage->ensure();
		$this->assertSame( [ (int) fileowner( $storage->root() ) ], $storage->owners(), 'Root owner and PHP user are the same here.' );
		$this->assertSame( (string) posix_getpwuid( (int) fileowner( $storage->root() ) )['name'], $storage->ownerName() );
		$this->assertSame( '', ( new StorageDir( $base . '/missing' ) )->ownerName() );
	}

	public function test_protect_new_only_creates_and_names_what_it_left(): void {
		$base            = TempDir::create();
		$this->cleanup[] = $base;
		file_put_contents( $base . '/.htaccess', 'theirs' );

		$this->assertSame( [ '.htaccess' ], StorageDir::protectNew( $base ) );
		$this->assertSame( 'theirs', file_get_contents( $base . '/.htaccess' ) );
		$this->assertSame( "User-agent: *\nDisallow: /\n", file_get_contents( $base . '/robots.txt' ) );
		$this->assertSame( [], preg_grep( '/^\.lwm-/', (array) scandir( $base ) ) );
		$this->expectException( FolderRefusedException::class );
		StorageDir::protectNew( $base . '/missing' );
	}

	private function folder( string $name ): string {
		$dir = WP_CONTENT_DIR . '/' . $name;
		mkdir( $dir );
		$this->cleanup[] = $dir;

		return $dir;
	}
}
