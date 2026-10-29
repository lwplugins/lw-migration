<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\OrphanTables;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class OrphanTablesTest extends DatabaseTestCase {

	/** Run ids this site registered in the tests that are not about the registry. */
	private const ALL = [ 'ab12', 'cd34', 'zz99' ];

	public function test_drops_temporary_tables_and_views_nobody_keeps(): void {
		$this->exec(
			'CREATE TABLE wp_options (v INT)',
			'CREATE TABLE lwtab12_options (v INT)',
			'CREATE TABLE lwoab12_posts (v INT)',
			'CREATE VIEW lwoab12_view AS SELECT 1 AS v',
			'CREATE TABLE lwtcd34_options (v INT)',
			'CREATE TABLE lwxcd34_wbk_a (v INT)',
			'CREATE TABLE lwocd34_b (v INT)',
			'CREATE TABLE lwycd34_c (v INT)',
			'CREATE VIEW lwtcd34_view AS SELECT 1 AS v',
			'CREATE TABLE `lwtcd34_a``b` (v INT)',
			'CREATE TABLE lwtzz99_posts (v INT)',
			'CREATE TABLE lwtabcdef (v INT)',
			'CREATE TABLE lwtab_12_x (v INT)',
			'CREATE TABLE xlwtcd34_x (v INT)',
			'CREATE TABLE lwzcd34_x (v INT)',
			'CREATE TABLE lwtcd3_x (v INT)'
		);

		$result = OrphanTables::sweep( self::db(), self::ALL, self::keep( OrphanTables::runPrefixes( 'lwtab12_' ) ), 'lwtzz99_' );
		$dropped = $result['dropped'];
		sort( $dropped );

		$this->assertSame( [ 'lwocd34_b', 'lwtcd34_a`b', 'lwtcd34_options', 'lwtcd34_view', 'lwxcd34_wbk_a', 'lwycd34_c' ], $dropped );
		$this->assertSame( [], $result['failed'] );
		$this->assertSame(
			[ 'lwoab12_posts', 'lwoab12_view', 'lwtab12_options', 'lwtab_12_x', 'lwtabcdef', 'lwtcd3_x', 'lwtzz99_posts', 'lwzcd34_x', 'wp_options', 'xlwtcd34_x' ],
			$this->left()
		);
	}

	public function test_undo_prefixes_keep_only_the_replaced_tables(): void {
		$this->exec(
			'CREATE TABLE lwtab12_options (v INT)',
			'CREATE TABLE lwxab12_wbk_a (v INT)',
			'CREATE TABLE lwoab12_posts (v INT)',
			'CREATE TABLE lwyab12_wbk_a (v INT)'
		);

		OrphanTables::sweep( self::db(), self::ALL, self::keep( OrphanTables::undoPrefixes( 'lwtab12_' ) ), 'wp_' );

		$this->assertSame( [ 'lwoab12_posts', 'lwyab12_wbk_a' ], $this->left() );
	}

	public function test_the_site_prefix_is_never_touched_whatever_its_case(): void {
		$this->exec( 'CREATE TABLE lwtab12_options (v INT)', 'CREATE TABLE lwoab12_posts (v INT)' );

		$result = OrphanTables::sweep( self::db(), self::ALL, self::keep( [] ), 'LWTAB12_' );

		$this->assertSame( [ 'lwoab12_posts' ], $result['dropped'] );
		$this->assertSame( [ 'lwtab12_options' ], $this->left() );
	}

	public function test_a_registered_runs_tables_are_swept_in_any_case(): void {
		if ( '0' !== self::db()->variable( 'lower_case_table_names' ) ) {
			$this->markTestSkipped( 'The server folds table names to lower case.' );
		}
		$this->exec( 'CREATE TABLE LWTAB12_options (v INT)', 'CREATE TABLE lwtAB12_options (v INT)' );

		$dropped = OrphanTables::sweep( self::db(), self::ALL, self::keep( [] ), 'wp_' )['dropped'];
		sort( $dropped );

		$this->assertSame( [ 'LWTAB12_options', 'lwtAB12_options' ], $dropped, 'Task 7 security review: a crafted archive may leave a run\'s table in another case; the sweep takes it with the run.' );
		$this->assertSame( [], $this->left() );
	}

	public function test_a_table_that_cannot_be_dropped_does_not_stop_the_rest(): void {
		$this->exec(
			'CREATE TABLE lwtcd34_a (id INT PRIMARY KEY) ENGINE=InnoDB',
			'CREATE TABLE lwtcd34_b (id INT PRIMARY KEY, a INT, FOREIGN KEY (a) REFERENCES lwtcd34_a (id)) ENGINE=InnoDB',
			'CREATE TABLE lwocd34_p (id INT PRIMARY KEY) ENGINE=InnoDB',
			'CREATE TABLE wp_child (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES lwocd34_p (id)) ENGINE=InnoDB',
			'CREATE TABLE lwycd34_z (v INT)'
		);

		$result  = OrphanTables::sweep( self::db(), self::ALL, self::keep( [] ), 'wp_' );
		$dropped = $result['dropped'];
		sort( $dropped );

		$this->assertSame( [ 'lwtcd34_a', 'lwtcd34_b', 'lwycd34_z' ], $dropped, 'A parent of another orphan goes on the second pass.' );
		$this->assertSame( [ 'lwocd34_p' ], array_keys( $result['failed'] ) );
		$this->assertMatchesRegularExpression( '/^(3730|1451): another table references it\z/', $result['failed']['lwocd34_p'], 'Code and a fixed description, never the server text (it names the other table and constraint).' );
		$this->assertSame( [ 'lwocd34_p', 'wp_child' ], $this->left() );
	}

	public function test_tables_this_site_did_not_register_are_never_touched(): void {
		$this->exec(
			'CREATE TABLE lwtabcd_options (v INT)',
			'CREATE TABLE lwoabcd_posts (v INT)',
			'CREATE TABLE lwtcd34_options (v INT)'
		);

		$result = OrphanTables::sweep( self::db(), [ 'cd34' ], self::keep( [] ), 'wp_' );

		$this->assertSame( [ 'lwtcd34_options' ], $result['dropped'] );
		$this->assertSame( [ 'lwoabcd_posts', 'lwtabcd_options' ], $result['unregistered'] );
		$this->assertSame( [ 'cd34' ], $result['cleared'], 'Every table of the run is gone.' );
		$this->assertSame( [ 'lwoabcd_posts', 'lwtabcd_options' ], $this->left(), 'Another site in the same database may own them.' );
	}

	public function test_ownership_is_asked_again_for_each_run_right_before_its_drops(): void {
		$this->exec( 'CREATE TABLE lwtcd34_options (v INT)', 'CREATE TABLE lwtef56_options (v INT)', 'CREATE TABLE lwoef56_posts (v INT)' );
		$asked = [];

		$result = OrphanTables::sweep(
			self::db(),
			[ 'cd34', 'ef56', 'gh78' ],
			static function ( string $run ) use ( &$asked ): array {
				$asked[] = $run;
				return 'ef56' === $run ? [ 'lwoef56_' ] : [];
			},
			'wp_'
		);

		$this->assertSame( [ 'cd34', 'ef56' ], $asked, 'Only runs with tables are asked, one by one.' );
		$this->assertSame( [ 'lwtcd34_options', 'lwtef56_options' ], $result['dropped'] );
		$this->assertSame( [ 'cd34', 'gh78' ], $result['cleared'], 'A run with a kept table is not cleared.' );
		$this->assertSame( [ 'lwoef56_posts' ], $this->left() );
	}

	public function test_run_and_undo_prefixes(): void {
		$this->assertSame( [ 'lwtab12_', 'lwxab12_', 'lwoab12_', 'lwyab12_' ], OrphanTables::runPrefixes( 'lwtab12_' ) );
		$this->assertSame( 'ab12', OrphanTables::runId( 'lwoab12_posts' ) );
		$this->assertSame( [ 'lwoab12_', 'lwyab12_' ], OrphanTables::undoPrefixes( 'lwtab12_' ) );
	}

	/**
	 * @param array<int, string> $prefixes Prefixes kept for every run.
	 */
	private static function keep( array $prefixes ): \Closure {
		return static fn( string $run ): array => $prefixes;
	}

	/**
	 * @return array<int, string> Every table and view left, sorted.
	 */
	private function left(): array {
		$left = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $left );

		return $left;
	}

	public function test_a_temporary_table_in_another_case_is_swept_with_its_run(): void {
		$this->exec( 'CREATE TABLE `LWTCD34_7_X` (v INT)', 'CREATE TABLE `LwTab12_users` (v INT)', 'CREATE TABLE wp_options (v INT)' );

		$result = OrphanTables::sweep( self::db(), self::ALL, self::keep( OrphanTables::runPrefixes( 'lwtab12_' ) ), 'wp_' );

		$this->assertSame( [ 'LWTCD34_7_X' ], $result['dropped'] );
		$this->assertSame( [ 'LwTab12_users', 'wp_options' ], $this->left(), 'A kept run keeps its tables whatever their case.' );
	}
}
