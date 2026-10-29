<?php
/**
 * Every statement the dump writer writes passes the import allow-list.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\ImportScope;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Tests\Support\DumpStatements;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixed DumpStatements corpus goes through the importer's read, prefix
 * mapping and schema fixing steps for a MySQL and a MariaDB destination, and
 * ImportScope::targetsImportTables() must accept every statement: the
 * allow-list is never stricter than what the plugin itself exports.
 */
final class ImportScopeDumpTest extends TestCase {

	private const TMP = 'lwtab12_';

	private const EXTRA_TMP = 'lwxab12_';

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function configurations(): array {
		$out = [];
		foreach ( [ 'single', 'extra', 'subsite' ] as $config ) {
			foreach ( [ 'mysql' => '8.0.36', 'mariadb' => '10.11.6-MariaDB' ] as $server => $version ) {
				$out[ "{$config} into {$server}" ] = [ $config, $version ];
			}
		}

		return $out;
	}

	#[DataProvider( 'configurations' )]
	public function test_every_statement_the_dump_writer_writes_is_accepted( string $config, string $serverVersion ): void {
		$placeholder = PrefixRules::PLACEHOLDER;
		$pairs       = [ $placeholder => 'subsite' === $config ? 'wp_7_' : 'wp_' ];
		$ids         = PrefixRules::identifierPairs( $pairs, 'wp_', self::TMP );
		$extra       = 'extra' === $config ? [ 'wbk_' ] : [];
		$path        = $this->dir . '/database.sql';
		file_put_contents( $path, ( new DumpStatements() )->dump( array_merge( [ $placeholder ], $extra ) ) );
		$scope    = self::scope( $config );
		$fixer    = new SchemaFixer( $serverVersion );
		$reader   = new StatementReader( $path );
		$refused  = [];
		$accepted = 0;
		$offset   = 0;
		while ( null !== ( $statement = $reader->next( $offset, 16777216 ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- read loop.
			$offset = $statement->end;
			$sql    = $statement->sql;
			if ( $scope->isTransactionControl( $sql ) ) {
				continue;
			}
			$sql = str_ireplace( array_keys( $ids ), array_values( $ids ), $sql );
			$sql = PrefixRules::mapExtraTables( $sql, $extra, self::EXTRA_TMP );
			$sql = str_ireplace( array_keys( $pairs ), array_values( $pairs ), $sql );
			$sql = $scope->isCreateTable( $sql ) ? $fixer->fixCreate( $sql ) : $sql;
			if ( $scope->targetsImportTables( $sql ) ) {
				++$accepted;
			} elseif ( count( $refused ) < 5 ) {
				$refused[] = json_encode( $sql, JSON_INVALID_UTF8_SUBSTITUTE );
			}
		}
		$reader->close();

		$this->assertSame( [], $refused, "Refused statements the dump writer wrote:\n" . implode( "\n", $refused ) );
		$this->assertGreaterThan( 300, $accepted );
	}

	private static function scope( string $config ): ImportScope {
		return match ( $config ) {
			'extra'   => new ImportScope( 'wp_', self::TMP, self::EXTRA_TMP ),
			'subsite' => new ImportScope( 'wp_', self::TMP, '', [ '7_' ], [ 'lwmsrc_' ] ),
			default   => new ImportScope( 'wp_', self::TMP ),
		};
	}
}
