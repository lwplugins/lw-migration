<?php
/**
 * Tests for BackupDirApi.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\BackupDirApi;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException as R;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class BackupDirApiTest extends MonkeyTestCase {

	private const MARKER = '0123456789abcdef';

	private const SUFFIX = 'lw-migration-backups-0123456789ab';

	private string $base;

	private bool $createdContentDir = false;

	private FakeWpdb $db;

	private Services $services;

	protected function setUp(): void {
		parent::setUp();
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
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value );
		$this->services = new Services();
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

	private function api(): BackupDirApi {
		return BackupDirApi::fromServices( $this->services );
	}

	private function setting( BackupDir $setting ): void {
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => $setting->toJson(),
			'autoload' => 'off',
		];
	}

	/** A folder of this site's own, prepared like a move would. */
	private function prepared( string $name ): string {
		$this->services->storage()->ensure();
		$dir = $this->base . '/' . $name;
		BackupDirRules::prepare( $dir, self::MARKER, $this->services->storage()->owners() );

		return $dir;
	}

	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	/** @param array<string, mixed> $view */
	private static function json( array $view ): string {
		return (string) json_encode( $view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function test_the_view_never_shows_the_default_path(): void {
		$this->services->storage()->ensure();
		$view = $this->api()->view();

		$this->assertSame( '', $view['custom'] );
		$this->assertFalse( $view['hidden'] );
		$this->assertNull( $view['moving'] );
		$this->assertNull( $view['problem'] );
		$this->assertSame( '', $view['problemText'] );
		$this->assertTrue( $view['editable'] );
		$this->assertTrue( $view['supported'] );
		$this->assertFalse( $view['running'] );
		$this->assertSame( [ 'custom', 'hidden', 'moving', 'problem', 'problemText', 'editable', 'supported', 'running' ], array_keys( $view ) );
		$root = $this->services->storage()->root();
		$this->assertStringNotContainsString( basename( $root ), self::json( $view ) );
		$this->assertStringNotContainsString( (string) realpath( $root ), self::json( $view ) );
	}

	public function test_the_view_names_a_problem_of_the_chosen_folder(): void {
		$dir = $this->prepared( 'chosen' );
		$this->setting( new BackupDir( $dir, null, self::MARKER ) );
		$this->assertNull( $this->api()->view()['problem'] );
		$this->assertSame( $dir, $this->api()->view()['custom'], 'A folder outside the web roots is shown as it was typed.' );

		TempDir::remove( $dir );
		$view = $this->api()->view();
		$this->assertSame( R::MISSING, $view['problem'] );
		$this->assertSame( FolderMessages::of( R::MISSING ), $view['problemText'] );
	}

	public function test_the_view_counts_what_is_left_of_an_unfinished_move(): void {
		$dir     = $this->prepared( 'target' );
		$storage = $this->services->storage();
		file_put_contents( $storage->defaultBackupsDir() . '/site-a.wpress', str_repeat( 'a', 300 ) );
		file_put_contents( $storage->defaultBackupsDir() . '/site-b.wpress', str_repeat( 'b', 200 ) );
		file_put_contents( $storage->defaultBackupsDir() . '/empty.wpress', '' );
		$this->setting( new BackupDir( '', $dir, self::MARKER ) );

		$view = $this->api()->view();
		$this->assertSame(
			[
				'to'        => $dir,
				'toHidden'  => false,
				'left'      => 2,
				'leftBytes' => 500,
			],
			$view['moving']
		);
		$this->assertSame( '', $view['custom'] );
	}

	public function test_the_body_must_be_one_path_or_one_action(): void {
		$this->services->storage()->ensure();
		$bodies = [
			[],
			[ 'path' => 5 ],
			[
				'path'  => '/x',
				'extra' => 1,
			],
			[ 'action' => 'later' ],
			[ 'action' => 5 ],
			[
				'path'   => '/x',
				'action' => 'finish',
			],
			[ 'path' => '/' . str_repeat( 'a', BackupDirRules::MAX_BYTES ) ],
		];
		foreach ( $bodies as $body ) {
			$refused = $this->refusal( fn() => $this->api()->move( $body ) );
			$this->assertSame( [ 400, ErrorMap::INVALID, 'path' ], [ $refused->status, $refused->errorCode, $refused->data['field'] ?? null ], (string) json_encode( $body ) );
		}
		foreach ( [ 'finish', 'undo' ] as $action ) {
			$refused = $this->refusal( fn() => $this->api()->move( [ 'action' => $action ] ) );
			$this->assertSame( [ 400, ErrorMap::INVALID, 'path' ], [ $refused->status, $refused->errorCode, $refused->data['field'] ?? null ] );
			$this->assertSame( FolderMessages::of( R::NOTHING_PENDING ), $refused->getMessage() );
		}
		$this->assertSame( [], $this->services->jobs()->ids() );
	}

	public function test_a_folder_inside_a_web_root_is_never_shown_with_its_random_name(): void {
		$inside = WP_CONTENT_DIR . '/' . self::SUFFIX;
		$this->setting( new BackupDir( $inside, null, self::MARKER ) );
		$view = $this->api()->view();
		$this->assertSame( WP_CONTENT_DIR . '/lw-migration-backups-…', $view['custom'] );
		$this->assertTrue( $view['hidden'] );
		$this->assertStringNotContainsString( '0123456789ab', self::json( $view ) );

		$this->setting( new BackupDir( '', $inside, self::MARKER ) );
		$view = $this->api()->view();
		$this->assertSame( WP_CONTENT_DIR . '/lw-migration-backups-…', $view['moving']['to'] ?? null );
		$this->assertTrue( $view['moving']['toHidden'] ?? null );
		$this->assertStringNotContainsString( '0123456789ab', self::json( $view ) );

		$this->assertSame( '/srv/backups', BackupDirApi::display( '/srv/backups' ) );
		$this->assertSame( '/srv/lw-migration-backups-0123456789abc', BackupDirApi::display( '/srv/lw-migration-backups-0123456789abc' ), 'Only our exact pattern is masked.' );
		$this->assertSame( '', BackupDirApi::display( '' ) );
	}

	public function test_finish_and_undo_reach_the_stored_folders(): void {
		$target = $this->prepared( 'target' );
		$this->setting( new BackupDir( '', $target, self::MARKER ) );

		$finish = $this->api()->move( [ 'action' => 'finish' ] );
		$this->assertSame( MoveJobs::TYPE, $finish->type );
		$this->assertSame( [ '', $target ], [ $finish->options['from'], $finish->options['to'] ] );
		$this->free( $finish );

		$undo = $this->api()->move( [ 'action' => 'undo' ] );
		$this->assertSame( [ $target, '' ], [ $undo->options['from'], $undo->options['to'] ] );
		$this->assertSame( $this->services->storage()->defaultBackupsDir(), $undo->options['toDir'] );
	}

	public function test_a_new_folder_starts_a_move_job(): void {
		$this->services->storage()->ensure();
		$new = $this->base . '/new';
		$job = $this->api()->move( [ 'path' => $new ] );

		$this->assertSame( MoveJobs::TYPE, $job->type );
		$this->assertSame( '', $job->options['from'] );
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $new, '#' ) . '/lw-migration-backups-[a-f0-9]{12}\z#', $job->options['to'] );
		$this->assertDirectoryExists( $job->options['to'] );
		$this->assertSame( $job->options['to'], BackupDir::read()->moving );
		$this->assertTrue( $this->api()->view()['running'] );
	}

	public function test_a_refused_folder_answers_400_with_its_text_at_the_path_field(): void {
		$this->services->storage()->ensure();
		$cases = [
			'/etc/lwm'                => R::SYSTEM,
			'relative'                => R::NOT_ABSOLUTE,
			WP_CONTENT_DIR . '/plugins' => R::SITE_FOLDER,
		];
		mkdir( $this->base . '/theirs' );
		file_put_contents( $this->base . '/theirs/' . BackupDirRules::MARKER, "lw-migration-backups\nbbbbbbbbbbbbbbbb\n" );
		$cases[ $this->base . '/theirs' ] = R::FOREIGN;
		foreach ( $cases as $path => $reason ) {
			$refused = $this->refusal( fn() => $this->api()->move( [ 'path' => $path ] ) );
			$this->assertSame( [ 400, ErrorMap::INVALID, 'path' ], [ $refused->status, $refused->errorCode, $refused->data['field'] ?? null ], $path );
			$this->assertSame( FolderMessages::of( $reason ), $refused->getMessage(), $path );
		}
		$this->assertSame( [ BackupDirRules::MARKER ], array_values( array_diff( (array) scandir( $this->base . '/theirs' ), [ '.', '..' ] ) ), 'Another site\'s folder is left as it was.' );
		$this->assertSame( [], $this->services->jobs()->ids() );
		$this->assertNull( $this->db->value( Options::BACKUP_DIR ) );
	}

	public function test_a_locked_setting_answers_403(): void {
		Filters\expectApplied( 'lw_migration_backup_dir_editable' )->andReturn( false );
		$refused = $this->refusal( fn() => $this->api()->move( [ 'path' => $this->base . '/new' ] ) );

		$this->assertSame( [ 403, 'lw_migration_backup_dir_locked' ], [ $refused->status, $refused->errorCode ] );
		$this->assertSame( FolderMessages::of( R::LOCKED ), $refused->getMessage() );
		$this->assertDirectoryDoesNotExist( $this->base . '/new' );
	}

	public function test_a_busy_site_refuses_the_move(): void {
		$this->services->storage()->ensure();
		$this->assertTrue( $this->services->lock()->acquire( 'ffffffffffffffff', time() ) );

		try {
			$this->api()->move( [ 'path' => $this->base . '/new' ] );
			$this->fail( 'A busy site is refused.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( 409, ErrorMap::of( $e, [], 'x' )['status'] );
		}
		$this->assertDirectoryDoesNotExist( $this->base . '/new' );
		$this->assertNull( $this->db->value( Options::BACKUP_DIR ) );
	}

	#[RunInSeparateProcess]
	public function test_a_move_answers_201_with_the_job_view_and_a_busy_site_409(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-response-class.php';
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Services::get()->storage()->ensure();
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( [ 'path' => $this->base . '/new' ] );
		$request->shouldReceive( 'get_body' )->andReturn( '{"path":"…"}' ); // A JSON object was sent.

		$response = Routes::moveBackups( $request );
		$this->assertNotInstanceOf( \WP_Error::class, $response, $response instanceof \WP_Error ? $response->get_error_message() : '' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$view = (array) $response->get_data();
		$this->assertSame( [ MoveJobs::TYPE, Job::PENDING ], [ $view['type'] ?? null, $view['status'] ?? null ] );
		$this->assertStringNotContainsString( $this->base, self::json( $view ) );

		$busy = Routes::moveBackups( $request );
		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( ErrorMap::SITE_BUSY, $busy->get_error_code() );
		$this->assertSame( 409, $busy->get_error_data()['status'] ?? null );
	}

	/**
	 * Ends a started move without running it: its job and its lock are gone.
	 *
	 * @param Job $job The move job.
	 */
	private function free( Job $job ): void {
		$this->services->lock()->release( $job->id );
		$this->services->jobs()->delete( $job->id );
	}
}
