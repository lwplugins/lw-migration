<?php
/**
 * The SFTP destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\ConfirmedFingerprint;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\FolderDestination;
use LightweightPlugins\Migration\Storage\Destinations\FolderSetting;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFolder;
use LightweightPlugins\Migration\Storage\Destinations\ServerAddress;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 SFTP. Settings: host, port, user, auth (password | key),
 * folder, hostKey (filled in when the admin confirms the server's key, plan
 * D31) and hostKeyType (stored with it by the confirmation, P-M8); secrets:
 * password, or privateKey (+ passphrase). The host and port follow
 * ServerAddress (H5) when saved and are checked again before every
 * connection, which goes to the checked address (P-C2). Always available:
 * the bundled phpseclib is pure PHP.
 */
final class SftpType implements DestinationType, AuthorityBound, ConfirmedFingerprint {

	public const KEY = 'sftp';

	/**
	 * @param \Closure|null $open fn( string $ip, int $port, int $timeout, string $where, string $hostKeyType ): SftpSession (tests); null = PhpseclibSession.
	 */
	public function __construct( private ?\Closure $open = null ) {
	}

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'SFTP (SSH)', 'lw-migration' );
	}

	public function unavailable(): ?string {
		return null;
	}

	public function fields(): array {
		$key = [ 'auth' => [ 'key' ] ];

		return [
			new Field( 'host', Field::TEXT, __( 'Server', 'lw-migration' ), true, __( 'Host name or IP address, for example backup.example.com.', 'lw-migration' ) ),
			new Field( 'port', Field::NUMBER, __( 'Port', 'lw-migration' ), false, '', [], 22 ),
			new Field( 'user', Field::TEXT, __( 'User name', 'lw-migration' ), true ),
			new Field(
				'auth',
				Field::SELECT,
				__( 'Log in with', 'lw-migration' ),
				true,
				'',
				[
					'password' => __( 'Password', 'lw-migration' ),
					'key'      => __( 'Private key', 'lw-migration' ),
				],
				'password'
			),
			new Field( 'password', Field::SECRET, __( 'Password', 'lw-migration' ), true, __( 'Stored encrypted on this site and never shown again. Leave it empty to keep the stored one.', 'lw-migration' ), [], null, [ 'auth' => [ 'password' ] ] ),
			new Field( 'privateKey', Field::SECRET_TEXT, __( 'Private key', 'lw-migration' ), true, __( 'Paste the whole private key (RSA, Ed25519 or ECDSA, in OpenSSH or PEM format). Stored encrypted on this site and never shown again. Leave it empty to keep the stored one.', 'lw-migration' ), [], null, $key ),
			new Field( 'passphrase', Field::SECRET, __( 'Passphrase of the key', 'lw-migration' ), false, __( 'Only if the key has one.', 'lw-migration' ), [], null, $key ),
			new Field( 'folder', Field::TEXT, __( 'Folder', 'lw-migration' ), false, __( 'Relative to the login folder, or from the server\'s root when it starts with /. Created when it does not exist.', 'lw-migration' ) ),
			new Field( 'hostKey', Field::TEXT, __( 'Server key fingerprint', 'lw-migration' ), false, __( 'Filled in when you confirm the server\'s key at the first connection check. Compare it on the server with ssh-keygen -lf and the host key file of the type shown, for example /etc/ssh/ssh_host_ed25519_key.pub (or ssh_host_ecdsa_key.pub, ssh_host_rsa_key.pub).', 'lw-migration' ) ),
		];
	}

	public function settings( array $raw ): array {
		$host = ServerAddress::host( $raw['host'] ?? null, 'host', __( 'Enter the server\'s host name or IP address (without sftp://).', 'lw-migration' ) );
		$port = ServerAddress::port( $raw['port'] ?? null, 22, 'port' );
		$user = is_string( $raw['user'] ?? null ) ? trim( $raw['user'] ) : '';
		if ( '' === $user || strlen( $user ) > 200 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $user ) ) {
			throw new FieldException( 'user', __( 'Enter the user name.', 'lw-migration' ) );
		}
		$auth = $raw['auth'] ?? 'password';
		if ( ! in_array( $auth, [ 'password', 'key' ], true ) ) {
			throw new FieldException( 'auth', __( 'Log in with a password or a private key.', 'lw-migration' ) );
		}
		$hostKey = is_string( $raw['hostKey'] ?? null ) ? trim( $raw['hostKey'] ) : '';
		if ( '' !== $hostKey && ! HostKey::isFingerprint( $hostKey ) ) {
			throw new FieldException( 'hostKey', __( 'A fingerprint looks like SHA256: and 43 more letters, digits, + or /. Leave it empty to confirm it at the connection check.', 'lw-migration' ) );
		}
		$settings = [
			'host'    => $host,
			'port'    => $port,
			'user'    => $user,
			'auth'    => $auth,
			'folder'  => FolderSetting::clean( $raw['folder'] ?? '', 'folder', true ),
			'hostKey' => $hostKey,
		];
		$keyType  = is_string( $raw['hostKeyType'] ?? null ) ? $raw['hostKeyType'] : '';
		if ( '' !== $hostKey && '' !== $keyType && HostKey::type( $keyType ) === $keyType ) {
			$settings['hostKeyType'] = $keyType; // Only a preference (P-M8): a malformed one is dropped, never refused.
		}

		return $settings;
	}

	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
		$s      = self::stored( $profile );
		$needed = 'key' === $s['auth'] ? 'privateKey' : 'password';
		if ( '' === (string) ( $secrets[ $needed ] ?? '' ) ) {
			throw new PermanentFailure( __( 'The password or the key of this destination is missing. Enter it again.', 'lw-migration' ), PermanentFailure::REAUTH );
		}
		$where     = self::where( $s );
		$open      = $this->open ?? static fn( string $ip, int $port, int $timeout, string $where, string $hostKeyType ): SftpSession => new PhpseclibSession( $ip, $port, $timeout, $where, $hostKeyType );
		$connector = new SftpConnector( $s, $secrets, $open, $where );
		$folder    = $s['folder'];

		return new FolderDestination( self::KEY, $where, static fn(): RemoteFolder => new SftpFolder( $connector->connect(), $folder, $where ) );
	}

	public function authority( array $settings ): array {
		return [ strtolower( trim( (string) ( $settings['host'] ?? '' ) ) ), (int) ( $settings['port'] ?? 22 ) ];
	}

	public function authorityFields(): array {
		return [ 'password', 'privateKey', 'passphrase' ]; // The host key has its own rule (DestinationsApi::forgetHostKey()).
	}

	public function fingerprintField(): string {
		return 'hostKey';
	}

	public function isFingerprint( string $value ): bool {
		return HostKey::isFingerprint( $value );
	}

	public function describe( Profile $profile ): string {
		$folder = (string) ( $profile->settings['folder'] ?? '' );

		return ( '' === $folder ? '' : $folder . ' · ' ) . self::where( $profile->settings );
	}

	public function insecure( Profile $profile ): bool {
		return false;
	}

	/**
	 * The stored settings as a connection needs them, checked again.
	 *
	 * @param Profile $profile The profile.
	 * @return array<string, mixed>
	 * @throws PermanentFailure INVALID when the stored settings no longer hold (a host now in a refused range says so).
	 */
	private static function stored( Profile $profile ): array {
		try {
			return [
				'host'   => ServerAddress::host( $profile->settings['host'] ?? null, 'host' ),
				'port'   => ServerAddress::port( $profile->settings['port'] ?? null, 22, 'port' ),
				'auth'   => 'key' === ( $profile->settings['auth'] ?? 'password' ) ? 'key' : 'password',
				'folder' => FolderSetting::clean( $profile->settings['folder'] ?? '', 'folder', true ),
			] + $profile->settings;
		} catch ( FieldException $e ) {
			$failure = ServerAddress::storedFailure( $e, $profile->settings['host'] ?? null, self::where( [ 'port' => is_numeric( $profile->settings['port'] ?? null ) ? (int) $profile->settings['port'] : 22 ] + $profile->settings ) );
			throw $failure;
		}
	}

	/**
	 * The address for messages: sftp://host:port, an IPv6 address in brackets, as in a URL.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function where( array $settings ): string {
		$host = (string) ( $settings['host'] ?? '' );

		return 'sftp://' . ( str_contains( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . (int) ( $settings['port'] ?? 22 );
	}
}
