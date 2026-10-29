<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\LinkCommand;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\PairingCode;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeLinkPeer;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\SafeWeb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each test runs in its own process: the WP_CLI stand-in keeps state for the
 * whole process. This site (A) uses the global FakeWpdb; the other site is a
 * FakeLinkPeer behind wp_safe_remote_request.
 */
#[RunTestsInSeparateProcesses]
final class LinkCommandTest extends MonkeyTestCase {

	private const HTTP_WARNING = 'Warning: This connection uses unencrypted http: the whole site travels unencrypted, and anyone on the network between the two sites can read it on the way, including its users and their password hashes. Use https on both sites where you can.';

	private const USER_ERROR = 'Pass --user=<admin login>: the connection acts for that admin.';

	private const SINGLE = 'Connections work between single sites only.';

	private string $site = 'https://a.example';

	private string $aAddress = 'https://a.example';

	private string $bAddress = 'https://b.example';

	private int $user = 1;

	private bool $multisite = false;

	private bool $capable = true;

	private FakeWpdb $db;

	private FakeLinkPeer $peer;

	/** @var array<int, array{0: string, 1: array<int, array<string, mixed>>, 2: array<int, string>}> */
	private array $formatted = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		$this->db = FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'site_url' )->alias( fn() => $this->site );
		Functions\when( 'get_bloginfo' )->alias( fn( $key = '' ) => 'version' === $key ? '7.1.2' : ( $this->site === $this->aAddress ? 'Site A' : 'Site B' ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $r ) => $r instanceof FakeWpError );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $h ) => $r['headers'][ strtolower( (string) $h ) ] ?? '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $base ) => $base . '?' . $key . '=' . $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user );
		Functions\when( 'wp_get_current_user' )->alias( fn() => (object) [ 'user_login' => $this->user > 0 ? 'admin' : '' ] );
		Functions\when( 'current_user_can' )->alias( fn() => $this->user > 0 && $this->capable );
		Functions\when( 'WP_CLI\Utils\format_items' )->alias(
			function ( string $format, array $items, array $fields ): void {
				$this->formatted[] = [ $format, $items, $fields ];
			}
		);
		$this->peer = new FakeLinkPeer(
			function ( \Closure $work ) {
				$this->site = $this->bAddress;
				try {
					return $work();
				} finally {
					$this->site = $this->aAddress;
				}
			}
		);
		$send = $this->peer->send();
		SafeWeb::install( $send );
	}

	/**
	 * @param string               $method Subcommand method.
	 * @param array<int, string>   $args   Arguments.
	 * @param array<string, mixed> $assoc  Flags.
	 * @return string|null The error, or null when the command succeeded.
	 */
	private function cli( string $method, array $args = [], array $assoc = [], ?\Closure $readCode = null ): ?string {
		try {
			( new LinkCommand( $readCode ) )->$method( $args, $assoc );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}

		return null;
	}

	/** The code printed by create-code. */
	private function printedCode(): string {
		foreach ( \WP_CLI::$lines as $line ) {
			if ( str_starts_with( $line, PairingCode::PREFIX ) ) {
				return $line;
			}
		}
		$this->fail( 'No code printed.' );
	}

	public function test_a_code_needs_an_admin_and_valid_rights(): void {
		$this->user = 0;
		$this->assertSame( self::USER_ERROR, $this->cli( 'create_code' ) );
		$this->user = 1;
		foreach ( [ 'push,pull,x', 'x', '', 'pull,', 'Pull' ] as $allow ) {
			$this->assertSame( 'Give --allow=pull, --allow=push or --allow=pull,push.', $this->cli( 'create_code', [], [ 'allow' => $allow ] ), $allow );
		}
		$this->assertNull( $this->db->value( Options::LINK_CODE ) );
	}

	public function test_a_code_allows_pull_by_default_and_is_printed_once(): void {
		$this->assertNull( $this->cli( 'create_code' ) );

		$this->assertSame( 'Pairing code (shown only now; it connects one site within 15 minutes, once):', \WP_CLI::$lines[0] );
		$code  = $this->printedCode();
		$this->assertSame( $code, \WP_CLI::$lines[1], 'The code is on its own line.' );
		$this->assertMatchesRegularExpression( '/^Valid until \d{4}-\d\d-\d\d \d\d:\d\d, once\.\z/', \WP_CLI::$lines[2] );
		$this->assertSame( 'The other site may: copy this site (pull).', \WP_CLI::$lines[3] );
		$this->assertNotContains( self::HTTP_WARNING, \WP_CLI::$lines );
		$nonce = PairingCode::decode( $code )['nonce'];
		$open  = ( new Codes() )->matches( KeyExchange::lookup( $nonce ), time() );
		$this->assertSame( [ [ 'pull' ], [ 'id' => 1, 'login' => 'admin' ] ], [ $open['allow'], $open['createdBy'] ] );
		$this->assertStringNotContainsString( $nonce, (string) $this->db->value( Options::LINK_CODE ) );

		\WP_CLI::$lines = [];
		$this->site     = $this->aAddress = 'http://a.example';
		$this->assertNull( $this->cli( 'create_code', [], [ 'allow' => 'pull,push' ] ) );
		$this->assertContains( 'The other site may: copy this site (pull), replace this site\'s content (push).', \WP_CLI::$lines );
		$this->assertContains( self::HTTP_WARNING, \WP_CLI::$lines );
	}

	public function test_adding_a_code_connects_the_sites(): void {
		$this->bAddress = 'http://b.example';
		$code           = $this->peer->code();

		$this->assertNull( $this->cli( 'add', [ $code ] ) );

		$link = ( new Links() )->all( time() )[0];
		$this->assertSame( 'Connected with Site B (http://b.example). Connection id: ' . $link['id'] . '.', end( \WP_CLI::$lines ) );
		$this->assertContains( self::HTTP_WARNING, \WP_CLI::$lines );
		$this->assertSame( [ 'out', 'ready' ], [ $link['direction'], $link['state'] ] );

		$this->assertSame( 'The other site did not accept this code: it was used, it expired, or a newer code replaced it. Make a new code there.', $this->cli( 'add', [ $code ] ) );
		$this->assertCount( 1, ( new Links() )->all( time() ), 'A used code adds nothing.' );
		$this->assertSame( 'This is not a pairing code. Copy the whole code from the other site\'s Connections page.', $this->cli( 'add', [ 'garbage' ] ) );
	}

	public function test_adding_without_an_argument_reads_the_code_from_the_prompt_or_stdin(): void {
		$this->bAddress = 'http://b.example';
		$code           = $this->peer->code();

		$this->assertNull( $this->cli( 'add', [], [], static fn(): string => $code ) );

		$this->assertCount( 1, ( new Links() )->all( time() ) );
		$this->assertStringStartsWith( 'Connected with Site B', (string) end( \WP_CLI::$lines ) );
	}

	public function test_adding_without_a_code_asks_and_stops_when_nothing_is_entered(): void {
		$read  = static function (): string {
			throw new \RuntimeException( 'Enter the Pairing code (hidden prompt on a terminal, or one line on standard input).' );
		};

		$this->assertSame( 'Enter the Pairing code (hidden prompt on a terminal, or one line on standard input).', $this->cli( 'add', [], [], $read ) );
		$this->assertSame( [], $this->peer->requests );
	}

	public function test_adding_asks_nothing_when_there_is_no_admin(): void {
		$this->user = 0;
		$read       = function (): string {
			$this->fail( 'No prompt before the user is checked.' );
		};

		$this->assertSame( self::USER_ERROR, $this->cli( 'add', [], [], $read ) );
	}

	public function test_adding_needs_an_admin(): void {
		$code       = $this->peer->code();
		$this->user = 0;
		$this->assertSame( self::USER_ERROR, $this->cli( 'add', [ $code ] ) );

		$this->user    = 2;
		$this->capable = false; // A subscriber given with --user.
		$this->assertSame( self::USER_ERROR, $this->cli( 'add', [ $code ] ) );

		$this->assertSame( [], $this->peer->requests );
		$this->assertSame( [], ( new Links() )->all( time() ) );
	}

	public function test_the_list_shows_both_directions_without_secrets(): void {
		$this->cli( 'add', [ $this->peer->code() ] );
		( new Links() )->add(
			[
				'id'         => 'aaaaaaaaaaaaaaaa',
				'direction'  => Links::IN,
				'address'    => 'http://c.example',
				'name'       => 'Site C',
				'allow'      => [ 'pull', 'push' ],
				'createdBy'  => [ 'id' => 1, 'login' => 'admin' ],
				'pairedFrom' => '203.0.113.7',
			],
			random_bytes( 32 ),
			1790000000,
			true
		);

		$this->assertNull( $this->cli( 'list_', [], [ 'format' => 'json' ] ) );

		[ $format, $items, $fields ] = $this->formatted[0];
		$this->assertSame( 'json', $format );
		$this->assertSame( [ 'id', 'direction', 'site', 'allow', 'state', 'http', 'paired', 'last_used' ], $fields );
		$this->assertSame( [ 'out', 'Site B (https://b.example)', 'pull', 'ready', 'no' ], [ $items[0]['direction'], $items[0]['site'], $items[0]['allow'], $items[0]['state'], $items[0]['http'] ] );
		$this->assertSame( [ 'in', 'Site C (http://c.example)', 'pull,push', 'yes', gmdate( 'Y-m-d H:i', 1790000000 ) . ' from 203.0.113.7', 'never' ], [ $items[1]['direction'], $items[1]['site'], $items[1]['allow'], $items[1]['http'], $items[1]['paired'], $items[1]['last_used'] ] );
		$this->assertSame( '-', $items[0]['last_used'], 'This site does not record when it used an outgoing connection.' );

		( new Links() )->touch( 'aaaaaaaaaaaaaaaa', 1790000030 ); // The pairing hello: still never used.
		$this->cli( 'list_', [], [ 'format' => 'json' ] );
		$this->assertSame( 'never', $this->formatted[1][1][1]['last_used'] );
		( new Links() )->touch( 'aaaaaaaaaaaaaaaa', 1790003600 );
		$this->cli( 'list_', [], [ 'format' => 'json' ] );
		$this->assertSame( gmdate( 'Y-m-d H:i', 1790003600 ), $this->formatted[2][1][1]['last_used'] );
		$json = (string) json_encode( $items );
		$this->assertStringNotContainsString( 'secret', $json );
		$this->assertStringNotContainsString( 'key', $json );
	}

	public function test_removing_says_whether_the_other_site_was_told(): void {
		$this->cli( 'add', [ $this->peer->code() ] );
		$id = ( new Links() )->all( time() )[0]['id'];

		$this->assertNull( $this->cli( 'remove', [ $id ], [ 'yes' => true ] ) );
		$this->assertSame( 'Success: Removed. The other site was told.', end( \WP_CLI::$lines ) );
		$this->assertSame( [], ( new Links() )->all( time() ) );
		$this->assertNull( $this->peer->links->find( $id, time() ) );

		$this->cli( 'add', [ $this->peer->code() ] );
		$id               = ( new Links() )->all( time() )[0]['id'];
		$this->peer->down = true;
		$this->assertNull( $this->cli( 'remove', [ $id ], [ 'yes' => true ] ) );
		$this->assertSame( 'Success: Removed here; the other site could not be told — remove it there too.', end( \WP_CLI::$lines ) );

		$this->assertSame( 'This connection does not exist (any more).', $this->cli( 'remove', [ $id ], [ 'yes' => true ] ) );
		$this->assertSame( 'This connection does not exist (any more).', $this->cli( 'remove', [ '../x' ], [ 'yes' => true ] ) );

		$this->peer->down = false;
		$this->cli( 'add', [ $this->peer->code() ] );
		$id = ( new Links() )->all( time() )[0]['id'];
		$this->assertSame( 'Pass --yes to remove without a prompt.', $this->cli( 'remove', [ $id ] ) );
		$this->assertCount( 1, ( new Links() )->all( time() ), 'Nothing is removed without --yes.' );
	}

	public function test_every_subcommand_stops_on_a_network(): void {
		$this->multisite = true;
		$calls           = [
			[ 'create_code', [], [] ],
			[ 'add', [ 'lwm1x' ], [] ],
			[ 'list_', [], [] ],
			[ 'remove', [ 'ffffffffffffffff' ], [ 'yes' => true ] ],
		];
		foreach ( $calls as [ $method, $args, $assoc ] ) {
			$this->assertSame( self::SINGLE, $this->cli( $method, $args, $assoc ), $method );
		}
		$this->assertSame( [], $this->db->rows );
	}

	public function test_the_removal_question_depends_on_the_direction(): void {
		$this->assertSame( 'Remove the connection with Site B (https://b.example)? This site can no longer send itself there or copy that site; the other site is told. [y/n]', LinkCommand::removeQuestion( Links::OUT, 'Site B (https://b.example)' ) );
		$this->assertSame( 'Remove the connection with Site C (http://c.example)? The other site can no longer use it. [y/n]', LinkCommand::removeQuestion( Links::IN, 'Site C (http://c.example)' ) );
	}
}
