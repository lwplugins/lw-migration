<?php
/**
 * Whether the site folder's file of a backup's name is that backup.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * E11 + M7b H13 (Task 10 review m5): after a name conflict on the last
 * fragment, or a session gone after its last fragment was sent (a lost
 * final answer), the file of the name is this backup only when it has the
 * backup's size and quickXorHash (the one hash documented for both OneDrive
 * for home and for work or school). quickXorHash is not cryptographic: it
 * tells this site's own upload from an unrelated file, not from one made to
 * collide (Task 13 docs note). No file starts over; any other file is never
 * taken or replaced.
 */
final class SameBackup {

	public function __construct( private OAuthHttp $http, private string $folder ) {
	}

	/**
	 * @param UploadSession $s    Session (any offset).
	 * @param string        $fold QuickXorHash fold of all the backup's bytes.
	 * @return UploadSession The session, complete with the file's id.
	 * @throws PermanentFailure SESSION_GONE when no file has the name; INVALID when another file has it (OneDrive's refusal of the lookup, a CloudFailure, passes through).
	 */
	public function prove( UploadSession $s, string $fold ): UploadSession {
		$answer = $this->http->request( 'GET', OneDriveDestination::filePath( $this->folder, $s->name ) . '?$select=id,name,size,file' );
		if ( 404 === $answer['status'] ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s no longer knows this upload; it starts over.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::SESSION_GONE, 404 );
		}
		if ( 200 !== $answer['status'] ) {
			$failure = GraphErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now() );
			throw $failure;
		}
		$item = $this->http->json( $answer );
		$id   = $item['id'] ?? null;
		$hash = is_array( $item['file'] ?? null ) && is_array( $item['file']['hashes'] ?? null ) ? ( $item['file']['hashes']['quickXorHash'] ?? null ) : null;
		if ( ! is_string( $id ) || 1 !== preg_match( OneDriveDestination::ID_PATTERN, $id ) || ( $item['size'] ?? null ) !== $s->size || ! is_string( $hash ) || ! hash_equals( QuickXorHash::digest( $fold, $s->size ), $hash ) ) {
			$failure = self::taken();
			throw $failure;
		}

		return new UploadSession( $s->type, $s->name, $s->size, $s->size, $s->chunk, $s->remote, UploadRecord::extra( $s->size, $id, $fold ) );
	}

	public static function taken(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( 'Another file with this backup\'s name is already in this site\'s folder at %s; it was not replaced.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::INVALID, 409 );
	}
}
