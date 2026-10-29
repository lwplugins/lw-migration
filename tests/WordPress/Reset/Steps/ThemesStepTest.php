<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\DefaultTheme;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\Steps\ThemesStep;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class ThemesStepTest extends MonkeyTestCase {

	private string $root;

	private string $themes;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->root   = TempDir::create();
		$this->themes = $this->root . '/wp-content/themes';
		$this->store  = new JobStore( $this->root . '/jobs' );
		foreach ( [ 'twentytwentyfive', 'child', 'parentx', 'old' ] as $slug ) {
			mkdir( $this->themes . '/' . $slug, 0755, true );
			file_put_contents( $this->themes . '/' . $slug . '/style.css', '/* Theme Name: ' . $slug . ' */' );
		}
		mkdir( $this->root . '/outside', 0755 );
		file_put_contents( $this->root . '/outside/style.css', '/* Theme Name: shared */' );
		symlink( $this->root . '/outside', $this->themes . '/shared' );
		mkdir( $this->root . '/other-root/elsewhere', 0755, true );
		mkdir( $this->root . '/lang/themes', 0755, true );
		foreach ( [ 'old-hu_HU.mo', 'old-hu_HU.po', 'twentytwentyfive-hu_HU.mo' ] as $file ) {
			file_put_contents( $this->root . '/lang/themes/' . $file, 'x' );
		}
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		parent::tearDown();
	}

	private function job( string $type ): Job {
		$job = ResetJobs::create( $this->store, new SiteLock(), SiteFixture::make( $this->root ), $type, false, [ 'id' => 1, 'login' => 'admin' ], 1791363600 );

		return $this->store->load( $job->id );
	}

	private function step( array &$log, array $installed, array $themes, string $coreDefault = '', ?RootGuard $root = null ): ThemesStep {
		return new ThemesStep(
			$this->store,
			new DefaultTheme(
				static fn( string $slug ): bool => in_array( $slug, $installed, true ),
				static fn(): ?string => '' === $coreDefault ? null : $coreDefault,
				static fn(): bool => false,
				static fn(): string => 'lw-migration-blank',
				false,
				true
			),
			static fn(): array => $themes,
			static fn(): array => [],
			$this->root . '/lang',
			static function ( string $slug, ?int $site ) use ( &$log ): void {
				$log[] = 'activate:' . $slug . ':' . var_export( $site, true );
			},
			static function ( array $list, ?int $site, array $names = [] ) use ( &$log ): void {
				$log[] = 'mods:' . implode( ',', $list ) . ':' . var_export( $site, true );
				$log[] = 'names:' . implode( ',', $names );
			},
			$root ?? new RootGuard( 33 )
		);
	}

	/** @return array<string, array{root: string, parent: string}> */
	private function all(): array {
		return [
			'twentytwentyfive' => [
				'root'   => $this->themes,
				'parent' => '',
			],
			'old'              => [
				'root'   => $this->themes,
				'parent' => '',
				'name'   => 'Old Theme',
			],
			'child'            => [
				'root'   => $this->themes,
				'parent' => 'parentx',
			],
			'parentx'          => [
				'root'   => $this->themes,
				'parent' => '',
			],
			'shared'           => [
				'root'   => $this->themes,
				'parent' => '',
			],
			'elsewhere'        => [
				'root'   => $this->root . '/other-root',
				'parent' => '',
			],
		];
	}

	public function test_the_default_is_activated_first_then_every_other_theme_goes(): void {
		FakeWpdb::install();
		$log  = [];
		$step = $this->step( $log, [ 'twentytwentyfive' ], $this->all() );
		$job  = $this->job( 'themes' );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame( 'activate:twentytwentyfive:NULL', $log[0], 'Switched before anything is deleted.' );
		$this->assertSame( [ '.', '..', 'shared', 'twentytwentyfive' ], scandir( $this->themes ) );
		$this->assertFileExists( $this->root . '/outside/style.css', 'A linked theme\'s target stays.' );
		$this->assertDirectoryExists( $this->root . '/other-root/elsewhere' );
		$this->assertSame( 'mods:child,old,parentx:NULL', $log[ count( $log ) - 2 ] );
		$this->assertSame( 'names:Old Theme', end( $log ), 'The legacy mods row of a theme is named by its header name.' );
		$this->assertSame( [ 'count' => 3, 'names' => [ 'child', 'old', 'parentx' ], 'leftovers' => 0 ], $job->state['removed']['themes'] );
		$this->assertSame( [ 'slug' => 'twentytwentyfive', 'source' => 'installed' ], $job->state['defaultTheme'] );
		$this->assertSame( [ '.', '..', 'twentytwentyfive-hu_HU.mo' ], scandir( $this->root . '/lang/themes' ) );
		$this->assertSame(
			[
				'The theme elsewhere was left: it lives in another theme folder.',
				'The theme shared was left: its folder is a link to another place.',
			],
			$job->warnings
		);
		$this->assertSame( '3 themes deleted; twentytwentyfive is active.', $result->message() );
	}

	public function test_the_default_theme_and_its_parent_are_kept(): void {
		// The default is "child" (its parent "parentx"): both stay, "old" goes.
		FakeWpdb::install();
		$log  = [];
		$step = $this->step(
			$log,
			[ 'child' ],
			[
				'child'   => [
					'root'   => $this->themes,
					'parent' => 'parentx',
				],
				'parentx' => [
					'root'   => $this->themes,
					'parent' => '',
				],
				'old'     => [
					'root'   => $this->themes,
					'parent' => '',
				],
			],
			'child'
		);
		$job  = $this->job( 'themes' );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertDirectoryExists( $this->themes . '/child' );
		$this->assertDirectoryExists( $this->themes . '/parentx' );
		$this->assertDirectoryDoesNotExist( $this->themes . '/old' );
	}

	public function test_a_run_cut_by_the_deadline_resumes_without_activating_again(): void {
		FakeWpdb::install();
		$log  = [];
		$step = $this->step( $log, [ 'twentytwentyfive' ], $this->all() );
		$job  = $this->job( 'themes' );
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 1 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );
		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( 1, $runs );
		$this->assertCount( 1, array_filter( $log, static fn( string $l ): bool => str_starts_with( $l, 'activate:' ) ) );
		$this->assertCount( 1, array_filter( $log, static fn( string $l ): bool => str_starts_with( $l, 'mods:' ) ) );
		$this->assertSame( [ '.', '..', 'shared', 'twentytwentyfive' ], scandir( $this->themes ) );
		$this->assertSame( 3, $job->state['removed']['themes']['count'] );
	}

	public function test_on_a_network_every_site_gets_the_default_and_forgets_the_deleted(): void {
		FakeWpdb::install()->blogIds = [ 1, 2 ];
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$log  = [];
		$step = $this->step( $log, [ 'twentytwentyfive' ], [ 'old' => $this->all()['old'] ] );
		$job  = $this->job( 'themes' );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame(
			[ 'activate:twentytwentyfive:NULL', 'activate:twentytwentyfive:1', 'activate:twentytwentyfive:2', 'mods:old:NULL', 'names:Old Theme', 'mods:old:1', 'names:Old Theme', 'mods:old:2', 'names:Old Theme' ],
			$log
		);
	}

	public function test_as_root_or_as_an_unknown_user_no_theme_is_touched(): void {
		FakeWpdb::install();
		foreach ( [ 0, null ] as $euid ) {
			$log = [];
			try {
				$this->step( $log, [ 'twentytwentyfive' ], $this->all(), '', new RootGuard( $euid ) )->run( $this->job( 'themes' ), new NoDeadline() );
				$this->fail( 'Refused.' );
			} catch ( JobException $e ) {
				$this->assertSame( 'This reset changes files, so it never runs as root. Run it as the site\'s owner, for example: sudo -u <owner> wp lw-migration reset ...', $e->getMessage() );
			}
			$this->assertSame( [], $log, 'Not even switched.' );
			$this->assertDirectoryExists( $this->themes . '/old' );
			FakeWpdb::install(); // A fresh lock for the next job.
		}
	}

	public function test_a_big_theme_deleted_over_many_ticks_is_progress_for_the_attempt_guard(): void {
		FakeWpdb::install();
		for ( $i = 0; $i < 300; $i++ ) {
			file_put_contents( $this->themes . '/old/f' . $i . '.php', 'x' );
		}
		$log   = [];
		$step  = $this->step( $log, [ 'twentytwentyfive' ], [ 'old' => $this->all()['old'] ] );
		$job   = $this->job( 'themes' );
		$ticks = 0;
		do {
			AttemptGuard::record( $job, ThemesStep::KEY );
			$result = $step->run( $job, new CountdownDeadline( 20 ) );
			++$ticks;
		} while ( ! $result->isDone() );
		$this->assertGreaterThan( AttemptGuard::MAX_ATTEMPTS, $ticks );
		$this->assertDirectoryDoesNotExist( $this->themes . '/old' );
	}

	private function stepWith( \ArrayObject $installed, array $themes ): ThemesStep {
		return new ThemesStep(
			$this->store,
			new DefaultTheme(
				static fn( string $slug ): bool => in_array( $slug, $installed->getArrayCopy(), true ),
				static fn(): ?string => null,
				static fn(): bool => false,
				static fn(): string => 'lw-migration-blank',
				false,
				true
			),
			static fn(): array => $themes,
			static fn(): array => [],
			$this->root . '/lang',
			static function (): void {},
			static function (): void {},
			new RootGuard( 33 )
		);
	}

	public function test_a_default_theme_gone_before_its_activation_is_chosen_again(): void {
		FakeWpdb::install();
		$job                        = $this->job( 'themes' );
		$job->state['defaultTheme'] = [
			'slug'   => 'gone',
			'source' => 'installed',
		];
		$job->cursor                = [ 'phase' => 'activate' ];
		$step                       = $this->stepWith( new \ArrayObject( [ 'twentytwentyfive' ] ), [ 'old' => $this->all()['old'] ] );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame( 'twentytwentyfive', $job->state['defaultTheme']['slug'] );
		$this->assertSame( '1 theme deleted; twentytwentyfive is active.', $result->message() );
	}

	public function test_a_default_theme_gone_while_themes_are_deleted_stops_the_reset(): void {
		FakeWpdb::install();
		for ( $i = 0; $i < 40; $i++ ) {
			file_put_contents( $this->themes . '/old/f' . $i . '.php', 'x' );
		}
		$installed = new \ArrayObject( [ 'twentytwentyfive' ] );
		$step      = $this->stepWith( $installed, [ 'old' => $this->all()['old'], 'parentx' => $this->all()['parentx'] ] );
		$job       = $this->job( 'themes' );
		$this->assertFalse( $step->run( $job, new CountdownDeadline( 5 ) )->isDone() );
		$installed->exchangeArray( [] ); // Someone deleted it between two requests.
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Failed closed.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'The default theme twentytwentyfive is no longer installed, so no more themes were deleted. Install a theme and activate it.', $e->getMessage() );
		}
		$this->assertDirectoryExists( $this->themes . '/parentx', 'Nothing more was deleted.' );
	}

	public function test_a_switch_that_did_not_take_fails_before_anything_is_deleted(): void {
		Functions\when( 'switch_theme' )->justReturn( null );
		Functions\when( 'get_stylesheet' )->justReturn( 'old' );
		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'The default theme twentytwentyfive could not be switched on, so no theme was deleted.' );
		ThemesStep::switchTo( 'twentytwentyfive', null );
	}

	public function test_a_switch_that_took_passes(): void {
		$switched = [];
		Functions\when( 'switch_theme' )->alias(
			static function ( string $slug ) use ( &$switched ): void {
				$switched[] = $slug;
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		ThemesStep::switchTo( 'twentytwentyfive', null );
		$this->assertSame( [ 'twentytwentyfive' ], $switched );
	}

	public function test_a_stub_fallback_is_told(): void {
		FakeWpdb::install();
		$log     = [];
		$written = false;
		$step    = new ThemesStep(
			$this->store,
			new DefaultTheme(
				static function ( string $slug ) use ( &$written ): bool {
					return $written && 'lw-migration-blank' === $slug; // Installed once written.
				},
				static fn(): ?string => null,
				static fn(): bool => false,
				static function () use ( &$written ): string {
					$written = true;
					return 'lw-migration-blank';
				},
				true,
				true
			),
			static fn(): array => [],
			static fn(): array => [],
			$this->root . '/lang',
			static function ( string $slug ) use ( &$log ): void {
				$log[] = $slug;
			},
			static function (): void {},
			new RootGuard( 33 )
		);
		$job  = $this->job( 'themes' );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame( [ 'lw-migration-blank' ], $log );
		$this->assertSame( [ 'slug' => 'lw-migration-blank', 'source' => 'stub' ], $job->state['defaultTheme'] );
		$this->assertSame( [ 'The default theme could not be downloaded, so a minimal blank theme was installed instead.' ], $job->warnings );
	}

	public function test_a_core_folder_mounted_inside_a_theme_is_kept_with_a_warning(): void {
		FakeWpdb::install();
		mkdir( $this->themes . '/old/inner', 0755 );
		file_put_contents( $this->themes . '/old/inner/keep.php', '<?php' );
		$log                               = [];
		$job                               = $this->job( 'themes' );
		$job->state['site']['pluginsDir']  = $this->themes . '/old/inner'; // As a bind mount of the plugins folder.
		$step                              = $this->step( $log, [ 'twentytwentyfive' ], [ 'twentytwentyfive' => $this->all()['twentytwentyfive'], 'old' => $this->all()['old'] ] );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertFileExists( $this->themes . '/old/inner/keep.php' );
		$this->assertFileDoesNotExist( $this->themes . '/old/style.css' );
		$this->assertContains( 'old: 1 folder of the site itself (WordPress, content, plugins or themes) was left as it is.', $job->warnings );
	}

	public function test_a_theme_folder_of_unremovable_files_still_ends_each_tick_and_counts_each_file_once(): void {
		if ( 0 === ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : -1 ) ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		FakeWpdb::install();
		$locked = $this->themes . '/old/locked';
		mkdir( $locked, 0755 );
		for ( $i = 0; $i < 60; $i++ ) {
			file_put_contents( $locked . '/f' . $i . '.php', '<?php' );
		}
		chmod( $locked, 0555 );
		$log    = [];
		$themes = array_intersect_key( $this->all(), [ 'twentytwentyfive' => 1, 'old' => 1 ] );
		$step   = $this->step( $log, [ 'twentytwentyfive' ], $themes );
		$job    = $this->job( 'themes' );
		$ticks  = 0;
		try {
			do {
				AttemptGuard::record( $job, ThemesStep::KEY ); // As the Runner does before every call.
				$result = $step->run( $job, new CountdownDeadline( 5 ) );
				++$ticks;
			} while ( ! $result->isDone() && $ticks < 100 );
		} finally {
			chmod( $locked, 0755 );
		}
		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( AttemptGuard::MAX_ATTEMPTS, $ticks, 'Many passes without a removal, none stopped as stuck.' );
		$this->assertFileDoesNotExist( $this->themes . '/old/style.css' );
		$this->assertSame( [ 'old: 60 files could not be removed (permissions).' ], $job->warnings, 'Each locked file counted once over all passes.' );
		$this->assertSame( 1, $job->state['removed']['themes']['leftovers'], 'Counted as deleted with files left.' );
	}
}
