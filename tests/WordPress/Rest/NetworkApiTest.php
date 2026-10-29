<?php
/**
 * Tests for Rest\NetworkApi.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Rest\NetworkApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkApiTest extends MonkeyTestCase {

	/** @var array<int, array<string, mixed>> Arguments of every get_sites() call. */
	private array $queries = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_main_network_id' )->justReturn( 1 ); // NetworkReader keeps to the main network.
		$sites = [];
		foreach ( range( 1, 45 ) as $id ) {
			$sites[] = (object) [
				'blog_id'  => (string) $id,
				'domain'   => 'net.test',
				'path'     => 1 === $id ? '/' : '/s' . $id . '/',
				'blogname' => 'Site ' . $id,
			];
		}
		Functions\when( 'get_home_url' )->alias( static fn( int $id ): string => 'http://net.test' . ( 1 === $id ? '' : '/s' . $id ) );
		Functions\when( 'get_sites' )->alias(
			function ( array $args ) use ( $sites ) {
				$this->queries[] = $args;
				$found           = array_values( array_filter( $sites, static fn( object $s ): bool => '' === $args['search'] || str_contains( $s->path, $args['search'] ) ) );

				return ! empty( $args['count'] ) ? count( $found ) : array_slice( $found, $args['offset'], $args['number'] );
			}
		);
	}

	private function api(): NetworkApi {
		return new NetworkApi( new NetworkReader() );
	}

	public function test_the_first_page_has_twenty_sites_and_the_page_count(): void {
		$result = $this->api()->sites( 1, 20, '' );

		$this->assertSame( 45, $result['total'] );
		$this->assertSame( 3, $result['pages'] );
		$this->assertCount( 20, $result['items'] );
		$this->assertSame(
			[
				'id'    => 1,
				'url'   => 'http://net.test',
				'title' => 'Site 1',
			],
			$result['items'][0]
		);
		$this->assertSame( [ 'items', 'total', 'pages' ], array_keys( $result ) );
	}

	public function test_a_later_page_and_a_search_by_address(): void {
		$this->assertSame( [ 41, 42, 43, 44, 45 ], array_column( $this->api()->sites( 3, 20, '' )['items'], 'id' ) );

		$found = $this->api()->sites( 1, 20, 's4' );
		$this->assertSame( [ 4, 40, 41, 42, 43, 44, 45 ], array_column( $found['items'], 'id' ) );
		$this->assertSame( 1, $found['pages'] );
	}

	public function test_query_values_are_read_with_their_defaults(): void {
		$result = $this->api()->find( null, null, null );

		$this->assertCount( 20, $result['items'] );
		$this->assertSame( 0, $this->queries[0]['offset'] );
		$this->assertSame( 20, $this->queries[0]['number'] );
		$this->assertSame( '', $this->queries[0]['search'] );
	}

	public function test_query_strings_are_read_as_numbers(): void {
		$this->assertSame( 21, $this->api()->find( '2', '20', '' )['items'][0]['id'] );
	}

	public function test_per_page_is_clamped_between_one_and_a_hundred(): void {
		$one = $this->api()->find( 1, 0, '' );
		$this->assertCount( 1, $one['items'] );
		$this->assertSame( 45, $one['pages'] );

		$this->api()->find( 1, 500, '' );
		$this->assertSame( 100, $this->queries[2]['number'] );
	}

	public function test_a_page_past_a_million_is_read_as_the_millionth(): void {
		$result = $this->api()->find( '99999999999', 100, '' );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( 99999900, $this->queries[0]['offset'], 'Page 1,000,000 of 100: the offset stays a small whole number.' );
	}

	/**
	 * @dataProvider refused
	 *
	 * @param mixed  $page    Page value.
	 * @param mixed  $perPage Per-page value.
	 * @param mixed  $search  Search value.
	 * @param string $field   The refused field.
	 */
	public function test_malformed_query_values_are_refused( $page, $perPage, $search, string $field ): void {
		try {
			$this->api()->find( $page, $perPage, $search );
			$this->fail( 'Accepted.' );
		} catch ( RestException $e ) {
			$this->assertSame( 400, $e->status );
			$this->assertSame( $field, $e->data['field'] );
		}
		$this->assertSame( [], $this->queries, 'Refused before the network is read.' );
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: string}>
	 */
	public static function refused(): array {
		return [
			'non-numeric page'  => [ 'two', 20, '', 'page' ],
			'page zero'         => [ 0, 20, '', 'page' ],
			'negative page'     => [ '-1', 20, '', 'page' ],
			'list as page'      => [ [ 1 ], 20, '', 'page' ],
			'non-numeric size'  => [ 1, 'many', '', 'perPage' ],
			'list as search'    => [ 1, 20, [ 'x' ], 'search' ],
			'search too long'   => [ 1, 20, str_repeat( 'a', 101 ), 'search' ],
			'control in search' => [ 1, 20, "shop\x00", 'search' ],
		];
	}
}
