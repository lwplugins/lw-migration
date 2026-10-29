<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\LinkPeerRoutes;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class LinkPeerRoutesTest extends MonkeyTestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/wpress/plain.wpress';

	private const OWNER = [
		'id'    => 1,
		'login' => 'admin',
	];

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_every_peer_route_is_guarded_by_a_signature(): void {
		$registered = $this->registered();
		$job        = LinkPeerRoutes::JOB;
		$expected   = [
			'/link/pair'                            => [ [ 'POST', 'pairing', 'pair' ] ],
			'/link/hello'                           => [ [ 'POST', 'checkPending', 'hello' ] ],
			'/link'                                 => [ [ 'DELETE', 'check', 'forget' ] ],
			'/link/push'                            => [ [ 'POST', 'check', 'startPush' ] ],
			'/link/pull'                            => [ [ 'POST', 'check', 'startPull' ] ],
			$job                                    => [ [ 'GET', 'check', 'job' ], [ 'DELETE', 'check', 'release' ] ],
			$job . '/tick'                          => [ [ 'POST', 'check', 'tick' ] ],
			$job . '/input'                         => [ [ 'POST', 'check', 'input' ] ],
			$job . '/upload/(?P<offset>\d{1,18})' => [ [ 'POST', 'check', 'upload' ] ],
			$job . '/archive'                       => [ [ 'GET', 'check', 'archive' ] ],
		];

		$this->assertSame( array_keys( $expected ), array_keys( $registered ) );
		foreach ( $registered as $route => [ $namespace, $endpoints ] ) {
			$this->assertSame( Routes::NAMESPACE, $namespace );
			$this->assertCount( count( $expected[ $route ] ), $endpoints, $route );
			foreach ( $expected[ $route ] as $i => [ $method, $guard, $callback ] ) {
				$endpoint = $endpoints[ $i ];
				$this->assertSame( $method, $endpoint['methods'], $route );
				$this->assertSame( [ Guard::class, $guard ], $endpoint['permission_callback'], $route );
				$this->assertNotSame( Permission::class, $endpoint['permission_callback'][0], $route );
				$this->assertNotSame( '__return_true', $endpoint['permission_callback'], $route );
				$this->assertSame( [ LinkPeerRoutes::class, $callback ], $endpoint['callback'], $route );
				$this->assertTrue( method_exists( LinkPeerRoutes::class, $callback ), $callback );
				$this->assertFalse( $endpoint['show_in_index'], $route );
			}
		}
		$this->assertNotFalse( has_filter( 'rest_post_dispatch', [ LinkPeerRoutes::class, 'noStore' ] ) );
		$this->assertSame( 1, preg_match( '@^' . $job . '/upload/(?P<offset>\d{1,18})$@i', '/link/jobs/0123456789abcdef/upload/123', $match ) );
		$this->assertSame( '123', $match['offset'] );
		$this->assertSame( 0, preg_match( '@^' . $job . '/upload/(?P<offset>\d{1,18})$@i', '/link/jobs/0123456789abcdef/upload/-1' ) );
	}

	public function test_nothing_is_registered_on_a_network(): void {
		$this->assertSame( [], $this->registered( true ) );
	}

	public function test_the_job_route_takes_only_sixteen_hex_digits(): void {
		$pattern = '@^' . LinkPeerRoutes::JOB . '$@i';

		$this->assertSame( 1, preg_match( $pattern, '/link/jobs/0123456789abcdef' ) );
		foreach ( [ '/link/jobs/0123456789abcde', '/link/jobs/0123456789abcdef0', '/link/jobs/../../x' ] as $bad ) {
			$this->assertSame( 0, preg_match( $pattern, $bad ), $bad );
		}
	}

	#[RunInSeparateProcess]
	public function test_pair_answers_the_refusal_when_the_code_was_used_meanwhile(): void {
		$db = $this->pairingSite();

		$this->assertEquals( Guard::refusal(), LinkPeerRoutes::pair( $this->pairRequest( str_repeat( 'a', 32 ) ) ), 'No open code.' );

		$nonce = ( new Codes() )->issue( self::OWNER, [ 'pull' ], time() )['nonce'];
		for ( $i = 0; $i < Links::MAX; $i++ ) {
			( new Links() )->add( self::link( sprintf( '%016x', $i ) ), random_bytes( 32 ), time(), true );
		}
		$full = LinkPeerRoutes::pair( $this->pairRequest( $nonce ) );
		$this->assertInstanceOf( \WP_Error::class, $full );
		$this->assertSame( [ 'lw_migration_link_full', 'This site already has 20 connections. Remove one before pairing.', [ 'status' => 409 ] ], [ $full->get_error_code(), $full->get_error_message(), $full->get_error_data() ] );
		$this->assertNotNull( ( new Codes() )->pending( time() ), 'A full list leaves the code usable.' );

		$db->rows   = [];
		$nonce      = ( new Codes() )->issue( self::OWNER, [ 'pull' ], time() )['nonce'];
		$db->failOn = Options::LINKS;
		$used       = LinkPeerRoutes::pair( $this->pairRequest( $nonce ) );
		$this->assertInstanceOf( \WP_Error::class, $used );
		$this->assertSame( [ 'lw_migration_link_code_used', [ 'status' => 409 ] ], [ $used->get_error_code(), $used->get_error_data() ] );
		$this->assertSame( 'The code was used but the connection could not be saved. Make a new code.', $used->get_error_message() );
		$this->assertNull( ( new Codes() )->pending( time() ), 'The code is used up.' );
		$db->failOn = null;

		foreach ( [ '203.0.113.9' => '203.0.113.9', '2001:db8::1' => '2001:db8::1', 'not-an-ip' => '', '' => '' ] as $remote => $stored ) {
			$db->rows                = [];
			$_SERVER['REMOTE_ADDR'] = $remote;
			$nonce                   = ( new Codes() )->issue( self::OWNER, [ 'pull' ], time() )['nonce'];
			$answer                  = LinkPeerRoutes::pair( $this->pairRequest( $nonce ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $answer, $remote );
			$this->assertSame( 200, $answer->get_status() );
			$id = $answer->get_data()['id'];
			$this->assertSame( $stored, ( new Links() )->find( $id, time() )['pairedFrom'], $remote );
			$this->assertEquals( Guard::refusal(), LinkPeerRoutes::pair( $this->pairRequest( $nonce ) ), 'A used code is the refusal.' );
		}
	}

	/**
	 * A storage failure while the code is used is no verdict on the code: the
	 * other site may try again (busy), and the code stays open.
	 */
	#[RunInSeparateProcess]
	public function test_a_storage_failure_at_the_code_answers_busy(): void {
		$db    = $this->pairingSite();
		$nonce = ( new Codes() )->issue( self::OWNER, [ 'pull' ], time() )['nonce'];
		$db->beforeWrite = static function (): void {
			throw new \RuntimeException( 'The database went away.' );
		};

		$this->assertEquals( Guard::busy(), LinkPeerRoutes::pair( $this->pairRequest( $nonce ) ) );
		$this->assertNotNull( ( new Codes() )->pending( time() ), 'The code was not used.' );
		$this->assertSame( [], json_decode( (string) ( $db->value( Options::LINKS ) ?? '[]' ), true ) );
	}

	#[RunInSeparateProcess]
	public function test_a_hello_that_cannot_confirm_answers_busy(): void {
		$db = $this->pairingSite();
		( new Links() )->add( self::link( '0123456789abcdef' ), random_bytes( 32 ), time(), false );
		$request = new \WP_REST_Request( 'POST', '/lw-migration/v1/link/hello' );
		$this->verify( $request, '0123456789abcdef' );
		$db->failOn = Options::LINKS;

		$this->assertEquals( Guard::busy(), LinkPeerRoutes::hello( $request ) );
	}

	#[RunInSeparateProcess]
	public function test_forget_removes_the_verified_link(): void {
		$this->pairingSite();
		( new Links() )->add( self::link( '0123456789abcdef' ), random_bytes( 32 ), time(), true );
		$request = new \WP_REST_Request( 'DELETE', '/lw-migration/v1/link' );
		$this->verify( $request, '0123456789abcdef' );

		$answer = LinkPeerRoutes::forget( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $answer );
		$this->assertSame( [ 'removed' => true ], $answer->get_data() );
		$this->assertNull( ( new Links() )->find( '0123456789abcdef', time() ) );
	}

	/**
	 * Review m1: no cache keeps an answer of a connection route (also a
	 * guard's refusal, which core turns into a response before the filter).
	 */
	#[RunInSeparateProcess]
	public function test_every_peer_answer_is_not_stored_by_caches(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-http-response-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-request-class.php';
		$routes = [
			'/lw-migration/v1/link'                                => true,
			'/lw-migration/v1/link/hello'                          => true,
			'/lw-migration/v1/link/pair'                           => true,
			'/lw-migration/v1/link/jobs/0123456789abcdef/tick'     => true,
			'/lw-migration/v1/linkx'                               => false,
			'/lw-migration/v1/links'                               => false,
			'/lw-migration/v1/jobs'                                => false,
			'/wp/v2/posts'                                         => false,
		];
		foreach ( $routes as $route => $stored ) {
			foreach ( [ 200, 401 ] as $status ) {
				$response = new \WP_HTTP_Response( [], $status );
				$result   = LinkPeerRoutes::noStore( $response, null, new \WP_REST_Request( 'POST', $route ) );
				$this->assertSame( $response, $result );
				$this->assertSame( $stored ? [ 'Cache-Control' => 'no-store, private' ] : [], $result->get_headers(), $route . ' ' . $status );
			}
		}
		$this->assertSame( 'kept', LinkPeerRoutes::noStore( 'kept', null, new \WP_REST_Request( 'POST', '/lw-migration/v1/link' ) ) );
	}

	/**
	 * The job routes act through PeerJobs and JobsApi: the job id and the
	 * upload offset come from the path, the chunk is the raw body, and every
	 * refusal is a JSON error with its status (the archive's before any header).
	 */
	#[RunInSeparateProcess]
	public function test_the_job_routes_drive_only_this_links_jobs(): void {
		$this->jobSite();
		$jobs  = Services::get()->jobs();
		$bytes = (string) file_get_contents( self::FIXTURE );
		$job   = $jobs->create( 'import', [ 'upload', 'validate' ], [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => false ], time() );
		$job->status = Job::NEEDS_INPUT;
		$job->input  = [
			'kind' => 'upload',
			'data' => [ 'size' => strlen( $bytes ) ],
		];
		$job->state  = [
			'upload' => [ 'size' => strlen( $bytes ) ],
			'link'   => [
				'id'      => '0123456789abcdef',
				'ref'     => 'aaaabbbbccccdddd',
				'name'    => 'Site A',
				'address' => 'https://a.example',
			],
			'site'   => SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray(),
		];
		$jobs->save( $job );
		$this->assertTrue( Services::get()->lock()->acquire( $job->id, time() ) );

		try {
			$this->assertErrorAnswer( LinkPeerRoutes::job( $this->signed( 'GET', '/link/jobs/1111111111111111', '', [ 'id' => '1111111111111111' ] ) ), 404, 'lw_migration_no_job' );

			$chunk  = substr( $bytes, 0, 8192 );
			$answer = LinkPeerRoutes::upload( $this->signed( 'POST', '/link/jobs/' . $job->id . '/upload/0', $chunk, [ 'id' => $job->id, 'offset' => '0' ] ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $answer, $answer instanceof \WP_Error ? $answer->get_error_message() : '' );
			$this->assertSame( [ 8192, null, 'Site A' ], [ $answer->get_data()['input']['received'], $answer->get_data()['archiveBytes'], $answer->get_data()['link']['name'] ] );

			$again = LinkPeerRoutes::upload( $this->signed( 'POST', '/link/jobs/' . $job->id . '/upload/0', $chunk, [ 'id' => $job->id, 'offset' => '0' ] ) );
			$this->assertErrorAnswer( $again, 409, 'lw_migration_upload_offset' );
			$this->assertSame( 8192, $again->get_error_data()['received'] ?? null, 'The offset is read from the path.' );

			$this->assertErrorAnswer( LinkPeerRoutes::archive( $this->signed( 'GET', '/link/jobs/' . $job->id . '/archive', '', [ 'id' => $job->id ] ) ), 409, 'lw_migration_archive_not_ready' );
			$this->assertFalse( headers_sent() );

			$this->assertErrorAnswer( LinkPeerRoutes::startPull( $this->signed( 'POST', '/link/pull', '{"ref":"x"}', [], 'application/json' ) ), 400, 'lw_migration_invalid' );
			$this->assertErrorAnswer( LinkPeerRoutes::startPush( $this->signed( 'POST', '/link/push', '{}', [], 'application/json', 'fedcba9876543210' ) ), 403, 'lw_migration_link_not_allowed' );
			$this->assertErrorAnswer( LinkPeerRoutes::job( $this->signed( 'GET', '/link/jobs/' . $job->id, '', [ 'id' => $job->id ], '', 'fedcba9876543210' ) ), 404, 'lw_migration_no_job' );

			$swapped                   = $jobs->create( 'import', [ 'swap', 'done' ], [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => false ], time() );
			$swapped->status           = Job::RUNNING;
			$swapped->state            = $job->state;
			$swapped->state['swapped'] = true;
			$jobs->save( $swapped );
			$this->capable = false;
			$past          = LinkPeerRoutes::job( $this->signed( 'GET', '/link/jobs/' . $swapped->id, '', [ 'id' => $swapped->id ] ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $past, 'Past the swap the link still drives its job (fix round 1).' );
			$this->assertSame( Job::RUNNING, $jobs->load( $swapped->id )->status, 'No I8 cancel past the swap.' );

			$this->assertErrorAnswer( LinkPeerRoutes::tick( $this->signed( 'POST', '/link/jobs/' . $job->id . '/tick', '', [ 'id' => $job->id ] ) ), 403, 'lw_migration_link_owner' );
			$this->assertSame( Job::CANCELLED, $jobs->load( $job->id )->status, 'A job route cancels the job of an admin who lost the capability (I8).' );
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}

	#[RunInSeparateProcess]
	public function test_release_cancels_and_answers_whether_it_was_deferred(): void {
		$this->jobSite();
		$jobs = Services::get()->jobs();
		$job  = $jobs->create( 'import', [ 'upload', 'validate' ], [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => false ], time() );
		$job->state = [
			'upload' => [ 'size' => 9000 ],
			'link'   => [
				'id'  => '0123456789abcdef',
				'ref' => 'aaaabbbbccccdddd',
			],
			'site'   => SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray(),
		];
		$jobs->save( $job );
		$this->assertTrue( Services::get()->lock()->acquire( $job->id, time() ) );

		try {
			$answer = LinkPeerRoutes::release( $this->signed( 'DELETE', '/link/jobs/' . $job->id, '', [ 'id' => $job->id ] ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $answer, $answer instanceof \WP_Error ? $answer->get_error_message() : '' );
			$this->assertSame( [ 'released' => true, 'cancelDeferred' => false ], $answer->get_data() );
			$this->assertSame( Job::CANCELLED, $jobs->load( $job->id )->status );
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}

	/**
	 * Ruling I8 / review focus 4: the other site removes the connection, so
	 * the jobs it started here stop at once; this site's own jobs stay.
	 */
	#[RunInSeparateProcess]
	public function test_forget_cancels_the_jobs_the_link_started(): void {
		$this->jobSite();
		$jobs   = Services::get()->jobs();
		$pushed = $jobs->create( 'import', [ 'upload', 'validate' ], [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => false ], time() );
		$pushed->state = [
			'upload' => [ 'size' => 9000 ],
			'link'   => [
				'id'  => '0123456789abcdef',
				'ref' => 'aaaabbbbccccdddd',
			],
			'site'   => SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray(),
		];
		$jobs->save( $pushed );
		$this->assertTrue( Services::get()->lock()->acquire( $pushed->id, time() ) );
		$mine               = $jobs->create( 'export', [ 'link_export' ], [], time() );
		$mine->state['link'] = [ 'id' => '0123456789abcdef' ];
		$jobs->save( $mine );

		try {
			$answer = LinkPeerRoutes::forget( $this->signed( 'DELETE', '/link' ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $answer );
			$this->assertSame( [ 'removed' => true ], $answer->get_data() );
			$this->assertSame( Job::CANCELLED, $jobs->load( $pushed->id )->status );
			$this->assertSame( Job::PENDING, $jobs->load( $mine->id )->status );
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}

	/**
	 * @param mixed $answer Route answer.
	 */
	private function assertErrorAnswer( $answer, int $status, string $code ): void {
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( [ $code, $status ], [ $answer->get_error_code(), $answer->get_error_data()['status'] ?? null ], $answer->get_error_message() );
	}

	/** Whether the link's admin may still use the plugin. */
	private bool $capable = true;

	/**
	 * A site with a storage folder, the link 0123456789abcdef (push and pull)
	 * and fedcba9876543210 (pull only), both made by user 1 "admin".
	 */
	private function jobSite(): void {
		$db                              = $this->pairingSite();
		$db->rows[ Options::STORAGE_DIR ] = [
			'value'    => 'lw-migration-0123456789ab',
			'autoload' => 'off',
		];
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );
		Functions\when( 'doing_filter' )->justReturn( false );
		Functions\when( 'get_userdata' )->alias(
			static fn( int $id ) => 1 === $id ? (object) [
				'ID'         => 1,
				'user_login' => 'admin',
			] : false
		);
		Functions\when( 'user_can' )->alias( fn(): bool => $this->capable );
		Services::get()->storage()->ensure();
		( new Links() )->add( [ 'allow' => [ 'pull', 'push' ] ] + self::link( '0123456789abcdef' ), random_bytes( 32 ), time(), true );
		( new Links() )->add( self::link( 'fedcba9876543210' ), random_bytes( 32 ), time(), true );
	}

	/**
	 * A request the link signed, verified by the guard as core would.
	 *
	 * @param array<string, string> $url Route pattern parameters.
	 */
	private function signed( string $method, string $path, string $body = '', array $url = [], string $type = 'application/octet-stream', string $link = '0123456789abcdef' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/lw-migration/v1' . $path, $body );
		$request->set_url_params( $url );
		if ( '' !== $type ) {
			$request->add_header( 'Content-Type', $type );
		}
		$this->verify( $request, $link );

		return $request;
	}

	/**
	 * @return array{id: string, direction: string, address: string, name: string, allow: array<int, string>, createdBy: array{id: int, login: string}, pairedFrom: string}
	 */
	private static function link( string $id ): array {
		return [
			'id'         => $id,
			'direction'  => Links::IN,
			'address'    => 'https://a.example',
			'name'       => 'A',
			'allow'      => [ 'pull' ],
			'createdBy'  => self::OWNER,
			'pairedFrom' => '',
		];
	}

	private function pairingSite(): FakeWpdb {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-request-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-response-class.php';
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'site_url' )->justReturn( 'https://b.example' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site B' );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );

		return FakeWpdb::install();
	}

	private function pairRequest( string $nonce ): \WP_REST_Request {
		$public  = KeyExchange::keypair()['public'];
		$request = new \WP_REST_Request(
			'POST',
			'/lw-migration/v1/link/pair',
			(string) json_encode(
				[
					'protocol' => 1,
					'code'     => KeyExchange::lookup( $nonce ),
					'public'   => bin2hex( $public ),
					'address'  => 'https://a.example',
					'name'     => 'A',
					'mac'      => KeyExchange::pairMac( $nonce, $public, 'https://a.example', 'A' ),
				]
			)
		);
		$request->add_header( 'Content-Type', 'application/json' );

		return $request;
	}

	/**
	 * Runs the guard on a request signed for the link, as core would before the callback.
	 */
	private function verify( \WP_REST_Request $request, string $id ): void {
		$key = ( new Links() )->find( $id, time() )['key'];
		foreach ( \LightweightPlugins\Migration\Link\Signer::headers( $id, (string) $key, $request->get_method(), $request->get_route(), $request->get_body(), time() ) as $name => $value ) {
			$request->add_header( $name, $value );
		}
		$this->assertTrue( '/lw-migration/v1/link/hello' === $request->get_route() ? Guard::checkPending( $request ) : Guard::check( $request ) );
	}

	/**
	 * Runs LinkPeerRoutes::register() and records what it registered.
	 *
	 * @return array<string, array{0: string, 1: array<int, array<string, mixed>>}>
	 */
	private function registered( bool $multisite = false ): array {
		Functions\when( 'is_multisite' )->justReturn( $multisite );
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $endpoints ) use ( &$registered ) {
				$registered[ $route ] = [ $namespace, $endpoints ];
				return true;
			}
		);

		LinkPeerRoutes::register();

		return $registered;
	}
}
