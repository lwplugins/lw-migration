<?php
/**
 * What a backup contains.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\Codec;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in job options (JSON). The password is never part of it.
 */
final class ExportOptions {

	/** Flag in package.json => property. */
	public const FLAGS = [
		'NoSpamComments'    => 'noSpamComments',
		'NoPostRevisions'   => 'noPostRevisions',
		'NoMedia'           => 'noMedia',
		'NoThemes'          => 'noThemes',
		'NoInactiveThemes'  => 'noInactiveThemes',
		'NoMustUsePlugins'  => 'noMustUsePlugins',
		'NoPlugins'         => 'noPlugins',
		'NoInactivePlugins' => 'noInactivePlugins',
		'NoCache'           => 'noCache',
		'NoDatabase'        => 'noDatabase',
		'NoEmailReplace'    => 'noEmailReplace',
	];

	/** CLI flag => property; the names match the established .wpress command line. */
	public const CLI_FLAGS = [
		'exclude-spam-comments'    => 'noSpamComments',
		'exclude-post-revisions'   => 'noPostRevisions',
		'exclude-media'            => 'noMedia',
		'exclude-themes'           => 'noThemes',
		'exclude-inactive-themes'  => 'noInactiveThemes',
		'exclude-muplugins'        => 'noMustUsePlugins',
		'exclude-plugins'          => 'noPlugins',
		'exclude-inactive-plugins' => 'noInactivePlugins',
		'exclude-cache'            => 'noCache',
		'exclude-database'         => 'noDatabase',
		'exclude-email-replace'    => 'noEmailReplace',
	];

	/** @var bool Leave out spam comments. */
	public bool $noSpamComments = false;

	/** @var bool Leave out post revisions. */
	public bool $noPostRevisions = false;

	/** @var bool Leave out the uploads folder. */
	public bool $noMedia = false;

	/** @var bool Leave out all themes. */
	public bool $noThemes = false;

	/** @var bool Leave out inactive themes. */
	public bool $noInactiveThemes = false;

	/** @var bool Leave out must-use plugins. */
	public bool $noMustUsePlugins = false;

	/** @var bool Leave out all plugins. */
	public bool $noPlugins = false;

	/** @var bool Leave out inactive plugins. */
	public bool $noInactivePlugins = false;

	/** @var bool Leave out cache folders. */
	public bool $noCache = false;

	/** @var bool Leave out the database. */
	public bool $noDatabase = false;

	/** @var bool Do not replace e-mail domains on import. */
	public bool $noEmailReplace = false;

	/** @var bool Encrypt the archive (the password lives elsewhere). */
	public bool $encrypt = false;

	/** @var bool Database-only archive (the safety backup before an import): no files are listed. */
	public bool $noFiles = false;

	/** @var array<int, string> Paths relative to wp-content. */
	public array $excludeFiles = [];

	/** @var array<int, string> */
	public array $excludeTables = [];

	/** @var array<int, string> */
	public array $includeTables = [];

	/** @var array<int, array{0: string, 1: string}> Old => new pairs. */
	public array $replace = [];

	/** @var string Codec type: '', 'gzip' or 'bzip2'. */
	public string $compression = Codec::NONE;

	/** @var string 'backup', 'pre-import' or 'pre-reset' (the naming of a backup taken before an import or a reset). */
	public string $kind = 'backup';

	/** @var array<int, int> Chosen blog ids of a network backup; [] = the whole network (and always on a single site). */
	public array $sites = [];

	/**
	 * @param array<string, mixed> $a Saved options.
	 * @return self
	 * @throws \InvalidArgumentException On an unknown compression or a traversing path.
	 */
	public static function fromArray( array $a ): self {
		$o = new self();
		foreach ( self::FLAGS as $prop ) {
			$o->$prop = ! empty( $a[ $prop ] );
		}
		$o->excludeFiles  = array_map( [ self::class, 'relativePath' ], self::strings( $a['excludeFiles'] ?? [] ) );
		$o->excludeTables = self::strings( $a['excludeTables'] ?? [] );
		$o->includeTables = self::strings( $a['includeTables'] ?? [] );
		foreach ( (array) ( $a['replace'] ?? [] ) as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== (string) $pair[0] && '' !== (string) $pair[1] ) {
				$o->replace[] = [ (string) $pair[0], (string) $pair[1] ];
			}
		}
		$o->compression = self::compression( (string) ( $a['compression'] ?? '' ) );
		$o->encrypt     = ! empty( $a['encrypt'] );
		$o->noFiles     = ! empty( $a['noFiles'] );
		$o->kind        = in_array( $a['kind'] ?? '', [ 'pre-import', 'pre-reset' ], true ) ? (string) $a['kind'] : 'backup';
		$o->sites       = self::siteIds( $a['sites'] ?? [] );
		if ( $o->noFiles ) {
			$o->noMedia          = true;
			$o->noThemes         = true;
			$o->noPlugins        = true;
			$o->noMustUsePlugins = true;
		}

		return $o;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return get_object_vars( $this );
	}

	public static function databaseOnly(): self {
		return self::fromArray(
			[
				'noFiles' => true,
				'kind'    => 'pre-import',
			]
		);
	}

	/**
	 * Everything, named pre-reset-…: the backup taken before a reset.
	 */
	public static function beforeReset(): self {
		return self::fromArray( [ 'kind' => 'pre-reset' ] );
	}

	/**
	 * @param array<int, string>   $args  Positional arguments (replace pairs).
	 * @param array<string, mixed> $assoc Flags.
	 * @return self
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public static function fromCli( array $args, array $assoc ): self {
		$data = [];
		foreach ( self::CLI_FLAGS as $flag => $prop ) {
			$data[ $prop ] = self::cliFlag( $assoc[ $flag ] ?? false );
		}
		$data['excludeFiles']  = $assoc['exclude-files'] ?? [];
		$data['excludeTables'] = $assoc['exclude-tables'] ?? [];
		$data['includeTables'] = $assoc['include-tables'] ?? [];
		$data['compression']   = isset( $assoc['compression'] ) ? (string) $assoc['compression'] : '';
		$data['encrypt']       = array_key_exists( 'password', $assoc );
		if ( array_key_exists( 'sites', $assoc ) ) {
			$data['sites'] = self::cliSites( $assoc['sites'] );
		}
		if ( self::cliFlag( $assoc['replace'] ?? false ) ) {
			if ( [] === $args || 0 !== count( $args ) % 2 ) {
				throw new \InvalidArgumentException( __( 'Give --replace pairs of values: --replace <old> <new> [<old> <new>]...', 'lw-migration' ) );
			}
			if ( in_array( '', array_map( 'strval', $args ), true ) ) {
				throw new \InvalidArgumentException( __( 'A --replace value cannot be empty: give both the old and the new value.', 'lw-migration' ) );
			}
			$data['replace'] = array_chunk( array_map( 'strval', $args ), 2 );
		} elseif ( [] !== $args ) {
			throw new \InvalidArgumentException( __( 'Unexpected arguments. To replace values use --replace <old> <new>.', 'lw-migration' ) );
		}

		return self::fromArray( $data );
	}

	/**
	 * @param mixed $value Comma-separated string or list.
	 * @return array<int, string>
	 */
	private static function strings( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		return array_values( array_filter( array_map( static fn( $v ): string => trim( (string) $v ), (array) $value ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * Saved options hold a list (fromCli() and the REST input parse their own
	 * text); anything that is not a whole positive number is left out, so the
	 * callers refuse invalid ids before they get here.
	 *
	 * @param mixed $value Ids: a list of ints or digit strings (anything else gives none).
	 * @return array<int, int> Positive, unique, ascending.
	 */
	private static function siteIds( $value ): array {
		$ids = [];
		foreach ( is_array( $value ) ? $value : [] as $id ) {
			$text = is_int( $id ) ? (string) $id : ( is_string( $id ) ? trim( $id ) : '' );
			if ( 1 === preg_match( '/^\d{1,18}\z/', $text ) && (int) $text > 0 ) {
				$ids[] = (int) $text;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * --sites=<id>[,<id>...]: every part a whole positive number.
	 *
	 * @param mixed $value Flag value (true for a bare --sites).
	 * @return array<int, string>
	 * @throws \InvalidArgumentException Otherwise.
	 */
	private static function cliSites( $value ): array {
		$parts = is_string( $value ) ? array_map( 'trim', explode( ',', $value ) ) : [];
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( '/^\d{1,18}\z/', $part ) || 0 === (int) $part ) {
				$parts = [];
				break;
			}
		}
		if ( [] === $parts ) {
			throw new \InvalidArgumentException( __( 'Give the site IDs: --sites=<id>[,<id>...]', 'lw-migration' ) );
		}

		return $parts;
	}

	/**
	 * WP-CLI passes --flag as true and --flag=<value> as a string.
	 *
	 * @param mixed $value Flag value.
	 * @return bool
	 */
	private static function cliFlag( $value ): bool {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'false', 'no' ], true );
		}

		return ! empty( $value );
	}

	/**
	 * @param string $path Path relative to wp-content.
	 * @return string
	 * @throws \InvalidArgumentException When the path is empty, has a '.' segment or climbs out of wp-content.
	 */
	private static function relativePath( string $path ): string {
		$path     = trim( (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', $path ) ), '/' );
		$segments = explode( '/', $path );
		if ( '' === $path || in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) {
			/* translators: %s: path given by the user */
			throw new \InvalidArgumentException( sprintf( __( 'Excluded paths must name a folder or file inside wp-content: %s', 'lw-migration' ), $path ) );
		}

		return $path;
	}

	/**
	 * @param string $type Compression type.
	 * @return string
	 * @throws \InvalidArgumentException On an unknown type.
	 */
	private static function compression( string $type ): string {
		if ( ! in_array( $type, [ Codec::NONE, Codec::GZIP, Codec::BZIP2 ], true ) ) {
			/* translators: %s: compression type given by the user */
			throw new \InvalidArgumentException( sprintf( __( 'Unknown compression type: %s (use gzip or bzip2).', 'lw-migration' ), $type ) );
		}

		return $type;
	}
}
