<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Reset\FreshInstall;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FreshInstallTest extends MonkeyTestCase {

	/** @var mixed The wp_roles global before the test. */
	private $roles;

	protected function setUp(): void {
		parent::setUp();
		$this->roles = $GLOBALS['wp_roles'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_roles'] = $this->roles;
		parent::tearDown();
	}

	private static function wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			public array $seen = [];
			/** @var mixed */
			public $answer = null;
			/**
			 * @return string|\WP_Error
			 */
			public function set_prefix( string $prefix ) {
				if ( null !== $this->answer ) {
					return $this->answer;
				}
				$old          = $this->prefix;
				$this->prefix = $prefix;
				$this->seen[] = $prefix;
				return $old;
			}
		};
	}

	public function test_the_sandbox_is_restored_even_when_the_install_throws(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$wpdb                = self::wpdb();
		$roles               = new \stdClass();
		$GLOBALS['wp_roles'] = $roles;
		$suspended           = [];
		Functions\when( 'wp_suspend_cache_addition' )->alias(
			static function ( $suspend = null ) use ( &$suspended ) {
				$suspended[] = $suspend;
				return false;
			}
		);
		$installing = false;
		Functions\when( 'wp_installing' )->alias(
			static function ( $set = null ) use ( &$installing ) {
				$was = $installing;
				if ( null !== $set ) {
					$installing = (bool) $set;
				}
				return null === $set ? $installing : $was;
			}
		);
		$order = [];
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key, $group ) use ( &$order, $wpdb ): bool {
				$order[] = 'delete ' . $key . ' ' . $group . ' ' . $wpdb->prefix;
				return true;
			}
		);
		Functions\expect( 'wp_cache_flush' )->twice()->andReturnUsing(
			static function () use ( &$order, $wpdb ): bool {
				$order[] = 'flush ' . $wpdb->prefix;
				return true;
			}
		); // Before set_prefix() (ruling I1) and in finally.
		$inside = null;
		$build  = new FreshInstall(
			static function ( array $options, int $userId ) use ( $wpdb, &$inside ): void {
				$inside = [ $wpdb->prefix, array_key_exists( 'wp_roles', $GLOBALS ) ? $GLOBALS['wp_roles'] : 'unset', $options['siteurl'], $userId, wp_installing() ];
				throw new \RuntimeException( 'dbDelta failed' );
			}
		);
		try {
			$build->build( $wpdb, 'lwtab12_', [ 'siteurl' => 'https://live.test' ], 7 );
			$this->fail( 'The install failure reaches the step.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'dbDelta failed', $e->getMessage() );
		}
		$this->assertSame( [ 'lwtab12_', null, 'https://live.test', 7, true ], $inside );
		$this->assertFalse( wp_installing(), 'Restored.' );
		$this->assertSame( 'wp_', $wpdb->prefix );
		$this->assertSame( [ 'lwtab12_', 'wp_' ], $wpdb->seen );
		$this->assertSame( $roles, $GLOBALS['wp_roles'] );
		$this->assertSame( [ true, false ], $suspended );
		$this->assertSame( [ 'flush wp_', 'delete 7 user_meta wp_', 'flush wp_' ], $order, 'Both before the prefix changes; the last after it is back.' );
	}

	public function test_a_prefix_the_database_refuses_stops_before_anything_runs(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\expect( 'wp_installing' )->never();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		$wpdb         = self::wpdb();
		$wpdb->answer = new \WP_Error( 'invalid_db_prefix', 'Invalid database prefix' );
		$ran          = false;
		try {
			( new FreshInstall(
				static function () use ( &$ran ): void {
					$ran = true;
				}
			) )->build( $wpdb, 'lwtab12_', [], 7 );
			$this->fail( 'Refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The temporary table prefix was refused.', $e->getMessage() );
		}
		$this->assertFalse( $ran );
		$this->assertSame( 'wp_', $wpdb->prefix );
	}

	public function test_only_a_temporary_prefix_is_ever_built(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'wp_cache_flush' )->never();
		$this->expectException( \LogicException::class );
		( new FreshInstall( static function (): void {} ) )->build( self::wpdb(), 'wp_', [], 7 );
	}

	public function test_never_on_a_network(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->expectException( \LogicException::class );
		( new FreshInstall( static function (): void {} ) )->build( new \stdClass(), 'lwtab12_', [], 7 );
	}
}
