<?php
/**
 * Adapts CREATE TABLE statements to the destination server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Up-front rewrites (column types, options, comments, table options, foreign
 * keys, collations) plus targeted retries for specific server errors.
 */
final class SchemaFixer {

	/** MariaDB versions that support a type natively. */
	private const NATIVE_SINCE = [
		'INET4'   => '10.10.0',
		'INET6'   => '10.5.0',
		'UUID'    => '10.7.0',
		'XMLTYPE' => '12.3.0',
		'VECTOR'  => '11.7.1',
	];

	private const TYPES = [
		'INET4'   => [ '/(?<!`)\bINET4\b(?!\s*\()/i', 'VARCHAR(15)' ],
		'INET6'   => [ '/(?<!`)\bINET6\b(?!\s*\()/i', 'VARCHAR(45)' ],
		'UUID'    => [ '/(?<!`)\bUUID\b(?!\s*\()/i', 'CHAR(36)' ],
		'XMLTYPE' => [ '/(?<!`)\bXMLTYPE\b(?!\s*\()/i', 'LONGTEXT' ],
		'VECTOR'  => [ '/(?<!`)\bVECTOR\s*\(\s*\d+\s*\)/i', 'BLOB' ],
	];

	private const REMOVED_TABLE_OPTIONS = [ 'TRANSACTIONAL', 'PAGE_CHECKSUM', 'TABLE_CHECKSUM', 'PAGE_COMPRESSED', 'PAGE_COMPRESSION_LEVEL', 'ENCRYPTED', 'ENCRYPTION_KEY_ID' ];

	/**
	 * A named CHECK constraint; the name (a masked identifier or a plain word) is dropped.
	 * MySQL 8 check names are unique per schema, so a dumped name would collide with the
	 * live table's; unnamed, the server derives `<table>_chk_N` from the import table and
	 * renames it along with the table on RENAME TABLE.
	 */
	private const CHECK_NAME = '/\bCONSTRAINT\s+(?:\x01\d+\x02|[^\s\x01\x02(),;`\'"]+)\s+(?=CHECK\s*\()/i';

	private const ENGINES = [ 'ENGINE=MyISAM', 'ENGINE=Aria', 'ENGINE=S3', 'ENGINE=ColumnStore', 'ENGINE=Spider', 'ENGINE=CONNECT', 'ENGINE=Mroonga' ];

	/**
	 * Parsed server version (x.y.z).
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Whether the server reports itself as MariaDB.
	 *
	 * @var bool
	 */
	private bool $mariaDb;

	public function __construct( string $serverVersionString ) {
		$this->mariaDb = false !== stripos( $serverVersionString, 'mariadb' );
		$this->version = self::parseVersion( $serverVersionString );
	}

	public static function parseVersion( string $v ): string {
		if ( 1 === preg_match( '/(\d+\.\d+\.\d+)-(\d+\.\d+\.\d+)/', $v, $m ) ) {
			return $m[2];
		}
		if ( 1 === preg_match( '/(\d+\.\d+\.\d+)/', $v, $m ) ) {
			return $m[1];
		}

		return '0.0.0';
	}

	public function isMariaDb(): bool {
		return $this->mariaDb;
	}

	public function version(): string {
		return $this->version;
	}

	public function fixCreate( string $sql ): string {
		return SqlSegments::apply( $sql, fn( string $masked ): string => $this->createRewrites( $masked ) );
	}

	private function createRewrites( string $sql ): string {
		$sql = $this->columnTypes( $sql );
		$sql = (string) preg_replace(
			[ '/AUTOINCREMENT/i', '/ON\s+CONFLICT\s+(ROLLBACK|ABORT|FAIL|IGNORE|REPLACE)/i', '/COLLATE\s+(BINARY|NOCASE|RTRIM)/i' ],
			[ 'AUTO_INCREMENT', '', '' ],
			$sql
		);
		$sql = (string) preg_replace( '/\/\*(.+?)\*\//s', '', $sql );
		$sql = $this->tableOptions( $sql );
		$sql = (string) preg_replace( [ '/\s+CONSTRAINT(.+)REFERENCES(.+),/i', '/,\s+CONSTRAINT(.+)REFERENCES(.+)/i' ], '', $sql );
		$sql = (string) preg_replace( self::CHECK_NAME, '', $sql );

		return $this->collations( $sql );
	}

	public function fixForError( string $sql, int $errno ): ?string {
		if ( ! in_array( $errno, [ 1030, 1071, 1709, 1214, 1101, 1273, 1115, 3822 ], true ) ) {
			return null;
		}
		$fixed = SqlSegments::apply( $sql, fn( string $masked ): string => $this->errorRewrite( $masked, $errno ) );

		return $fixed === $sql ? null : $fixed;
	}

	private function errorRewrite( string $sql, int $errno ): string {
		switch ( $errno ) {
			case 1030:
				$fixed = str_ireplace( self::ENGINES, 'ENGINE=InnoDB', $sql );
				break;
			case 1071:
			case 1709:
				$fixed = $this->dynamicRowFormat( $sql );
				break;
			case 1214:
				$fixed = (string) preg_replace( [ '/\s+FULLTEXT KEY(.+),/i', '/,\s+FULLTEXT KEY(.+)/i' ], '', $sql );
				break;
			case 1101:
				$fixed = (string) preg_replace(
					[
						"/(\s+(?:TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT)[^,]*?)\s+DEFAULT\s+(?:\x01\d+\x02|[^\s,]+)/i",
						"/(\s+(?:TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB)[^,]*?)\s+DEFAULT\s+(?:\x01\d+\x02|[^\s,]+)/i",
					],
					'$1',
					$sql
				);
				break;
			case 1273:
				$fixed = false !== stripos( $sql, '_0900_' )
					? (string) preg_replace( [ '/utf8mb4_0900_bin/i', '/utf8mb4_0900_\w+/i' ], [ 'utf8mb4_bin', 'utf8mb4_unicode_520_ci' ], $sql )
					: str_ireplace( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', $sql );
				break;
			case 3822: // Duplicate check constraint name.
				$fixed = (string) preg_replace( self::CHECK_NAME, '', $sql );
				break;
			default: // 1115: unknown character set (utf8mb3 alias not known).
				$fixed = str_ireplace( 'utf8mb3', 'utf8', $sql );
		}

		return $fixed;
	}

	private function dynamicRowFormat( string $sql ): string {
		if ( 1 === preg_match( '/ROW_FORMAT\s*=\s*DYNAMIC/i', $sql ) ) {
			return $sql;
		}
		if ( 1 === preg_match( '/ROW_FORMAT\s*=\s*\w+/i', $sql ) ) {
			return (string) preg_replace( '/ROW_FORMAT\s*=\s*\w+/i', 'ROW_FORMAT=DYNAMIC', $sql );
		}

		return str_ireplace(
			[ 'ENGINE=InnoDB', 'ENGINE=MyISAM' ],
			[ 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC', 'ENGINE=MyISAM ROW_FORMAT=DYNAMIC' ],
			$sql
		);
	}

	private function columnTypes( string $sql ): string {
		foreach ( self::TYPES as $type => [ $pattern, $replacement ] ) {
			if ( $this->mariaDb && version_compare( $this->version, self::NATIVE_SINCE[ $type ], '>=' ) ) {
				continue;
			}
			$sql = (string) preg_replace( $pattern, $replacement, $sql );
		}

		return $sql;
	}

	private function tableOptions( string $sql ): string {
		$patterns = [ '/`?TYPE`?\s*=\s*\'?(\w+)\'?/i', '/`?ENGINE`?\s*=\s*\'?Aria\'?/i' ];
		$replace  = [ 'ENGINE=$1', 'ENGINE=MyISAM' ];
		foreach ( self::REMOVED_TABLE_OPTIONS as $option ) {
			$patterns[] = '/`?' . $option . '`?\s*=\s*\'?\w+\'?/i';
			$replace[]  = '';
		}
		$patterns[] = '/`?ROW_FORMAT`?\s*=\s*\'?PAGE\'?\b/i'; // Aria-only value; the others stay.
		$replace[]  = '';
		$patterns[] = '/(WITH|WITHOUT)\s+SYSTEM\s+VERSIONING/i';
		$patterns[] = '/,?\s*WITHOUT\s+ROWID/i';
		$patterns[] = '/,?\s*\bSTRICT\b(?=(?:\s*--[^\n]*)*\s*;?\s*$)/i';
		array_push( $replace, '', '', '' );

		return (string) preg_replace( $patterns, $replace, $sql );
	}

	private function collations( string $sql ): string {
		$search  = [];
		$replace = [];
		// MariaDB never knows the 0900 collations, whatever its version number says.
		if ( $this->mariaDb || ! version_compare( $this->version, '8.0', '>=' ) ) {
			array_push( $search, 'utf8mb4_0900_bin', 'utf8mb4_0900_ai_ci', 'utf8mb4_0900_as_ci', 'utf8mb4_0900_as_cs' );
			array_push( $replace, 'utf8mb4_bin', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_ci' );
		}
		if ( ! version_compare( $this->version, '8.0', '>=' ) ) {
			array_push( $search, 'utf8mb3_unicode_ci', 'utf8mb3_general_ci', 'utf8mb3_bin', 'utf8mb3' );
			array_push( $replace, 'utf8_unicode_ci', 'utf8_general_ci', 'utf8_bin', 'utf8' );
		}
		if ( ! version_compare( $this->version, '5.6.0', '>=' ) ) {
			$search[]  = 'utf8mb4_unicode_520_ci';
			$replace[] = 'utf8mb4_unicode_ci';
		}
		if ( ! version_compare( $this->version, '5.5.3', '>=' ) ) {
			array_push( $search, 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_bin', 'utf8mb4' );
			array_push( $replace, 'utf8_unicode_ci', 'utf8_general_ci', 'utf8_bin', 'utf8' );
		}

		return [] === $search ? $sql : str_ireplace( $search, $replace, $sql );
	}
}
