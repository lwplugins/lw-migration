<?php
/**
 * Tests for ResetApi.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Reset\PasswordGuard;
use LightweightPlugins\Migration\Reset\Preflight;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\Tries;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\ResetApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteBusyException;

final class ResetApiTest extends MonkeyTestCase {

	private const NOW = 1791363600;

	private string $root;

	private bool $createdContentDir = false;

	private FakeWpdb $db;

	private bool $multisite = false;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
		$this->root = TempDir::create();
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => WP_CONTENT_DIR . '/uploads' ] );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		} else {
			TempDir::remove( WP_CONTENT_DIR . '/lw-migration-0123456789ab' );
		}
		parent::tearDown();
	}

	private function user(): \WP_User {
		$user             = \Mockery::mock( \WP_User::class );
		$user->ID         = 7;
		$user->user_login = 'anna';
		$user->user_pass  = '$wp$2y$10$hash';

		return $user;
	}

	/**
	 * @param string      $password The right password.
	 * @param string|null $setup    What the database setup check answers.
	 */
	private function api( string $password, ?string $setup = null ): ResetApi {
		$user = $this->user();
		$site = SiteFixture::make( $this->root, [ 'homeUrl' => 'https://example.com' ] );

		return new ResetApi(
			new Services(),
			new PasswordGuard( new Tries(), static fn( string $p ): bool => $p === $password ),
			static fn(): \WP_User => $user,
			static fn(): int => self::NOW,
			fn( Site $site ): Preflight => new Preflight( $site, $this->multisite, static fn(): bool => true, static fn( string $dir ): bool => true, static fn(): ?string => $setup, new RootGuard( 33 ) ),
			static fn(): Site => $site
		);
	}

	private function exception( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	/**
	 * @return array{0: int, 1: string, 2: mixed}
	 */
	private function refusal( \Closure $call ): array {
		$e = $this->exception( $call );

		return [ $e->status, $e->errorCode, $e->data['field'] ?? null ];
	}

	public function test_the_order_of_refusals_never_burns_a_try_for_a_typo(): void {
		$api = $this->api( 'right' );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'type' ], $this->refusal( fn() => $api->start( [ 'type' => 'all', 'password' => 'right' ] ) ) );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'confirm' ], $this->refusal( fn() => $api->start( [ 'type' => 'database', 'password' => 'wrong', 'confirm' => 'example.org' ] ) ) );
		$this->assertSame( 0, ( new Tries() )->lockedFor( 7, self::NOW ), 'The wrong confirmation stopped first; no failure was counted.' );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'password' ], $this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => [ 'x' ] ] ) ) );
		$this->assertNull( $this->db->value( Options::RESET_TRIES ), 'Nothing was counted.' );
	}

	public function test_a_wrong_password_names_the_field_and_the_fifth_locks(): void {
		$api = $this->api( 'right' );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( [ 403, 'lw_migration_invalid', 'password' ], $this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong' ] ) ) );
		}
		$e = $this->exception( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong' ] ) );
		$this->assertSame( [ 429, 'lw_migration_reset_locked', 900 ], [ $e->status, $e->errorCode, $e->data['retryAfter'] ] );
		$e = $this->exception( fn() => $api->start( [ 'type' => 'media', 'password' => 'right' ] ) );
		$this->assertSame( [ 429, 'lw_migration_reset_locked' ], [ $e->status, $e->errorCode ], 'Locked: even the right password waits.' );
	}

	public function test_a_refused_type_says_why_before_the_password_is_checked(): void {
		$this->multisite = true;
		$e               = $this->exception( fn() => $this->api( 'right' )->start( [ 'type' => 'database', 'password' => 'wrong', 'confirm' => 'example.com' ] ) );
		$this->assertSame( [ 409, 'lw_migration_reset_refused', 'network' ], [ $e->status, $e->errorCode, $e->data['reason'] ] );
		$this->assertSame( 'On a network only plugins, themes and media can be reset in this version.', $e->getMessage() );
		$this->assertSame( 0, ( new Tries() )->lockedFor( 7, self::NOW ) );
	}

	public function test_the_right_password_starts_a_job_that_holds_no_secret(): void {
		$job = $this->api( 'right' )->start( [ 'type' => 'database', 'password' => 'right', 'confirm' => ' example.com ', 'backup' => false ] );
		$this->assertSame( [ 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ], $job->steps );
		$this->assertSame( [ 'id' => 7, 'login' => 'anna' ], $job->state['admin'] );
		$this->assertStringNotContainsString( 'right', (string) json_encode( $job->toArray() ) );
		$this->assertNull( $this->db->value( Options::RESET_TRIES ), 'A success clears the attempt.' );
	}

	public function test_backup_is_on_unless_false_is_sent(): void {
		$this->assertSame( 'reset_backup', $this->api( 'right' )->start( [ 'type' => 'media', 'password' => 'right' ] )->steps[0] );
	}

	public function test_an_empty_password_is_refused_without_a_try(): void {
		$this->assertSame( [ 400, 'lw_migration_invalid', 'password' ], $this->refusal( fn() => $this->api( 'right' )->start( [ 'type' => 'media', 'password' => '' ] ) ) );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'password' ], $this->refusal( fn() => $this->api( 'right' )->start( [ 'type' => 'media' ] ) ) );
		$this->assertSame( 0, ( new Tries() )->lockedFor( 7, self::NOW ) );
		$this->assertNull( $this->db->value( Options::RESET_TRIES ) );
	}

	public function test_a_database_setup_refusal_comes_before_the_password(): void {
		$e = $this->exception( fn() => $this->api( 'right', 'sqlite' )->start( [ 'type' => 'database', 'password' => 'wrong', 'confirm' => 'example.com' ] ) );
		$this->assertSame( [ 409, 'lw_migration_reset_refused', 'database_setup' ], [ $e->status, $e->errorCode, $e->data['reason'] ] );
		$this->assertSame( 0, ( new Tries() )->lockedFor( 7, self::NOW ) );
		$e = $this->exception( fn() => $this->api( 'right', 'core_view:wp_postmeta' )->start( [ 'type' => 'full', 'password' => 'right', 'confirm' => 'example.com' ] ) );
		$this->assertSame( 'A core table of this site is a database view (wp_postmeta), so the database cannot be reset here.', $e->getMessage(), 'The detail reaches the text.' );
	}

	public function test_an_unknown_key_or_a_loosely_typed_value_is_refused_before_anything_is_counted(): void {
		$api = $this->api( 'right' );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'force' ], $this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong', 'force' => true ] ) ) );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'backup' ], $this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong', 'backup' => 'false' ] ) ) );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'backup' ], $this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong', 'backup' => 0 ] ) ) );
		$this->assertSame( [ 400, 'lw_migration_invalid', 'confirm' ], $this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong', 'confirm' => [ 'example.com' ] ] ) ) );
		$this->assertNull( $this->db->value( Options::RESET_TRIES ) );
	}

	public function test_a_failure_that_cannot_be_recorded_refuses_as_busy(): void {
		$api = $this->api( 'right' );
		$this->refusal( fn() => $api->start( [ 'type' => 'media', 'password' => 'wrong' ] ) );
		$this->assertNotNull( $this->db->value( Options::RESET_TRIES ) );
		$this->db->failWrites = true;
		$e                    = $this->exception( fn() => $api->start( [ 'type' => 'media', 'password' => 'right' ] ) );
		$this->assertSame( [ 409, 'lw_migration_reset_busy' ], [ $e->status, $e->errorCode ] );
	}

	public function test_a_busy_site_is_the_site_busy_refusal(): void {
		( new \LightweightPlugins\Migration\WordPress\SiteLock() )->acquire( 'aaaaaaaaaaaaaaaa', time() );
		try {
			$this->api( 'right' )->start( [ 'type' => 'media', 'password' => 'right', 'backup' => false ] );
			$this->fail( 'Busy.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( ErrorMap::SITE_BUSY, ErrorMap::of( $e, [], 'x' )['code'] );
		}
	}

	public function test_a_busy_site_refuses_before_the_password_and_counts_no_try(): void {
		( new \LightweightPlugins\Migration\WordPress\SiteLock() )->acquire( 'aaaaaaaaaaaaaaaa', time() );
		try {
			$this->api( 'right' )->start( [ 'type' => 'media', 'password' => 'wrong', 'backup' => false ] );
			$this->fail( 'Busy.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( ErrorMap::SITE_BUSY, ErrorMap::of( $e, [], 'x' )['code'] );
		}
		$this->assertNull( $this->db->value( Options::RESET_TRIES ), 'No try was counted.' );
	}

	public function test_a_site_without_a_typable_address_refuses_the_database_reset(): void {
		$api = new ResetApi(
			new Services(),
			new PasswordGuard( new Tries(), static fn(): bool => true ),
			fn(): \WP_User => $this->user(),
			static fn(): int => self::NOW,
			static fn( Site $site ): Preflight => new Preflight( $site, false, static fn(): bool => true, static fn(): bool => true, static fn(): ?string => null, new RootGuard( 33 ) ),
			fn(): Site => SiteFixture::make( $this->root, [ 'homeUrl' => '' ] )
		);
		$e = $this->exception( fn() => $api->start( [ 'type' => 'database', 'password' => 'right', 'confirm' => '' ] ) );
		$this->assertSame( [ 409, 'lw_migration_reset_refused', 'no_address' ], [ $e->status, $e->errorCode, $e->data['reason'] ] );
	}

	public function test_a_failing_table_listing_answers_without_server_text(): void {
		$api = new ResetApi(
			new Services(),
			new PasswordGuard( new Tries(), static fn(): bool => true ),
			fn(): \WP_User => $this->user(),
			static fn(): int => self::NOW,
			fn( Site $site ): Preflight => new Preflight(
				$site,
				false,
				static fn(): bool => true,
				static fn(): bool => true,
				static function (): ?string {
					throw new DatabaseException( 'Table listing failed: SELECT * FROM secret_server_table' );
				},
				new RootGuard( 33 )
			),
			fn(): Site => SiteFixture::make( $this->root, [ 'homeUrl' => 'https://example.com' ] )
		);
		try {
			$api->start( [ 'type' => 'database', 'password' => 'right', 'confirm' => 'example.com' ] );
			$this->fail( 'Refused.' );
		} catch ( \Throwable $e ) {
			$error = ErrorMap::of( $e, [], 'Something went wrong.' );
			$this->assertStringNotContainsString( 'secret_server_table', $error['message'] );
			$this->assertSame( 500, $error['status'] );
		}
	}
}
