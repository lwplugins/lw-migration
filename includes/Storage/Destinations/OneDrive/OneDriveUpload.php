<?php
/**
 * OneDrive's upload session.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E9–E11, E18, E23 with preflight P-C2, P-I2, P-I3, P-I5
 * (driveitem-createuploadsession, read 2026-10-14). start() opens a session
 * in the site's folder with conflictBehavior fail, so an existing file is never
 * replaced; every fragment is a PUT to the uploadUrl without Authorization,
 * "bytes a-b/total" with the same total each time, every one but the last a
 * 320 KiB multiple. 202 names the next expected range; 200/201 with the item
 * ends the upload, and only after its last byte; 416 (a fragment the service
 * already has) asks for the status. The session's uploadUrl must be an https
 * address of a DNS name (P-C2; the host set is not documented).
 *
 * Never another file (M7b H13): the session keeps the quickXorHash fold of
 * what OneDrive confirmed (`extra.xor`, QuickXorHash). A name conflict on
 * the last fragment, or a session gone after the last fragment was sent (a
 * lost final answer), is this backup only when the site folder's file of
 * that name has the backup's size and quickXorHash; anything else is never
 * replaced (INVALID) or starts over (SESSION_GONE, no file).
 *
 * A status answer (resume(), after a lost answer) is adopted only within
 * what was sent, and since the hash needs the bytes, in two steps: resume()
 * keeps the offset and records what OneDrive holds (`extra.held`); the next
 * uploadChunk() takes those bytes into the hash and moves on without
 * sending them again. A gone session whose last fragment was in flight is
 * recorded as held up to the end, and its bytes then decide by the file of
 * the name. A stored session of another type, address, chunk rule or extras
 * starts over before any request.
 */
final class OneDriveUpload {

	public const MULTIPLE = UploadRecord::MULTIPLE;

	public const MAX = UploadRecord::MAX;

	/** Slowest rate a fragment is given time for (bytes a second, P-M2). */
	private const RATE = 262144;

	/**
	 * Whether a file of the name is this backup.
	 *
	 * @var SameBackup
	 */
	private SameBackup $proof;

	public function __construct( private OAuthHttp $http, private ChunkSizer $sizer, private string $folder ) {
		$this->proof = new SameBackup( $http, $folder );
	}

	/**
	 * @param string $name Backup or test file name.
	 * @param int    $size Bytes.
	 * @throws PermanentFailure INVALID when a file has the name or the answer lacks an upload address (OneDrive's own refusal, a CloudFailure, passes through).
	 */
	public function start( string $name, int $size ): UploadSession {
		$answer = $this->http->request(
			'POST',
			OneDriveDestination::filePath( $this->folder, $name ) . ':/createUploadSession',
			[ 'Content-Type' => 'application/json' ],
			(string) wp_json_encode( [ 'item' => [ '@microsoft.graph.conflictBehavior' => 'fail' ] ] )
		);
		if ( 409 === $answer['status'] ) {
			$failure = SameBackup::taken(); // Nothing of this upload was sent yet: it cannot be this backup.
			throw $failure;
		}
		if ( 200 !== $answer['status'] ) {
			$failure = $this->failure( $answer, GraphErrors::API );
			throw $failure;
		}
		$url = $this->http->json( $answer )['uploadUrl'] ?? null;
		if ( ! is_string( $url ) || ! UploadRecord::isProviderAddress( $url ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s answered without a usable upload address.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::INVALID );
		}

		return new UploadSession( OneDriveDestination::TYPE, $name, $size, 0, $this->sizer->first(), $url, UploadRecord::extra( 0, '', QuickXorHash::empty() ) );
	}

	/**
	 * @param UploadSession $s     Session of ours.
	 * @param string        $bytes The next fragment (exactly nextLength()).
	 * @throws PermanentFailure INVALID for another length (nothing sent) or another file of the name, SESSION_GONE for a session not ours, gone, or answered out of step (OneDrive's refusal, a CloudFailure, passes through).
	 */
	public function chunk( UploadSession $s, string $bytes ): UploadSession {
		UploadRecord::assertOwn( $s );
		$length = strlen( $bytes );
		if ( $length !== $s->nextLength() || $length < 1 ) {
			throw new PermanentFailure( __( 'A part of the backup had an unexpected length; it was not sent.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$held = (int) $s->extra['held'];
		if ( $held > $s->offset ) {
			return $this->adopt( $s, $bytes, $held ); // OneDrive has these bytes already (resume()).
		}
		$given = $bytes; // A status answer after a 416 may adopt up to all of it.
		if ( $length > $this->sizer->largest() ) { // Stored by a process with more free memory (P-M3).
			$length = $this->sizer->largest();
			$bytes  = substr( $bytes, 0, $length );
		}
		$started = $this->sizer->clock();
		$end     = $s->offset + $length;
		$answer  = $this->http->request(
			'PUT',
			$s->remote,
			[
				'Content-Length' => (string) $length,
				'Content-Range'  => sprintf( 'bytes %d-%d/%d', $s->offset, $end - 1, $s->size ),
			],
			$bytes,
			[
				'auth'    => false,
				'timeout' => CloudTimeout::forBytes( $length, self::RATE ),
			]
		);

		return $this->after( $s, $answer, $given, $length, $this->sizer->next( $length, $started ) );
	}

	/**
	 * The status query (GET uploadUrl, without Authorization).
	 *
	 * @param UploadSession $s Session of ours.
	 * @throws PermanentFailure SESSION_GONE for a session not ours or gone, or a position outside what was sent.
	 * @throws CloudFailure When OneDrive refuses.
	 */
	public function status( UploadSession $s ): UploadSession {
		UploadRecord::assertOwn( $s );
		if ( $s->isComplete() ) {
			return $s;
		}
		$top    = min( $s->size, $s->offset + $s->nextLength() );
		$answer = $this->http->request( 'GET', $s->remote, [], '', [ 'auth' => false ] );
		if ( in_array( $answer['status'], [ 404, 410 ], true ) && $top === $s->size ) {
			return $s->with( $s->offset, UploadRecord::extra( $s->size, '', UploadRecord::fold( $s ) ) ); // The last fragment may have ended it: its bytes decide.
		}
		if ( 200 !== $answer['status'] ) {
			throw $this->failure( $answer, GraphErrors::UPLOAD );
		}
		$held = self::expected( $this->http->json( $answer ) );
		if ( -1 === $held && $top === $s->size ) { // Nothing more expected while the last fragment was in flight: it may have ended the upload (review m2).
			return $s->with( $s->offset, UploadRecord::extra( $s->size, '', UploadRecord::fold( $s ) ) );
		}
		if ( $held < $s->offset || $held > $top || $held === $s->size ) {
			throw self::outOfStep();
		}

		return $s->with( $s->offset, UploadRecord::extra( $held, '', UploadRecord::fold( $s ) ) );
	}

	/**
	 * The item id of a complete upload: from its final answer, or the file of its name.
	 *
	 * @param UploadSession $s A complete session of ours.
	 * @throws PermanentFailure SESSION_GONE when no file has the name; INVALID when another file has it.
	 */
	public function itemId( UploadSession $s ): string {
		$id = (string) $s->extra['itemId'];

		return '' !== $id ? $id : (string) $this->proof->prove( $s, UploadRecord::fold( $s ) )->extra['itemId'];
	}

	/**
	 * The answer to a fragment.
	 *
	 * @param UploadSession                                                    $s      Session before.
	 * @param array{status: int, body: string, headers: array<string, string>} $answer Answer.
	 * @param string                                                           $given  The fragment the step handed in.
	 * @param int                                                              $sent   Bytes of it sent.
	 * @param int                                                              $chunk  Next fragment size.
	 * @throws PermanentFailure SESSION_GONE for a position outside what was sent or a finish before the last byte; INVALID for an unconfirmed file or another file of the name (any other answer is classified).
	 */
	private function after( UploadSession $s, array $answer, string $given, int $sent, int $chunk ): UploadSession {
		$last = $s->offset + $sent === $s->size;
		$json = $this->http->json( $answer );
		if ( in_array( $answer['status'], [ 200, 201 ], true ) ) {
			$id = $json['id'] ?? null;
			if ( ! $last ) {
				$failure = self::outOfStep(); // Finished before the last bytes were sent.
				throw $failure;
			}
			if ( ! is_string( $id ) || 1 !== preg_match( OneDriveDestination::ID_PATTERN, $id ) || ( $json['name'] ?? null ) !== $s->name || ( $json['size'] ?? null ) !== $s->size ) {
				/* translators: %s: provider name */
				throw new PermanentFailure( sprintf( __( '%s did not confirm the uploaded file.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::INVALID );
			}
			return $this->moved( $s, substr( $given, 0, $sent ), $chunk, $id );
		}
		if ( 202 === $answer['status'] ) {
			$held = self::expected( $json );
			if ( $held <= $s->offset || $held > $s->offset + $sent || $held === $s->size ) {
				$failure = self::outOfStep();
				throw $failure;
			}
			return $this->moved( $s, substr( $given, 0, $held - $s->offset ), $chunk, '' );
		}
		if ( 416 === $answer['status'] ) { // A fragment OneDrive already has: ask where it stands.
			$asked = $this->status( $s );
			$held  = (int) $asked->extra['held'];
			if ( $held <= $s->offset ) {
				$failure = self::outOfStep();
				throw $failure;
			}
			return $this->adopt( $asked, $given, $held );
		}
		if ( $last && in_array( $answer['status'], [ 404, 409, 410 ], true ) ) { // A name conflict, or the session ended meanwhile.
			$done = $this->proof->prove( $s, UploadRecord::fold( $s ) ^ QuickXorHash::fold( substr( $given, 0, $sent ), $s->offset ) );
			$this->cancel( $s ); // Its bytes are in our file already; a preserved session is not left behind (review m3).
			return $done;
		}

		$failure = $this->failure( $answer, GraphErrors::UPLOAD );
		throw $failure;
	}

	/**
	 * OneDrive holds $held bytes: those after the offset are in hand, so they
	 * go into the hash without being sent again; held to the end means the
	 * session is gone after its last fragment, and the file of the name decides.
	 *
	 * @param UploadSession $s     Session before.
	 * @param string        $bytes The fragment the step handed in (from $s->offset).
	 * @param int           $held  OneDrive's position (offset < held <= offset + strlen( $bytes )).
	 * @throws PermanentFailure SESSION_GONE for a position past the bytes in hand; see SameBackup::prove().
	 */
	private function adopt( UploadSession $s, string $bytes, int $held ): UploadSession {
		if ( $held > $s->offset + strlen( $bytes ) ) {
			throw self::outOfStep();
		}
		$prefix = substr( $bytes, 0, $held - $s->offset );
		if ( $held === $s->size ) {
			return $this->proof->prove( $s, UploadRecord::fold( $s ) ^ QuickXorHash::fold( $prefix, $s->offset ) );
		}

		return $this->moved( $s, $prefix, min( $s->chunk, $this->sizer->largest() ), '' );
	}

	/**
	 * The session after these bytes arrived.
	 *
	 * @param UploadSession $s      Session before.
	 * @param string        $bytes  Bytes OneDrive confirmed (from $s->offset).
	 * @param int           $chunk  Next fragment size.
	 * @param string        $itemId The file's id once complete ('' before).
	 */
	private function moved( UploadSession $s, string $bytes, int $chunk, string $itemId ): UploadSession {
		$offset = $s->offset + strlen( $bytes );
		$xor    = UploadRecord::fold( $s ) ^ QuickXorHash::fold( $bytes, $s->offset );

		return new UploadSession( $s->type, $s->name, $s->size, $offset, $chunk, $s->remote, UploadRecord::extra( $offset, $itemId, $xor ) );
	}

	/**
	 * Best effort: cancel a session whose bytes already are in this backup's
	 * file (DELETE uploadUrl without Authorization; any answer or failure is
	 * left alone — OneDrive drops it at expirationDateTime anyway).
	 *
	 * @param UploadSession $s Session of ours.
	 */
	private function cancel( UploadSession $s ): void {
		try {
			$this->http->request( 'DELETE', $s->remote, [], '', [ 'auth' => false ] );
		} catch ( CloudFailure $e ) { // @phpstan-ignore catch.neverThrown (OAuthHttp::request() lets a CloudFailure pass through, as its docblock says; WPCS allows it only the one @throws tag of its own throw)
			unset( $e ); // Only tidying; the upload is done.
		}
	}

	/**
	 * @param array<string, mixed> $json A status or fragment answer.
	 * @return int The start of the first expected range (-1: none readable).
	 */
	private static function expected( array $json ): int {
		$ranges = $json['nextExpectedRanges'] ?? null;
		$first  = is_array( $ranges ) ? ( $ranges[0] ?? null ) : null;

		return is_string( $first ) && 1 === preg_match( '/^(\d{1,15})-(?:\d{1,15})?\z/', $first, $m ) ? (int) $m[1] : -1;
	}

	private static function outOfStep(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( '%s answered this upload with a position it cannot have; the upload starts over.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::SESSION_GONE );
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer Answer.
	 * @param string                                                           $where  GraphErrors::API or UPLOAD.
	 */
	private function failure( array $answer, string $where ): CloudFailure {
		return GraphErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now(), $where );
	}
}
