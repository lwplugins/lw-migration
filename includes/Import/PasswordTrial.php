<?php
/**
 * Checks the password of an encrypted archive that carries no signature.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\Crc32;
use LightweightPlugins\Migration\Archive\Entry;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\NoDeadline;

defined( 'ABSPATH' ) || exit;

/**
 * Without a signature any password passes the decrypt step, so the first step
 * that writes files tries it on the archive first: the first chunk of
 * database.sql must decrypt (and decompress) to the start of an SQL dump (or
 * match the entry's checksum when it is that short); without a database the
 * first small entry with a checksum (else the first one with a checksum) is
 * extracted into the work folder and must match it. A wrong password is asked again before anything is written. The result
 * is kept in state.passwordTrial until the password is asked again. The trial
 * file may use the room left in the import's write limit, but does not use it
 * up (it is removed again).
 */
final class PasswordTrial {

	public const STATE = 'passwordTrial';

	/** Stored bytes above which an entry is tried only when no smaller one carries a checksum. */
	public const TRIAL_MAX_BYTES = 1048576;

	/** Work file of the no-database trial (removed again). */
	private const TRIAL_FILE = 'password-trial';

	/** How an SQL dump can start: a comment or a statement. */
	private const DUMP_START = '/^(?:--|\/\*|#|(?:SET|CREATE|DROP|INSERT|START|USE|LOCK|UNLOCK|ALTER|BEGIN|DELIMITER)\b)/i';

	/**
	 * Asks for the password and forgets an earlier trial (the next password is tried again).
	 *
	 * @param Job    $job    Import job.
	 * @param string $reason 'encrypted' (none known) or 'wrong'.
	 */
	public static function ask( Job $job, string $reason ): StepResult {
		unset( $job->state[ self::STATE ] );

		return StepResult::needsInput( 'password', [ 'reason' => $reason ] );
	}

	/**
	 * @param Job         $job     Import job.
	 * @param ChunkCodec  $codec   Codec built from the current password.
	 * @param string      $workDir The job's work folder.
	 * @param WriteBudget $budget  The import's write limit (not used up by the trial).
	 * @return bool False when the password is wrong.
	 * @throws ImportException When the archive cannot be read, the trial file cannot be written or the write limit is reached.
	 */
	public static function passes( Job $job, ChunkCodec $codec, string $workDir, WriteBudget $budget ): bool {
		$package = ImportState::package( $job );
		if ( ! $package->isEncrypted() || '' !== $package->encryptedSignature() || ! empty( $job->state[ self::STATE ] ) ) {
			return true;
		}
		if ( ! self::trial( ImportState::archive( $job ), $codec, $workDir, $budget ) ) {
			return false;
		}
		$job->state[ self::STATE ] = true;

		return true;
	}

	/**
	 * @param string      $archive Archive.
	 * @param ChunkCodec  $codec   Codec under trial.
	 * @param string      $workDir The job's work folder.
	 * @param WriteBudget $budget  The import's write limit (not used up by the trial).
	 * @throws ImportException When the archive cannot be read, the work folder cannot be written or the write limit is reached.
	 */
	private static function trial( string $archive, ChunkCodec $codec, string $workDir, WriteBudget $budget ): bool {
		$reader = new Reader( $archive, $codec );
		try {
			[ $database, $first ] = self::candidates( $reader );
			if ( null !== $database ) {
				return self::dumpStarts( $archive, $database, $codec );
			}

			return null === $first || self::extracts( $reader, $first, $workDir, WriteBudget::fromArray( $budget->toArray() ) );
		} catch ( WriteLimitException $e ) {
			throw new ImportException( ExtractBudget::stopMessage( $budget ), 0, $e );
		} catch ( CorruptedEntryException $e ) {
			return false;
		} catch ( ArchiveException $e ) {
			// The original message names server paths (work folder, archive).
			throw new ImportException( __( 'Could not check the archive password: the job\'s work folder could not be written or the archive could not be read.', 'lw-migration' ), 0, $e );
		} finally {
			$reader->close();
		}
	}

	/**
	 * Walks the headers (no data is read). The no-database trial extracts one
	 * entry, so a small one is preferred: a browser tick must not extract a
	 * multi-gigabyte file to test a password.
	 *
	 * @internal Public for tests.
	 * @param Reader $reader Archive.
	 * @return array{0: Entry|null, 1: Entry|null} database.sql (when not empty) and the entry to try without one.
	 */
	public static function candidates( Reader $reader ): array {
		$first = null;
		$small = null;
		foreach ( $reader->entries() as $entry ) {
			if ( $entry->isConfig() || 0 === $entry->size ) {
				continue;
			}
			if ( Format::DATABASE === $entry->name ) {
				return [ $entry, $small ?? $first ];
			}
			if ( '' === $entry->crc ) {
				continue;
			}
			$first = $first ?? $entry;
			if ( null === $small && $entry->size <= self::TRIAL_MAX_BYTES ) {
				$small = $entry;
			}
		}

		return [ null, $small ?? $first ];
	}

	/**
	 * @param string     $archive  Archive.
	 * @param Entry      $database database.sql.
	 * @param ChunkCodec $codec    Codec under trial.
	 * @throws ArchiveException When the archive cannot be read, or (CorruptedEntryException) the chunk does not decrypt or decompress.
	 */
	private static function dumpStarts( string $archive, Entry $database, ChunkCodec $codec ): bool {
		$handle = fopen( $archive, 'rb' );
		if ( false === $handle ) {
			throw new ArchiveException( 'Cannot open the archive.' );
		}
		try {
			if ( 0 !== fseek( $handle, $database->dataOffset() ) ) {
				throw new ArchiveException( 'Cannot seek in the archive.' );
			}
			[ $data, $used ] = $codec->decodeNext( $handle, $database->size );
		} finally {
			fclose( $handle );
		}
		if ( $used === $database->size && '' !== $database->crc ) {
			return Crc32::ofString( $data ) === $database->crc;
		}

		return 1 === preg_match( self::DUMP_START, ltrim( $data, "\xEF\xBB\xBF \t\r\n" ) );
	}

	/**
	 * @param Reader      $reader  Archive.
	 * @param Entry       $entry   Entry with a checksum.
	 * @param string      $workDir The job's work folder.
	 * @param WriteBudget $room    A copy of the import's write limit.
	 * @throws CorruptedEntryException When the entry does not decrypt, decompress or match its checksum.
	 * @throws WriteLimitException When the entry does not fit in the room left.
	 */
	private static function extracts( Reader $reader, Entry $entry, string $workDir, WriteBudget $room ): bool {
		$target = rtrim( $workDir, '/' ) . '/' . self::TRIAL_FILE;
		try {
			return $reader->extractTo( $entry, $target, new ExtractCursor(), new NoDeadline(), $room );
		} finally {
			Files::removeFile( $target ); // Quietly: the work folder is removed with the job.
		}
	}
}
