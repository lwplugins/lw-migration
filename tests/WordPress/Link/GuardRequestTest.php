<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The permission callbacks on a request object: what core hands them besides the raw body.
 */
#[RunTestsInSeparateProcesses]
final class GuardRequestTest extends MonkeyTestCase {

	private const ID = '0123456789abcdef';

	private const ROUTE = '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/tick';

	private FakeWpdb $db;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-request-class.php';
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		$this->db  = FakeWpdb::install();
		$this->key = random_bytes( 32 );
		( new Links() )->add( [ 'id' => self::ID, 'direction' => Links::IN, 'address' => 'https://a.example', 'name' => 'A', 'allow' => [ 'pull' ], 'createdBy' => [ 'id' => 1, 'login' => 'admin' ], 'pairedFrom' => '' ], $this->key, time() - 10, true );
	}

	private function request( string $method = 'POST', string $route = self::ROUTE, string $body = '', ?string $type = null, ?string $signedBody = null ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route, $body );
		foreach ( Signer::headers( self::ID, $this->key, $method, $route, $signedBody ?? $body, time() ) as $name => $value ) {
			$request->add_header( $name, $value );
		}
		if ( null !== $type ) {
			$request->add_header( 'Content-Type', $type );
		}

		return $request;
	}

	private static function refused( $result ): bool {
		return $result instanceof \WP_Error && 'lw_migration_link_refused' === $result->get_error_code() && [ 'status' => 401 ] === $result->get_error_data();
	}

	public function test_a_signed_request_passes_and_its_link_waits_for_the_callback(): void {
		$request = $this->request( 'POST', self::ROUTE, '{"a":1}', 'application/json; charset=utf-8' );
		$this->assertTrue( Guard::check( $request ) );
		$link = Guard::link( $request );
		$this->assertSame( self::ID, $link['id'] );
		$this->assertSame( $this->key, $link['key'] );
		$this->assertTrue( self::refused( Guard::check( $request ) ), 'A replay is refused.' );

		foreach ( [ null, 'application/octet-stream', 'Application/JSON', 'application/x-www-form-urlencoded', 'application/x-www-form-urlencoded; charset=UTF-8' ] as $type ) {
			$this->assertTrue( Guard::check( $this->request( 'POST', self::ROUTE, 'x', $type ) ), (string) $type );
		}
	}

	/**
	 * Core's rest_send_allow_header (rest_post_dispatch) calls every permission
	 * callback of the matched route again, also for a request core refused
	 * before dispatching it (garbled JSON): there the guard must not use up
	 * the nonce or touch the link, so it refuses read-only.
	 */
	public function test_the_allow_header_pass_after_dispatch_writes_nothing(): void {
		Functions\when( 'doing_filter' )->alias( static fn( $hook = null ) => 'rest_post_dispatch' === $hook );
		$before  = $this->db->rows;
		$request = $this->request();

		$this->assertTrue( self::refused( Guard::check( $request ) ) );
		$this->assertTrue( self::refused( Guard::checkPending( $request ) ) );
		$this->assertSame( $before, $this->db->rows, 'Nothing is written outside the dispatch.' );

		Functions\when( 'doing_filter' )->justReturn( false );
		$this->assertTrue( Guard::check( $request ), 'The same request still passes in the dispatch.' );
	}

	public function test_a_route_that_was_not_guarded_has_no_link(): void {
		$this->expectException( \LogicException::class );
		Guard::link( new \WP_REST_Request( 'POST', self::ROUTE ) );
	}

	public function test_form_fields_or_files_beside_a_signed_empty_body_are_refused(): void {
		$before = $this->db->rows;
		$cases  = [];

		$multipart             = $this->request( 'POST', self::ROUTE, '', 'multipart/form-data; boundary=x' );
		$multipart->bodyParams = [ 'offset' => '0' ];
		$cases['multipart']    = $multipart;

		$fields               = $this->request( 'POST', self::ROUTE, '' );
		$fields->bodyParams   = [ 'offset' => '0' ];
		$cases['body params'] = $fields;

		$files               = $this->request( 'POST', self::ROUTE, '' );
		$files->fileParams   = [ 'archive' => [ 'name' => 'x.zip' ] ];
		$cases['file params'] = $files;

		$form                 = $this->request( 'POST', self::ROUTE, '', 'application/x-www-form-urlencoded; charset=UTF-8' );
		$form->bodyParams     = [ 'offset' => '0' ];
		$cases['form fields'] = $form;

		foreach ( [ 'text/plain', 'multipart/form-data', 'application/json,multipart/form-data', 'garbage' ] as $type ) {
			$cases[ $type ] = $this->request( 'POST', self::ROUTE, '', $type );
		}
		foreach ( $cases as $label => $request ) {
			$this->assertTrue( self::refused( Guard::check( $request ) ), $label );
			$this->assertTrue( self::refused( Guard::checkPending( $request ) ), $label );
		}
		$this->assertSame( $before, $this->db->rows, 'Nothing is written for these refusals.' );
	}

	public function test_a_repeated_link_header_is_refused(): void {
		$request = $this->request();
		$request->add_header( Signer::HEADER_LINK, self::ID ); // Core joins it: "<id>,<id>".
		$this->assertTrue( self::refused( Guard::check( $request ) ) );
	}

	public function test_a_contended_replay_cache_answers_busy(): void {
		$this->db->failWrites = true;
		$busy                 = Guard::check( $this->request() );
		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( [ 'lw_migration_link_busy', [ 'status' => 503 ] ], [ $busy->get_error_code(), $busy->get_error_data() ] );
	}

	public function test_the_pairing_request_must_be_plain_json(): void {
		$codes  = new Codes();
		$nonce  = $codes->issue( [ 'id' => 1, 'login' => 'admin' ], [ 'pull' ], time() )['nonce'];
		$public = KeyExchange::keypair()['public'];
		$body   = (string) json_encode(
			[
				'protocol' => 1,
				'code'     => KeyExchange::lookup( $nonce ),
				'public'   => bin2hex( $public ),
				'address'  => 'https://a.example',
				'name'     => 'A',
				'mac'      => KeyExchange::pairMac( $nonce, $public, 'https://a.example', 'A' ),
			]
		);
		$route  = '/lw-migration/v1/link/pair';
		$make   = static function ( ?string $type ) use ( $route, $body ): \WP_REST_Request {
			$request = new \WP_REST_Request( 'POST', $route, $body );
			if ( null !== $type ) {
				$request->add_header( 'Content-Type', $type );
			}

			return $request;
		};
		$before = $this->db->rows;
		$this->assertTrue( Guard::pairing( $make( 'application/json' ) ) );

		$extra        = $make( 'application/json' );
		$extra->query = [
			'rest_route' => $route,
			'x'          => '1',
		];
		$fields             = $make( 'application/json' );
		$fields->bodyParams = [ 'code' => 'x' ];
		$files              = $make( 'application/json' );
		$files->fileParams  = [ 'f' => [] ];
		foreach ( [ 'extra query' => $extra, 'body params' => $fields, 'file params' => $files, 'no type' => $make( null ), 'multipart' => $make( 'multipart/form-data' ) ] as $label => $request ) {
			$this->assertTrue( self::refused( Guard::pairing( $request ) ), $label );
		}
		$this->assertSame( $before, $this->db->rows, 'The pairing guard only reads.' );
	}
}
