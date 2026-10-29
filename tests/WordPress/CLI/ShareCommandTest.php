<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\CLI\ShareCommand;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Each test runs in its own process: the WP_CLI stand-in and Services::get()
 * keep state for the whole process.
 */
final class ShareCommandTest extends MonkeyTestCase {

	private const NAME = 'site-20261009-120000-001.wpress';

	private const ADDRESS = 'http://site.test/index.php?rest_route=/lw-migration/v1/backups/shared';

	private FakeWpdb $db;

	/** @var array<int, array{0: string, 1: array<int, array<string, mixed>>, 2: array<int, string>}> */
	private array $formatted = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		$this->db = FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'rest_url' )->justReturn( self::ADDRESS );
		Functions\when( 'add_query_arg' )->alias( static fn( string $key, string $value, string $url ): string => $url . '&' . $key . '=' . $value );
		Functions\when( 'WP_CLI\Utils\format_items' )->alias(
			function ( string $format, array $items, array $fields ): void {
				$this->formatted[] = [ $format, $items, $fields ];
			}
		);
		$storage = Services::get()->storage();
		$storage->ensure();
		file_put_contents( $storage->backupsDir() . '/' . self::NAME, 'backup' );
	}

	protected function tearDown(): void {
		TempDir::remove( WP_CONTENT_DIR );
		parent::tearDown();
	}

	/**
	 * @param array<int, string>   $args  Arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return string|null The error, or null when the command succeeded.
	 */
	private function share( array $args, array $assoc = [] ): ?string {
		try {
			( new ShareCommand() )( $args, $assoc );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}

		return null;
	}

	#[RunInSeparateProcess]
	public function test_a_link_is_printed_once_and_only_its_digest_is_stored(): void {
		$before = time();

		$this->assertNull( $this->share( [ self::NAME ], [ 'expires' => '2h' ] ) );

		$this->assertSame( 'Transfer link (shown only now; anyone who has it can download this backup):', \WP_CLI::$lines[0] );
		$this->assertMatchesRegularExpression( '/^' . preg_quote( self::ADDRESS, '/' ) . '&share=[a-f0-9]{32}\z/', \WP_CLI::$lines[1] );
		$this->assertStringStartsWith( 'Expires: ', \WP_CLI::$lines[2] );
		$token = substr( \WP_CLI::$lines[1], -32 );
		$raw   = (string) $this->db->value( Options::SHARES );
		$this->assertStringNotContainsString( $token, $raw );
		$link = ( new Shares() )->all()[0];
		$this->assertSame( [ 'own', self::NAME ], [ $link['folder'], $link['name'] ] );
		$this->assertGreaterThanOrEqual( $before + 7200, $link['expiresAt'] );
		$this->assertLessThanOrEqual( time() + 7200, $link['expiresAt'] );

		$this->assertNull( $this->share( [], [ 'list' => true, 'format' => 'json' ] ) );
		[ $format, $items, $fields ] = $this->formatted[0];
		$this->assertSame( 'json', $format );
		$this->assertSame( [ 'id', 'backup', 'folder', 'expires', 'uses', 'last_used', 'state' ], $fields );
		$this->assertSame( [ $link['id'], self::NAME, 'own', 0, '', 'live' ], [ $items[0]['id'], $items[0]['backup'], $items[0]['folder'], $items[0]['uses'], $items[0]['last_used'], $items[0]['state'] ] );
		$this->assertStringNotContainsString( $token, (string) json_encode( $items ) );
	}

	#[RunInSeparateProcess]
	public function test_the_default_expiry_is_a_day(): void {
		$this->assertNull( $this->share( [ self::NAME ] ) );

		$this->assertGreaterThanOrEqual( time() + Shares::DEFAULT_TTL - 5, ( new Shares() )->all()[0]['expiresAt'] );
	}

	#[RunInSeparateProcess]
	public function test_refusals(): void {
		$expiry = 'Give a time from 5m to 7d, for example 30m, 24h or 7d.';
		foreach ( [ '4m', '8d', '169h', '30', '1w', '', '0m', ' 5m', '12345678m' ] as $bad ) {
			$this->assertSame( $expiry, $this->share( [ self::NAME ], [ 'expires' => $bad ] ), $bad );
		}
		$this->assertSame( 'No backup named nope.wpress.', $this->share( [ 'nope.wpress' ] ) );
		$this->assertSame( 'Give a backup name, --list or --revoke=<id>.', $this->share( [] ) );
		$this->assertSame( 'Give a backup name, --list or --revoke=<id>.', $this->share( [ self::NAME ], [ 'list' => true ] ) );
		$this->assertSame( 'Give a backup name, --list or --revoke=<id>.', $this->share( [], [ 'list' => true, 'revoke' => '0a1b2c3d' ] ) );
		$this->assertSame( 'No transfer link with this id.', $this->share( [], [ 'revoke' => '0a1b2c3d' ] ) );
		$this->assertSame( 'No transfer link with this id.', $this->share( [], [ 'revoke' => '../x' ] ) );
		$this->assertSame( [], ( new Shares() )->all() );

		$this->assertNull( $this->share( [ self::NAME ], [ 'expires' => '5m' ] ) );
		$this->assertNull( $this->share( [ self::NAME ], [ 'expires' => '7d' ] ) );
		$this->assertCount( 2, ( new Shares() )->all() );
	}

	#[RunInSeparateProcess]
	public function test_revoke_removes_the_link(): void {
		$this->share( [ self::NAME ] );
		$id = ( new Shares() )->all()[0]['id'];

		$this->assertNull( $this->share( [], [ 'revoke' => $id ] ) );

		$this->assertSame( 'Success: Revoked.', end( \WP_CLI::$lines ) );
		$this->assertSame( [], ( new Shares() )->all() );
	}
}
