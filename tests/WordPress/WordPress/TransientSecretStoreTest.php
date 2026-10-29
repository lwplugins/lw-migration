<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\TransientSecretStore;

final class TransientSecretStoreTest extends MonkeyTestCase {

	private const KEY = 'lw_migration_job_secret_0123456789abcdef';

	/** @var array<string, array{0: mixed, 1: int}> */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->store[ $key ] = [ $value, $ttl ];
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->store[ $key ][0] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->store[ $key ] );
				return true;
			}
		);
	}

	public function test_put_get_delete(): void {
		$secrets = new TransientSecretStore();

		$this->assertSame( [], $secrets->get( '0123456789abcdef' ) );
		$secrets->put( '0123456789abcdef', [ 'password' => 'hunter2-secret' ] );
		$this->assertSame( [ 'password' => 'hunter2-secret' ], $secrets->get( '0123456789abcdef' ) );
		$this->assertSame( DAY_IN_SECONDS, $this->store[ self::KEY ][1] );
		$secrets->delete( '0123456789abcdef' );
		$this->assertSame( [], $secrets->get( '0123456789abcdef' ) );
	}

	public function test_the_stored_value_is_sealed(): void {
		( new TransientSecretStore() )->put( '0123456789abcdef', [ 'password' => 'hunter2-secret' ] );

		$stored = $this->store[ self::KEY ][0];
		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( 'hunter2', $stored );
		$this->assertStringNotContainsString( 'password', $stored );
	}

	public function test_a_value_it_cannot_open_reads_as_no_secret(): void {
		( new TransientSecretStore() )->put( '0123456789abcdef', [ 'password' => 'hunter2-secret' ] );
		Functions\when( 'wp_salt' )->justReturn( 'another-site-salt' );
		$this->assertSame( [], ( new TransientSecretStore() )->get( '0123456789abcdef' ), 'Other salts: asked again, never misread.' );

		$this->assertArrayNotHasKey( self::KEY, $this->store, 'A value that does not open is deleted.' );

		$this->store[ self::KEY ] = [ [ 'password' => 'plain' ], DAY_IN_SECONDS ];
		$this->assertSame( [], ( new TransientSecretStore() )->get( '0123456789abcdef' ), 'An old plain array is not trusted.' );
		$this->assertArrayNotHasKey( self::KEY, $this->store, 'An old plain array is deleted.' );
	}

	public function test_an_injected_box_seals_and_opens(): void {
		$box     = new SecretBox( str_repeat( 'k', SecretBox::KEY_BYTES ) );
		$secrets = new TransientSecretStore( $box );
		Functions\when( 'wp_salt' )->alias(
			static function (): string {
				throw new \LogicException( 'An injected box needs no salt.' );
			}
		);

		$secrets->put( '0123456789abcdef', [ 'password' => 'hunter2-secret' ] );

		$this->assertSame( '{"password":"hunter2-secret"}', $box->open( $this->store[ self::KEY ][0] ) );
		$this->assertSame( [ 'password' => 'hunter2-secret' ], $secrets->get( '0123456789abcdef' ) );
	}

	public function test_without_sodium_put_throws_and_get_reads_no_secret(): void {
		$this->store[ self::KEY ] = [ 'lwm1:anything', DAY_IN_SECONDS ];
		$secrets                  = new TransientSecretStore( null, false );

		$this->assertSame( [], $secrets->get( '0123456789abcdef' ) );
		$this->assertArrayHasKey( self::KEY, $this->store, 'Without sodium nothing is judged or deleted.' );
		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'sodium' );
		$secrets->put( '0123456789abcdef', [ 'password' => 'pw' ] );
	}

	public function test_a_value_that_cannot_be_encoded_has_its_own_message(): void {
		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'The password could not be stored (invalid characters).' );
		( new TransientSecretStore() )->put( '0123456789abcdef', [ 'password' => "\xB1\x31" ] );
	}

	public function test_a_failed_write_throws(): void {
		Functions\when( 'set_transient' )->justReturn( false );
		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'saving a WordPress transient failed' );
		( new TransientSecretStore() )->put( '0123456789abcdef', [ 'password' => 'pw' ] );
	}

	public function test_the_stored_rows_are_excluded_from_dumps(): void {
		foreach ( [ '_transient_' . self::KEY, '_transient_timeout_' . self::KEY ] as $row ) {
			$excluded = false;
			foreach ( Options::DUMP_EXCLUDED_PREFIXES as $prefix ) {
				$excluded = $excluded || str_starts_with( $row, $prefix );
			}
			$this->assertTrue( $excluded, $row );
		}
	}
}
