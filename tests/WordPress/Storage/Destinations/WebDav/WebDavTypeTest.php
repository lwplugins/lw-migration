<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavDestination;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavType;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class WebDavTypeTest extends MonkeyTestCase {

	/** @var array<int, string> Names the address check was asked for. */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		AddressGuard::useResolver(
			function ( string $host ): array {
				$this->asked[] = $host;
				return [ '93.184.216.34' ];
			}
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	public function test_the_form_asks_address_folder_server_kind_user_and_password(): void {
		$fields = ( new WebDavType() )->fields();

		$this->assertSame( [ 'url', 'folder', 'mode', 'user', 'password' ], array_map( static fn( $f ): string => $f->key, $fields ) );
		$this->assertSame( [ 'auto', 'nextcloud', 'webdav' ], array_keys( $fields[2]->options ) );
		$this->assertSame( 'auto', $fields[2]->default );
		$this->assertTrue( $fields[4]->isSecret() );
		$this->assertSame( [ true, false, true, true, true ], array_map( static fn( $f ): bool => $f->required, $fields ) );
		$this->assertStringContainsString( 'wp lw-migration upload', $fields[2]->help, 'The one-request risk is said up front (spec 21.4).' );
		$this->assertStringContainsString( 'another name', $fields[2]->help, 'A rebranded Nextcloud is pointed to the Nextcloud choice (Task 4 m6).' );
	}

	public function test_settings_are_normalised(): void {
		$s = ( new WebDavType() )->settings(
			[
				'url'    => ' https://cloud.example/remote.php/dav/files/alice ',
				'folder' => '/Backups//site/',
				'mode'   => 'nextcloud',
				'user'   => ' alice ',
			]
		);

		$this->assertSame(
			[
				'url'    => 'https://cloud.example/remote.php/dav/files/alice',
				'folder' => 'Backups/site',
				'mode'   => 'nextcloud',
				'user'   => 'alice',
			],
			$s
		);
	}

	public function test_refusals_name_their_field(): void {
		$cases = [
			'url'    => [ 'url' => 'ftp://x/', 'user' => 'a' ],
			'user'   => [ 'url' => 'https://dav.example/', 'user' => 'a:b' ],
			'mode'   => [ 'url' => 'https://dav.example/', 'user' => 'a', 'mode' => 'tus' ],
			'folder' => [ 'url' => 'https://dav.example/', 'user' => 'a', 'folder' => '../x' ],
		];
		foreach ( $cases as $field => $raw ) {
			try {
				( new WebDavType() )->settings( $raw );
				$this->fail( $field );
			} catch ( FieldException $e ) {
				$this->assertSame( $field, $e->field );
			}
		}
		try {
			( new WebDavType() )->settings( [ 'url' => 'https://dav.example/webdav/', 'user' => 'a', 'mode' => 'nextcloud' ] );
			$this->fail( 'Not a Nextcloud address.' );
		} catch ( FieldException $e ) {
			$this->assertSame( 'url', $e->field );
		}
	}

	public function test_availability_insecure_and_make(): void {
		$this->assertNull( ( new WebDavType( null, static fn(): bool => true ) )->unavailable() );
		$this->assertNotNull( ( new WebDavType( null, static fn( string $f ): bool => 'curl_init' !== $f ) )->unavailable() );
		$this->assertNotNull( ( new WebDavType( null, static fn( string $f ): bool => 'simplexml_load_string' !== $f ) )->unavailable() );
		$type  = new WebDavType();
		$plain = new Profile( 'abcd1234', 'webdav', 'Box', $type->settings( [ 'url' => 'http://dav.lan/', 'user' => 'a' ] ) );
		$this->assertTrue( $type->insecure( $plain ) );
		$this->assertInstanceOf( WebDavDestination::class, $type->make( $plain, [ 'password' => 'pw' ] ) );
		try {
			$type->make( $plain, [] );
			$this->fail( 'No password.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
		$this->assertCount( 1, ( new \ReflectionMethod( WebDavType::class, 'make' ) )->getParameters()[1]->getAttributes( \SensitiveParameter::class ) );
	}

	/**
	 * Task 4 m5: stored settings that no longer hold are INVALID, like S3Type::make().
	 */
	public function test_make_refuses_stored_settings_that_no_longer_hold(): void {
		$cases = [
			'address'          => [ 'url' => 'ftp://dav.example/', 'user' => 'a', 'mode' => 'auto' ],
			'mode'             => [ 'url' => 'https://dav.example/', 'user' => 'a', 'mode' => 'tus' ],
			'nextcloud elsewhere' => [ 'url' => 'https://dav.example/', 'user' => 'a', 'mode' => 'nextcloud' ],
		];
		foreach ( $cases as $case => $settings ) {
			try {
				( new WebDavType() )->make( new Profile( 'abcd1234', 'webdav', 'Box', $settings ), [ 'password' => 'pw' ] );
				$this->fail( $case );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason, $case );
				$this->assertStringNotContainsString( 'dav.example', $e->getMessage() );
			}
		}
	}

	/**
	 * Task 4 m5: the card and the list never wait for a name lookup.
	 */
	public function test_describe_and_insecure_read_the_stored_address_without_a_lookup(): void {
		$type        = new WebDavType();
		$profile     = new Profile( 'abcd1234', 'webdav', 'Box', $type->settings( [ 'url' => 'https://Cloud.Example:443/remote.php/dav/files/alice/', 'folder' => 'Backups/site', 'user' => 'a' ] ) );
		$plain       = new Profile( 'abcd1234', 'webdav', 'Box', [ 'url' => 'HTTP://[::1]:8080/dav/', 'folder' => '' ] );
		$broken      = new Profile( 'abcd1234', 'webdav', 'Box', [ 'url' => 'ftp://x/' ] );
		$this->asked = [];

		$this->assertSame( 'Backups/site · https://cloud.example', $type->describe( $profile ) );
		$this->assertSame( 'http://[::1]:8080', $type->describe( $plain ) );
		$this->assertSame( '', $type->describe( $broken ) );
		$this->assertSame( [ false, true, false ], [ $type->insecure( $profile ), $type->insecure( $plain ), $type->insecure( $broken ) ] );
		$this->assertSame( [], $this->asked );
	}

	/**
	 * Final review m3: a stored name that now resolves to a refused range
	 * gets the address text, not the form's prompt.
	 */
	public function test_a_stored_name_now_resolving_to_a_refused_address_says_so_at_make(): void {
		$type    = new WebDavType();
		$profile = new Profile( 'abcd1234', 'webdav', 'Box', $type->settings( [ 'url' => 'https://dav.example:8443/dav/', 'user' => 'a' ] ) );
		AddressGuard::useResolver( static fn(): array => [ '169.254.169.254' ] );

		try {
			$type->make( $profile, [ 'password' => 'pw' ] );
			$this->fail( 'A refused address.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, 'The address https://dav.example:8443 points to a network address that is not allowed for storage.' ], [ $e->reason, $e->getMessage() ] );
		}
	}
}
