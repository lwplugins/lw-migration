<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\DestinationCommand;
use LightweightPlugins\Migration\CLI\DestinationFlags;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\ConnectLinks;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\OAuthApi;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\CertificatePin;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpConnection;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ConnectTicket;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavType;
use LightweightPlugins\Migration\Tests\Support\FakeFtp;
use LightweightPlugins\Migration\Tests\Support\FakeKeyedType;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DestinationCommandTest extends MonkeyTestCase {

	private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	private const PAGE = 'https://www.site-one.com/wp-admin/admin.php?page=lw-migration';

	/** @var array<int, array<string, mixed>> */
	private array $listed = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		\WP_CLI::$errors = [];
		\WP_CLI::$lines  = [];
		\WP_CLI::$config = [];
		FakeOptions::install();
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		FakeKeyedType::reset();
		Functions\when( 'WP_CLI\Utils\format_items' )->alias(
			function ( string $format, array $items, array $fields ): void {
				$this->listed = $items;
			}
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private static function api(): DestinationsApi {
		return new DestinationsApi( new Destinations( new ProfileRepository(), new SecretVault(), new DestinationRegistry( [ new S3Type() ] ) ), static fn(): array => [] );
	}

	/**
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @param array<int, string>   $stdin STDIN lines.
	 */
	private function cli( array $args, array $assoc, array $stdin = [] ): ?string {
		$command = new DestinationCommand(
			static function () use ( &$stdin ): ?string {
				return array_shift( $stdin );
			},
			self::api(),
			static fn(): bool => false
		);
		$method  = 'list' === $args[0] ? 'listing' : $args[0]; // WP-CLI maps "list" to listing() through @subcommand.
		try {
			$command->$method( array_slice( $args, 1 ), $assoc );
		} catch ( \RuntimeException $e ) {
			return \WP_CLI::$errors[0] ?? $e->getMessage();
		}
		return null;
	}

	private const SETTINGS = [
		'name'       => 'Local MinIO',
		'preset'     => 'custom',
		'endpoint'   => 'http://127.0.0.1:9000',
		'bucket'     => 'bkt',
		'access-key' => 'minioadmin',
	];

	public function test_add_reads_the_secret_from_stdin_and_never_prints_it(): void {
		$this->assertNull( $this->cli( [ 'add', 's3' ], self::SETTINGS, [ self::SECRET . "\n" ] ) );

		$this->assertCount( 1, ( new ProfileRepository() )->all() );
		$id = ( new ProfileRepository() )->all()[0]->id;
		$this->assertSame( [ 'secretKey' => self::SECRET ], ( new SecretVault() )->open( $id ) );
		$this->assertStringNotContainsString( self::SECRET, implode( "\n", \WP_CLI::$lines ) );
		$this->assertSame( true, ( new ProfileRepository() )->all()[0]->settings['pathStyle'] );
	}

	/**
	 * Review Focus 3: a secret on the command line lands in the shell history.
	 */
	public function test_a_secret_given_as_a_flag_is_refused(): void {
		$error = $this->cli( [ 'add', 's3' ], self::SETTINGS + [ 'secret-key' => self::SECRET ] );

		$this->assertNotNull( $error );
		$this->assertStringContainsString( '--secret-key', $error );
		$this->assertStringNotContainsString( self::SECRET, $error );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	public function test_an_unknown_flag_and_a_missing_secret_are_refused(): void {
		$this->assertStringContainsString( '--colour', (string) $this->cli( [ 'add', 's3' ], self::SETTINGS + [ 'colour' => 'blue' ] ) );
		\WP_CLI::$errors = [];
		$this->assertNotNull( $this->cli( [ 'add', 's3' ], self::SETTINGS, [] ) );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	public function test_list_shows_no_secret(): void {
		$this->cli( [ 'add', 's3' ], self::SETTINGS, [ self::SECRET . "\n" ] );
		$this->cli( [ 'list' ], [ 'format' => 'json' ] );

		$this->assertSame( [ 'id', 'name', 'type', 'where', 'status', 'last_upload' ], array_keys( $this->listed[0] ) );
		$this->assertStringNotContainsString( self::SECRET, (string) json_encode( $this->listed ) );
	}

	public function test_a_secret_flag_is_refused_before_stdin_is_read(): void {
		$read    = false;
		$command = new DestinationCommand(
			static function () use ( &$read ): ?string {
				$read = true;
				return self::SECRET . "\n";
			},
			self::api(),
			static fn(): bool => false
		);
		try {
			$command->add( [ 's3' ], self::SETTINGS + [ 'secret-key' => self::SECRET ] );
			$this->fail( 'A secret flag is refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertFalse( $read );
		}
	}

	public function test_remove_needs_yes_without_a_terminal_and_then_removes(): void {
		$this->cli( [ 'add', 's3' ], self::SETTINGS, [ self::SECRET . "\n" ] );
		$id = ( new ProfileRepository() )->all()[0]->id;

		$this->assertNotNull( $this->cli( [ 'remove', $id ], [] ) );
		$this->assertCount( 1, ( new ProfileRepository() )->all() );

		$this->assertNull( $this->cli( [ 'remove', $id ], [ 'yes' => true ] ) );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
		$this->assertSame( [], ( new SecretVault() )->open( $id ) );
	}

	public function test_a_malformed_or_unknown_id_is_refused(): void {
		$this->assertSame( 'This destination does not exist (any more).', $this->cli( [ 'test', '../x' ], [] ) );
		\WP_CLI::$errors = [];
		$this->assertSame( 'This destination does not exist (any more).', $this->cli( [ 'remove', 'deadbeef' ], [ 'yes' => true ] ) );
	}

	/**
	 * The global --prompt never reaches $assoc and WP-CLI logs the typed command line: refuse it.
	 */
	public function test_the_global_prompt_is_refused_for_add_only(): void {
		\WP_CLI::$config['prompt'] = true;
		try {
			DestinationCommand::refusePrompt( 'destination add' );
			$this->fail( 'The global --prompt is refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '--prompt', \WP_CLI::$errors[0] );
		}
		\WP_CLI::$errors = [];
		\WP_CLI::$config['prompt'] = null;
		DestinationCommand::refusePrompt( 'destination add' );
		$this->assertSame( [], \WP_CLI::$errors );
	}

	public function test_a_toggle_that_is_not_a_boolean_is_refused_naming_the_field(): void {
		$error = (string) $this->cli( [ 'add', 's3' ], self::SETTINGS + [ 'path-style' => 'false' ], [ self::SECRET . "\n" ] );

		$this->assertStringContainsString( 'path-style', $error );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	public function test_a_real_no_path_style_flag_is_stored_false(): void {
		$this->assertNull( $this->cli( [ 'add', 's3' ], self::SETTINGS + [ 'path-style' => false ], [ self::SECRET . "\n" ] ) );

		$this->assertSame( false, ( new ProfileRepository() )->all()[0]->settings['pathStyle'] );
	}

	public function test_a_text_flag_without_a_value_is_refused(): void {
		$error = (string) $this->cli( [ 'add', 's3' ], [ 'bucket' => true ] + self::SETTINGS, [ self::SECRET . "\n" ] );

		$this->assertStringContainsString( '--bucket', $error );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	public function test_kebab_names_follow_the_schema_keys(): void {
		$this->assertSame( [ 'access-key', 'path-style', 'account-id', 'bucket' ], array_map( [ DestinationFlags::class, 'kebab' ], [ 'accessKey', 'pathStyle', 'accountId', 'bucket' ] ) );
	}

	/**
	 * WP-CLI takes --user and --url as its own global parameters (the WordPress
	 * user to run as, the site): a field with such a key gets another flag,
	 * or its value would never reach the command.
	 */
	public function test_fields_named_like_a_wp_cli_global_parameter_get_their_own_flag(): void {
		$this->assertSame( [ 'username', 'address', 'host' ], array_map( [ DestinationFlags::class, 'kebab' ], [ 'user', 'url', 'host' ] ) );

		$registry = new DestinationRegistry( [ new FtpType(), new WebDavType() ] );
		$command  = new DestinationCommand(
			static fn(): ?string => "pw\n",
			new DestinationsApi( new Destinations( new ProfileRepository(), new SecretVault(), $registry ), static fn(): array => [] ),
			static fn(): bool => false,
			null,
			$registry
		);
		$command->add( [ 'ftp' ], [ 'name' => 'Box', 'host' => '127.0.0.1', 'username' => 'me', 'security' => 'ftps' ] );
		$command->add( [ 'webdav' ], [ 'name' => 'Dav', 'address' => 'https://127.0.0.1/dav/', 'username' => 'you', 'mode' => 'webdav' ] );

		$this->assertSame( [], \WP_CLI::$errors );
		$saved = ( new ProfileRepository() )->all();
		$this->assertSame( 'me', $saved[0]->settings['user'] );
		$this->assertSame( 'you', $saved[1]->settings['user'] );
		$this->assertStringStartsWith( 'https://127.0.0.1/dav', $saved[1]->settings['url'] );
	}

	private const SHOWN = 'SHA256:4U+iyVJ67eRjXO5juHU2wtdIAKv3vfEN0q53GZZr/a4';

	/**
	 * @param array<int, string>   $args   Positional arguments.
	 * @param array<string, mixed> $assoc  Flags.
	 * @param array<int, string>   $stdin  STDIN lines.
	 * @param array<int, string>   $asked  Questions asked (out).
	 * @param string|null          $answer Answer to a question (null: no terminal).
	 */
	private function keyedCli( array $args, array $assoc, array $stdin = [], array &$asked = [], ?string $answer = null ): ?string {
		$registry = FakeKeyedType::registry();
		$command  = new DestinationCommand(
			static function () use ( &$stdin ): ?string {
				return array_shift( $stdin );
			},
			new DestinationsApi( new Destinations( new ProfileRepository(), new SecretVault(), $registry ), static fn(): array => [] ),
			static fn(): bool => null !== $answer,
			static function ( string $question ) use ( &$asked, $answer ): ?string {
				$asked[] = $question;
				return $answer;
			},
			$registry
		);
		try {
			$command->{$args[0]}( array_slice( $args, 1 ), $assoc );
		} catch ( \RuntimeException $e ) {
			return \WP_CLI::$errors[0] ?? $e->getMessage();
		}
		return null;
	}

	private static function unknownKey(): PermanentFailure {
		return new PermanentFailure( 'Shows ' . self::SHOWN . '.', PermanentFailure::HOST_KEY_UNKNOWN, 0, null, [ 'hostKey' => self::SHOWN, 'hostKeyType' => 'ssh-ed25519' ] );
	}

	private function addedKeyed(): string {
		$this->assertNull( $this->keyedCli( [ 'add', 'keyed' ], [ 'name' => 'Box', 'host' => 'example.com' ], [ "pw\n" ] ) );
		\WP_CLI::$errors = [];

		return ( new ProfileRepository() )->all()[0]->id;
	}

	public function test_add_reads_a_key_from_its_file_and_asks_only_the_shown_secrets(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/id', "KEY-LINE-1\nKEY-LINE-2\n" );

		$error = $this->keyedCli( [ 'add', 'keyed' ], [ 'name' => 'Box', 'host' => 'example.com', 'auth' => 'key', 'private-key-file' => $dir . '/id' ], [ "the-passphrase\n" ] );

		TempDir::remove( $dir );
		$this->assertNull( $error );
		$id = ( new ProfileRepository() )->all()[0]->id;
		$this->assertSame(
			[
				'privateKey' => "KEY-LINE-1\nKEY-LINE-2",
				'passphrase' => 'the-passphrase',
			],
			( new SecretVault() )->open( $id ),
			'The hidden password field was not read; the one STDIN line went to the passphrase.'
		);
		$this->assertStringNotContainsString( 'KEY-LINE', implode( "\n", \WP_CLI::$lines ) );
		$this->assertStringNotContainsString( $dir, implode( "\n", \WP_CLI::$lines ) );
	}

	public function test_a_multi_line_secret_is_never_a_flag_value(): void {
		$error = $this->keyedCli( [ 'add', 'keyed' ], [ 'name' => 'Box', 'host' => 'example.com', 'auth' => 'key', 'private-key' => 'KEY' ] );

		$this->assertStringContainsString( '--private-key-file', (string) $error );
		$this->assertStringNotContainsString( 'KEY ', (string) $error );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	/**
	 * P-M5: a refused multi-line secret names the flag it is given with.
	 */
	public function test_a_refused_key_file_names_its_file_flag(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/id', "KEY\0LINE" );

		$error = $this->keyedCli( [ 'add', 'keyed' ], [ 'name' => 'Box', 'host' => 'example.com', 'auth' => 'key', 'private-key-file' => $dir . '/id' ], [ "\n" ] );

		TempDir::remove( $dir );
		$this->assertStringContainsString( '(--private-key-file)', (string) $error );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	/**
	 * Review Focus 3 (CLI side): without a terminal, a host key is trusted only with --trust-host-key naming it.
	 */
	public function test_test_without_a_terminal_names_the_fingerprint_and_trusts_only_the_given_one(): void {
		$id = $this->addedKeyed();

		FakeKeyedType::remote( $id )->failNext( 'test', self::unknownKey() );
		$error = $this->keyedCli( [ 'test', $id ], [] );
		$this->assertStringContainsString( '--trust-host-key=' . self::SHOWN, (string) $error );
		$this->assertStringContainsString( 'ssh_host_ed25519_key.pub', (string) $error );
		$this->assertArrayNotHasKey( 'hostKey', ( new ProfileRepository() )->find( $id )->settings );

		FakeKeyedType::remote( $id )->failNext( 'test', new PermanentFailure( 'Shows ' . self::SHOWN . '.', PermanentFailure::HOST_KEY_UNKNOWN, 0, null, [ 'hostKey' => self::SHOWN, 'hostKeyType' => 'ecdsa-sha2-nistp256' ] ) );
		\WP_CLI::$errors = [];
		$this->assertStringContainsString( 'ssh-keygen -lf /etc/ssh/ssh_host_ecdsa_key.pub', (string) $this->keyedCli( [ 'test', $id ], [] ), 'The key file of the type shown.' );

		FakeKeyedType::remote( $id )->failNext( 'test', self::unknownKey() );
		FakeKeyedType::remote( $id )->failNext( 'test', self::unknownKey() );
		\WP_CLI::$errors = [];
		$this->assertNotNull( $this->keyedCli( [ 'test', $id ], [ 'trust-host-key' => 'SHA256:' . str_repeat( 'B', 43 ) ] ), 'Another fingerprint than the one shown.' );
		$this->assertArrayNotHasKey( 'hostKey', ( new ProfileRepository() )->find( $id )->settings );

		FakeKeyedType::remote( $id )->failNext( 'test', self::unknownKey() ); // test()
		FakeKeyedType::remote( $id )->failNext( 'test', self::unknownKey() ); // trust() looks again
		\WP_CLI::$errors = [];
		$this->assertNull( $this->keyedCli( [ 'test', $id ], [ 'trust-host-key' => self::SHOWN ] ) );
		$this->assertSame( self::SHOWN, ( new ProfileRepository() )->find( $id )->settings['hostKey'] );
	}

	public function test_on_a_terminal_the_fingerprint_is_asked_about(): void {
		$id = $this->addedKeyed(); // Added without a terminal; only the test runs on one.
		for ( $i = 0; $i < 3; $i++ ) {
			FakeKeyedType::remote( $id )->failNext( 'test', self::unknownKey() );
		}
		$asked = [];

		$this->assertNotNull( $this->keyedCli( [ 'test', $id ], [], [], $asked, 'n' ) );
		$this->assertArrayNotHasKey( 'hostKey', ( new ProfileRepository() )->find( $id )->settings );
		\WP_CLI::$errors = [];
		$this->assertNull( $this->keyedCli( [ 'test', $id ], [], [], $asked, 'y' ) );

		$this->assertCount( 2, $asked );
		$this->assertStringContainsString( self::SHOWN, $asked[1] );
		$this->assertSame( self::SHOWN, ( new ProfileRepository() )->find( $id )->settings['hostKey'] );
	}

	/**
	 * Issue #5: an FTPS certificate no certificate authority vouches for is
	 * confirmed like a host key, with its own flag and the openssl command.
	 */
	public function test_an_ftps_certificate_is_trusted_with_its_own_flag(): void {
		$pin = '14:B2:20:50:8B:BB:3C:51:E0:6E:5C:20:59:B9:F0:51:AE:12:34:9E:EF:9E:B3:8F:F9:5F:B1:44:73:0D:F5:90';
		AddressGuard::useResolver( static fn( string $host ): array => [ '93.184.216.34' ] );
		$ftp      = new FakeFtp();
		$type     = new FtpType(
			static function ( array $at, string $password, string $where ) use ( $ftp, $pin ): FtpConnection {
				CertificatePin::check( $at['pin'], $pin, $where );
				return $ftp;
			},
			static fn( string $what ): bool => true
		);
		$registry = new DestinationRegistry( [ $type ] );
		$api      = new DestinationsApi( new Destinations( new ProfileRepository(), new SecretVault(), $registry ), static fn(): array => [] );
		( new ProfileRepository() )->save( new Profile( 'aaaa1111', FtpType::KEY, 'Box', $type->settings( [ 'host' => 'ftp.example.com', 'user' => 'u' ] ) ) );
		( new SecretVault() )->put( 'aaaa1111', [ 'password' => 'pw' ] );
		$asked   = [];
		$command = new DestinationCommand(
			static fn(): ?string => null,
			$api,
			static fn(): bool => false,
			static function ( string $question ) use ( &$asked ): ?string {
				$asked[] = $question;
				return null;
			},
			$registry
		);
		$run     = static function ( array $assoc ) use ( $command ): ?string {
			\WP_CLI::$errors = [];
			try {
				$command->test( [ 'aaaa1111' ], $assoc );
			} catch ( \RuntimeException $e ) {
				return \WP_CLI::$errors[0] ?? $e->getMessage();
			}
			return null;
		};

		$error = (string) $run( [] );
		$this->assertStringContainsString( '--trust-certificate=' . $pin, $error );
		$this->assertStringContainsString( CertificatePin::COMMAND, $error );
		$this->assertStringContainsString( 'needs a value', (string) $run( [ 'trust-certificate' => true ] ) );
		$this->assertNotNull( $run( [ 'trust-certificate' => str_replace( '14:', 'AA:', $pin ) ] ), 'Another fingerprint than the one shown.' );
		$this->assertSame( '', ( new ProfileRepository() )->find( 'aaaa1111' )->settings['certFingerprint'] );

		$this->assertNull( $run( [ 'trust-certificate' => strtolower( $pin ) ] ), 'Lower-case hex, as typed by hand, is the same fingerprint.' );
		$this->assertSame( $pin, ( new ProfileRepository() )->find( 'aaaa1111' )->settings['certFingerprint'] );
		$this->assertSame( [], $asked );
	}

	public function test_another_failure_of_the_test_is_an_error_without_a_question(): void {
		$id = $this->addedKeyed();
		FakeKeyedType::remote( $id )->failNext( 'test', new PermanentFailure( 'Login refused.', PermanentFailure::AUTH ) );
		$asked = [];

		$this->assertSame( 'Login refused.', $this->keyedCli( [ 'test', $id ], [ 'trust-host-key' => self::SHOWN ], [], $asked, 'y' ) );
		$this->assertSame( [], $asked );
	}

	/**
	 * Review I1: a key file for a key the settings do not use is never dropped silently (the piped line would become the password).
	 */
	public function test_a_key_file_for_a_hidden_key_field_is_refused_naming_the_setting(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/id', "KEY-LINE-1\n" );

		$error = (string) $this->keyedCli( [ 'add', 'keyed' ], [ 'name' => 'Box', 'host' => 'example.com', 'private-key-file' => $dir . '/id' ], [ "the-passphrase\n" ] );

		TempDir::remove( $dir );
		$this->assertStringContainsString( '--private-key-file', $error );
		$this->assertStringContainsString( '--auth=key', $error );
		$this->assertStringNotContainsString( $dir, $error );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
		$this->assertContains( $GLOBALS['wpdb']->value( Options::DESTINATION_SECRETS ), [ null, '', '[]', '{}' ], 'Nothing sealed.' );
	}

	/**
	 * Review m1.
	 */
	public function test_a_trust_host_key_flag_without_a_value_is_refused(): void {
		$id = $this->addedKeyed();

		$this->assertSame( 'The option --trust-host-key needs a value.', $this->keyedCli( [ 'test', $id ], [ 'trust-host-key' => true ] ) );
		$this->assertSame( [], FakeKeyedType::remote( $id )->calls, 'Refused before any connection.' );
	}

	/**
	 * P-M1 / O7: the connect marker is no flag, and token fields are no flags either.
	 */
	public function test_the_connect_marker_and_token_fields_are_unknown_flags(): void {
		[ $settings, $ask ] = DestinationFlags::split( new FakeOAuthType(), [ 'name' => 'D', 'client-id' => 'c' ] );
		$this->assertSame( [ 'clientId' => 'c', 'ownApp' => true ], $settings );
		$this->assertSame( [ 'clientSecret' ], array_map( static fn( $f ): string => $f->key, $ask ) );

		foreach ( [ 'account', 'refresh-token', 'access-token', 'grant' ] as $flag ) {
			\WP_CLI::$errors = [];
			try {
				DestinationFlags::split( new FakeOAuthType(), [ 'client-id' => 'c', $flag => 'x' ] );
				$this->fail( 'Refused: --' . $flag );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'Unknown option --' . $flag, \WP_CLI::$errors[0] ?? '' );
			}
		}
	}

	private const CALLBACK = 'https://www.site-one.com/wp-json/lw-migration/v1/oauth/callback';

	private ?Destinations $oauthDestinations = null;

	private function oauthDestinations(): Destinations {
		if ( null === $this->oauthDestinations ) {
			FakeTransients::install();
			Functions\when( 'rest_url' )->alias( static fn( string $path ): string => 'https://www.site-one.com/wp-json/' . ltrim( $path, '/' ) );
			Functions\when( 'is_multisite' )->justReturn( false );
			Functions\when( 'wp_get_session_token' )->justReturn( 'session-of-nobody' );
			$this->oauthDestinations = new Destinations( new ProfileRepository(), new SecretVault(), new DestinationRegistry( [ new S3Type(), new FakeOAuthType() ] ) );
		}

		return $this->oauthDestinations;
	}

	/**
	 * A command over a registry with FakeOAuthType and an OAuthApi with fixed addresses.
	 *
	 * @param array<int, string> $stdin STDIN lines.
	 */
	private function command( array $stdin = [] ): DestinationCommand {
		$d = $this->oauthDestinations();

		return new DestinationCommand(
			static function () use ( &$stdin ): ?string {
				return array_shift( $stdin );
			},
			new DestinationsApi( $d, static fn(): array => [] ),
			static fn(): bool => false,
			null,
			$d->registry(),
			new ConnectLinks(
				new OAuthApi( $d, new OAuthState(), static fn(): string => self::CALLBACK, static fn(): string => self::PAGE, static fn(): int => 1_800_000_000 ),
				new ConnectTicket( static fn(): int => 1_800_000_000 ),
				static fn(): string => self::PAGE
			)
		);
	}

	/** Saves a `fakeoauth` profile with clientId `c` and a sealed clientSecret. */
	private function addOAuthProfile(): string {
		$d = $this->oauthDestinations();

		return ( new DestinationsApi( $d, static fn(): array => [] ) )->create(
			[
				'type'     => 'fakeoauth',
				'name'     => 'D',
				'settings' => [ 'clientId' => 'c' ],
				'secrets'  => [ 'clientSecret' => 's' ],
			]
		)['id'];
	}

	public function test_the_connect_marker_is_not_an_option(): void {
		$this->expectException( \RuntimeException::class );
		try {
			$this->command()->add( [ 'fakeoauth' ], [ 'name' => 'Drive', 'client-id' => 'c', 'account' => 'x' ] );
		} finally {
			$this->assertStringContainsString( '--account', implode( "\n", \WP_CLI::$errors ) );
			$this->assertSame( [], ( new ProfileRepository() )->all() );
		}
	}

	public function test_adding_an_oauth_destination_prints_the_redirect_address_and_the_connect_command(): void {
		$this->command( [ 'client-secret-1' ] )->add( [ 'fakeoauth' ], [ 'name' => 'Drive', 'client-id' => 'c' ] );

		$out = implode( "\n", \WP_CLI::$lines );
		$this->assertStringContainsString( self::CALLBACK, $out );
		$this->assertMatchesRegularExpression( '/wp lw-migration destination connect [a-f0-9]{8} --user=/', $out );
		$this->assertStringNotContainsString( 'client-secret-1', $out );
		$this->assertSame( [ 'clientSecret' => 'client-secret-1' ], ( new SecretVault() )->open( ( new ProfileRepository() )->all()[0]->id ) );
	}

	public function test_onedrive_takes_tenant_and_access_flags_and_asks_the_client_secret(): void {
		[ $settings, $ask ] = DestinationFlags::split( new OneDriveType(), [ 'name' => 'D', 'client-id' => 'c', 'tenant' => 'organizations', 'access' => 'files' ] );

		$this->assertSame( [ 'clientId' => 'c', 'tenant' => 'organizations', 'access' => 'files', 'ownApp' => true ], $settings );
		$this->assertSame( [ 'clientSecret' ], array_map( static fn( $f ): string => $f->key, $ask ) );
	}

	/**
	 * An own-app-only setting (here OneDrive's --tenant, without --client-id)
	 * means the own app, like --client-id: never silently the LW app, which
	 * would drop the tenant.
	 */
	public function test_an_own_app_only_flag_implies_the_own_app(): void {
		[ $tenant, $askTenant ] = DestinationFlags::split( new OneDriveType(), [ 'name' => 'X', 'tenant' => 'contoso.com' ] );
		[ $access ]             = DestinationFlags::split( new OneDriveType(), [ 'name' => 'X', 'access' => 'files' ] );
		[ $off ]                = DestinationFlags::split( new OneDriveType(), [ 'name' => 'X', 'own-app' => false, 'tenant' => 'contoso.com' ] );

		$this->assertSame( [ 'tenant' => 'contoso.com', 'ownApp' => true ], $tenant );
		$this->assertSame( [ 'clientSecret' ], array_map( static fn( $f ): string => $f->key, $askTenant ), 'The own app asks its secret.' );
		$this->assertSame( [ 'access' => 'files', 'ownApp' => true ], $access );
		$this->assertSame( [ 'ownApp' => false, 'tenant' => 'contoso.com' ], $off, 'An explicit --own-app=false wins.' );
	}

	public function test_adding_another_type_prints_no_connect_step(): void {
		$this->command( [ self::SECRET . "\n" ] )->add( [ 's3' ], self::SETTINGS );

		$this->assertStringNotContainsString( 'destination connect', implode( "\n", \WP_CLI::$lines ) );
	}

	public function test_connect_without_an_administrator_user_is_refused(): void {
		$id = $this->addOAuthProfile();
		foreach ( [ [ 0, true ], [ 7, false ] ] as [ $user, $can ] ) {
			Functions\when( 'get_current_user_id' )->justReturn( $user );
			Functions\when( 'user_can' )->justReturn( $can );
			\WP_CLI::$errors = [];
			try {
				$this->command()->connect( [ $id ], [] );
				$this->fail( 'Refused without an administrator.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( '--user=', implode( "\n", \WP_CLI::$errors ) );
			}
		}
		$this->assertSame( [], FakeTransients::$store, 'No state was issued.' );
	}

	/**
	 * Issue #12: the command line prints the plugin's page with a one-time
	 * ticket for --user, never an authorize address or a state.
	 */
	public function test_connect_prints_a_one_time_link_to_the_plugin_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias( static fn( string $key, string $value, string $url ): string => $url . '&' . $key . '=' . $value );
		$id = $this->addOAuthProfile();
		\WP_CLI::$lines = [];

		$this->command()->connect( [ $id ], [] );

		$this->assertMatchesRegularExpression( '#^https://www\.site-one\.com/wp-admin/admin\.php\?page=lw-migration&lwm-connect=[a-f0-9]{64}$#', (string) end( \WP_CLI::$lines ) );
		$this->assertStringNotContainsString( 'accounts.google.com', implode( "\n", \WP_CLI::$lines ) );
		$this->assertCount( 1, FakeTransients::$store, 'One ticket, no state.' );
		$name   = (string) array_key_first( FakeTransients::$store );
		$record = FakeTransients::$store[ $name ][0];
		$this->assertStringStartsWith( 'lw_migration_connect_', $name );
		$this->assertSame( [ 7, $id ], [ $record['user'], $record['profile'] ] );
	}

	public function test_connect_of_a_missing_or_non_oauth_destination_is_an_error(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );
		$this->command( [ self::SECRET . "\n" ] )->add( [ 's3' ], self::SETTINGS );
		$s3 = ( new ProfileRepository() )->all()[0]->id;
		foreach ( [ 'abcd1234', $s3 ] as $id ) {
			\WP_CLI::$errors = [];
			try {
				$this->command()->connect( [ $id ], [] );
				$this->fail( 'Refused: ' . $id );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( [], \WP_CLI::$errors );
			}
		}
		$this->assertSame( [], FakeTransients::$store );
	}

	/**
	 * P-M5: the REAUTH text says "Choose Connect" (a UI word); the command line names its own command.
	 */
	public function test_testing_a_destination_that_is_not_connected_names_the_connect_command(): void {
		$id = $this->addOAuthProfile();

		try {
			$this->command()->test( [ $id ], [] );
			$this->fail( 'A destination that is not connected fails its test.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'wp lw-migration destination connect ' . $id . ' --user=', implode( "\n", \WP_CLI::$errors ) );
		}
	}

	public function test_an_oauth_destination_without_options_uses_the_lw_app_and_asks_nothing(): void {
		$this->command( [ 'never-read' ] )->add( [ 'fakeoauth' ], [ 'name' => 'Drive' ] );

		$out     = implode( "\n", \WP_CLI::$lines );
		$profile = ( new ProfileRepository() )->all()[0];
		$this->assertSame( [ 'ownApp' => false ], $profile->settings );
		$this->assertMatchesRegularExpression( '/wp lw-migration destination connect [a-f0-9]{8} --user=/', $out );
		$this->assertStringNotContainsString( self::CALLBACK, $out, 'Nothing to register for the LW app.' );
		$this->assertSame( [], ( new SecretVault() )->open( $profile->id ), 'No secret was asked or stored.' );
	}

	public function test_own_app_flags(): void {
		[ $alone, $askAlone ] = DestinationFlags::split( new FakeOAuthType(), [ 'name' => 'D', 'client-id' => 'c' ] );
		[ $on, $askOn ]       = DestinationFlags::split( new FakeOAuthType(), [ 'name' => 'D', 'own-app' => true, 'client-id' => 'c' ] );
		[ $off, $askOff ]     = DestinationFlags::split( new FakeOAuthType(), [ 'name' => 'D', 'own-app' => false ] );

		$this->assertSame( [ 'clientId' => 'c', 'ownApp' => true ], $alone, '--client-id alone means --own-app.' );
		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'c' ], $on );
		$this->assertSame( [ 'ownApp' => false ], $off );
		$this->assertSame( [ [ 'clientSecret' ], [ 'clientSecret' ], [] ], array_map( static fn( array $ask ): array => array_map( static fn( $f ): string => $f->key, $ask ), [ $askAlone, $askOn, $askOff ] ) );
	}
}
