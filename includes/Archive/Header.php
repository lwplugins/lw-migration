<?php
/**
 * The 4377-byte entry header.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Name a255 | size a14 | mtime a12 | path a4088 | crc32 a8 — NUL padded.
 */
final class Header {

	public const SIZE_FIELD_OFFSET = 255;
	public const CRC_FIELD_OFFSET  = 4369;

	private const PACK_FORMAT   = 'a255a14a12a4088a8';
	private const UNPACK_FORMAT = 'a255name/a14size/a12mtime/a4088path/a8crc';

	public static function pack( string $entryName, int $size, int $mtime, string $crc = '' ): string {
		if ( str_starts_with( str_replace( '\\', '/', $entryName ), '/' ) ) {
			throw new ArchiveException( sprintf( 'Entry names must be relative: %s', $entryName ) );
		}
		[ $dir, $base ] = self::split( $entryName );

		if ( '' === $base ) {
			throw new ArchiveException( sprintf( 'Empty file name in entry: %s', $entryName ) );
		}
		if ( strlen( $base ) > Format::NAME_MAX ) {
			throw new ArchiveException( sprintf( 'File name longer than %d bytes: %s', Format::NAME_MAX, $entryName ) );
		}
		if ( strlen( $dir ) > Format::PATH_MAX ) {
			throw new ArchiveException( sprintf( 'Directory path longer than %d bytes: %s', Format::PATH_MAX, $entryName ) );
		}
		if ( $size < 0 || $mtime < 0 ) {
			throw new ArchiveException( sprintf( 'Negative size or time for entry: %s', $entryName ) );
		}
		if ( '' !== $crc && ! self::isCrc( $crc ) ) {
			throw new ArchiveException( sprintf( 'Invalid CRC for entry: %s', $entryName ) );
		}

		return pack( self::PACK_FORMAT, $base, (string) $size, (string) $mtime, $dir, strtolower( $crc ) );
	}

	/**
	 * Splits an entry name into [directory or '.', basename].
	 *
	 * @param string $entryName Archive path.
	 * @return array{0: string, 1: string}
	 */
	public static function split( string $entryName ): array {
		$name = str_replace( '\\', '/', $entryName );
		$pos  = strrpos( $name, '/' );

		if ( false === $pos ) {
			return [ '.', $name ];
		}

		return [ substr( $name, 0, $pos ), substr( $name, $pos + 1 ) ];
	}

	public static function unpack( string $block, int $headerOffset ): Entry {
		if ( Format::HEADER_SIZE !== strlen( $block ) ) {
			throw new InvalidArchiveException( sprintf( 'Truncated entry header at byte %d.', $headerOffset ) );
		}

		$data  = (array) unpack( self::UNPACK_FORMAT, $block );
		$name  = rtrim( (string) $data['name'], "\0" );
		$path  = rtrim( (string) $data['path'], "\0" );
		$size  = trim( (string) $data['size'], "\0 " );
		$mtime = trim( (string) $data['mtime'], "\0 " );
		$crc   = strtolower( rtrim( (string) $data['crc'], "\0" ) );

		$valid = '' !== $name
			&& ctype_digit( $size )
			&& ( '' === $mtime || ctype_digit( $mtime ) )
			&& ( '' === $crc || self::isCrc( $crc ) );

		if ( ! $valid ) {
			throw new InvalidArchiveException( sprintf( 'Invalid entry header at byte %d.', $headerOffset ) );
		}

		$full = ( '.' === $path || '' === $path ) ? $name : $path . '/' . $name;

		return new Entry( $full, (int) $size, (int) $mtime, $crc, $headerOffset );
	}

	private static function isCrc( string $crc ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}$/i', $crc );
	}
}
