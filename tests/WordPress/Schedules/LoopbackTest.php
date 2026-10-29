<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Loopback;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LoopbackTest extends MonkeyTestCase {

	private const JOB = '0123456789abcdef';

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
	}

	public function test_a_token_opens_once(): void {
		$loopback = new Loopback();
		$token    = (string) $loopback->issue( self::JOB, 1000 );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', $token );
		$this->assertStringNotContainsString( $token, (string) $this->db->value( Options::LOOPBACK ), 'Only its hash is stored.' );
		$this->assertTrue( $loopback->redeem( self::JOB, $token, 1001 ) );
		$this->assertFalse( $loopback->redeem( self::JOB, $token, 1002 ), 'A replay fails.' );
		$this->assertNull( $this->db->value( Options::LOOPBACK ) );
	}

	public function test_a_wrong_job_an_expired_or_a_replaced_token_is_refused(): void {
		$loopback = new Loopback();
		$old      = (string) $loopback->issue( self::JOB, 1000 );
		$token    = (string) $loopback->issue( self::JOB, 1000 );

		$this->assertFalse( $loopback->redeem( self::JOB, $old, 1001 ), 'Replaced by the newer token.' );
		$this->assertFalse( $loopback->redeem( 'fedcba9876543210', $token, 1001 ) );
		$this->assertFalse( $loopback->redeem( self::JOB, strtoupper( $token ), 1001 ) );
		$this->assertFalse( $loopback->redeem( self::JOB, '', 1001 ) );
		$this->assertFalse( $loopback->redeem( self::JOB, $token, 1000 + Loopback::TTL ) );
		$this->assertTrue( $loopback->redeem( self::JOB, $token, 1000 + Loopback::TTL - 1 ), 'None of the refusals used it up.' );
	}

	public function test_a_near_miss_token_is_refused_and_the_hash_is_compared_timing_safe(): void {
		$loopback = new Loopback();
		$token    = (string) $loopback->issue( self::JOB, 1000 );
		foreach ( [ 0, 15, 31 ] as $at ) {
			$near        = $token;
			$near[ $at ] = '0' === $near[ $at ] ? '1' : '0';
			$this->assertFalse( $loopback->redeem( self::JOB, $near, 1001 ), 'One hex digit off at ' . $at . '.' );
		}
		$this->assertFalse( $loopback->redeem( self::JOB, $token . '0', 1001 ) );
		$this->assertFalse( $loopback->redeem( self::JOB . '0', $token, 1001 ) );
		$this->assertTrue( $loopback->redeem( self::JOB, $token, 1001 ) );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Schedules/Loopback.php' );
		$this->assertMatchesRegularExpression( '/hash_equals\(\s*\$data\[\'hash\'\],\s*hash\(\s*\'sha256\',\s*\$token\s*\)\s*\)/', $source, 'The stored hash is compared with hash_equals.' );
	}

	public function test_a_damaged_row_is_refused_and_a_new_token_replaces_it(): void {
		$rows = [
			'not json',
			'{"job":"0123456789abcdef","hash":5,"expires":2000}',
			'{"job":"0123456789abcdef","hash":"ab","expires":"2000"}',
			'{"job":"0123456789abcdef","expires":2000}',
		];
		foreach ( $rows as $raw ) {
			$this->db->rows[ Options::LOOPBACK ] = [
				'value'    => $raw,
				'autoload' => 'off',
			];
			$this->assertFalse( ( new Loopback() )->redeem( self::JOB, str_repeat( 'a', 32 ), 1001 ), $raw );
		}

		$token = (string) ( new Loopback() )->issue( self::JOB, 1000 );
		$this->assertSame( 'off', $this->db->rows[ Options::LOOPBACK ]['autoload'] );
		$this->assertSame( [ 'job', 'hash', 'expires' ], array_keys( (array) json_decode( (string) $this->db->value( Options::LOOPBACK ), true ) ) );
		$this->assertSame( hash( 'sha256', $token ), json_decode( (string) $this->db->value( Options::LOOPBACK ), true )['hash'] );
		$this->assertTrue( ( new Loopback() )->redeem( self::JOB, $token, 1001 ) );
	}

	public function test_issue_yields_to_a_request_that_wrote_the_row_first(): void {
		$loopback              = new Loopback();
		$loopback->issue( self::JOB, 1000 );
		$this->db->beforeWrite = static function (): void {
			( new Loopback() )->issue( self::JOB, 1000 );
		};

		$this->assertNull( $loopback->issue( self::JOB, 1000 ) );
	}

	public function test_two_requests_with_one_token_open_one_slice(): void {
		$loopback              = new Loopback();
		$token                 = (string) $loopback->issue( self::JOB, 1000 );
		$this->db->beforeWrite = static function () use ( $token ): void {
			( new Loopback() )->redeem( self::JOB, $token, 1001 ); // The other request deletes it first.
		};

		$this->assertFalse( $loopback->redeem( self::JOB, $token, 1001 ) );
	}

	public function test_send_does_not_wait_and_carries_only_the_job_and_the_token(): void {
		Functions\when( 'site_url' )->alias( static fn( string $path ): string => 'https://site.test/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( string $key, string $value, string $url ): string => $url . '?' . $key . '=' . $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ): string => (string) json_encode( $data ) );
		Filters\expectApplied( 'lw_migration_loopback_url' )->once()->with( 'https://site.test/index.php?rest_route=/lw-migration/v1/schedules/continue' )->andReturnFirstArg();
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( string $url, array $args ): array {
				$this->assertSame( 'https://site.test/index.php?rest_route=/lw-migration/v1/schedules/continue', $url );
				$this->assertSame( [ 0.01, false, false ], [ $args['timeout'], $args['blocking'], $args['sslverify'] ] );
				$this->assertSame( [ 'job' => self::JOB, 'token' => 'abc' ], json_decode( $args['body'], true ) );
				return [];
			}
		);

		( new Loopback() )->send( self::JOB, 'abc' );
	}
}
