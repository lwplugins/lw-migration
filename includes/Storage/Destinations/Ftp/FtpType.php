<?php
/**
 * The FTP/FTPS destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
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
 * Spec 21.4 FTP/FTPS. Settings: host, port, security (ftps | ftp), passive,
 * user, folder, certFingerprint (FTPS: filled in when the admin confirms a
 * certificate no certificate authority vouches for, issue #5); secret:
 * password. Plain FTP goes through ext-ftp (ExtFtpConnection), FTPS through
 * cURL with the certificate checked or pinned (CurlFtpsConnection); each is
 * refused at save when this PHP cannot do it, and the type is unavailable
 * when it can do neither (spec 21.2, plan D39). The host and port follow
 * ServerAddress (H5) when saved; before every connection the host is
 * resolved and checked again, and the connection is opened to that checked
 * address (P-C2). Plain FTP is insecure. A moved server forgets the password
 * and the confirmed certificate.
 */
final class FtpType implements DestinationType, AuthorityBound, ConfirmedFingerprint {

	public const KEY = 'ftp';

	/**
	 * @param \Closure|null $open fn( array $at, string $password, string $where ): FtpConnection (tests); null = CurlFtpsConnection::open() for FTPS, ExtFtpConnection::open() for FTP. $at['host'] is the checked address, $at['name'] the stored host.
	 * @param \Closure|null $has  fn( string $what ): bool (tests): 'ftp_connect' (ext-ftp) or 'ftps' (CurlFtpsConnection::supported()); null = this PHP.
	 */
	public function __construct( private ?\Closure $open = null, private ?\Closure $has = null ) {
	}

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'FTP or FTPS', 'lw-migration' );
	}

	public function unavailable(): ?string {
		return $this->has( 'ftp_connect' ) || $this->has( 'ftps' ) ? null : $this->unavailableFtp();
	}

	public function fields(): array {
		return [
			new Field( 'host', Field::TEXT, __( 'Server', 'lw-migration' ), true, __( 'Host name or IP address, for example ftp.example.com.', 'lw-migration' ) ),
			new Field( 'port', Field::NUMBER, __( 'Port', 'lw-migration' ), false, '', [], 21 ),
			new Field(
				'security',
				Field::SELECT,
				__( 'Encryption', 'lw-migration' ),
				true,
				__( 'FTPS encrypts the connection and checks the server\'s certificate. A certificate no certificate authority vouches for (a self-signed one, say) is shown at the connection check for you to confirm. Plain FTP sends the password and the backups unencrypted.', 'lw-migration' ),
				[
					'ftps' => __( 'FTPS (explicit TLS)', 'lw-migration' ),
					'ftp'  => __( 'None (plain FTP)', 'lw-migration' ),
				],
				'ftps'
			),
			new Field( 'passive', Field::TOGGLE, __( 'Passive mode', 'lw-migration' ), false, __( 'Leave it on unless your server asks for active mode.', 'lw-migration' ), [], true ),
			new Field( 'user', Field::TEXT, __( 'User name', 'lw-migration' ), true ),
			new Field( 'password', Field::SECRET, __( 'Password', 'lw-migration' ), true, __( 'Stored encrypted on this site and never shown again. Leave it empty to keep the stored one.', 'lw-migration' ) ),
			new Field( 'folder', Field::TEXT, __( 'Folder', 'lw-migration' ), false, __( 'Relative to the login folder, or from the server\'s root when it starts with /. Created when it does not exist.', 'lw-migration' ) ),
			new Field( 'certFingerprint', Field::TEXT, __( 'Certificate fingerprint', 'lw-migration' ), false, __( 'Only for a certificate no certificate authority vouches for: filled in when you confirm it at the connection check. Compare it on the server with openssl x509 -noout -fingerprint -sha256 -in and the certificate file.', 'lw-migration' ), [], null, [ 'security' => [ 'ftps' ] ] ),
		];
	}

	public function settings( array $raw ): array {
		$host     = ServerAddress::host( $raw['host'] ?? null, 'host', __( 'Enter the server\'s host name or IP address (without ftp://).', 'lw-migration' ) );
		$port     = ServerAddress::port( $raw['port'] ?? null, 21, 'port' );
		$security = $raw['security'] ?? 'ftps';
		if ( ! in_array( $security, [ 'ftps', 'ftp' ], true ) ) {
			throw new FieldException( 'security', __( 'Choose FTPS or plain FTP.', 'lw-migration' ) );
		}
		if ( 'ftps' === $security && ! $this->has( 'ftps' ) ) {
			throw new FieldException( 'security', CurlFtpsConnection::unsupported() );
		}
		if ( 'ftp' === $security && ! $this->has( 'ftp_connect' ) ) {
			throw new FieldException( 'security', $this->unavailableFtp() );
		}
		$pin = is_string( $raw['certFingerprint'] ?? null ) ? trim( $raw['certFingerprint'] ) : '';
		if ( '' !== $pin ) {
			$pin = (string) CertificatePin::normalize( $pin );
			if ( '' === $pin ) {
				throw new FieldException( 'certFingerprint', __( 'A certificate fingerprint is 32 pairs of hexadecimal digits joined by colons (SHA-256). Leave it empty to confirm it at the connection check.', 'lw-migration' ) );
			}
		}
		$passive = $raw['passive'] ?? true;
		if ( ! is_bool( $passive ) ) {
			throw new FieldException( 'passive', __( 'Passive mode is on or off.', 'lw-migration' ) );
		}
		$user = is_string( $raw['user'] ?? null ) ? trim( $raw['user'] ) : '';
		if ( '' === $user || strlen( $user ) > 200 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $user ) ) {
			throw new FieldException( 'user', __( 'Enter the user name.', 'lw-migration' ) );
		}

		return [
			'host'     => $host,
			'port'     => $port,
			'security' => $security,
			'passive'  => $passive,
			'user'     => $user,
			'folder'   => FolderSetting::clean( $raw['folder'] ?? '', 'folder', true ),
		] + ( 'ftps' === $security ? [ 'certFingerprint' => $pin ] : [] );
	}

	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
		$password = (string) ( $secrets['password'] ?? '' );
		if ( '' === $password ) {
			throw new PermanentFailure( __( 'The password of this destination is missing. Enter it again.', 'lw-migration' ), PermanentFailure::REAUTH );
		}
		$at    = self::at( $profile );
		$where = ( $at['tls'] ? 'ftps://' : 'ftp://' ) . self::bracketed( $at['host'] ) . ':' . $at['port'];
		$open  = $this->open ?? static fn( array $at, #[\SensitiveParameter] string $password, string $where ): FtpConnection => $at['tls'] ? CurlFtpsConnection::open( $at, $password, $where, CloudTimeout::seconds() ) : ExtFtpConnection::open( $at, $password, $where, CloudTimeout::seconds() );

		return new FolderDestination(
			self::KEY,
			$where,
			static function () use ( $open, $at, $password, $where ): RemoteFolder {
				$at['host'] = ServerAddress::connectTo( $at['host'], $where, null ); // Checked again before every connection.
				return FtpFolder::open( $open( $at, $password, $where ), $where );
			}
		);
	}

	public function describe( Profile $profile ): string {
		$s      = $profile->settings;
		$folder = (string) ( $s['folder'] ?? '' );

		return ( '' === $folder ? '' : $folder . ' · ' ) . ( 'ftp' === ( $s['security'] ?? 'ftps' ) ? 'ftp://' : 'ftps://' ) . self::bracketed( (string) ( $s['host'] ?? '' ) );
	}

	public function insecure( Profile $profile ): bool {
		return 'ftp' === ( $profile->settings['security'] ?? 'ftps' );
	}

	public function authority( array $settings ): array {
		return [ strtolower( trim( (string) ( $settings['host'] ?? '' ) ) ), (int) ( $settings['port'] ?? 21 ), (string) ( $settings['security'] ?? 'ftps' ) ];
	}

	public function authorityFields(): array {
		return [ 'password', 'certFingerprint' ];
	}

	public function fingerprintField(): string {
		return 'certFingerprint';
	}

	public function isFingerprint( string $value ): bool {
		return CertificatePin::isFingerprint( $value );
	}

	/**
	 * The stored settings as a connection needs them, checked again.
	 *
	 * @param Profile $profile The profile.
	 * @return array{host: string, name: string, port: int, tls: bool, passive: bool, user: string, folder: string, pin: string} host and name: the stored host (make() replaces host with the checked address).
	 * @throws PermanentFailure INVALID when the stored settings no longer hold (a host now in a refused range says so).
	 */
	private static function at( Profile $profile ): array {
		$s = $profile->settings;
		try {
			$host = ServerAddress::host( $s['host'] ?? null, 'host' );
			$tls  = 'ftp' !== ( $s['security'] ?? 'ftps' );

			return [
				'host'    => $host,
				'name'    => $host,
				'port'    => ServerAddress::port( $s['port'] ?? null, 21, 'port' ),
				'tls'     => $tls,
				'passive' => false !== ( $s['passive'] ?? true ),
				'user'    => (string) ( $s['user'] ?? '' ),
				'folder'  => FolderSetting::clean( $s['folder'] ?? '', 'folder', true ),
				'pin'     => $tls ? (string) CertificatePin::normalize( (string) ( $s['certFingerprint'] ?? '' ) ) : '',
			];
		} catch ( FieldException $e ) {
			$port    = is_numeric( $s['port'] ?? null ) ? (int) $s['port'] : 21;
			$failure = ServerAddress::storedFailure( $e, $s['host'] ?? null, ( 'ftp' !== ( $s['security'] ?? 'ftps' ) ? 'ftps://' : 'ftp://' ) . self::bracketed( strtolower( trim( (string) ( $s['host'] ?? '' ) ) ) ) . ':' . $port );
			throw $failure;
		}
	}

	/**
	 * An IPv6 address in brackets, as in a URL.
	 *
	 * @param string $host Host as stored.
	 */
	private static function bracketed( string $host ): string {
		return str_contains( $host, ':' ) ? '[' . $host . ']' : $host;
	}

	private function unavailableFtp(): string {
		return __( 'This server\'s PHP has no FTP extension (ext-ftp), so FTP destinations cannot be used here. Ask your host to enable it, or choose SFTP.', 'lw-migration' );
	}

	/**
	 * @param string $what 'ftp_connect' (ext-ftp) or 'ftps' (checked FTPS through cURL).
	 */
	private function has( string $what ): bool {
		if ( null !== $this->has ) {
			return (bool) ( $this->has )( $what );
		}

		return 'ftps' === $what ? CurlFtpsConnection::supported() : function_exists( $what );
	}
}
