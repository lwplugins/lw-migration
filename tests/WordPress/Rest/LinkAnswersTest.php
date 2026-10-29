<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Rest\LinkAnswers;
use LightweightPlugins\Migration\Rest\LinkPeerRoutes;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Issue #1: every answer to a verified signed request carries the link's
 * signature over that request's nonce, the status and the body core sends.
 */
final class LinkAnswersTest extends MonkeyTestCase {

	private const LINK = '0123456789abcdef';

	private string $key = '';

	private function site(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-http-response-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-request-class.php';
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'doing_filter' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $options = 0 ) => json_encode( $data, $options ) );
		FakeWpdb::install();
		$this->key = random_bytes( 32 );
		( new Links() )->add(
			[
				'id'         => self::LINK,
				'direction'  => Links::IN,
				'address'    => 'https://a.example',
				'name'       => 'A',
				'allow'      => [ 'pull' ],
				'createdBy'  => [ 'id' => 1, 'login' => 'admin' ],
				'pairedFrom' => '',
			],
			$this->key,
			time(),
			true
		);
	}

	/**
	 * A request the link signed, verified by the guard as core would.
	 */
	private function verified( string $route = '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/tick' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', $route );
		foreach ( Signer::headers( self::LINK, $this->key, 'POST', $route, '', time() ) as $name => $value ) {
			$request->add_header( $name, $value );
		}
		$this->assertTrue( Guard::check( $request ) );

		return $request;
	}

	#[RunInSeparateProcess]
	public function test_an_answer_to_a_verified_request_is_signed_over_its_nonce_status_and_body(): void {
		$this->site();
		$request = $this->verified();
		$data    = [
			'status'        => 'done',
			'archiveBytes'  => 4096,
			'archiveSha256' => str_repeat( 'a', 64 ),
			'message'       => 'Ready – "kész" / <b>',
		];
		foreach ( [ 200, 201, 409 ] as $status ) {
			$response = new \WP_HTTP_Response( $data, $status );

			$this->assertSame( $response, LinkAnswers::sign( $response, null, $request ) );

			$signature = $response->get_headers()[ Signer::HEADER_RESPONSE ] ?? '';
			$this->assertTrue( Signer::verifyAnswer( self::LINK, $this->key, (string) $request->get_header( Signer::HEADER_NONCE ), $status, (string) json_encode( $data ), $signature ), (string) $status );
		}
	}

	#[RunInSeparateProcess]
	public function test_the_signed_body_follows_cores_json_options(): void {
		$this->site();
		$request = $this->verified();
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => 'rest_json_encode_options' === $hook ? $value | JSON_PRETTY_PRINT : $value );
		$response = new \WP_HTTP_Response( [ 'a' => [ 1, 2 ] ], 200 );

		LinkAnswers::sign( $response, null, $request );

		$this->assertTrue( Signer::verifyAnswer( self::LINK, $this->key, (string) $request->get_header( Signer::HEADER_NONCE ), 200, (string) json_encode( [ 'a' => [ 1, 2 ] ], JSON_PRETTY_PRINT ), $response->get_headers()[ Signer::HEADER_RESPONSE ] ?? '' ) );
	}

	#[RunInSeparateProcess]
	public function test_an_answer_without_a_verified_link_is_left_unsigned(): void {
		$this->site();
		$refused = new \WP_REST_Request( 'POST', '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/tick' );
		$refused->add_header( Signer::HEADER_NONCE, str_repeat( 'a', 32 ) );
		$this->assertNotTrue( Guard::check( $refused ) );
		$pair = new \WP_REST_Request( 'POST', '/lw-migration/v1/link/pair' );

		foreach ( [ $refused, $pair ] as $request ) {
			$response = new \WP_HTTP_Response( [ 'code' => 'lw_migration_link_refused' ], 401 );
			LinkAnswers::sign( $response, null, $request );
			$this->assertArrayNotHasKey( Signer::HEADER_RESPONSE, $response->get_headers(), $request->get_route() );
		}
		$this->assertSame( 'kept', LinkAnswers::sign( 'kept', null, $this->verified() ) );
	}

	#[RunInSeparateProcess]
	public function test_the_peer_routes_sign_their_answers_after_every_other_filter(): void {
		$this->site();
		Functions\when( 'register_rest_route' )->justReturn( true );

		LinkPeerRoutes::register();

		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_post_dispatch', [ LinkAnswers::class, 'sign' ] ) );
		$this->assertSame( 10, has_filter( 'rest_post_dispatch', [ LinkPeerRoutes::class, 'noStore' ] ) );
	}
}
