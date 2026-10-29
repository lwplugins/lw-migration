<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\BackupDirCommand;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException as R;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Each test runs in its own process: the WP_CLI stand-in and Services::get()
 * keep state for the whole process.
 */
final class BackupDirCommandTest extends MonkeyTestCase {

	private const MARKER = '0123456789abcdef';

	private string $base;

	private bool $createdContentDir = false;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		$this->db = FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
		$this->base = TempDir::create();
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => WP_CONTENT_DIR . '/uploads' ] );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'site_url' )->justReturn( 'https://x.test' );
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => (string) $number );
		Services::get()->storage()->ensure();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		} else {
			TempDir::remove( WP_CONTENT_DIR . '/lw-migration-0123456789ab' );
		}
		parent::tearDown();
	}

	private function setting( BackupDir $setting ): void {
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => $setting->toJson(),
			'autoload' => 'off',
		];
	}

	private function prepared( string $name ): string {
		$dir = $this->base . '/' . $name;
		BackupDirRules::prepare( $dir, self::MARKER, Services::get()->storage()->owners() );

		return $dir;
	}

	/**
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return string The error the command stopped with.
	 */
	private function refused( array $args, array $assoc ): string {
		try {
			( new BackupDirCommand() )->move( $args, $assoc );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}
		$this->fail( 'The command went on.' );
	}

	#[RunInSeparateProcess]
	public function test_show_names_the_folder_and_an_unfinished_move(): void {
		$default = Services::get()->storage()->defaultBackupsDir();
		( new BackupDirCommand() )->show();
		$this->assertSame( [ 'Backups folder: default (' . $default . ')' ], \WP_CLI::$lines );

		$target = $this->prepared( 'target' );
		file_put_contents( $default . '/site-a.wpress', 'backup' );
		$this->setting( new BackupDir( '', $target, self::MARKER ) );
		\WP_CLI::$lines = [];
		( new BackupDirCommand() )->show();
		$this->assertSame(
			[
				'Backups folder: default (' . $default . ')',
				'A move to ' . $target . ' has not finished: 1 backup is still in the folder above. Run the move again to finish it, or move to the folder above to undo it.',
			],
			\WP_CLI::$lines
		);

		TempDir::remove( $target );
		$this->setting( new BackupDir( $target, null, self::MARKER ) );
		\WP_CLI::$lines = [];
		( new BackupDirCommand() )->show();
		$this->assertSame( [ 'Backups folder: ' . $target, 'Warning: ' . FolderMessages::of( R::MISSING ) ], \WP_CLI::$lines );
	}

	public function test_the_question_masks_the_random_folder_and_counts_the_backups(): void {
		$one = BackupDirCommand::question( [ 'count' => 1, 'bytes' => 5 ], '/srv/site/wp-content/lw-migration-backups-0123456789ab' );
		$this->assertSame( 'Move 1 backup (5 B) to /srv/site/wp-content/lw-migration-backups-…? Scheduled backups wait, and no backup or restore can start until it is done. [y/n]', $one );
		$this->assertStringNotContainsString( '0123456789ab', $one, 'The job draws the random segment again.' );

		$two = BackupDirCommand::question( [ 'count' => 2, 'bytes' => 9 ], '/srv/backups' );
		$this->assertSame( 'Move 2 backups (9 B) to /srv/backups? Scheduled backups wait, and no backup or restore can start until it is done. [y/n]', $two );
	}

	#[RunInSeparateProcess]
	public function test_move_takes_exactly_one_folder_or_flag(): void {
		$text = 'Give the new folder, or one of --default, --finish, --undo.';
		$this->assertSame( $text, $this->refused( [], [ 'yes' => true ] ) );
		$this->assertSame( $text, $this->refused( [ $this->base . '/new' ], [ 'default' => true ] ) );
		$this->assertSame( $text, $this->refused( [], [ 'finish' => true, 'undo' => true ] ) );
		$this->assertSame( FolderMessages::of( R::NOTHING_PENDING ), $this->refused( [], [ 'finish' => true ] ) );
		$this->assertSame( FolderMessages::of( R::NOTHING_PENDING ), $this->refused( [], [ 'undo' => true ] ) );
		$this->assertSame( FolderMessages::of( R::SYSTEM ), $this->refused( [ '/etc/lwm' ], [ 'yes' => true ] ) );
		$this->assertSame( FolderMessages::of( R::SAME ), $this->refused( [], [ 'default' => true, 'yes' => true ] ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/new' );
		$this->assertSame( [], Services::get()->jobs()->ids() );
	}

	#[RunInSeparateProcess]
	public function test_a_locked_setting_is_refused(): void {
		Filters\expectApplied( 'lw_migration_backup_dir_editable' )->andReturn( false );

		$this->assertSame( FolderMessages::of( R::LOCKED ), $this->refused( [ $this->base . '/new' ], [ 'yes' => true ] ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/new' );
	}

	#[RunInSeparateProcess]
	public function test_a_busy_site_is_refused_with_the_lock_expiry(): void {
		$now = time();
		$this->assertTrue( Services::get()->lock()->acquire( 'ffffffffffffffff', $now ) );

		$this->assertSame( SiteBusyException::describe( $now + SiteLock::TTL ), $this->refused( [ $this->base . '/new' ], [ 'yes' => true ] ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/new' );
	}

	#[RunInSeparateProcess]
	public function test_a_move_runs_to_the_end_and_back(): void {
		$services = Services::get();
		file_put_contents( $services->storage()->defaultBackupsDir() . '/site-a.wpress', 'backup bytes' );
		$new = $this->base . '/new';

		( new BackupDirCommand() )->move( [ $new ], [ 'yes' => true ] );
		$this->assertSame( 'Success: 1 backup moved.', end( \WP_CLI::$lines ) );
		$child = BackupDir::read()->path;
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $new, '#' ) . '/lw-migration-backups-[a-f0-9]{12}\z#', $child, 'The typed folder holds a folder with a random name.' );
		$this->assertSame( 'backup bytes', file_get_contents( $child . '/site-a.wpress' ) );
		$this->assertFileDoesNotExist( $services->storage()->defaultBackupsDir() . '/site-a.wpress' );
		$this->assertNull( BackupDir::read()->moving );
		$this->assertNull( $services->lock()->holder( time() ), 'A finished move frees the site.' );

		( new BackupDirCommand() )->move( [], [ 'default' => true, 'yes' => true ] );
		$this->assertSame( 'Success: 1 backup moved.', end( \WP_CLI::$lines ) );
		$this->assertSame( 'backup bytes', file_get_contents( $services->storage()->defaultBackupsDir() . '/site-a.wpress' ) );
		$this->assertSame( '', BackupDir::read()->path );
		$this->assertDirectoryDoesNotExist( $child, 'The emptied folder of our own is removed.' );
		$this->assertSame( [ '.', '..' ], scandir( $new ), 'The typed folder stays, empty.' );
	}
}
