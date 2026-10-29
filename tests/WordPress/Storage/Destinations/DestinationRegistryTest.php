<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Tests\Support\FakeDestination;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DestinationRegistryTest extends MonkeyTestCase {

	private static function type( string $key, ?string $unavailable ): DestinationType {
		return new class( $key, $unavailable ) implements DestinationType {
			public function __construct( private string $k, private ?string $u ) {
			}
			public function key(): string {
				return $this->k;
			}
			public function label(): string {
				return 'Type ' . $this->k;
			}
			public function unavailable(): ?string {
				return $this->u;
			}
			public function fields(): array {
				return [
					new Field( 'host', Field::TEXT, 'Host', true ),
					new Field( 'password', Field::SECRET, 'Password', true ),
					new Field( 'mode', Field::SELECT, 'Mode', false, '', [ 'a' => 'A', 'b' => 'B' ], 'a', [ 'host' => [ 'x' ] ] ),
				];
			}
			public function settings( array $raw ): array {
				return $raw;
			}
			public function make( Profile $profile, array $secrets ): DestinationInterface {
				return new FakeDestination();
			}
			public function describe( Profile $profile ): string {
				return '';
			}
			public function insecure( Profile $profile ): bool {
				return false;
			}
		};
	}

	public function test_the_schema_lists_each_type_with_its_fields_and_availability(): void {
		$registry = new DestinationRegistry( [ self::type( 'ftp', 'This server has no FTP extension.' ), self::type( 'one', null ) ] );

		$schema = $registry->schema();

		$this->assertSame( [ 'ftp', 'one' ], array_column( $schema, 'type' ) );
		$this->assertSame( [ false, true ], array_column( $schema, 'available' ) );
		$this->assertSame( 'This server has no FTP extension.', $schema[0]['reason'] );
		$this->assertSame(
			[
				'key'      => 'mode',
				'kind'     => 'select',
				'label'    => 'Mode',
				'required' => false,
				'help'     => '',
				'options'  => [
					[
						'value' => 'a',
						'label' => 'A',
					],
					[
						'value' => 'b',
						'label' => 'B',
					],
				],
				'default'  => 'a',
				'when'     => [ 'host' => [ 'x' ] ],
			],
			$schema[1]['fields'][2]
		);
		$this->assertNull( $schema[1]['fields'][1]['default'] );
		$this->assertSame( [ 'password' ], $registry->secretFields( 'one' ) );
		$this->assertNull( $registry->get( 'nope' ) );
	}

	public function test_this_site_offers_its_types_in_order(): void {
		$keys = array_map( static fn( DestinationType $t ): string => $t->key(), DestinationRegistry::fromWordPress()->all() );

		$this->assertSame( [ 's3', 'webdav', 'ftp', 'sftp', 'gdrive', 'dropbox', 'onedrive' ], $keys );
	}
}
