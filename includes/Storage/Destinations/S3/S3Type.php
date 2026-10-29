<?php
/**
 * The S3 destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\FolderSetting;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 S3 and compatibles. Settings: preset, region, accountId (R2),
 * endpoint (custom), bucket, prefix, pathStyle, accessKey; secret: secretKey.
 * settings() normalises (trimmed, lower-case region, folder without leading,
 * trailing or doubled slashes, no "..") and checks the whole address with
 * S3Presets; values another preset does not use are dropped.
 */
final class S3Type implements DestinationType, AuthorityBound {

	public const KEY = 's3';

	private const ACCESS_KEY = '#^[A-Za-z0-9+/=._-]{8,256}\z#';

	/**
	 * @param \Closure|null $send Transport for the clients (tests); null = wp_remote_request.
	 */
	public function __construct( private ?\Closure $send = null ) {
	}

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'S3-compatible storage', 'lw-migration' );
	}

	public function unavailable(): ?string {
		return function_exists( 'simplexml_load_string' ) ? null : __( 'This server lacks PHP\'s SimpleXML extension, which S3 storage needs.', 'lw-migration' );
	}

	public function fields(): array {
		$regional = [ 'preset' => [ S3Presets::AWS, S3Presets::B2, S3Presets::WASABI, S3Presets::DIGITALOCEAN, S3Presets::HETZNER, S3Presets::SCALEWAY, S3Presets::CUSTOM ] ];

		return [
			new Field( 'preset', Field::SELECT, __( 'Provider', 'lw-migration' ), true, '', S3Presets::labels(), S3Presets::AWS ),
			new Field( 'accountId', Field::TEXT, __( 'Account ID', 'lw-migration' ), true, __( 'Shown on the R2 overview page of your Cloudflare dashboard.', 'lw-migration' ), [], '', [ 'preset' => [ S3Presets::R2 ] ] ),
			new Field( 'endpoint', Field::TEXT, __( 'Storage address', 'lw-migration' ), true, __( 'For example https://s3.example.com or http://192.168.1.10:9000. A plain http address sends your backups unencrypted.', 'lw-migration' ), [], '', [ 'preset' => [ S3Presets::CUSTOM ] ] ),
			new Field( 'region', Field::TEXT, __( 'Region', 'lw-migration' ), false, __( 'The region code of the bucket, for example eu-central-1. Empty: the provider\'s default. For DigitalOcean and Hetzner it is the location (fra1, fsn1); the connection test tells whether it fits.', 'lw-migration' ), [], '', $regional ),
			new Field( 'bucket', Field::TEXT, __( 'Bucket', 'lw-migration' ), true ),
			new Field( 'prefix', Field::TEXT, __( 'Folder in the bucket', 'lw-migration' ), false, __( 'Optional, for example backups/my-site. Several sites can share a bucket, each in its own folder.', 'lw-migration' ) ),
			new Field( 'pathStyle', Field::TOGGLE, __( 'Bucket in the path', 'lw-migration' ), false, __( 'Some providers and most self-hosted storages need the bucket in the address path instead of the host name.', 'lw-migration' ) ),
			new Field( 'accessKey', Field::TEXT, __( 'Access key ID', 'lw-migration' ), true ),
			new Field( 'secretKey', Field::SECRET, __( 'Secret access key', 'lw-migration' ), true, __( 'Stored encrypted on this site and never shown again. Leave it empty to keep the stored one.', 'lw-migration' ) ),
		];
	}

	public function settings( array $raw ): array {
		$preset = is_string( $raw['preset'] ?? null ) ? $raw['preset'] : '';
		$path   = $raw['pathStyle'] ?? null;
		if ( null !== $path && ! is_bool( $path ) ) {
			throw new FieldException( 'pathStyle', __( 'Bucket in the path is on or off.', 'lw-migration' ) );
		}
		$clean = [
			'preset'    => $preset,
			'region'    => strtolower( self::text( $raw['region'] ?? '' ) ),
			'accountId' => strtolower( self::text( $raw['accountId'] ?? '' ) ),
			'endpoint'  => self::text( $raw['endpoint'] ?? '' ),
			'bucket'    => self::text( $raw['bucket'] ?? '' ),
			'prefix'    => FolderSetting::clean( $raw['prefix'] ?? '', 'prefix' ),
			'pathStyle' => is_bool( $path ) ? $path : S3Presets::defaultPathStyle( $preset ),
			'accessKey' => self::text( $raw['accessKey'] ?? '' ),
		];
		S3Presets::resolve( $clean );
		if ( 1 !== preg_match( self::ACCESS_KEY, $clean['accessKey'] ) ) {
			throw new FieldException( 'accessKey', __( 'Enter the access key ID your provider gave you.', 'lw-migration' ) );
		}
		if ( S3Presets::R2 !== $preset ) {
			unset( $clean['accountId'] );
		}
		if ( S3Presets::CUSTOM !== $preset ) {
			unset( $clean['endpoint'] );
		}
		if ( S3Presets::R2 === $preset ) {
			unset( $clean['region'] );
		}

		return $clean;
	}

	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
		$secret = (string) ( $secrets['secretKey'] ?? '' );
		if ( '' === $secret ) {
			throw new PermanentFailure( __( 'The secret access key of this destination is missing. Enter it again.', 'lw-migration' ), PermanentFailure::REAUTH );
		}
		try {
			$endpoint = S3Endpoint::fromSettings( $profile->settings );
		} catch ( FieldException $e ) {
			throw new PermanentFailure( $e->getMessage(), PermanentFailure::INVALID );
		}
		$signer = new SigV4Signer( (string) ( $profile->settings['accessKey'] ?? '' ), $secret, $endpoint->region );

		return new S3Destination( new S3Client( $endpoint, $signer, $this->send ), (string) ( $profile->settings['prefix'] ?? '' ) );
	}

	public function authority( array $settings ): array {
		return [ (string) ( $settings['preset'] ?? '' ), strtolower( rtrim( trim( (string) ( $settings['endpoint'] ?? '' ) ), '/' ) ), (string) ( $settings['accountId'] ?? '' ) ];
	}

	public function authorityFields(): array {
		return [ 'secretKey' ];
	}

	public function describe( Profile $profile ): string {
		$s      = $profile->settings;
		$where  = (string) ( $s['bucket'] ?? '' ) . ( '' === (string) ( $s['prefix'] ?? '' ) ? '' : '/' . (string) $s['prefix'] );
		$labels = S3Presets::labels();

		return $where . ' · ' . ( $labels[ (string) ( $s['preset'] ?? '' ) ] ?? '' );
	}

	public function insecure( Profile $profile ): bool {
		$endpoint = $profile->settings['endpoint'] ?? '';

		return S3Presets::CUSTOM === ( $profile->settings['preset'] ?? '' ) && is_string( $endpoint ) && str_starts_with( strtolower( trim( $endpoint ) ), 'http://' );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}
}
