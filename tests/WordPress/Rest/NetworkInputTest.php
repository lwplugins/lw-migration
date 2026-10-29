<?php
/**
 * Tests for Rest\NetworkInput.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\NetworkInput;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkInputTest extends MonkeyTestCase {

	/** @var array<int, string> Queries the fake database answered. */
	private array $queries = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_main_network_id' )->justReturn( 1 ); // NetworkReader keeps to the main network.
		Functions\when( 'is_multisite' )->justReturn( true );
		$queries         = &$this->queries;
		$GLOBALS['wpdb'] = new class( $queries ) {
			public string $blogs = 'wp_blogs';

			/**
			 * @param array<int, string> $queries Shared query log.
			 */
			public function __construct( private array &$queries ) {
			}

			/**
			 * @param mixed ...$args Values.
			 */
			public function prepare( string $sql, ...$args ): string {
				return vsprintf( $sql, $args );
			}

			/**
			 * @return array<int, string>
			 */
			public function get_col( string $sql ): array {
				$this->queries[] = $sql;
				preg_match( '/IN \(([^)]*)\)/', $sql, $m );

				return array_values( array_intersect( array_map( 'trim', explode( ',', $m[1] ?? '' ) ), [ '1', '2', '3' ] ) );
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	public function test_site_ids_are_whole_numbers_of_existing_sites_in_the_given_order(): void {
		$this->assertSame( [ 3, 2 ], NetworkInput::siteIds( [ 3, 2 ] ) );
		$this->assertSame( [], NetworkInput::siteIds( [] ), 'No choice: the whole network, without a query.' );
		$this->assertCount( 1, $this->queries );
	}

	/**
	 * @dataProvider refusedIds
	 *
	 * @param mixed $raw Request value.
	 */
	public function test_anything_but_a_list_of_distinct_positive_ids_is_refused( $raw ): void {
		$e = $this->refusal( static fn() => NetworkInput::siteIds( $raw ) );

		$this->assertSame( 400, $e->status );
		$this->assertSame( ErrorMap::INVALID, $e->errorCode );
		$this->assertSame( 'sites', $e->data['field'] );
		$this->assertSame( [], $this->queries, 'Refused before the database is asked.' );
	}

	/**
	 * A dropped value would widen the choice (an empty list is the whole network).
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedIds(): array {
		return [
			'not a list'      => [ 'sites' ],
			'an object'       => [ [ 'a' => 2 ] ],
			'string id'       => [ [ '2' ] ],
			'zero'            => [ [ 0 ] ],
			'negative'        => [ [ -2 ] ],
			'float'           => [ [ 2.0 ] ],
			'boolean'         => [ [ true ] ],
			'null'            => [ [ null ] ],
			'duplicate'       => [ [ 2, 2 ] ],
			'too many'        => [ range( 1, 1001 ) ],
			'one bad of many' => [ [ 2, 'x', 3 ] ],
		];
	}

	public function test_a_site_that_does_not_exist_is_refused_by_its_id(): void {
		$e = $this->refusal( static fn() => NetworkInput::siteIds( [ 2, 99 ] ) );

		$this->assertSame( 400, $e->status );
		$this->assertSame( 'sites', $e->data['field'] );
		$this->assertStringContainsString( '99', $e->getMessage() );
		$this->assertArrayNotHasKey( 'generic', $e->data );
	}

	public function test_a_single_site_takes_no_site_choice(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertSame( [], NetworkInput::siteIds( [] ) );
		$this->assertSame( 'sites', $this->refusal( static fn() => NetworkInput::siteIds( [ 1 ] ) )->data['field'] );
		$this->assertSame( [], $this->queries );
	}

	public function test_site_addresses_by_archive_index(): void {
		$this->assertSame( [ 0 => 'http://net.test/shop', 2 => '' ], NetworkInput::siteAddresses( [ 0 => 'http://net.test/shop', 2 => '' ] ) );
		$this->assertSame( [], NetworkInput::siteAddresses( [] ) );
	}

	/**
	 * @dataProvider refusedAddresses
	 *
	 * @param mixed $raw Request value.
	 */
	public function test_malformed_addresses_are_refused( $raw ): void {
		$e = $this->refusal( static fn() => NetworkInput::siteAddresses( $raw ) );

		$this->assertSame( 400, $e->status );
		$this->assertSame( 'sites', $e->data['field'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedAddresses(): array {
		return [
			'not an object'    => [ 'http://net.test/shop' ],
			'non-string value' => [ [ 0 => 5 ] ],
			'nested value'     => [ [ 0 => [ 'http://net.test/shop' ] ] ],
			'name key'         => [ [ 'shop' => 'http://net.test/shop' ] ],
			'negative key'     => [ [ -1 => 'http://net.test/shop' ] ],
			'too long'         => [ [ 0 => 'http://net.test/' . str_repeat( 'a', 2000 ) ] ],
			'control char'     => [ [ 0 => "http://net.test/shop\n" ] ],
			'too many'         => [ array_fill( 0, 1001, 'http://net.test/x' ) ],
		];
	}
}
