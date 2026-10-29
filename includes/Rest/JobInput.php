<?php
/**
 * Checks what the browser sends.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Allowlists, never blocklists: only the named keys of the exact JSON type and
 * within the size limits get through; an unknown key, a loosely typed value
 * ("0", "false", 1, null for a boolean) or an oversized one is a 400. A server
 * path (the CLI's FILE source) is never accepted, the safety backup's naming
 * (`kind`) and the database-only switch (`noFiles`) are never chosen by the
 * browser, and an answer must be the one the job asked for. Every refusal is a
 * RestException with our translated text (code ErrorMap::INVALID, the field in
 * data['field']); the InvalidArgumentException of ExportOptions/ImportOptions
 * is wrapped, never passed on.
 */
final class JobInput {

	private const MAX_TEXT = 1024;

	private const MAX_URL = 4096;

	private const MAX_LIST = 500;

	private const MAX_PAIRS = 100;

	private const LISTS = [ 'excludeFiles', 'excludeTables', 'includeTables' ];

	/** Fields of the job request that JobsApi checks itself; they may sit next to an import source. */
	private const ENVELOPE = [ 'type', 'password' ];

	/** The import sources the browser may use, with the keys each takes besides `source` and `safetyBackup`. */
	private const SOURCES = [
		ImportOptions::UPLOAD => [ 'name', 'size' ],
		ImportOptions::BACKUP => [ 'name', 'folder' ],
		ImportOptions::URL    => [ 'url' ],
	];

	/**
	 * @param mixed $raw The "options" object of an export request.
	 * @throws RestException On an unknown key or a value of the wrong type or size.
	 */
	public static function exportOptions( $raw ): ExportOptions {
		if ( ! is_array( $raw ) ) {
			throw RestException::invalidField( 'options' );
		}
		$flags = array_merge( array_values( ExportOptions::FLAGS ), [ 'encrypt' ] );
		self::onlyKeys( $raw, array_merge( $flags, self::LISTS, [ 'replace', 'compression', 'sites' ] ), 'options' );
		$clean = [];
		foreach ( $flags as $prop ) {
			$clean[ $prop ] = self::flag( self::field( $raw, $prop, false ), $prop );
		}
		foreach ( self::LISTS as $key ) {
			$clean[ $key ] = self::texts( self::field( $raw, $key, [] ), $key );
		}
		$clean['replace']     = self::pairs( self::field( $raw, 'replace', [] ) );
		$clean['compression'] = self::text( self::field( $raw, 'compression', '' ), 'compression', self::MAX_TEXT );
		$clean['sites']       = NetworkInput::siteIds( self::field( $raw, 'sites', [] ) ); // Refused here, never dropped by fromArray().

		return self::checked( static fn(): ExportOptions => ExportOptions::fromArray( $clean ) );
	}

	/**
	 * The options of an export request, which holds nothing but the envelope, `options`
	 * and the cloud keys (ExportCloudInput).
	 *
	 * @param array<string, mixed> $body Export request.
	 * @throws RestException On an unknown key, or options refused by exportOptions().
	 */
	public static function exportRequest( array $body ): ExportOptions {
		self::onlyKeys( $body, array_merge( self::ENVELOPE, [ 'options' ], ExportCloudInput::KEYS ), 'type' );

		return self::exportOptions( self::field( $body, 'options', [] ) );
	}

	/**
	 * A non-negative whole number from JSON or a query string (at most 18 digits).
	 *
	 * @param mixed  $value The value.
	 * @param string $field Field name for the refusal.
	 * @throws RestException Unless a non-negative whole number.
	 */
	public static function wholeNumber( $value, string $field ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,18}\z/', $value ) ) {
			return (int) $value;
		}
		throw RestException::invalidField( $field );
	}

	/**
	 * @param mixed $raw Password field (never trimmed).
	 * @throws RestException On a non-string, an overlong value or a NUL byte.
	 */
	public static function password( $raw ): ?string {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_TEXT || str_contains( $raw, "\0" ) ) {
			throw RestException::invalidField( 'password' );
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $body    Import request.
	 * @param Repository           $backups Backups.
	 * @param int                  $room    Most bytes an upload may declare (WebLimits::uploadRoom()).
	 * @return array{options: ImportOptions, state: array<string, mixed>}
	 * @throws RestException On an unknown source or key, a missing backup, a bad upload or address.
	 */
	public static function importSource( array $body, Repository $backups, int $room ): array {
		$source = self::field( $body, 'source', '' );
		if ( ! is_string( $source ) || ! isset( self::SOURCES[ $source ] ) ) {
			throw RestException::invalid( __( 'Choose a file to upload, an existing backup or an address to import from.', 'lw-migration' ), 'source' );
		}
		self::onlyKeys( $body, array_merge( self::ENVELOPE, [ 'source', 'safetyBackup' ], self::SOURCES[ $source ] ), 'source' );
		$safety = self::flag( self::field( $body, 'safetyBackup', true ), 'safetyBackup' );
		if ( ImportOptions::UPLOAD === $source ) {
			return self::upload( $body, $safety, $room );
		}
		if ( ImportOptions::BACKUP === $source ) {
			return self::backup( $body, $safety, $backups );
		}
		$url = self::text( self::field( $body, 'url', '' ), 'url', self::MAX_URL );

		return [
			'options' => self::checked(
				static fn(): ImportOptions => ImportOptions::fromArray(
					[
						'source'       => ImportOptions::URL,
						'location'     => $url,
						'safetyBackup' => $safety,
					]
				),
				'url'
			),
			'state'   => [],
		];
	}

	/**
	 * Exactly the answer the waiting job asked for.
	 *
	 * @param Job                  $job  Job waiting for input.
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed> Input for Runner::provideInput().
	 * @throws RestException When the answer is not the one asked for, or malformed.
	 */
	public static function answer( Job $job, array $body ): array {
		$kind = Job::NEEDS_INPUT === $job->status ? ( $job->input['kind'] ?? '' ) : '';
		$keys = array_keys( $body );
		if ( 'password' === $kind && [ 'password' ] === $keys ) {
			return [ 'password' => self::givenPassword( $body['password'] ) ];
		}
		$sites = array_key_exists( 'sites', $body ); // The new addresses of an archive's sites: on a network only.
		if ( 'confirm' === $kind && is_bool( $body['confirm'] ?? null ) && [] === array_diff( $keys, [ 'confirm', 'safetyBackup', 'sites' ] ) && ( ! $sites || is_multisite() ) ) {
			$answer = [ 'confirm' => $body['confirm'] ];
			if ( array_key_exists( 'safetyBackup', $body ) ) {
				$answer['safetyBackup'] = self::flag( $body['safetyBackup'], 'safetyBackup' );
			}
			if ( $sites ) {
				$answer['sites'] = NetworkInput::siteAddresses( $body['sites'] );
			}

			return $answer;
		}
		throw new RestException( 'lw_migration_unexpected_input', __( 'The job is not waiting for this answer. Reload the page.', 'lw-migration' ), 409 );
	}

	/**
	 * @param mixed $raw Password answer.
	 * @throws RestException Unless a non-empty password.
	 */
	private static function givenPassword( $raw ): string {
		$password = self::password( $raw );
		if ( null === $password ) {
			throw RestException::invalidField( 'password' );
		}

		return $password;
	}

	/**
	 * @param array<string, mixed> $body   Upload request.
	 * @param bool                 $safety Safety backup choice.
	 * @param int                  $room   Most bytes allowed.
	 * @return array{options: ImportOptions, state: array<string, mixed>}
	 * @throws RestException On a bad name or size.
	 */
	private static function upload( array $body, bool $safety, int $room ): array {
		$name = self::text( self::field( $body, 'name', '' ), 'name', self::MAX_TEXT );
		$size = self::field( $body, 'size', null );
		if ( ! BackupName::isValid( $name ) ) {
			throw RestException::invalid( __( 'Choose a .wpress file.', 'lw-migration' ), 'name' );
		}
		if ( ! is_int( $size ) || $size < 2 * Format::HEADER_SIZE ) {
			throw RestException::invalid( __( 'This file is too small to be a .wpress archive.', 'lw-migration' ), 'size' );
		}
		if ( $size > $room ) {
			/* translators: %s: file size */
			throw new RestException( 'lw_migration_no_room', sprintf( __( 'There is not enough free disk space for this %s upload.', 'lw-migration' ), size_format( $size ) ), 400 );
		}

		return [
			'options' => new ImportOptions( ImportOptions::UPLOAD, $name, $safety ),
			'state'   => [ 'upload' => [ 'size' => $size ] ],
		];
	}

	/**
	 * @param array<string, mixed> $body    Backup request.
	 * @param bool                 $safety  Safety backup choice.
	 * @param Repository           $backups Backups.
	 * @return array{options: ImportOptions, state: array<string, mixed>}
	 * @throws RestException On a malformed value (400) or a backup that is not there (404).
	 */
	private static function backup( array $body, bool $safety, Repository $backups ): array {
		$name   = self::text( self::field( $body, 'name', '' ), 'name', self::MAX_TEXT );
		$folder = self::text( self::field( $body, 'folder', Backup::OWN ), 'folder', self::MAX_TEXT );
		if ( null === $backups->findIn( $name, $folder ) ) {
			throw RestException::backupMissing();
		}

		return [
			'options' => new ImportOptions( ImportOptions::BACKUP, $name, $safety, $folder ),
			'state'   => [],
		];
	}

	/**
	 * The value of a key that is present (even when null), else the default.
	 *
	 * @param array<array-key, mixed> $data     Request object.
	 * @param string                  $key      Key.
	 * @param mixed                   $fallback Value for a missing key.
	 * @return mixed
	 */
	private static function field( array $data, string $key, $fallback ) {
		return array_key_exists( $key, $data ) ? $data[ $key ] : $fallback;
	}

	/**
	 * @param array<array-key, mixed> $data    Request object.
	 * @param array<int, string>      $allowed Keys it may have.
	 * @param string                  $field   Name of the object for the message.
	 * @throws RestException On any other key.
	 */
	private static function onlyKeys( array $data, array $allowed, string $field ): void {
		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw RestException::invalidField( $field );
			}
		}
	}

	/**
	 * @param mixed  $value Value.
	 * @param string $field Field name for the message.
	 * @throws RestException Unless a boolean.
	 */
	private static function flag( $value, string $field ): bool {
		if ( ! is_bool( $value ) ) {
			throw RestException::invalidField( $field );
		}

		return $value;
	}

	/**
	 * @param mixed  $value Value.
	 * @param string $field Field name for the message.
	 * @param int    $max   Byte limit.
	 * @throws RestException Unless a string within the limit without control characters.
	 */
	private static function text( $value, string $field, int $max ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $max || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw RestException::invalidField( $field );
		}

		return $value;
	}

	/**
	 * @param mixed  $value Value.
	 * @param string $field Field name for the message.
	 * @return array<int, string> Entries that are not blank (an empty input box sends one).
	 * @throws RestException Unless a list of strings within the limits.
	 */
	private static function texts( $value, string $field ): array {
		if ( ! self::isList( $value ) || count( $value ) > self::MAX_LIST ) {
			throw RestException::invalidField( $field );
		}
		$texts = [];
		foreach ( $value as $item ) {
			$text = self::text( $item, $field, self::MAX_TEXT );
			if ( '' !== trim( $text ) ) {
				$texts[] = $text;
			}
		}

		return $texts;
	}

	/**
	 * A blank side is refused (R15): ExportOptions would drop the pair silently.
	 *
	 * @param mixed $value Value.
	 * @return array<int, array{0: string, 1: string}> The pairs, values untrimmed.
	 * @throws RestException Unless a list of [old, new] non-blank string pairs within the limits.
	 */
	private static function pairs( $value ): array {
		if ( ! self::isList( $value ) || count( $value ) > self::MAX_PAIRS ) {
			throw RestException::invalidField( 'replace' );
		}
		$pairs = [];
		foreach ( $value as $pair ) {
			if ( ! is_array( $pair ) || [ 0, 1 ] !== array_keys( $pair ) ) {
				throw RestException::invalidField( 'replace' );
			}
			$old = self::text( $pair[0], 'replace', self::MAX_TEXT );
			$new = self::text( $pair[1], 'replace', self::MAX_TEXT );
			if ( '' === trim( $old ) || '' === trim( $new ) ) {
				throw RestException::invalidField( 'replace' );
			}
			$pairs[] = [ $old, $new ];
		}

		return $pairs;
	}

	/**
	 * A JSON array (keys 0..n-1), not an object.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true array<int, mixed> $value
	 */
	private static function isList( $value ): bool {
		return is_array( $value ) && array_keys( $value ) === array_keys( array_values( $value ) );
	}

	/**
	 * Runs an options or job constructor and turns its refusal into ours,
	 * keeping its translated text: an InvalidArgumentException of the options
	 * and an ExportException/ImportException of ExportJobs/ImportJobs::create
	 * are ours and translated.
	 *
	 * @template T
	 * @param \Closure    $build Constructor call.
	 * @param string|null $field The request field the value came from, when there is one.
	 * @phpstan-param \Closure(): T $build
	 * @return T
	 * @throws RestException When the constructor refuses the value.
	 */
	public static function checked( \Closure $build, ?string $field = null ) {
		try {
			return $build();
		} catch ( \InvalidArgumentException | ExportException | ImportException $e ) {
			throw RestException::invalid( $e->getMessage(), $field );
		}
	}
}
