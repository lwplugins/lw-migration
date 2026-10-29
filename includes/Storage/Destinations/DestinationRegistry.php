<?php
/**
 * The cloud destination types this site offers.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthView;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectUri;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpType;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavType;

defined( 'ABSPATH' ) || exit;

/**
 * Type key → type. schema() is what the admin form and `wp lw-migration
 * destination add` build their fields from; an unavailable type is listed
 * with its reason (spec 21.2: "pl. FTP csak ext-ftp-vel").
 */
final class DestinationRegistry {

	/**
	 * By key.
	 *
	 * @var array<string, DestinationType>
	 */
	private array $types = [];

	/**
	 * @param array<int, DestinationType> $types Types in display order.
	 */
	public function __construct( array $types ) {
		foreach ( $types as $type ) {
			$this->types[ $type->key() ] = $type;
		}
	}

	public static function fromWordPress(): self {
		return new self( [ new S3Type(), new WebDavType(), new FtpType(), new SftpType(), new GoogleDrive\GoogleDriveType(), new Dropbox\DropboxType(), new OneDrive\OneDriveType() ] );
	}

	public function get( string $key ): ?DestinationType {
		return $this->types[ $key ] ?? null;
	}

	/**
	 * @return array<int, DestinationType>
	 */
	public function all(): array {
		return array_values( $this->types );
	}

	/**
	 * An OAuth type's item also has `oauth` (OAuthView::schema()).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function schema(): array {
		$schema = [];
		foreach ( $this->types as $key => $type ) {
			$reason   = $type->unavailable();
			$schema[] = [
				'type'      => $key,
				'label'     => $type->label(),
				'available' => null === $reason,
				'reason'    => (string) $reason,
				'fields'    => array_map( static fn( Field $field ): array => $field->toArray(), $type->fields() ),
			] + ( $type instanceof OAuthDestinationType ? [ 'oauth' => OAuthView::schema( $type, [], RedirectUri::current() ) ] : [] );
		}

		return $schema;
	}

	/**
	 * @param string $key Type key.
	 * @return array<int, string> Keys of its secret fields.
	 */
	public function secretFields( string $key ): array {
		$type = $this->get( $key );
		if ( null === $type ) {
			return [];
		}

		return array_values( array_map( static fn( Field $field ): string => $field->key, array_filter( $type->fields(), static fn( Field $field ): bool => $field->isSecret() ) ) );
	}
}
