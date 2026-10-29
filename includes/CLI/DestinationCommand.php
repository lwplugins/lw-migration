<?php
/**
 * Manages the cloud destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Rest\ConnectLinks;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectUri;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\CertificatePin;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\HostKey;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, lists, tests and removes the places backups are uploaded to.
 */
final class DestinationCommand {

	/**
	 * STDIN line: fn(): ?string.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $stdin;

	/**
	 * Whether STDIN is a terminal: fn(): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $interactive;

	/**
	 * A question on the terminal: fn( string $question ): ?string.
	 *
	 * @var \Closure
	 */
	private \Closure $ask;

	/**
	 * @param \Closure|null            $stdin       fn(): ?string (tests; null = STDIN).
	 * @param DestinationsApi|null     $api         The destinations (tests; null = this site's).
	 * @param \Closure|null            $interactive fn(): bool (tests; null = Confirmation::stdinIsInteractive()).
	 * @param \Closure|null            $ask         fn( string $question ): ?string (tests; null = Prompt::line()).
	 * @param DestinationRegistry|null $registry    Types (tests; null = DestinationRegistry::fromWordPress()).
	 * @param ConnectLinks|null        $links       Connect links (tests; null = ConnectLinks::fromWordPress()).
	 */
	public function __construct( ?\Closure $stdin = null, private ?DestinationsApi $api = null, ?\Closure $interactive = null, ?\Closure $ask = null, private ?DestinationRegistry $registry = null, private ?ConnectLinks $links = null ) {
		$this->stdin       = $stdin;
		$this->interactive = $interactive ?? static fn(): bool => Confirmation::stdinIsInteractive();
		$this->ask         = $ask ?? static fn( string $question ): ?string => Prompt::line( $question );
	}

	/**
	 * Lists the destinations.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function listing( array $args, array $assoc ): void {
		$rows = array_map(
			static fn( array $item ): array => [
				'id'          => $item['id'],
				'name'        => $item['name'],
				'type'        => $item['typeLabel'],
				'where'       => $item['where'] . ( $item['insecure'] ? ' (' . __( 'unencrypted', 'lw-migration' ) . ')' : '' ),
				'status'      => $item['status'],
				'last_upload' => $item['lastUploadAt'] > 0 ? gmdate( 'Y-m-d H:i', (int) $item['lastUploadAt'] ) . ' UTC' : '-',
			],
			$this->api()->all()['items']
		);
		Console::items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'id', 'name', 'type', 'where', 'status', 'last_upload' ] );
	}

	/**
	 * Adds a destination. Secrets are never options: they are asked for with a
	 * hidden prompt (the default), or read from standard input one per line;
	 * a multi-line secret (an SSH private key) is read from a file. Only the
	 * secrets the settings use are asked for.
	 * Do not use the global --prompt here: it would show what you type.
	 *
	 * A Google Drive, Dropbox or OneDrive destination needs nothing more: it
	 * connects through the LW app. With --own-app it uses your own app at the
	 * provider: --client-id (OneDrive also --tenant and
	 * --access=appfolder|files), and its client secret is asked for like any
	 * secret; --client-id, --tenant or --access alone also means --own-app.
	 * Then connect it with the connect command.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Destination type (s3, webdav, ftp, sftp, gdrive, dropbox, onedrive).
	 *
	 * --name=<name>
	 * : Name shown in lists.
	 *
	 * [--<field>=<value>]
	 * : Settings of the type, e.g. --preset=b2 --region=us-west-004 --bucket=my-backups --prefix=site --access-key=…; --path-style or --no-path-style. The user name of a WebDAV, FTP or SFTP server is --username, a WebDAV address --address (WP-CLI keeps --user and --url for itself). A multi-line secret (an SSH private key) is read from a file given as --<field>-file=<path>, e.g. --private-key-file=/home/me/.ssh/id_ed25519; use an absolute path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration destination add gdrive --name=Drive
	 *     wp lw-migration destination add dropbox --name=Box --own-app --client-id=abc123
	 *     wp lw-migration destination add s3 --name=Offsite --preset=r2 --account-id=0123… --bucket=backups --access-key=…
	 *     printf '%s\n' "$SECRET" | wp lw-migration destination add s3 --name=MinIO --preset=custom --endpoint=http://127.0.0.1:9000 --bucket=b --access-key=minioadmin
	 *     wp lw-migration destination add sftp --name=Box --host=backup.example.com --username=me --auth=key --private-key-file=/home/me/.ssh/id_ed25519 --folder=backups
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function add( array $args, array $assoc ): void {
		$registry = $this->types();
		$kind     = $registry->get( (string) ( $args[0] ?? '' ) );
		if ( null === $kind ) {
			/* translators: %s: comma-separated type keys */
			Console::error( sprintf( __( 'Unknown destination type. Types: %s', 'lw-migration' ), implode( ', ', array_map( static fn( $t ): string => $t->key(), $registry->all() ) ) ) );
			return;
		}
		[ $settings, $lineFields, $fileFields ] = DestinationFlags::split( $kind, $assoc );
		try {
			$secrets = array_merge( SecretFiles::read( $fileFields, $assoc ), SecretInput::read( $lineFields, ( $this->interactive )(), $this->stdin ) ); // Files first: a missing key file stops before anything is asked.
			$item    = $this->api()->create(
				[
					'type'     => $kind->key(),
					'name'     => (string) ( $assoc['name'] ?? '' ),
					'settings' => $settings,
					'secrets'  => $secrets,
				]
			);
		} catch ( RestException | \RuntimeException $e ) {
			Console::error( DestinationFlags::text( $e, $kind ) );
			return;
		}
		/* translators: 1: destination name, 2: destination ID */
		Console::success( sprintf( __( 'Destination "%1$s" added with ID %2$s. Check it with: wp lw-migration destination test %2$s', 'lw-migration' ), $item['name'], $item['id'] ) );
		if ( $item['insecure'] ) {
			Console::warning( __( 'This destination sends backups unencrypted.', 'lw-migration' ) );
		}
		if ( $kind instanceof OAuthDestinationType ) {
			if ( OAuthMode::ownApp( (array) $item['settings'] ) ) {
				/* translators: %s: the redirect address */
				Console::log( sprintf( __( 'Register this redirect address in your app at the provider: %s', 'lw-migration' ), RedirectUri::current() ) );
			}
			/* translators: %s: destination ID */
			Console::log( sprintf( __( 'Then connect the account: wp lw-migration destination connect %s --user=<your administrator login>', 'lw-migration' ), $item['id'] ) );
		}
	}

	/**
	 * Prints the link that connects a Google Drive, Dropbox or OneDrive destination to its account.
	 *
	 * The link is for one administrator (choose with --user) and works once, within 10 minutes, in a browser where that administrator is logged in to this site.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Destination ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration destination connect 1a2b3c4d --user=admin
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function connect( array $args, array $assoc ): void {
		unset( $assoc );
		$user = get_current_user_id();
		if ( $user <= 0 || ! user_can( $user, Permission::capability() ) ) {
			Console::error( __( 'Run this with --user=<an administrator\'s login>: the link works only in a browser where that administrator is logged in.', 'lw-migration' ) );
			return;
		}
		try {
			$url = ( $this->links ?? ConnectLinks::fromWordPress() )->link( (string) ( $args[0] ?? '' ), $user );
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		Console::log( __( 'Open this link within 10 minutes in a browser where that administrator is logged in to this site. It works once, and only for that administrator:', 'lw-migration' ) );
		Console::log( $url );
	}

	/**
	 * Writes, reads back and deletes a small test file at a destination. An
	 * SFTP server's host key, or an FTPS server's certificate that no
	 * certificate authority vouches for, is confirmed here the first time
	 * (and after it changed): on a terminal you are asked, otherwise pass the
	 * fingerprint.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Destination ID.
	 *
	 * [--trust-host-key=<fingerprint>]
	 * : The SHA256 fingerprint of the SFTP server's host key you checked on the server (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub, or the ecdsa or rsa key file of the type shown).
	 *
	 * [--trust-certificate=<fingerprint>]
	 * : The SHA-256 fingerprint of the FTPS server's certificate you checked on the server (openssl x509 -noout -fingerprint -sha256 -in and the certificate file).
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function test( array $args, array $assoc ): void {
		$id = (string) ( $args[0] ?? '' );
		foreach ( [ 'trust-host-key', 'trust-certificate' ] as $flag ) {
			if ( true === ( $assoc[ $flag ] ?? null ) ) {
				/* translators: %s: option name */
				Console::error( sprintf( __( 'The option %s needs a value.', 'lw-migration' ), '--' . $flag ) );
				return;
			}
		}
		try {
			$item = $this->api()->test( $id );
		} catch ( RestException $e ) {
			$item = $this->hostKey( $id, $e, $assoc );
			if ( null === $item ) {
				return;
			}
		}
		/* translators: %s: destination name */
		Console::success( sprintf( __( '%s works: a test file was written, read back and deleted.', 'lw-migration' ), $item['name'] ) );
	}

	/**
	 * A host key or a certificate to confirm: ask on a terminal, or take
	 * --trust-host-key / --trust-certificate. The API stores it only when the
	 * server shows exactly that fingerprint now.
	 *
	 * @param string               $id    Profile id.
	 * @param RestException        $e     The failed test.
	 * @param array<string, mixed> $assoc Flags.
	 * @return array<string, mixed>|null The view after the key was trusted and the test passed; null after an error.
	 */
	private function hostKey( string $id, RestException $e, array $assoc ): ?array {
		$field = isset( $e->data['certFingerprint'] ) ? 'certFingerprint' : 'hostKey';
		$cert  = 'certFingerprint' === $field;
		$shown = (string) ( $e->data[ $field ] ?? '' );
		if ( '' === $shown || ! in_array( (string) ( $e->data['reason'] ?? '' ), PermanentFailure::UNCONFIRMED, true ) ) {
			Console::error( $this->failure( $id, $e ) );
			return null;
		}
		Console::log( $e->getMessage() );
		$flag  = $cert ? 'trust-certificate' : 'trust-host-key';
		$given = is_string( $assoc[ $flag ] ?? null ) ? trim( $assoc[ $flag ] ) : '';
		if ( '' === $given && ( $this->interactive )() ) {
			/* translators: %s: SHA-256 fingerprint */
			$certificate = __( 'Trust the certificate %s? [y/n]', 'lw-migration' );
			/* translators: %s: SHA256 fingerprint */
			$hostKey = __( 'Trust the host key %s? [y/n]', 'lw-migration' );
			$answer  = ( $this->ask )( sprintf( $cert ? $certificate : $hostKey, $shown ) );
			$given   = null !== $answer && 'y' === strtolower( trim( $answer ) ) ? $shown : '';
		}
		if ( '' === $given ) {
			$command = $cert ? CertificatePin::COMMAND : HostKey::command( (string) ( $e->data['hostKeyType'] ?? '' ) );
			/* translators: 1: destination ID, 2: fingerprint, 3: a command, e.g. ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub, 4: option name */
			Console::error( sprintf( __( 'Compare the fingerprint with your server (%3$s), then run: wp lw-migration destination test %1$s --%4$s=%2$s', 'lw-migration' ), $id, $shown, $command, $flag ) );
			return null;
		}
		try {
			return $this->api()->trust( $id, [ $field => $given ] );
		} catch ( RestException $t ) {
			Console::error( $t->getMessage() );
			return null;
		}
	}

	/**
	 * Removes a destination (its stored secrets too). Backups at the destination stay.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Destination ID.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function remove( array $args, array $assoc ): void {
		$id = (string) ( $args[0] ?? '' );
		try {
			$name = $this->api()->find( $id )->name;
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		/* translators: %s: destination name */
		$decision = Confirmation::decide( $assoc, ( $this->interactive )(), static fn(): ?string => Prompt::line( sprintf( __( 'Remove the destination %s? Its backups stay there. [y/n]', 'lw-migration' ), $name ) ) );
		if ( Confirmation::CONFIRMED !== $decision ) {
			Console::error( Confirmation::NEEDS_YES === $decision ? __( 'Without a terminal, confirm with --yes.', 'lw-migration' ) : __( 'Nothing was removed.', 'lw-migration' ) );
			return;
		}
		try {
			$result = $this->api()->delete( $id );
		} catch ( RestException $e ) { // lw_migration_destination_storage: our own text.
			Console::error( $e->getMessage() );
			return;
		}
		/* translators: %s: destination name */
		Console::success( sprintf( __( 'Removed %s.', 'lw-migration' ), $name ) );
		foreach ( (array) ( $result['warnings'] ?? [] ) as $warning ) {
			Console::warning( (string) $warning );
		}
		if ( $result['usedBy'] > 0 ) {
			/* translators: %d: number of schedules */
			Console::warning( sprintf( _n( '%d schedule named it; its runs now skip it.', '%d schedules named it; their runs now skip it.', $result['usedBy'], 'lw-migration' ), $result['usedBy'] ) );
		}
	}

	/**
	 * The global --prompt is not in $assoc and WP-CLI would prompt visibly
	 * and log the typed command line: refuse it (before_invoke hook).
	 */
	public static function refusePrompt(): void {
		if ( WP_CLI::get_config( 'prompt' ) ) {
			Console::error( __( 'Do not use --prompt with destination add: it would show and log what you type. Secrets are asked for with a hidden prompt by default.', 'lw-migration' ) );
		}
	}

	/**
	 * A failed test in command-line words (an OAuth destination to connect
	 * names the connect command, P-M5).
	 *
	 * @param string        $id Profile id.
	 * @param RestException $e  The failure.
	 */
	private function failure( string $id, RestException $e ): string {
		try {
			$type = $this->types()->get( $this->api()->find( $id )->type );
		} catch ( RestException $gone ) {
			return $e->getMessage();
		}

		return null === $type ? $e->getMessage() : DestinationFlags::text( $e, $type, $id );
	}

	private function types(): DestinationRegistry {
		return $this->registry ?? DestinationRegistry::fromWordPress();
	}

	private function api(): DestinationsApi {
		if ( null === $this->api ) {
			$this->api = DestinationsApi::fromServices( Services::get() );
		}

		return $this->api;
	}
}
