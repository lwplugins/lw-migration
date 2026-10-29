<?php
/**
 * Connections to other sites over REST: list, code, add, check, remove, push, pull.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Import\ImportJobs;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\KeyExchange;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Pairing;
use LightweightPlugins\Migration\Link\PeerJobs;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Support\Text;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Only the answer to createCode() carries a pairing code; no answer ever
 * carries a secret, a nonce or a lookup hash (the list shows when the open
 * code expires, nothing else of it). Every failure of a call to the other
 * site goes through refusal() (review m9), so its text reaches the user
 * instead of ErrorMap's generic 500. Removing an incoming link, here or
 * because the other site removed it (forget()), first cancels its jobs.
 */
final class LinksApi {

	/** Most bytes of a typed code (an address of 2048 bytes fits with room to spare). */
	private const MAX_CODE = 3000;

	/**
	 * @param Links       $links     Links.
	 * @param Codes       $codes     The open code.
	 * @param Pairing     $pairing   Pairing.
	 * @param LinkClients $clients   Clients of outgoing links.
	 * @param \Closure    $cancelFor fn( string $linkId ): int — cancels the link's jobs on this site.
	 * @param \Closure    $startPush fn( array{id: string, name: string, address: string, allow: array<int, string>} $link, ExportOptions $options, bool $safetyBackup ): Job.
	 * @param \Closure    $startPull fn( array{id: string, name: string, address: string, allow: array<int, string>} $link, bool $safetyBackup, array{id: int, login: string}|null $admin ): Job.
	 */
	public function __construct( private Links $links, private Codes $codes, private Pairing $pairing, private LinkClients $clients, private \Closure $cancelFor, private \Closure $startPush, private \Closure $startPull ) {
	}

	/**
	 * @param Services $services Services (the jobs a link started here are cancelled through JobsApi).
	 */
	public static function fromServices( Services $services ): self {
		$cancelFor = static fn( string $id ): int => PeerJobs::fromServices( $services )->cancelFor( $id, static fn( string $job ): array => JobsApi::fromServices( $services )->cancel( $job ) );
		$startPush = static function ( array $link, ExportOptions $options, bool $safety ) use ( $services ): Job {
			$services->storage()->ensure();

			return PushJobs::create( $services->jobs(), $services->lock(), SiteReader::read(), $options, $link, $safety, time() );
		};

		return new self( new Links(), new Codes(), Pairing::fromWordPress(), LinkClients::fromWordPress(), $cancelFor, $startPush, self::pullStarter( $services ) );
	}

	/**
	 * A pull: an import whose archive the other site exports and this site
	 * downloads over the link (ImportOptions::LINK).
	 *
	 * @param Services $services Services.
	 */
	private static function pullStarter( Services $services ): \Closure {
		return static function ( array $link, bool $safety, ?array $admin ) use ( $services ): Job {
			$storage = $services->storage();
			$storage->ensure();
			if ( $safety ) {
				try {
					$storage->ensureBackups();
				} catch ( FolderRefusedException $e ) {
					throw RestException::backupDirUnusable( $e );
				}
			}
			$permalinks = '' !== (string) get_option( 'permalink_structure' );

			return ImportJobs::create(
				$services->jobs(),
				$services->lock(),
				SiteReader::read(),
				new ImportOptions( ImportOptions::LINK, $link['id'], $safety ),
				$admin,
				$permalinks,
				null,
				$services->webSecrets(),
				time(),
				[
					'link' => [
						'id'      => $link['id'],
						'name'    => $link['name'],
						'address' => $link['address'],
					],
				]
			);
		};
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, code: array{expiresAt: int}|null, available: bool}
	 */
	public function all(): array {
		$now     = time();
		$expires = $this->codes->pending( $now );

		return [
			'items'     => $this->links->all( $now ),
			'code'      => null === $expires ? null : [ 'expiresAt' => $expires ],
			'available' => KeyExchange::available() && SecretBox::available(),
		];
	}

	/**
	 * @param array<string, mixed>          $body  {allow?: array<int, string>} — default pull only.
	 * @param array{id: int, login: string} $owner The current admin.
	 * @return array{code: string, expiresAt: int, insecure: bool}
	 * @throws RestException 400 for a malformed right list or (refusal()) an address that cannot be given in a code.
	 */
	public function createCode( array $body, array $owner ): array {
		self::only( $body, [ 'allow' ], 'allow' );
		$allow = $body['allow'] ?? [ Links::PULL ]; // Push only when ticked (user ruling U2).
		if ( ! is_array( $allow ) || array_values( $allow ) !== $allow || [] === $allow || count( Links::allow( $allow ) ) !== count( $allow ) ) {
			throw RestException::invalid( __( 'Choose what the other site may do: send its content here, copy this site, or both.', 'lw-migration' ), 'allow' );
		}
		try {
			return $this->pairing->createCode( $owner, $allow );
		} catch ( LinkException $e ) {
			$refused = self::refusal( $e );
			throw $refused;
		}
	}

	/**
	 * @return array{dropped: bool}
	 */
	public function dropCode(): array {
		$open = null !== $this->codes->pending( time() );
		$this->codes->clear();

		return [ 'dropped' => $open ];
	}

	/**
	 * @param array<string, mixed>          $body  {code: string}.
	 * @param array{id: int, login: string} $owner The current admin.
	 * @return array<string, mixed> The new link's view, `insecure` true when either address is http.
	 * @throws RestException 400 for a malformed body, a bad code or no admin; refusal() when the other site fails.
	 */
	public function add( array $body, array $owner ): array {
		self::only( $body, [ 'code' ], 'code' );
		$code = $body['code'] ?? null;
		if ( ! is_string( $code ) || strlen( $code ) > self::MAX_CODE ) {
			throw RestException::invalidField( 'code' );
		}
		try {
			return $this->pairing->add( $code, $owner );
		} catch ( LinkException $e ) {
			$refused = self::refusal( $e );
			throw $refused;
		} catch ( \InvalidArgumentException $e ) {
			throw RestException::invalid( __( 'A connection acts for an admin of this site. Log in as one and try again.', 'lw-migration' ) );
		}
	}

	/**
	 * Removes a link here. The other site of an outgoing link is told (best
	 * effort: it refuses the link anyway once it was removed there too);
	 * an incoming link's jobs are cancelled first.
	 *
	 * @param string $id Link id.
	 * @return array{removed: bool, notified: bool}
	 * @throws RestException 404 for an unknown link.
	 */
	public function remove( string $id ): array {
		$link = $this->known( $id );
		if ( Links::IN === $link['direction'] ) {
			return $this->forget( $id ) + [ 'notified' => false ];
		}
		try {
			$this->clients->forLink( $id )->json( 'DELETE', '', null, 10 );
			$notified = true;
		} catch ( LinkException $e ) {
			$notified = false;
		}

		return [
			'removed'  => $this->links->remove( $id ),
			'notified' => $notified,
		];
	}

	/**
	 * An incoming link goes: its jobs here stop first.
	 *
	 * @param string $id Link id.
	 * @return array{removed: bool}
	 */
	public function forget( string $id ): array {
		( $this->cancelFor )( $id );

		return [ 'removed' => $this->links->remove( $id ) ];
	}

	// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInComment -- "wordpress" is the answer's lower-case key.
	/**
	 * Asks the other site of an outgoing link who it is (a signed hello).
	 *
	 * @param string $id Link id.
	 * @return array{peer: array{name: string, address: string, allow: array<int, string>, wordpress: string}}
	 * @throws RestException 404 for an unknown link, 400 for an incoming one, refusal() when the other site fails.
	 */
	public function check( string $id ): array { // phpcs:enable WordPress.WP.CapitalPDangit.MisspelledInComment
		if ( Links::IN === $this->known( $id )['direction'] ) {
			throw RestException::invalid( __( 'Only the site that typed the code can check the connection.', 'lw-migration' ) );
		}
		try {
			$json = $this->clients->forLink( $id )->json( 'POST', '/hello' )['json'];
		} catch ( LinkException $e ) {
			$refused = self::refusal( $e );
			throw $refused;
		}

		return [
			'peer' => [
				'name'      => self::text( $json['name'] ?? '' ),
				'address'   => self::text( $json['address'] ?? '' ),
				'allow'     => Links::allow( is_array( $json['allow'] ?? null ) ? $json['allow'] : [] ),
				'wordpress' => self::text( $json['wordpress'] ?? '' ),
			],
		];
	}

	/**
	 * Sends this site to the other site of an outgoing link that allows it.
	 *
	 * @param string               $id   Link id.
	 * @param array<string, mixed> $body {options?: export options, safetyBackup?: bool} — the other site backs up its database first by default.
	 * @return Job The new push.
	 * @throws RestException 400 for a malformed body, an encrypted or a sites export, or a link without the push right; 404 (refusal()) for a link that cannot send.
	 *                       The start throws SiteBusyException when another job holds this site (ErrorMap: 409).
	 */
	public function push( string $id, array $body ): Job {
		self::only( $body, [ 'options', 'safetyBackup' ], 'options' );
		$raw     = $body['options'] ?? [];
		$asked   = is_array( $raw ) && ( true === ( $raw['encrypt'] ?? null ) || [] !== ( $raw['sites'] ?? [] ) ); // Before the single-site check of the sites.
		$options = $asked ? null : JobInput::exportOptions( $raw );
		if ( null === $options || $options->encrypt || [] !== $options->sites ) {
			throw RestException::invalid( PushJobs::plainOnly(), 'options' );
		}
		$safety = $body['safetyBackup'] ?? true;
		if ( ! is_bool( $safety ) ) {
			throw RestException::invalidField( 'safetyBackup' );
		}
		try {
			$link = $this->clients->view( $id );
		} catch ( LinkException $e ) {
			$refused = self::refusal( $e );
			throw $refused;
		}
		if ( ! in_array( Links::PUSH, $link['allow'], true ) ) {
			throw RestException::invalid( __( 'This connection does not allow sending this site there.', 'lw-migration' ), 'link' );
		}

		return ( $this->startPush )( $link, $options, $safety );
	}

	/**
	 * Copies the other site of an outgoing link that allows it onto this site.
	 *
	 * @param string                             $id    Link id.
	 * @param array<string, mixed>               $body  {safetyBackup?: bool} — this site backs up its database first by default.
	 * @param array{id: int, login: string}|null $admin The admin who stays logged in after the restore.
	 * @return Job The new import.
	 * @throws RestException 400 for a malformed body or a link without the pull right; 404 (refusal()) for a link that cannot copy.
	 *                       The start throws SiteBusyException when another job holds this site (ErrorMap: 409).
	 */
	public function pull( string $id, array $body, ?array $admin ): Job {
		self::only( $body, [ 'safetyBackup' ], 'safetyBackup' );
		$safety = $body['safetyBackup'] ?? true;
		if ( ! is_bool( $safety ) ) {
			throw RestException::invalidField( 'safetyBackup' );
		}
		try {
			$link = $this->clients->view( $id );
		} catch ( LinkException $e ) {
			$refused = self::refusal( $e );
			throw $refused;
		}
		if ( ! in_array( Links::PULL, $link['allow'], true ) ) {
			throw RestException::invalid( __( 'This connection does not allow copying that site here.', 'lw-migration' ), 'link' );
		}

		return ( $this->startPull )( $link, $safety, $admin );
	}

	/**
	 * The REST answer of a failed connection call: a bad code or address is
	 * the request's fault (400 on the code field), a gone link a 404, every
	 * other failure a 502 with its text and reason.
	 *
	 * @param LinkException $e What went wrong while talking to the other site.
	 */
	public static function refusal( LinkException $e ): RestException {
		if ( in_array( $e->reason, [ LinkException::CODE, LinkException::SCHEME ], true ) ) {
			return RestException::invalid( $e->getMessage(), 'code' );
		}
		if ( LinkException::GONE === $e->reason ) {
			return new RestException( 'lw_migration_no_link', $e->getMessage(), 404 );
		}

		return new RestException( 'lw_migration_link_failed', $e->getMessage(), 502, [ 'reason' => $e->reason ] );
	}

	/**
	 * @param string $id Link id.
	 * @return array<string, mixed> The link (Links::find()).
	 * @throws RestException 404.
	 */
	private function known( string $id ): array {
		$link = 1 === preg_match( Signer::LINK_PATTERN, $id ) ? $this->links->find( $id, time() ) : null;
		if ( null === $link ) {
			throw new RestException( 'lw_migration_no_link', __( 'This connection does not exist (any more).', 'lw-migration' ), 404 );
		}

		return $link;
	}

	/**
	 * Refuses a body with a key outside $keys.
	 *
	 * @param array<string, mixed> $body  Body.
	 * @param array<int, string>   $keys  Allowed keys.
	 * @param string               $field Field named in the refusal.
	 * @throws RestException 400.
	 */
	private static function only( array $body, array $keys, string $field ): void {
		if ( [] !== array_diff( array_keys( $body ), $keys ) ) {
			throw RestException::invalidField( $field );
		}
	}

	/**
	 * A text of the other site as plain, clipped text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? Text::clip( sanitize_text_field( $value ), Links::MAX_TEXT ) : '';
	}
}
