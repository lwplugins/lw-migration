<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Endpoint;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Presets;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class S3PresetsTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		AddressGuard::useResolver(
			static fn( string $host ): array => 'metadata.google.internal' === $host ? [ '169.254.169.254' ] : ( 'nowhere.example.test' === $host ? [] : [ '93.184.216.34' ] )
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * Hosts from the providers' docs (spec 21.10, plan D20).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
	 */
	public static function hosts(): array {
		return [
			'aws'          => [ [ 'preset' => 'aws', 'region' => 'eu-central-1' ], 'https', 's3.eu-central-1.amazonaws.com', 'eu-central-1' ],
			'b2'           => [ [ 'preset' => 'b2', 'region' => 'us-west-004' ], 'https', 's3.us-west-004.backblazeb2.com', 'us-west-004' ],
			'wasabi'       => [ [ 'preset' => 'wasabi', 'region' => 'eu-central-2' ], 'https', 's3.eu-central-2.wasabisys.com', 'eu-central-2' ],
			'r2'           => [ [ 'preset' => 'r2', 'accountId' => '0123456789abcdef0123456789abcdef', 'region' => 'ignored' ], 'https', '0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com', 'auto' ],
			'digitalocean' => [ [ 'preset' => 'digitalocean', 'region' => 'fra1' ], 'https', 'fra1.digitaloceanspaces.com', 'fra1' ],
			'hetzner'      => [ [ 'preset' => 'hetzner', 'region' => 'hel1' ], 'https', 'hel1.your-objectstorage.com', 'hel1' ],
			'scaleway'     => [ [ 'preset' => 'scaleway', 'region' => 'nl-ams' ], 'https', 's3.nl-ams.scw.cloud', 'nl-ams' ],
			'custom'       => [ [ 'preset' => 'custom', 'endpoint' => 'http://127.0.0.1:9000/', 'region' => '' ], 'http', '127.0.0.1:9000', 'us-east-1' ],
			'custom private ip'         => [ [ 'preset' => 'custom', 'endpoint' => 'http://10.0.0.5:9000', 'region' => '' ], 'http', '10.0.0.5:9000', 'us-east-1' ],
			'custom loopback v6'        => [ [ 'preset' => 'custom', 'endpoint' => 'http://[::1]:9000', 'region' => '' ], 'http', '[::1]:9000', 'us-east-1' ],
			'custom unresolved name'    => [ [ 'preset' => 'custom', 'endpoint' => 'http://nowhere.example.test', 'region' => '' ], 'http', 'nowhere.example.test', 'us-east-1' ],
			'custom https default port' => [ [ 'preset' => 'custom', 'endpoint' => 'https://s3.example.com:443', 'region' => '' ], 'https', 's3.example.com', 'us-east-1' ],
			'custom http default port'  => [ [ 'preset' => 'custom', 'endpoint' => 'http://s3.example.com:80/', 'region' => '' ], 'http', 's3.example.com', 'us-east-1' ],
			'custom https port 80 kept' => [ [ 'preset' => 'custom', 'endpoint' => 'https://s3.example.com:80', 'region' => '' ], 'https', 's3.example.com:80', 'us-east-1' ],
		];
	}

	/**
	 * @param array<string, mixed> $settings Settings without the bucket.
	 */
	#[DataProvider( 'hosts' )]
	public function test_each_preset_resolves_to_its_documented_host( array $settings, string $scheme, string $host, string $region ): void {
		$resolved = S3Presets::resolve( $settings + [ 'bucket' => 'my-bucket' ] );

		$this->assertSame( [ $scheme, $host, $region ], [ $resolved['scheme'], $resolved['host'], $resolved['region'] ] );
	}

	public function test_path_style_defaults_follow_the_preset_and_a_dotted_bucket_forces_it_on_https(): void {
		$this->assertTrue( S3Presets::resolve( [ 'preset' => 'b2', 'region' => 'us-west-004', 'bucket' => 'b-1' ] )['pathStyle'] );
		$this->assertFalse( S3Presets::resolve( [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'b-1' ] )['pathStyle'] );
		$this->assertTrue( S3Presets::resolve( [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'b-1', 'pathStyle' => true ] )['pathStyle'] );
		$this->assertTrue( S3Presets::resolve( [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'www.example.com', 'pathStyle' => false ] )['pathStyle'] );
	}

	public function test_only_the_custom_preset_makes_a_custom_endpoint(): void {
		$this->assertTrue( S3Endpoint::fromSettings( [ 'preset' => 'custom', 'endpoint' => 'http://10.0.0.5:9000', 'bucket' => 'b-1' ] )->custom );
		$this->assertFalse( S3Endpoint::fromSettings( [ 'preset' => 'aws', 'region' => 'eu-central-1', 'bucket' => 'b-1' ] )->custom );
	}

	public function test_an_uppercase_or_underscore_bucket_forces_path_style(): void {
		$this->assertTrue( S3Presets::resolve( [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'My-Bucket', 'pathStyle' => false ] )['pathStyle'] );
		$this->assertTrue( S3Presets::resolve( [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'my_bucket', 'pathStyle' => false ] )['pathStyle'] );
	}

	public function test_a_key_with_a_dot_segment_is_refused(): void {
		$endpoint = new S3Endpoint( 'https', 's3.example.com', 'us-east-1', 'my-bucket', true );
		foreach ( [ 'a/../b', '../b', 'a/./b', '.', 'a/..' ] as $key ) {
			try {
				$endpoint->path( $key );
				$this->fail( 'Refused: ' . $key );
			} catch ( \LogicException $e ) {
				$this->assertInstanceOf( \LogicException::class, $e );
			}
		}
		$this->assertSame( '/my-bucket/a/.hidden/b..c', $endpoint->path( 'a/.hidden/b..c' ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refused(): array {
		return [
			'unknown preset'        => [ [ 'preset' => 'gcs', 'bucket' => 'b-1' ], 'preset' ],
			'region with a dot'     => [ [ 'preset' => 'aws', 'region' => 'us.east', 'bucket' => 'b-1' ], 'region' ],
			'r2 without an account' => [ [ 'preset' => 'r2', 'bucket' => 'b-1' ], 'accountId' ],
			'custom ftp endpoint'   => [ [ 'preset' => 'custom', 'endpoint' => 'ftp://host', 'bucket' => 'b-1' ], 'endpoint' ],
			'custom with a path'    => [ [ 'preset' => 'custom', 'endpoint' => 'https://host/minio', 'bucket' => 'b-1' ], 'endpoint' ],
			'custom with a user'    => [ [ 'preset' => 'custom', 'endpoint' => 'https://u:p@host', 'bucket' => 'b-1' ], 'endpoint' ],
			'backslash host'        => [ [ 'preset' => 'custom', 'endpoint' => 'http://evil.com\\.good.com', 'bucket' => 'b-1' ], 'endpoint' ],
			'percent host'          => [ [ 'preset' => 'custom', 'endpoint' => 'https://ho%41st', 'bucket' => 'b-1' ], 'endpoint' ],
			'underscore host'       => [ [ 'preset' => 'custom', 'endpoint' => 'https://a_b.com', 'bucket' => 'b-1' ], 'endpoint' ],
			'non-ascii host'        => [ [ 'preset' => 'custom', 'endpoint' => 'https://exämple.com', 'bucket' => 'b-1' ], 'endpoint' ],
			'port zero'             => [ [ 'preset' => 'custom', 'endpoint' => 'https://host:0', 'bucket' => 'b-1' ], 'endpoint' ],
			'metadata ip' => [ [ 'preset' => 'custom', 'endpoint' => 'http://169.254.169.254', 'bucket' => 'b-1' ], 'endpoint' ],
			'metadata ip with a port' => [ [ 'preset' => 'custom', 'endpoint' => 'http://169.254.169.254:80', 'bucket' => 'b-1' ], 'endpoint' ],
			'decimal ip' => [ [ 'preset' => 'custom', 'endpoint' => 'http://2852039166', 'bucket' => 'b-1' ], 'endpoint' ],
			'hex ip' => [ [ 'preset' => 'custom', 'endpoint' => 'http://0xa9fea9fe', 'bucket' => 'b-1' ], 'endpoint' ],
			'octal ip' => [ [ 'preset' => 'custom', 'endpoint' => 'http://0251.0376.0251.0376', 'bucket' => 'b-1' ], 'endpoint' ],
			'mapped v6' => [ [ 'preset' => 'custom', 'endpoint' => 'http://[::ffff:a9fe:a9fe]', 'bucket' => 'b-1' ], 'endpoint' ],
			'aws v6 metadata' => [ [ 'preset' => 'custom', 'endpoint' => 'http://[fd00:ec2::254]', 'bucket' => 'b-1' ], 'endpoint' ],
			'alibaba metadata' => [ [ 'preset' => 'custom', 'endpoint' => 'http://100.100.100.200', 'bucket' => 'b-1' ], 'endpoint' ],
			'name that resolves to metadata' => [ [ 'preset' => 'custom', 'endpoint' => 'http://metadata.google.internal', 'bucket' => 'b-1' ], 'endpoint' ],
			'endpoint over 2048 characters' => [ [ 'preset' => 'custom', 'endpoint' => 'http://' . str_repeat( 'a.', 1021 ) . 'test', 'bucket' => 'b-1' ], 'endpoint' ],
			'numeric last label' => [ [ 'preset' => 'custom', 'endpoint' => 'https://host.123', 'bucket' => 'b-1' ], 'endpoint' ],
			'bucket with a slash'   => [ [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'a/b' ], 'bucket' ],
			'bucket too short'      => [ [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'ab' ], 'bucket' ],
		];
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	#[DataProvider( 'refused' )]
	public function test_a_bad_value_names_its_field( array $settings, string $field ): void {
		try {
			S3Presets::resolve( $settings );
			$this->fail( 'Refused.' );
		} catch ( FieldException $e ) {
			$this->assertSame( $field, $e->field );
		}
	}

	public function test_the_endpoint_builds_signed_paths_and_urls_both_ways(): void {
		$virtual = new S3Endpoint( 'https', 's3.eu-central-1.amazonaws.com', 'eu-central-1', 'my-bucket', false );
		$path    = new S3Endpoint( 'http', '127.0.0.1:9000', 'us-east-1', 'my-bucket', true );

		$this->assertSame( 'my-bucket.s3.eu-central-1.amazonaws.com', $virtual->host() );
		$this->assertSame( '/site/a b.wpress', $virtual->path( 'site/a b.wpress' ) );
		$this->assertSame( '/', $virtual->path( '' ) );
		$this->assertSame( 'https://my-bucket.s3.eu-central-1.amazonaws.com/site/a%20b.wpress?partNumber=1&uploadId=x%2By', $virtual->url( 'site/a b.wpress', [ 'uploadId' => 'x+y', 'partNumber' => '1' ] ) );
		$this->assertSame( '127.0.0.1:9000', $path->host() );
		$this->assertSame( '/my-bucket', $path->path( '' ) );
		$this->assertSame( 'http://127.0.0.1:9000/my-bucket/a.wpress?uploads=', $path->url( 'a.wpress', [ 'uploads' => '' ] ) );
		$this->assertTrue( $path->insecure() );
		$this->assertSame( 'https://my-bucket.s3.eu-central-1.amazonaws.com', $virtual->display() );
	}
}
