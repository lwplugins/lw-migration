<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpSession;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpType;
use LightweightPlugins\Migration\Tests\Support\FakeSftp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SftpTypeTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const KEY = 'SHA256:4U+iyVJ67eRjXO5juHU2wtdIAKv3vfEN0q53GZZr/a4';

	/** @var array<string, array<int, string>> What the names resolve to now. */
	private array $dns = [];

	/** @var array<int, array<int, mixed>> host, port, where of every session opened. */
	private array $opened = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dns = [
			'h'                => [ '93.184.216.34' ],
			'sftp.example.com' => [ '93.184.216.34' ],
		];
		AddressGuard::useResolver( fn( string $host ): array => $this->dns[ $host ] ?? [] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/** A type whose sessions go to $server, recording what each was opened with. */
	private function recording( FakeSftp $server ): SftpType {
		return new SftpType(
			function ( string $host, int $port, int $timeout, string $where ) use ( $server ): SftpSession {
				$this->opened[] = [ $host, $port, $where ];
				return $server;
			}
		);
	}

	public function test_the_form_shows_the_password_or_the_key_by_the_login_kind(): void {
		$fields = [];
		foreach ( ( new SftpType() )->fields() as $field ) {
			$fields[ $field->key ] = $field;
		}

		$this->assertSame( [ 'host', 'port', 'user', 'auth', 'password', 'privateKey', 'passphrase', 'folder', 'hostKey' ], array_keys( $fields ) );
		$this->assertTrue( $fields['password']->visible( [ 'auth' => 'password' ] ) );
		$this->assertFalse( $fields['privateKey']->visible( [ 'auth' => 'password' ] ) );
		$this->assertTrue( $fields['privateKey']->visible( [ 'auth' => 'key' ] ) );
		$this->assertFalse( $fields['password']->visible( [ 'auth' => 'key' ] ) );
		$this->assertSame( Field::SECRET_TEXT, $fields['privateKey']->kind );
		$this->assertSame( Field::SECRET, $fields['passphrase']->kind );
		$this->assertTrue( $fields['privateKey']->required );
		$this->assertFalse( $fields['passphrase']->required );
		$this->assertFalse( $fields['hostKey']->isSecret() );
		$this->assertNull( ( new SftpType() )->unavailable() );
	}

	public function test_settings_are_normalised_and_checked(): void {
		$s = ( new SftpType() )->settings( [ 'host' => ' sftp.example.com ', 'user' => 'me', 'folder' => '/var//backups/', 'hostKey' => ' ' . self::KEY . ' ' ] );

		$this->assertSame(
			[
				'host'    => 'sftp.example.com',
				'port'    => 22,
				'user'    => 'me',
				'auth'    => 'password',
				'folder'  => '/var/backups',
				'hostKey' => self::KEY,
			],
			$s
		);
		$bad = [
			'hostKey' => [ 'hostKey' => 'MD5:ab' ],
			'auth'    => [ 'auth' => 'agent' ],
			'port'    => [ 'port' => '0' ],
			'host'    => [ 'host' => 'sftp://x' ],
			'user'    => [ 'user' => "me\n" . 'x' ],
			'folder'  => [ 'folder' => '../etc' ],
		];
		foreach ( $bad as $field => $raw ) {
			try {
				( new SftpType() )->settings( array_merge( [ 'host' => 'h', 'user' => 'me' ], $raw ) );
				$this->fail( $field );
			} catch ( FieldException $e ) {
				$this->assertSame( $field, $e->field );
			}
		}
	}

	/**
	 * P-C2 / H5: the shared save-time address rule — no disguised address,
	 * no refused range.
	 */
	public function test_a_disguised_or_refused_address_is_refused_at_save(): void {
		$this->dns['meta.example.com'] = [ '169.254.169.254' ];

		foreach ( [ '2130706433', '0x7f.0.0.1', '169.254.169.254', 'meta.example.com' ] as $host ) {
			try {
				( new SftpType() )->settings( [ 'host' => $host, 'user' => 'me' ] );
				$this->fail( $host );
			} catch ( FieldException $e ) {
				$this->assertSame( 'host', $e->field );
				$this->assertStringNotContainsString( $host, $e->getMessage() );
			}
		}
	}

	/**
	 * P-M8: the confirmed key's type stays with the key; without a key there is no type.
	 */
	public function test_the_key_type_is_kept_only_with_a_key_and_only_in_its_shape(): void {
		$type = new SftpType();

		$this->assertSame( 'ecdsa-sha2-nistp256', $type->settings( [ 'host' => 'h', 'user' => 'me', 'hostKey' => self::KEY, 'hostKeyType' => 'ecdsa-sha2-nistp256' ] )['hostKeyType'] );
		$this->assertArrayNotHasKey( 'hostKeyType', $type->settings( [ 'host' => 'h', 'user' => 'me', 'hostKeyType' => 'ssh-ed25519' ] ) );
		$this->assertArrayNotHasKey( 'hostKeyType', $type->settings( [ 'host' => 'h', 'user' => 'me', 'hostKey' => self::KEY, 'hostKeyType' => 'ssh ed25519' ] ) );
	}

	public function test_a_missing_secret_of_the_chosen_login_needs_new_credentials(): void {
		$type = $this->recording( new FakeSftp() );

		foreach ( [ [ 'password', [] ], [ 'key', [ 'password' => 'pw' ] ] ] as [ $auth, $secrets ] ) {
			try {
				$type->make( new Profile( 'abcd1234', 'sftp', 'S', $type->settings( [ 'host' => 'h', 'user' => 'me', 'auth' => $auth ] ) ), $secrets );
				$this->fail( $auth );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::REAUTH, $e->reason );
			}
		}
		$this->assertSame( [], $this->opened );
	}

	public function test_a_made_destination_uploads_through_a_checked_login_to_the_checked_address(): void {
		$server = new FakeSftp();
		$type   = $this->recording( $server );
		$dest   = $type->make( new Profile( 'abcd1234', 'sftp', 'S', $type->settings( [ 'host' => 'h', 'user' => 'me', 'folder' => 'backups', 'hostKey' => self::KEY ] ) ), [ 'password' => 'pw' ] );

		$s = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 ), 'abc' );
		$dest->finishUpload( $s );

		$this->assertSame( 'abc', $server->files[ 'backups/' . self::NAME ] );
		$this->assertSame( [ [ '93.184.216.34', 22, 'sftp://h:22' ] ], array_values( array_unique( $this->opened, SORT_REGULAR ) ), 'Never the name.' );
		$this->assertTrue( $server->closed );
		$this->assertFalse( $type->insecure( new Profile( 'abcd1234', 'sftp', 'S', [] ) ) );
		$this->assertSame( 'backups · sftp://h:22', $type->describe( new Profile( 'abcd1234', 'sftp', 'S', $type->settings( [ 'host' => 'h', 'user' => 'me', 'folder' => 'backups' ] ) ) ) );
	}

	public function test_an_ipv6_server_is_reached_by_its_address_and_shown_in_brackets(): void {
		$type    = $this->recording( new FakeSftp() );
		$profile = new Profile( 'abcd1234', 'sftp', 'S', $type->settings( [ 'host' => '[fd00::5]', 'port' => '2222', 'user' => 'me', 'hostKey' => self::KEY ] ) );

		$type->make( $profile, [ 'password' => 'pw' ] )->list();

		$this->assertSame( [ 'fd00::5', 2222, 'sftp://[fd00::5]:2222' ], $this->opened[0] );
		$this->assertSame( 'sftp://[fd00::5]:2222', $type->describe( $profile ) );
	}

	public function test_broken_stored_settings_stop_before_any_connection(): void {
		$type = $this->recording( new FakeSftp() );

		try {
			$type->make( new Profile( 'abcd1234', 'sftp', 'S', [ 'host' => '0x7f.0.0.1', 'user' => 'me' ] ), [ 'password' => 'pw' ] )->list();
			$this->fail( 'A stored host the rules refuse.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $this->opened );
	}

	/**
	 * Final review m3 (the same rule as FTP and WebDAV): a stored name that
	 * now resolves to a refused range gets the address text, not the form's prompt.
	 */
	public function test_a_stored_name_now_resolving_to_a_refused_address_says_so_at_make(): void {
		$type    = $this->recording( new FakeSftp() );
		$profile = new Profile( 'abcd1234', 'sftp', 'S', $type->settings( [ 'host' => 'h', 'user' => 'me' ] ) );

		$this->dns['h'] = [ '169.254.169.254' ];
		try {
			$type->make( $profile, [ 'password' => 'pw' ] );
			$this->fail( 'A refused address.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, 'The address sftp://h:22 points to a network address that is not allowed for storage.' ], [ $e->reason, $e->getMessage() ] );
		}
		$this->assertSame( [], $this->opened );
	}
}
