<?php
/**
 * The WebDAV destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\FolderSetting;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ServerAddress;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 WebDAV. Settings: url, folder, mode, user; secret: password.
 * Unavailable without cURL (the body is streamed through it, plan D22) or
 * SimpleXML (PROPFIND answers). settings() checks the address as it is
 * saved (WebDavAddress, with a lookup); make() checks it again and turns a
 * refusal into INVALID; describe() and insecure() read the stored address
 * without a lookup (Task 4 m5).
 */
final class WebDavType implements DestinationType, AuthorityBound {

	public const KEY = 'webdav';

	private const MODES = [ WebDavDestination::AUTO, WebDavDestination::NEXTCLOUD, WebDavDestination::PLAIN ];

	/**
	 * @param \Closure|null $send Transport for the clients (tests); null = wp_remote_request.
	 * @param \Closure|null $has  fn( string $function ): bool (tests); null = function_exists.
	 */
	public function __construct( private ?\Closure $send = null, private ?\Closure $has = null ) {
	}

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'WebDAV (Nextcloud and others)', 'lw-migration' );
	}

	public function unavailable(): ?string {
		$has = $this->has ?? static fn( string $function ): bool => function_exists( $function );
		if ( ! $has( 'curl_init' ) ) {
			return __( 'This server lacks PHP\'s cURL extension, which WebDAV uploads need.', 'lw-migration' );
		}

		return $has( 'simplexml_load_string' ) ? null : __( 'This server lacks PHP\'s SimpleXML extension, which WebDAV needs.', 'lw-migration' );
	}

	public function fields(): array {
		return [
			new Field( 'url', Field::TEXT, __( 'WebDAV address', 'lw-migration' ), true, __( 'Nextcloud: copy it from Files, Files settings, WebDAV; it looks like https://cloud.example.com/remote.php/dav/files/your-name/. A plain http address sends your backups unencrypted.', 'lw-migration' ) ),
			new Field( 'folder', Field::TEXT, __( 'Folder', 'lw-migration' ), false, __( 'Created when it does not exist, for example Backups/my-site.', 'lw-migration' ) ),
			new Field(
				'mode',
				Field::SELECT,
				__( 'Server', 'lw-migration' ),
				true,
				__( 'Nextcloud gets backups in resumable parts. Detect recognises it by its status page; for a Nextcloud shown under another name, choose Nextcloud. Other WebDAV servers get each backup in one request: over 200 MB that works only from the command line (wp lw-migration upload), because a web request would hit the time limit.', 'lw-migration' ),
				[
					WebDavDestination::AUTO      => __( 'Detect (Nextcloud in parts)', 'lw-migration' ),
					WebDavDestination::NEXTCLOUD => 'Nextcloud',
					WebDavDestination::PLAIN     => __( 'Other WebDAV server', 'lw-migration' ),
				],
				WebDavDestination::AUTO
			),
			new Field( 'user', Field::TEXT, __( 'User name', 'lw-migration' ), true ),
			new Field( 'password', Field::SECRET, __( 'Password', 'lw-migration' ), true, __( 'Use an app password (Nextcloud: Settings, Security, Devices & sessions). Stored encrypted on this site and never shown again. Leave it empty to keep the stored one.', 'lw-migration' ) ),
		];
	}

	public function settings( array $raw ): array {
		$mode = $raw['mode'] ?? WebDavDestination::AUTO;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new FieldException( 'mode', __( 'Choose the kind of server.', 'lw-migration' ) );
		}
		$folder = FolderSetting::clean( $raw['folder'] ?? '', 'folder' );
		$url    = is_string( $raw['url'] ?? null ) ? trim( $raw['url'] ) : '';
		self::address( $url, $folder, $mode );
		$user = is_string( $raw['user'] ?? null ) ? trim( $raw['user'] ) : '';
		if ( '' === $user || strlen( $user ) > 200 || str_contains( $user, ':' ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $user ) ) {
			throw new FieldException( 'user', __( 'Enter the user name (it cannot contain a colon).', 'lw-migration' ) );
		}

		return [
			'url'    => $url,
			'folder' => $folder,
			'mode'   => $mode,
			'user'   => $user,
		];
	}

	public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
		$password = (string) ( $secrets['password'] ?? '' );
		if ( '' === $password ) {
			throw new PermanentFailure( __( 'The password of this destination is missing. Enter it again.', 'lw-migration' ), PermanentFailure::REAUTH );
		}
		$s    = $profile->settings;
		$mode = $s['mode'] ?? WebDavDestination::AUTO;
		try {
			if ( ! in_array( $mode, self::MODES, true ) ) {
				throw new FieldException( 'mode', __( 'Choose the kind of server.', 'lw-migration' ) );
			}
			$address = self::address( (string) ( $s['url'] ?? '' ), (string) ( $s['folder'] ?? '' ), $mode );
		} catch ( FieldException $e ) {
			throw self::storedFailure( $e, (string) ( $s['url'] ?? '' ) );
		}

		return new WebDavDestination( new WebDavClient( (string) ( $s['user'] ?? '' ), $password, $address->display(), $this->send ), $address, $mode );
	}

	public function authorityFields(): array {
		return [ 'password' ];
	}

	/**
	 * Scheme, host and port of the stored address (the path stays free to change).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array{0: string, 1: string, 2: int}
	 */
	public function authority( array $settings ): array {
		$parts  = parse_url( trim( (string) ( $settings['url'] ?? '' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of an address the type already checked.
		$parts  = is_array( $parts ) ? $parts : [];
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		return [ $scheme, strtolower( (string) ( $parts['host'] ?? '' ) ), (int) ( $parts['port'] ?? ( 'http' === $scheme ? 80 : 443 ) ) ];
	}

	public function describe( Profile $profile ): string {
		$folder = (string) ( $profile->settings['folder'] ?? '' );
		try {
			$where = WebDavAddress::shown( (string) ( $profile->settings['url'] ?? '' ) );
		} catch ( FieldException $e ) {
			return '';
		}

		return ( '' === $folder ? '' : $folder . ' · ' ) . $where;
	}

	public function insecure( Profile $profile ): bool {
		$url = $profile->settings['url'] ?? '';

		return is_string( $url ) && str_starts_with( strtolower( trim( $url ) ), 'http://' );
	}

	/**
	 * Final review m3: a stored address whose host now resolves to a refused
	 * range says so (ServerAddress::storedFailure()); any other refusal
	 * keeps its own text.
	 *
	 * @param FieldException $e   The refusal.
	 * @param string         $url The stored WebDAV address.
	 */
	private static function storedFailure( FieldException $e, string $url ): PermanentFailure {
		try {
			$where = WebDavAddress::shown( $url );
		} catch ( FieldException $ignored ) {
			return new PermanentFailure( $e->getMessage(), PermanentFailure::INVALID );
		}

		return ServerAddress::storedFailure( $e, trim( (string) parse_url( $where, PHP_URL_HOST ), '[]' ), $where ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of our own display form.
	}

	/**
	 * @param string $url    The WebDAV address.
	 * @param string $folder The folder.
	 * @param string $mode   One of MODES.
	 * @throws FieldException 'url' or 'folder' when the address does not hold; 'url' for the Nextcloud mode on another address.
	 */
	private static function address( string $url, string $folder, string $mode ): WebDavAddress {
		$address = WebDavAddress::fromSettings( $url, $folder );
		if ( WebDavDestination::NEXTCLOUD === $mode && ! $address->isNextcloud() ) {
			throw new FieldException( 'url', __( 'A Nextcloud address looks like https://cloud.example.com/remote.php/dav/files/your-name/.', 'lw-migration' ) );
		}

		return $address;
	}
}
