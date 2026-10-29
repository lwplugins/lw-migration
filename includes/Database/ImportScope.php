<?php
/**
 * Which dump statements the importer may run or defer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * An allow-list, checked after prefix mapping: only statements that write
 * the temporary import tables run, so a crafted dump can never touch the
 * live tables. Views are deferred only when they name a final-prefix view; a
 * subsite restore into a network defers none (a view's body could read any
 * table, and no hand-made SQL parser is a safe boundary). The temporary prefix
 * is matched in its exact case.
 */
final class ImportScope {

	/** The rest of a backtick-quoted name after its prefix, a doubled backtick included, and its closing backtick. */
	private const NAME_REST = '(?:[^`]|``)++`';

	private const VIEW_ATTRIBUTES = '(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(DEFINER\s*=\s*\S+\s+)?(SQL\s+SECURITY\s+\w+\s+)?';

	/** @var string Regex alternative of the temporary prefixes. */
	private string $tmp;

	/** @var string */
	private string $final;

	/** @var CreateTableCheck */
	private CreateTableCheck $create;

	/** @var InsertCheck */
	private InsertCheck $insert;

	/** @var bool With site suffixes: a subsite restore into a network brings no view at all. */
	private bool $noViews = false;

	/**
	 * A sites or single-site restore into a network (review C1) names its site
	 * suffixes: then only <tmp><site suffix>… and <tmp><stage suffix>… tables
	 * may be written, and only <base><site suffix>… views deferred.
	 *
	 * @param string             $basePrefix     Final table prefix.
	 * @param string             $tmpPrefix      Temporary prefix.
	 * @param string             $extraTmpPrefix Temporary prefix of extra-prefix tables ('' = none; ignored with site suffixes).
	 * @param array<int, string> $siteSuffixes   "<newId>_" of every mapped site ([] = no restriction).
	 * @param array<int, string> $stageSuffixes  Held staging suffixes (lwmsrc_), tables only.
	 */
	public function __construct( string $basePrefix, string $tmpPrefix, string $extraTmpPrefix = '', array $siteSuffixes = [], array $stageSuffixes = [] ) {
		$only = [];
		if ( [] !== $siteSuffixes ) {
			$only           = array_map( static fn( string $s ): string => $tmpPrefix . $s, array_merge( $siteSuffixes, $stageSuffixes ) );
			$sites          = array_map( static fn( string $s ): string => $basePrefix . $s, $siteSuffixes );
			$this->tmp      = self::alternation( $only );
			$this->final    = self::alternation( $sites );
			$this->noViews  = true;
			$extraTmpPrefix = '';
		} else {
			$this->tmp   = '' === $extraTmpPrefix ? self::alternation( [ $tmpPrefix ] ) : self::alternation( [ $tmpPrefix, $extraTmpPrefix ] );
			$this->final = preg_quote( $basePrefix, '/' );
		}
		$this->create = new CreateTableCheck( $tmpPrefix, $extraTmpPrefix, $only );
		$this->insert = new InsertCheck( $tmpPrefix, $extraTmpPrefix, $only );
	}

	/**
	 * @param array<int, string> $prefixes Literal prefixes, matched in their exact case (the regexes around are case-insensitive).
	 */
	private static function alternation( array $prefixes ): string {
		return '(?-i:' . implode( '|', array_map( static fn( string $p ): string => preg_quote( $p, '/' ), $prefixes ) ) . ')';
	}

	public function isTransactionControl( string $sql ): bool {
		return 1 === preg_match( '/^(START\s+TRANSACTION|COMMIT)\s*;?$/i', $sql );
	}

	public function isTableDdl( string $sql ): bool {
		return 1 === preg_match( '/^(DROP|CREATE)\s+TABLE\b/i', $sql );
	}

	public function isCreateTable( string $sql ): bool {
		return 1 === preg_match( '/^CREATE\s+TABLE\b/i', $sql );
	}

	/**
	 * Every CREATE statement, however it is spelled, must pass CreateTableCheck.
	 *
	 * @param string $sql Statement that is about to run.
	 * @return bool
	 */
	public function targetsImportTables( string $sql ): bool {
		if ( 0 === strncasecmp( $sql, 'INSERT', 6 ) ) {
			return $this->insert->isSafe( $sql );
		}
		if ( 0 === strncasecmp( $sql, 'CREATE', 6 ) ) {
			return $this->create->isSafe( $sql );
		}

		return 1 === preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\s+`' . $this->tmp . self::NAME_REST . '\s*;?$/i', $sql );
	}

	/**
	 * Whether a CREATE TABLE names an import table (its options may still be refused).
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	public function namesImportTable( string $sql ): bool {
		return 1 === preg_match( '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`' . $this->tmp . self::NAME_REST . '/i', $sql );
	}

	public function isView( string $sql ): bool {
		return 1 === preg_match( '/^(DROP\s+VIEW|CREATE\s+' . self::VIEW_ATTRIBUTES . 'VIEW)\b/i', $sql );
	}

	public function isSafeCreate( string $sql ): bool {
		return $this->create->isSafe( $sql );
	}

	/**
	 * Whether a CREATE TABLE is refused for a column default, generated column, CHECK constraint or key part.
	 *
	 * @param string $sql Statement.
	 */
	public function refusesColumnExpression( string $sql ): bool {
		return $this->create->refusesExpression( $sql );
	}

	/**
	 * The view statement to defer, or null when it is not allowed. CREATE VIEW
	 * loses DEFINER, SQL SECURITY and ALGORITHM (as on export) and becomes
	 * CREATE OR REPLACE VIEW, so a replay after a crash runs it again without
	 * an "already exists" error and a live view of the same name is replaced.
	 * The server refuses to replace a base table with a view, so no table is
	 * ever replaced. The output passes this check again unchanged.
	 *
	 * @param string $sql View statement with final prefixes.
	 * @return string|null
	 */
	public function deferrableView( string $sql ): ?string {
		$f = $this->final;
		if ( 1 === preg_match( '/^DROP\s+VIEW\s+IF\s+EXISTS\s+`' . $f . self::NAME_REST . '\s*;?$/i', $sql ) ) {
			return $this->noViews ? null : $sql;
		}
		$sql = (string) preg_replace( '/^CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(DEFINER\s*=\s*\S+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW\s+/i', 'CREATE OR REPLACE VIEW ', $sql );

		return 1 === preg_match( '/^CREATE OR REPLACE VIEW `' . $f . self::NAME_REST . '\s+AS\b/i', $sql ) && ! $this->noViews ? $sql : null;
	}

	/**
	 * The name a CREATE VIEW statement gives its view (for a warning); null for any other statement.
	 *
	 * @param string $sql View statement.
	 */
	public function viewName( string $sql ): ?string {
		$create = '/^CREATE\s+' . self::VIEW_ATTRIBUTES . 'VIEW\s+`((?:[^`]|``)+)`/i';

		return 1 === preg_match( $create, $sql, $m ) ? str_replace( '``', '`', $m[5] ) : null;
	}
}
