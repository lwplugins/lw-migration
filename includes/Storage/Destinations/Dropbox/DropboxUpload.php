<?php
/**
 * Dropbox's upload session.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Dropbox;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E9–E11, E22 with preflight P-I3 and M7b's never-overwrite rule
 * (upload_session/start, append_v2 and finish, read 2026-10-14): start
 * opens an empty session, every part is one append_v2 at the session's
 * offset (a 4 MiB multiple growing to 128 MiB, under the 150 MiB a request
 * may carry; the last part is what is left) with the content_hash of
 * its bytes (a part damaged in transit is refused and sent again), finish commits it as
 * /<site>/<name> in the app folder with mode add and no renaming, so an existing
 * file is never replaced. The session keeps the joined block digests of
 * what Dropbox confirmed (`extra.blocks`, base64; ContentHash), so the
 * file's content_hash is known without a second read.
 *
 * An append whose answer got lost is answered next time with
 * incorrect_offset and the offset Dropbox holds: it is adopted only between
 * what was confirmed and what was just sent, on a block boundary or at the
 * end (the digests of that prefix are in hand); anything else starts over
 * (SESSION_GONE). A finish whose answer got lost (the session is closed),
 * or a later upload of the same backup that finds its name taken, is this
 * backup only when the file there has the backup's size and content hash;
 * any other file is never replaced (INVALID). A stored session of another
 * type, session id, chunk rule, offset or digest count starts over before
 * any request. Sessions last 7 days; no cancel call is documented.
 */
final class DropboxUpload {

	/** Largest part (E10; a request may carry 150 MiB). */
	public const MAX = 134217728;

	public const SESSION_PATTERN = '/^[A-Za-z0-9:_-]{1,256}\z/';

	/** Slowest rate a part is given time for (bytes a second, P-M2). */
	private const RATE = 262144;

	public function __construct( private DropboxApi $api, private ChunkSizer $sizer, private string $folder ) {
	}

	/**
	 * @param string $name Backup or test file name.
	 * @param int    $size Bytes.
	 * @throws PermanentFailure INVALID for an answer without a session id of Dropbox's shape (Dropbox's refusal, a CloudFailure, passes through).
	 */
	public function start( string $name, int $size ): UploadSession {
		$answer = $this->api->content( 'files/upload_session/start', [ 'close' => false ] );
		if ( 200 !== $answer['status'] ) {
			throw $this->failure( $answer, false );
		}
		$id = $this->api->http()->json( $answer )['session_id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( self::SESSION_PATTERN, $id ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s answered without an upload session.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::INVALID );
		}

		return new UploadSession( DropboxDestination::TYPE, $name, $size, 0, $this->sizer->first(), $id, [ 'blocks' => '' ] );
	}

	/**
	 * @param UploadSession $s     Session of ours.
	 * @param string        $bytes The next part (exactly nextLength()).
	 * @throws PermanentFailure INVALID for another length (nothing sent), SESSION_GONE for a session not ours, gone, or answered out of step (Dropbox's refusal, a CloudFailure, passes through).
	 */
	public function chunk( UploadSession $s, string $bytes ): UploadSession {
		$this->assertOwn( $s );
		$length = strlen( $bytes );
		if ( $length !== $s->nextLength() || $length < 1 ) {
			throw new PermanentFailure( __( 'A part of the backup had an unexpected length; it was not sent.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$given = $bytes; // What the step handed in: a lost earlier append may hold up to all of it (Task 9 m1).
		if ( $length > $this->sizer->largest() ) { // Stored by a process with more free memory (P-M3).
			$length = $this->sizer->largest();
			$bytes  = substr( $bytes, 0, $length );
		}
		$started = $this->sizer->clock();
		$answer  = $this->api->content(
			'files/upload_session/append_v2',
			[
				'cursor'       => self::cursor( $s, $s->offset ),
				'close'        => false,
				'content_hash' => ContentHash::of( ContentHash::blocks( $bytes ) ),
			],
			$bytes,
			[],
			[ 'timeout' => CloudTimeout::forBytes( $length, self::RATE ) ]
		);
		if ( 200 === $answer['status'] ) {
			return $this->moved( $s, $bytes, $this->sizer->next( $length, $started ) );
		}
		$error = $this->api->http()->json( $answer )['error'] ?? null;
		$held  = is_array( $error ) && 'incorrect_offset' === ( $error['.tag'] ?? null ) ? ( $error['correct_offset'] ?? null ) : null;
		if ( 409 === $answer['status'] && is_int( $held ) ) {
			return $this->adopt( $s, $given, $held );
		}

		throw $this->failure( $answer, true );
	}

	/**
	 * @param UploadSession $s A complete session of ours.
	 * @throws \LogicException When it is not complete.
	 * @throws PermanentFailure SESSION_GONE for a session not ours or gone; INVALID when Dropbox does not confirm the file or another file has the name.
	 * @throws CloudFailure When Dropbox refuses otherwise.
	 */
	public function finish( UploadSession $s ): RemoteFile {
		$this->assertOwn( $s );
		if ( ! $s->isComplete() ) {
			throw new \LogicException( 'Only a complete upload is finished.' );
		}
		$answer = $this->api->content(
			'files/upload_session/finish',
			[
				'cursor' => self::cursor( $s, $s->size ),
				'commit' => [
					'path'       => '/' . $this->folder . '/' . $s->name,
					'mode'       => 'add',
					'autorename' => false,
					'mute'       => true,
				],
			]
		);
		if ( 200 === $answer['status'] ) {
			$file = DropboxDestination::file( $this->api->http()->json( $answer ), $this->folder );
			if ( null === $file || $file->name !== $s->name || $file->size !== $s->size ) {
				/* translators: %s: provider name */
				throw new PermanentFailure( sprintf( __( '%s did not confirm the uploaded file.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::INVALID );
			}
			return $file;
		}
		$tags = DropboxErrors::tags( $answer['body'] );
		if ( 409 === $answer['status'] && ( [ 'lookup_failed', 'closed' ] === array_slice( $tags, 0, 2 ) || [ 'path', 'conflict', 'file' ] === array_slice( $tags, 0, 3 ) ) ) {
			return $this->existing( $s );
		}
		if ( 409 === $answer['status'] && [ 'path', 'conflict' ] === array_slice( $tags, 0, 2 ) ) {
			throw self::taken();
		}

		throw $this->failure( $answer, true );
	}

	/**
	 * H6 / P-I3: a stored session of another type, session id, chunk rule,
	 * name, offset (off a block boundary before the end) or digest count
	 * starts over before any request.
	 *
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is not one of ours.
	 */
	public function assertOwn( UploadSession $s ): void {
		$blocks = [ 'blocks' ] === array_keys( $s->extra ) && is_string( $s->extra['blocks'] ) ? base64_decode( $s->extra['blocks'], true ) : false;
		$fits   = false !== $blocks && strlen( $blocks ) === 32 * ContentHash::count( $s->offset ) && ( 0 === $s->offset % ContentHash::BLOCK || $s->isComplete() );
		if ( DropboxDestination::TYPE !== $s->type || ! DropboxDestination::isFileName( $s->name ) || 1 !== preg_match( self::SESSION_PATTERN, $s->remote ) || $s->chunk < 1 || 0 !== $s->chunk % ContentHash::BLOCK || $s->chunk > self::MAX || ! $fits ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::SESSION_GONE );
		}
	}

	/**
	 * The session after these bytes arrived.
	 *
	 * @param UploadSession $s     Session before.
	 * @param string        $bytes Bytes Dropbox confirmed (from $s->offset).
	 * @param int           $chunk Next part size.
	 */
	private function moved( UploadSession $s, string $bytes, int $chunk ): UploadSession {
		$blocks = (string) base64_decode( (string) $s->extra['blocks'], true ) . ContentHash::blocks( $bytes );

		return new UploadSession( $s->type, $s->name, $s->size, $s->offset + strlen( $bytes ), $chunk, $s->remote, [ 'blocks' => base64_encode( $blocks ) ] );
	}

	/**
	 * Dropbox answered incorrect_offset: it holds $held bytes. Adopted only past what
	 * it had confirmed and up to the part the step handed in (this request
	 * may have sent less of it under the memory cap, an earlier one all of
	 * it), on a block boundary or at the end — the bytes up to there are in
	 * hand (P-I3: never past offset + chunk).
	 *
	 * @param UploadSession $s     Session before.
	 * @param string        $bytes The part the step handed in (from $s->offset).
	 * @param int           $held  Dropbox's offset.
	 * @throws PermanentFailure SESSION_GONE for any other offset.
	 */
	private function adopt( UploadSession $s, string $bytes, int $held ): UploadSession {
		$prefix = $held - $s->offset;
		if ( $prefix < 1 || $prefix > strlen( $bytes ) || ( 0 !== $held % ContentHash::BLOCK && $held !== $s->size ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s answered this upload with a position it cannot have; the upload starts over.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::SESSION_GONE, 409 );
		}

		return $this->moved( $s, substr( $bytes, 0, $prefix ), min( $s->chunk, $this->sizer->largest() ) );
	}

	/**
	 * The file of the backup's name: this backup only with its size and content hash.
	 *
	 * @param UploadSession $s A complete session of ours.
	 * @throws PermanentFailure SESSION_GONE when no file has the name; INVALID when another file has it.
	 * @throws CloudFailure When Dropbox refuses the lookup.
	 */
	private function existing( UploadSession $s ): RemoteFile {
		$answer = $this->api->rpc( 'files/get_metadata', [ 'path' => '/' . $this->folder . '/' . $s->name ] );
		if ( 409 === $answer['status'] && in_array( 'not_found', DropboxErrors::tags( $answer['body'] ), true ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s no longer knows this upload; it starts over.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::SESSION_GONE, 409 );
		}
		if ( 200 !== $answer['status'] ) {
			throw $this->failure( $answer, false );
		}
		$meta = $this->api->http()->json( $answer );
		$file = 'file' === ( $meta['.tag'] ?? null ) ? DropboxDestination::file( $meta, $this->folder ) : null;
		$hash = $meta['content_hash'] ?? null;
		$mine = ContentHash::of( (string) base64_decode( (string) $s->extra['blocks'], true ) );
		if ( null === $file || $file->size !== $s->size || ! is_string( $hash ) || ! hash_equals( $mine, $hash ) ) {
			throw self::taken();
		}

		return $file;
	}

	private static function taken(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( 'Another file with this backup\'s name is already in this site\'s folder at %s; it was not replaced.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::INVALID, 409 );
	}

	/**
	 * @param UploadSession $s      Session.
	 * @param int           $offset Offset the request starts at.
	 * @return array{session_id: string, offset: int}
	 */
	private static function cursor( UploadSession $s, int $offset ): array {
		return [
			'session_id' => $s->remote,
			'offset'     => $offset,
		];
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer  Answer.
	 * @param bool                                                             $session About the upload session.
	 */
	private function failure( array $answer, bool $session ): CloudFailure {
		return DropboxErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->api->http()->now(), $session );
	}
}
