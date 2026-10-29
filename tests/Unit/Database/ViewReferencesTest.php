<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\ViewReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #14: a deferred view must not read another database.
 */
final class ViewReferencesTest extends TestCase {

	private const HEAD = 'CREATE OR REPLACE VIEW `wp_v` AS ';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function sameDatabase(): array {
		return [
			'canonical table.column'          => [ 'select `wp_options`.`option_name` AS `option_name` from `wp_options`' ],
			'alias.column unquoted'           => [ "select o.option_name from wp_options o where o.autoload = 'yes'" ],
			'current database qualified'      => [ 'select `site_db`.`wp_posts`.`ID` AS `ID` from `site_db`.`wp_posts`' ],
			'current database unquoted'       => [ 'select p.ID from site_db.wp_posts p join wp_postmeta m on p.ID = m.post_id' ],
			'names inside strings'            => [ "select 'other.t' AS a, 'it\\'s other.t' AS b, \"other\".\"t\" AS c from wp_options" ],
			'decimal numbers'                 => [ 'select 1.5 AS x, .5 AS y, 1.5e3 AS z from wp_options' ],
			'table wildcard'                  => [ 'select t.* from wp_options t' ],
			'subquery in the select list'     => [ 'select (select count(0) from wp_posts) AS n, w.x from wp_options w' ],
			'grouping and ordering'           => [ 'select a.x from wp_a a group by a.x order by a.x, a.y' ],
			'comma lists of columns'          => [ 'select a.x, b.y from wp_a a, wp_b b where a.id = b.id' ],
			'function over columns'           => [ 'select concat(a.x, b.y) AS c from wp_a a join wp_b b using (id)' ],
			'on condition then where'         => [ 'select a.x from wp_a a left join wp_b b on (a.id = b.id and b.k in (1, 2)) where b.z = a.z' ],
			'derived table with outer column' => [ 'select s.x from (select a.x from wp_a a) s, wp_b b where s.x = b.x' ],
			'single-part names only'          => [ 'select ID from wp_posts union select 1 from dual' ],
			'quoted keyword as a column'      => [ 'select `from`, `join`, a.b from wp_a a' ],
			'column ending in join'           => [ 'select rejoin, a.b from wp_a a' ],
			'system variable'                 => [ 'select @@session.sql_mode AS m, @@global.version AS v' ],
			'alias named after its use'       => [ 'select later.x from wp_a AS later' ],
		];
	}

	#[DataProvider( 'sameDatabase' )]
	public function test_views_reading_only_the_current_database_pass( string $body ): void {
		$this->assertNull( ViewReferences::otherDatabase( self::HEAD . $body, 'site_db' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function otherDatabase(): array {
		return [
			'backtick db.table'           => [ 'select `x`.`a` AS `a` from `other`.`wp_posts` `x`', 'other' ],
			'unquoted db.table'           => [ 'select a from other.wp_posts', 'other' ],
			'three-part column'           => [ 'select `other`.`t`.`c` AS `c` from `wp_x`', 'other' ],
			'three-part wildcard'         => [ 'select other.t.* from wp_a', 'other' ],
			'joined table'                => [ 'select b.c from wp_a a join other.t b on a.id = b.id', 'other' ],
			'straight join'               => [ 'select 1 from wp_a straight_join other.t', 'other' ],
			'comma-joined table'          => [ 'select 1 from wp_a a, other.t b', 'other' ],
			'comma after an on condition' => [ 'select 1 from wp_a a join wp_b b on a.id = b.id, other.t c', 'other' ],
			'parenthesized join'          => [ 'select 1 from (other.t1 join wp_b on 1)', 'other' ],
			'derived table'               => [ 'select s.x from (select x from other.t) s', 'other' ],
			'subquery in where'           => [ 'select 1 from wp_a where x in (select y from other.t)', 'other' ],
			'union'                       => [ 'select 1 from wp_a union select 1 from other.t', 'other' ],
			'stored function'             => [ 'select other.f(1) AS x', 'other' ],
			'spaces around the dot'       => [ 'select 1 from other . t', 'other' ],
			'mixed quoting'               => [ 'select 1 from `other`.t', 'other' ],
			'system schema'               => [ 'select 1 from information_schema.tables', 'information_schema' ],
			'another case'                => [ 'select 1 from SITE_DB.wp_posts', 'SITE_DB' ],
			'backtick inside the name'    => [ 'select 1 from `o``ther`.`t`', 'o`ther' ],
			'dot inside the name'         => [ 'select 1 from `oth.er`.`t`', 'oth.er' ],
			'table statement'             => [ 'table other.t', 'other' ],
			'union with a table statement' => [ 'select 1 from wp_a union table other.t', 'other' ],
			'name that looks like a number' => [ 'select 1 from other.1e5', 'other' ],
			'sequence in an expression'   => [ 'select nextval(other.seq) AS n', 'other' ],
			'qualifier never named'       => [ 'select other.t AS n from wp_a', 'other' ],
		];
	}

	#[DataProvider( 'otherDatabase' )]
	public function test_a_reference_to_another_database_is_found( string $body, string $database ): void {
		$this->assertSame( $database, ViewReferences::otherDatabase( self::HEAD . $body, 'site_db' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unreadable(): array {
		return [
			'block comment'       => [ 'select 1 /* from other.t */ from wp_a' ],
			'versioned comment'   => [ 'select 1 from /*!50001 other.t */ wp_a' ],
			'line comment'        => [ "select 1 from wp_a -- x\n" ],
			'unterminated quote'  => [ "select 'x from wp_a" ],
			'odbc escape'         => [ 'select 1 from { OJ other.t1 left outer join wp_a on 1 }' ],
		];
	}

	/**
	 * Review I1: an index hint or a keyword alias must not end the FROM list, and a
	 * qualifier is a table only when the statement declares it in a table position.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bypasses(): array {
		return [
			'index hint for order by'          => [ 'select s.secret AS otherdb from wp_options use index for order by (primary), otherdb.secrets s' ],
			'key hint for group by, i_s'       => [ 'select t.table_name AS information_schema from wp_users use key for group by (primary), information_schema.tables t' ],
			'empty index hint for group by'    => [ 'select s.secret AS otherdb from wp_options use index for group by (), otherdb.secrets s' ],
			'force index for join'             => [ 'select s.secret AS otherdb from wp_options force index for join (primary), otherdb.secrets s' ],
			'ignore hint list'                 => [ 'select s.secret AS otherdb from wp_options ignore index (a), use key (b), otherdb.secrets s' ],
			'except as an alias'               => [ 'select s.secret AS otherdb from wp_options except, otherdb.secrets s' ],
			'intersect as an alias'            => [ 'select s.secret AS otherdb from wp_options intersect, otherdb.secrets s' ],
			'window as an alias'               => [ 'select s.secret AS otherdb from wp_options window, otherdb.secrets s' ],
			'alias named like the database'    => [ 'select s.secret from wp_options otherdb use index for order by (primary), otherdb.secrets s' ],
			'sequence with a matching alias'   => [ 'select nextval(otherdb.seq) AS otherdb from wp_options' ],
			'next value for with an alias'     => [ 'select next value for otherdb.seq AS otherdb from wp_options' ],
			'qualifier only as column alias'   => [ 'select otherdb.secret AS otherdb from wp_options' ],
			'window alias then use index'      => [ 'select 1 from wp_users otherdb, wp_options window use index (primary), otherdb.secrets' ],
			'window alias then hint for order' => [ 'select 1 from wp_users otherdb, wp_options window use index for order by (primary), otherdb.secrets s' ],
			'window alias after cross join'    => [ 'select 1 from wp_users otherdb cross join wp_options window use index (primary), otherdb.t' ],
			'window alias then ignore key'     => [ 'select 1 from wp_users otherdb, wp_options window ignore key (primary), otherdb.secrets' ],
			'window alias then force index'    => [ 'select 1 from wp_users otherdb, wp_options window force index (primary), otherdb.secrets' ],
			'system time as of'                => [ "select 1 from wp_users otherdb, wp_options for system_time as of timestamp '2026-01-01 00:00:00', otherdb.secrets" ],
			'system time all'                  => [ 'select 1 from wp_users otherdb, wp_options for system_time all, otherdb.secrets' ],
			'index hint after a where'         => [ 'select 1 from wp_users otherdb where 1 use index (primary), otherdb.secrets' ],
		];
	}

	#[DataProvider( 'bypasses' )]
	public function test_a_lost_from_list_or_an_undeclared_qualifier_is_never_let_through( string $body ): void {
		$this->assertNotNull( ViewReferences::otherDatabase( self::HEAD . $body, 'site_db' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function declaredTables(): array {
		return [
			'index hints keep the from list'  => [ 'select o.option_name from wp_options o use index for order by (primary), wp_users u where u.ID = o.option_id' ],
			'derived table alias'             => [ 'select d.n from (select count(0) AS n from wp_posts) AS d' ],
			'table function alias'            => [ 'select jt.v from wp_options o, json_table(o.option_value, \'$[*]\' columns (v int path \'$\')) AS jt' ],
			'common table expression'         => [ 'with c AS (select 1 AS x) select c.x from c' ],
			'union of two selects'            => [ 'select a.x from wp_a a union all select b.x from wp_b b' ],
			'window clause'                   => [ 'select a.x, row_number() over w AS n from wp_a a window w AS (order by a.x)' ],
			'hint after an alias and a list'  => [ 'select o.x from wp_options AS o use index (a), ignore key (b) join wp_users u on u.ID = o.x' ],
		];
	}

	#[DataProvider( 'declaredTables' )]
	public function test_qualifiers_declared_as_tables_or_aliases_pass( string $body ): void {
		$this->assertNull( ViewReferences::otherDatabase( self::HEAD . $body, 'site_db' ) );
	}

	#[DataProvider( 'unreadable' )]
	public function test_a_body_that_cannot_be_read_reports_an_unnamed_database( string $body ): void {
		$this->assertSame( '', ViewReferences::otherDatabase( self::HEAD . $body, 'site_db' ) );
	}

	public function test_too_many_qualifiers_to_follow_cannot_be_checked(): void {
		$names = range( 0, 1000 );
		$body  = 'select ' . implode( ', ', array_map( static fn( int $i ): string => "q{$i}.x", $names ) ) . ' from ' . implode( ', ', array_map( static fn( int $i ): string => "wp_t q{$i}", $names ) );

		$this->assertSame( '', ViewReferences::otherDatabase( self::HEAD . $body, 'site_db' ) );
	}

	public function test_a_drop_view_names_no_database(): void {
		$this->assertNull( ViewReferences::otherDatabase( 'DROP VIEW IF EXISTS `wp_v`;', 'site_db' ) );
	}
}
