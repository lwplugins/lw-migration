<?php
/**
 * A destination type with conditional secrets and a host key, like SFTP.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\ConfirmedFingerprint;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\HostKey;

/**
 * Type "keyed": host (required), auth (password | key, default password),
 * password (required while auth = password), privateKey (multi-line, required
 * while auth = key), passphrase (optional, while auth = key), hostKey. Every
 * profile gets its own FakeDestination from $remotes (made on first use), so
 * a test reaches the "remote side". settings() keeps only non-empty values.
 * Call reset() in a test's setUp.
 */
final class FakeKeyedType implements DestinationType, ConfirmedFingerprint {

	public const KEY = 'keyed';

	/** @var array<string, FakeDestination> Profile id => remote. */
	public static array $remotes = [];

	/**
	 * Runs whenever a destination is opened, so a test can change the world
	 * while a connection is open (e.g. delete or rename the profile).
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $onOpen = null;

	/**
	 * Forgets the remotes and the open hook.
	 */
	public static function reset(): void {
		self::$remotes = [];
		self::$onOpen  = null;
	}

	public static function registry(): DestinationRegistry {
		return new DestinationRegistry( [ new self() ] );
	}

	public static function remote( string $id ): FakeDestination {
		self::$remotes[ $id ] = self::$remotes[ $id ] ?? new FakeDestination( 1024 );

		return self::$remotes[ $id ];
	}

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'Keyed';
	}

	public function unavailable(): ?string {
		return null;
	}

	public function fields(): array {
		return [
			new Field( 'host', Field::TEXT, 'Host', true ),
			new Field( 'auth', Field::SELECT, 'Login', true, '', [ 'password' => 'Password', 'key' => 'Key' ], 'password' ),
			new Field( 'password', Field::SECRET, 'Password', true, '', [], null, [ 'auth' => [ 'password' ] ] ),
			new Field( 'privateKey', Field::SECRET_TEXT, 'Private key', true, '', [], null, [ 'auth' => [ 'key' ] ] ),
			new Field( 'passphrase', Field::SECRET, 'Passphrase', false, '', [], null, [ 'auth' => [ 'key' ] ] ),
			new Field( 'hostKey', Field::TEXT, 'Host key' ),
		];
	}

	public function settings( array $raw ): array {
		return array_filter(
			[
				'host'    => (string) ( $raw['host'] ?? '' ),
				'auth'    => (string) ( $raw['auth'] ?? 'password' ),
				'hostKey' => (string) ( $raw['hostKey'] ?? '' ),
			],
			static fn( string $v ): bool => '' !== $v
		);
	}

	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
		if ( null !== self::$onOpen ) {
			( self::$onOpen )();
		}

		return self::remote( $profile->id );
	}

	public function fingerprintField(): string {
		return 'hostKey';
	}

	public function isFingerprint( string $value ): bool {
		return HostKey::isFingerprint( $value );
	}

	public function describe( Profile $profile ): string {
		return 'keyed://' . (string) ( $profile->settings['host'] ?? '' );
	}

	public function insecure( Profile $profile ): bool {
		return false;
	}
}
