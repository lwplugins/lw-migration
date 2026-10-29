<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Destination;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class S3TypeTest extends MonkeyTestCase {

	private const GOOD = [
		'preset'    => 'b2',
		'region'    => 'US-West-004 ',
		'bucket'    => ' my-bucket ',
		'prefix'    => '/backups//my-site/',
		'accessKey' => ' 004abcdef0123456789 ',
	];

	public function test_settings_are_normalised_and_preset_defaults_filled_in(): void {
		$settings = ( new S3Type() )->settings( self::GOOD + [ 'endpoint' => 'https://ignored', 'accountId' => 'ignored' ] );

		$this->assertSame(
			[
				'preset'    => 'b2',
				'region'    => 'us-west-004',
				'bucket'    => 'my-bucket',
				'prefix'    => 'backups/my-site',
				'pathStyle' => true,
				'accessKey' => '004abcdef0123456789',
			],
			$settings
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refused(): array {
		return [
			'parent folder'   => [ [ 'prefix' => 'a/../b' ], 'prefix' ],
			'odd characters'  => [ [ 'prefix' => 'a?b' ], 'prefix' ],
			'short key'       => [ [ 'accessKey' => 'abc' ], 'accessKey' ],
			'path style text' => [ [ 'pathStyle' => 'yes' ], 'pathStyle' ],
		];
	}

	/**
	 * @param array<string, mixed> $change Values replaced.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused' )]
	public function test_bad_settings_name_their_field( array $change, string $field ): void {
		try {
			( new S3Type() )->settings( $change + self::GOOD );
			$this->fail( 'Refused.' );
		} catch ( FieldException $e ) {
			$this->assertSame( $field, $e->field );
		}
	}

	public function test_the_schema_has_one_secret_field_and_the_registry_offers_the_type(): void {
		$registry = DestinationRegistry::fromWordPress();

		$this->assertInstanceOf( S3Type::class, $registry->get( 's3' ) );
		$this->assertSame( [ 'secretKey' ], $registry->secretFields( 's3' ) );
		$keys = array_map( static fn( Field $f ): string => $f->key, ( new S3Type() )->fields() );
		$this->assertSame( [ 'preset', 'accountId', 'endpoint', 'region', 'bucket', 'prefix', 'pathStyle', 'accessKey', 'secretKey' ], $keys );
		$this->assertNull( ( new S3Type() )->unavailable() );
	}

	public function test_make_needs_the_secret_and_describes_the_place(): void {
		$type     = new S3Type();
		$settings = $type->settings( self::GOOD );
		$profile  = new Profile( 'abcd1234', 's3', 'Offsite', $settings );

		$this->assertInstanceOf( S3Destination::class, $type->make( $profile, [ 'secretKey' => 'K' ] ) );
		$this->assertSame( 'my-bucket/backups/my-site · Backblaze B2', $type->describe( $profile ) );
		$this->assertFalse( $type->insecure( $profile ) );
		$this->assertTrue( $type->insecure( new Profile( 'abcd1234', 's3', 'Local', $type->settings( [ 'preset' => 'custom', 'endpoint' => 'http://127.0.0.1:9000', 'bucket' => 'bkt', 'accessKey' => 'minioadmin' ] ) ) ) );
		try {
			$type->make( $profile, [] );
			$this->fail( 'A missing secret is a reauth.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
	}

	public function test_insecure_reads_the_stored_scheme_without_a_lookup(): void {
		$type = new S3Type();
		$http = new Profile( 'abcd1234', 's3', 'Local', [ 'preset' => 'custom', 'endpoint' => 'http://storage.invalid:9000', 'bucket' => 'bkt', 'accessKey' => 'minioadmin' ] );
		$tls  = new Profile( 'abcd1234', 's3', 'Local', [ 'preset' => 'custom', 'endpoint' => 'https://storage.invalid', 'bucket' => 'bkt', 'accessKey' => 'minioadmin' ] );

		$this->assertTrue( $type->insecure( $http ) );
		$this->assertFalse( $type->insecure( $tls ) );
	}
}
