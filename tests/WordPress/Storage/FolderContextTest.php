<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderContext;
use LightweightPlugins\Migration\Storage\FolderRefusedException as R;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FolderContextTest extends MonkeyTestCase {

	private string $base;

	private bool $createdContentDir = false;

	/** @var mixed */
	private $themes;

	/** @var mixed */
	private $documentRoot;

	protected function setUp(): void {
		parent::setUp();
		$this->base = (string) realpath( TempDir::create() );
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
		foreach ( [ 'uploads', 'themes', 'docroot', 'external', 'beside' ] as $dir ) {
			mkdir( $this->base . '/' . $dir );
		}
		$this->themes                     = $GLOBALS['wp_theme_directories'] ?? null;
		$this->documentRoot               = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$GLOBALS['wp_theme_directories']  = [ $this->base . '/themes' ];
		$_SERVER['DOCUMENT_ROOT']         = $this->base . '/docroot';
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->base . '/uploads' ] );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'get_option' )->alias( fn( string $name, $fallback = false ) => 'ai1wm_backups_path' === $name ? $this->base . '/external' : $fallback );
		Functions\when( 'site_url' )->justReturn( 'https://x.test' );
	}

	protected function tearDown(): void {
		if ( null === $this->themes ) {
			unset( $GLOBALS['wp_theme_directories'] );
		} else {
			$GLOBALS['wp_theme_directories'] = $this->themes;
		}
		if ( null === $this->documentRoot ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->documentRoot;
		}
		TempDir::remove( $this->base );
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		}
		parent::tearDown();
	}

	private function rules(): BackupDirRules {
		return FolderContext::rules( new StorageDir( WP_CONTENT_DIR . '/lw-migration-0123456789ab' ) );
	}

	private function refused( string $reason, string $path ): void {
		try {
			$this->rules()->check( $path );
			$this->fail( 'Expected refusal ' . $reason . ' for ' . $path );
		} catch ( R $e ) {
			$this->assertSame( $reason, $e->reason, $path );
		}
	}

	private function assertSuffixed( string $parent, string $folder ): void {
		$this->assertSame( rtrim( $parent, '/' ), dirname( $folder ) );
		$this->assertMatchesRegularExpression( BackupDirRules::SUFFIX_PATTERN, basename( $folder ) );
	}

	public function test_the_derived_document_root_covers_wordpress_in_a_subfolder(): void {
		$this->assertSame( '/var/www/html', FolderContext::documentRoot( '/var/www/html/wp/', 'https://x.test/wp' ) );
		$this->assertSame( '/var/www/html', FolderContext::documentRoot( '/var/www/html/', 'https://x.test' ) );
		$this->assertSame( '/var/www/html', FolderContext::documentRoot( '/var/www/html/', 'https://x.test/' ) );
		$this->assertSame( '', FolderContext::documentRoot( '/srv/a/', 'https://x.test/other' ) );
		$this->assertSame( '', FolderContext::documentRoot( '/srv/xwp/', 'https://x.test/wp' ), 'A directory boundary, not a suffix of the name.' );
	}

	public function test_rules_refuse_every_site_root_and_overlap(): void {
		foreach ( [ WP_CONTENT_DIR . '/plugins/x', WP_CONTENT_DIR . '/mu-plugins/x', $this->base . '/themes/x', $this->base . '/uploads/x', rtrim( ABSPATH, '/' ) . '/wp-admin/x', rtrim( ABSPATH, '/' ) . '/wp-includes/x' ] as $path ) {
			$this->refused( R::SITE_FOLDER, $path );
		}
		foreach ( [ WP_CONTENT_DIR . '/lw-migration-0123456789ab/x', $this->base . '/external/x', WP_CONTENT_DIR . '/ai1wm-backups/x' ] as $path ) {
			$this->refused( R::OVERLAP, $path );
		}
		foreach ( [ WP_CONTENT_DIR . '/upgrade', WP_CONTENT_DIR . '/languages/x', WP_CONTENT_DIR . '/cache' ] as $path ) {
			$this->refused( R::CONTENT_SUBFOLDER, $path );
		}

		$this->assertSuffixed( ABSPATH, $this->rules()->check( ABSPATH ) );
		$this->assertSuffixed( (string) realpath( WP_CONTENT_DIR ), $this->rules()->check( WP_CONTENT_DIR ) );
		$this->assertSuffixed( $this->base . '/docroot', $this->rules()->check( $this->base . '/docroot' ) );
		$this->assertSuffixed( $this->base . '/beside/new', $this->rules()->check( $this->base . '/beside/new' ) );
	}

	public function test_the_derived_document_root_is_a_web_root_in_wp_cli(): void {
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$repo = dirname( rtrim( ABSPATH, '/' ) );
		$this->assertSuffixed( $repo, $this->rules()->check( $repo ) ); // Every typed folder holds a random child now.

		Functions\when( 'site_url' )->justReturn( 'https://x.test/' . basename( rtrim( ABSPATH, '/' ) ) );
		$this->assertSuffixed( $repo, $this->rules()->check( $repo ) );
	}

	public function test_a_document_root_with_control_characters_is_ignored(): void {
		$_SERVER['DOCUMENT_ROOT'] = $this->base . "/docroot\n";
		$this->assertSuffixed( $this->base . '/docroot/new', $this->rules()->check( $this->base . '/docroot/new' ) );
	}

	public function test_the_backups_folders_of_the_site_overlap_a_new_one(): void {
		$chosen = $this->base . '/beside/chosen';
		BackupDirRules::prepare( $chosen, '0123456789abcdef' );
		$storage = new StorageDir( WP_CONTENT_DIR . '/lw-migration-0123456789ab', new BackupDir( $chosen, null, '0123456789abcdef' ) );

		try {
			FolderContext::rules( $storage )->check( $chosen . '/inside' );
			$this->fail( 'A folder inside the current backups folder is refused.' );
		} catch ( R $e ) {
			$this->assertSame( R::OVERLAP, $e->reason );
		}
	}

	public function test_editable_follows_the_filter_and_supported_the_platform(): void {
		$this->assertTrue( FolderContext::editable() );
		Filters\expectApplied( 'lw_migration_backup_dir_editable' )->once()->with( true )->andReturn( false );
		$this->assertFalse( FolderContext::editable() );
		$this->assertSame( '/' === DIRECTORY_SEPARATOR, FolderContext::supported() );
	}
}
