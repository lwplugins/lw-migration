<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\SiteFactory;
use LightweightPlugins\Migration\Multisite\SiteNotCreatedException;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SiteFactoryTest extends MonkeyTestCase {

	private string $dir;

	/** @var array<int, int> */
	private array $deleted = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		Functions\when( 'is_wp_error' )->alias( static fn( $r ): bool => $r instanceof FakeWpError );
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'wp_delete_site' )->alias(
			function ( int $id ) {
				$this->deleted[] = $id;
				return 9 === $id ? new FakeWpError( 'Site does not exist.' ) : (object) [ 'id' => $id ];
			}
		);
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_create_inserts_the_checked_address_for_the_given_admin(): void {
		Functions\expect( 'wp_insert_site' )->once()->with(
			[
				'domain'     => '127.0.0.1:8093',
				'path'       => '/shop/',
				'network_id' => 1,
				'title'      => '127.0.0.1:8093/shop',
				'user_id'    => 3,
			]
		)->andReturn( 7 );

		$this->assertSame( 7, ( new SiteFactory() )->create( '127.0.0.1:8093', '/shop/', 1, '127.0.0.1:8093/shop', 3 ) );
	}

	public function test_without_an_importing_admin_the_first_super_admin_owns_the_site(): void {
		Functions\when( 'get_super_admins' )->justReturn( [ 'gone', 'boss' ] );
		Functions\when( 'get_user_by' )->alias( static fn( string $field, string $login ) => 'boss' === $login ? (object) [ 'ID' => 4 ] : false );
		Functions\expect( 'wp_insert_site' )->once()->with( \Mockery::on( static fn( array $args ): bool => 4 === $args['user_id'] ) )->andReturn( 8 );

		$this->assertSame( 8, ( new SiteFactory() )->create( 'new.test', '/shop/', 1, 'new.test/shop', 0 ) );
	}

	public function test_a_refused_site_stops_the_import_with_the_reason(): void {
		Functions\when( 'wp_insert_site' )->justReturn( new FakeWpError( 'Sorry, that site already exists!' ) );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The new site new.test/shop could not be created: Sorry, that site already exists!' );
		( new SiteFactory() )->create( 'new.test', '/shop/', 1, 'new.test/shop', 3 );
	}

	public function test_the_new_row_is_reported_from_the_insert_action_once(): void {
		$hook = null;
		Actions\expectAdded( 'wp_insert_site' )->once()->whenHappen(
			static function ( $callback ) use ( &$hook ): void {
				$hook = $callback;
			}
		);
		Actions\expectRemoved( 'wp_insert_site' )->once();
		Functions\when( 'wp_insert_site' )->alias(
			static function () use ( &$hook ): int {
				$hook( (object) [ 'id' => 7 ] );
				return 7;
			}
		);
		$reported = [];

		$id = ( new SiteFactory() )->create( 'new.test', '/shop/', 1, 'new.test/shop', 3, static function ( int $id ) use ( &$reported ): void {
			$reported[] = $id;
		} );

		$this->assertSame( [ 7, [ 7 ] ], [ $id, $reported ] );
	}

	public function test_a_refusal_before_the_row_exists_says_no_site_was_made(): void {
		Functions\when( 'wp_insert_site' )->justReturn( new FakeWpError( 'Sorry, that site already exists!', 'site_taken' ) );

		$this->expectException( SiteNotCreatedException::class );
		( new SiteFactory() )->create( 'new.test', '/shop/', 1, 'new.test/shop', 3 );
	}

	public function test_a_failure_after_the_row_exists_is_not_a_refusal(): void {
		Functions\when( 'wp_insert_site' )->justReturn( new FakeWpError( 'Could not retrieve site data.', 'get_site_error' ) );

		try {
			( new SiteFactory() )->create( 'new.test', '/shop/', 1, 'new.test/shop', 3 );
			$this->fail( 'The failure stops the import.' );
		} catch ( ImportException $e ) {
			$this->assertNotInstanceOf( SiteNotCreatedException::class, $e, 'The row may exist: the intent stays for the undo.' );
		}
	}

	public function test_an_unfinished_site_setup_is_finished(): void {
		Functions\when( 'wp_is_site_initialized' )->alias( static fn( int $id ): bool => 5 === $id );
		Functions\expect( 'wp_initialize_site' )->once()->with(
			7,
			[
				'title'   => 'new.test/shop',
				'user_id' => 3,
			]
		)->andReturn( true );

		( new SiteFactory() )->finishSetup( 5, 'new.test/x', 3 );
		( new SiteFactory() )->finishSetup( 7, 'new.test/shop', 3 );
		$this->addToAssertionCount( 1 );
	}

	public function test_a_site_setup_that_cannot_be_finished_stops_the_import(): void {
		Functions\when( 'wp_is_site_initialized' )->justReturn( false );
		Functions\when( 'wp_initialize_site' )->justReturn( new FakeWpError( 'Site with the ID does not exist.', 'site_invalid_id' ) );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The new site 7 could not be set up: Site with the ID does not exist.' );
		( new SiteFactory() )->finishSetup( 7, 'new.test/shop', 3 );
	}

	public function test_a_site_without_its_addresses_is_refused(): void {
		Functions\when( 'switch_to_blog' )->justReturn( true );
		Functions\expect( 'restore_current_blog' )->once()->andReturn( true );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => '/w/u',
				'baseurl' => 'https://new.test/u',
			]
		);
		Functions\when( 'get_option' )->alias( static fn( string $name ) => 'home' === $name ? 'https://new.test/shop' : false );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The site 7 is not set up completely (it has no address), so the restore stopped. A site this restore created is removed again; start the restore again.' );
		( new SiteFactory() )->describe( 7 );
	}

	public function test_undo_goes_on_after_a_site_whose_delete_throws(): void {
		Functions\when( 'wp_delete_site' )->alias(
			function ( int $id ) {
				$this->deleted[] = $id;
				if ( 7 === $id ) {
					throw new \RuntimeException( 'Table drop failed.' );
				}
				return (object) [ 'id' => $id ];
			}
		);
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store, [ 7, 8 ] );
		unset( $job->state['creating'] );

		$warnings = ( new SiteFactory() )->undoCreated( $job, $store->dir( $job->id ) );

		$this->assertSame( [ 7, 8 ], $this->deleted );
		$this->assertSame( [ 7 ], $job->state['createdSites'], 'Only the site that is still there stays recorded.' );
		$this->assertCount( 2, $warnings );
	}

	public function test_describe_reads_the_site_and_always_switches_back(): void {
		$switched = [];
		Functions\when( 'switch_to_blog' )->alias(
			static function ( int $id ) use ( &$switched ): bool {
				$switched[] = 'to ' . $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			static function () use ( &$switched ): bool {
				$switched[] = 'back';
				return true;
			}
		);
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => '/w/wp-content/uploads/sites/7',
				'baseurl' => 'https://new.test/wp-content/uploads/sites/7',
			]
		);
		$options = [
			'siteurl'         => 'https://new.test/shop/',
			'home'            => 'https://new.test/shop',
			'upload_path'     => false,
			'upload_url_path' => '',
		];
		Functions\when( 'get_option' )->alias( static fn( string $name ) => $options[ $name ] );

		$this->assertSame(
			[
				'siteUrl'       => 'https://new.test/shop',
				'homeUrl'       => 'https://new.test/shop',
				'uploadsDir'    => '/w/wp-content/uploads/sites/7',
				'uploadsUrl'    => 'https://new.test/wp-content/uploads/sites/7/',
				'uploadPath'    => '',
				'uploadUrlPath' => '',
			],
			( new SiteFactory() )->describe( 7 )
		);
		$this->assertSame( [ 'to 7', 'back' ], $switched );

		Functions\when( 'wp_upload_dir' )->alias(
			static function (): array {
				throw new \RuntimeException( 'Uploads unreadable.' );
			}
		);
		try {
			( new SiteFactory() )->describe( 8 );
			$this->fail( 'The failure reaches the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( [ 'to 7', 'back', 'to 8', 'back' ], $switched, 'The main site is restored also on a failure.' );
		}
	}

	public function test_the_main_site_and_id_one_are_never_deleted(): void {
		Functions\when( 'get_main_site_id' )->justReturn( 2 );
		$sites = new SiteFactory();

		$this->assertFalse( $sites->delete( 1 ) );
		$this->assertFalse( $sites->delete( 2 ) );
		$this->assertFalse( $sites->delete( 0 ) );
		$this->assertTrue( $sites->delete( 7 ) );
		$this->assertFalse( $sites->delete( 9 ), 'WordPress refused it.' );
		$this->assertSame( [ 7, 9 ], $this->deleted );
	}

	public function test_undo_leaves_no_empty_uploads_folder_of_a_removed_site(): void {
		$uploads = $this->dir . '/uploads/sites';
		mkdir( $uploads . '/7', 0755, true );
		mkdir( $uploads . '/8/2026', 0755, true );
		mkdir( $uploads . '/9', 0755, true );
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store, [ 7, 8, 9 ] );
		unset( $job->state['creating'] );
		foreach ( [ 7, 8, 9 ] as $i => $id ) {
			$job->state['blogs'][ $i ] = [
				'newId'         => $id,
				'url'           => 'https://new.test/b' . $id,
				'newUploadsDir' => $uploads . '/' . $id,
			];
		}

		( new SiteFactory() )->undoCreated( $job, $store->dir( $job->id ) );

		$this->assertDirectoryDoesNotExist( $uploads . '/7', 'WordPress empties the folder of a deleted site but keeps it.' );
		$this->assertDirectoryExists( $uploads . '/8/2026', 'A folder with anything left in it stays.' );
		$this->assertDirectoryExists( $uploads . '/9', 'A site WordPress did not delete keeps its folder.' );
	}

	public function test_the_largest_site_id_is_read_from_the_live_table(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $blogs = 'wp_blogs';

			/** @var array<int, string> */
			public array $queries = [];

			public function get_var( string $sql ): string {
				$this->queries[] = $sql;
				return '12';
			}
		};

		$this->assertSame( 12, ( new SiteFactory() )->maxId() );
		$this->assertSame( [ 'SELECT MAX(blog_id) FROM wp_blogs' ], $GLOBALS['wpdb']->queries );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_an_interrupted_create_counts_only_a_newer_site(): void {
		Functions\when( 'domain_exists' )->justReturn( 7 );
		$creating = [
			'index'       => 0,
			'domain'      => 'new.test',
			'path'        => '/shop/',
			'maxIdBefore' => 6,
		];
		$sites    = new SiteFactory();

		$this->assertSame( 7, $sites->createdAt( $creating, 1 ) );
		$this->assertNull( $sites->createdAt( [ 'maxIdBefore' => 7 ] + $creating, 1 ), 'It existed before the create.' );
		$this->assertNull( $sites->createdAt( [ 'index' => 0 ] + array_diff_key( $creating, [ 'maxIdBefore' => 0 ] ), 1 ), 'Without the recorded largest id nothing counts as created.' );
	}

	public function test_undo_removes_the_created_sites_and_an_interrupted_create_only_before_the_swap(): void {
		Functions\when( 'domain_exists' )->justReturn( 10 );
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store, [ 7, 9, 'x', 1 ] );

		$warnings = ( new SiteFactory() )->undoCreated( $job, $store->dir( $job->id ) );

		$this->assertSame( [ 7, 9, 10 ], $this->deleted, 'Id 1 and broken ids are never deleted; the interrupted create (id 10 > 6) is.' );
		$this->assertSame(
			[
				'The new sites this restore had created were removed again.',
				'The new site https://new.test/b9 could not be removed: delete it under Network Admin > Sites.',
			],
			$warnings
		);
		$this->assertSame( [ 9 ], $job->state['createdSites'] );
		$this->assertArrayNotHasKey( 'creating', $job->state );
	}

	public function test_undo_keeps_every_site_once_the_swap_may_have_run(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store, [ 7 ] );
		file_put_contents( $store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, '' );

		$this->assertSame( [], ( new SiteFactory() )->undoCreated( $job, $store->dir( $job->id ) ) );
		$job->state['swapped'] = true;
		unlink( $store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
		$this->assertSame( [], ( new SiteFactory() )->undoCreated( $job, $store->dir( $job->id ) ) );
		$this->assertSame( [], $this->deleted );
		$this->assertSame( [ 7 ], $job->state['createdSites'] );
	}

	/**
	 * @param array<int, mixed> $created state.createdSites.
	 */
	private function job( JobStore $store, array $created ): Job {
		$job                        = $store->create( 'import', [ 'network_sites' ], [], 1 );
		$job->state['target']       = ( TargetNetwork::fromArray( [ 'domain' => 'new.test' ] ) )->toArray();
		$job->state['createdSites'] = $created;
		$job->state['creating']     = [
			'index'       => 2,
			'domain'      => 'new.test',
			'path'        => '/b10/',
			'maxIdBefore' => 6,
		];
		$job->state['blogs']        = [
			[
				'newId' => 9,
				'url'   => 'https://new.test/b9',
			],
		];
		$store->save( $job );

		return $job;
	}
}
