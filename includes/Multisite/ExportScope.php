<?php
/**
 * Tables, prefix pairs and row filters of a network export.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\PrefixMap;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Export\ExportException;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 6 (reference rules R1–R4): network mode, chosen sites with or
 * without the main site (blog 1). The caller lists the tables and quotes
 * values; only the refusal of an unusable prefix needs WordPress (its text).
 */
final class ExportScope {

	public const NETWORK         = 'network';
	public const SITES_WITH_MAIN = 'sites_with_main';
	public const SITES           = 'sites';

	/** Network-global tables of WordPress (users, usermeta + ms_global_tables), in the format's order. */
	public const GLOBAL_TABLES = [ 'users', 'usermeta', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' ];

	private const P = PrefixRules::PLACEHOLDER;

	/** @var array<int, int> Every blog id of the network, ascending. */
	private array $blogIds;

	/** @var array<int, int>|null Chosen blog ids, ascending; null = whole network. */
	private ?array $chosen;

	/**
	 * @param string               $basePrefix $wpdb->base_prefix.
	 * @param array<int, int>      $blogIds    Every blog id of the network.
	 * @param array<int, int>|null $chosen     Chosen blog ids; null = whole network ([] is not: pass null for it).
	 * @throws ExportException For a prefix that is not a plain identifier or that the chained pairs would rename twice (R24).
	 * @throws \InvalidArgumentException For a choice without one valid blog id (a caller bug: never widen it to the network).
	 */
	public function __construct( private string $basePrefix, array $blogIds, ?array $chosen ) {
		$plain       = 1 === preg_match( '/^[A-Za-z0-9_]+\z/', $basePrefix );
		$placeholder = $plain && ( 0 === stripos( self::P, $basePrefix ) || 0 === stripos( $basePrefix, self::P ) );
		if ( ! $plain || $placeholder ) {
			throw new ExportException( __( 'A network backup needs a table prefix of letters, digits and underscores that does not start like the archive placeholder.', 'lw-migration' ) );
		}
		$this->blogIds = self::sorted( $blogIds );
		$this->chosen  = null === $chosen ? null : self::sorted( $chosen );
		if ( [] === $this->chosen ) {
			throw new \InvalidArgumentException( 'A sites export needs at least one blog id; pass null for the whole network.' );
		}
	}

	public function mode(): string {
		if ( null === $this->chosen ) {
			return self::NETWORK;
		}

		return in_array( 1, $this->chosen, true ) ? self::SITES_WITH_MAIN : self::SITES;
	}

	public function hasMain(): bool {
		return self::SITES !== $this->mode();
	}

	/**
	 * @return array<int, int> Exported subsites (not blog 1).
	 */
	public function subsites(): array {
		return array_values( array_filter( $this->chosen ?? $this->blogIds, static fn( int $id ): bool => $id > 1 ) );
	}

	/**
	 * @return array<int, string> Table prefix of every exported blog, the main site first.
	 */
	public function blogPrefixes(): array {
		$prefixes = $this->hasMain() ? [ $this->basePrefix ] : [];
		foreach ( $this->subsites() as $id ) {
			$prefixes[] = $this->basePrefix . $id . '_';
		}

		return $prefixes;
	}

	/**
	 * @return array<int, string> What TableLister lists before tables() filters.
	 */
	public function listPrefixes(): array {
		return self::NETWORK === $this->mode() ? array_merge( [ $this->basePrefix ], ImportPlan::DEFAULT_EXTRA_PREFIXES ) : [ $this->basePrefix ];
	}

	/**
	 * @param array<int, string> $listed Tables TableLister found (listPrefixes()).
	 * @return array<int, string> Exported tables, in listed order.
	 */
	public function tables( array $listed ): array {
		$mode = $this->mode();
		if ( self::NETWORK === $mode ) {
			return array_values( $listed );
		}
		$b      = $this->basePrefix;
		$own    = array_map( static fn( int $id ): string => $b . $id . '_', $this->subsites() );
		$global = array_merge( array_map( static fn( string $t ): string => $b . $t, self::GLOBAL_TABLES ), [ $b . 'bp_' ] );
		$out    = [];
		foreach ( $listed as $table ) {
			$isBlog = 1 === preg_match( '/^' . preg_quote( $b, '/' ) . '[0-9]+_/', $table );
			$keep   = self::startsWithAny( $table, $own )
				|| ( self::SITES_WITH_MAIN === $mode && str_starts_with( $table, $b ) && ! $isBlog )
				|| ( self::SITES === $mode && self::startsWithAny( $table, $global ) );
			if ( $keep ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	public function renamer(): PrefixMap {
		$b      = $this->basePrefix;
		$blogs  = array_map( static fn( int $id ): array => [ $b . $id . '_', self::P . $id . '_' ], $this->subsites() );
		$mode   = $this->mode();
		$tables = $blogs;
		if ( self::NETWORK === $mode ) {
			$tables[] = [ $b, self::P ];

			return new PrefixMap( $tables, array_merge( $blogs, $this->baseColumnPairs( self::P ) ) );
		}
		if ( self::SITES_WITH_MAIN === $mode ) {
			foreach ( self::GLOBAL_TABLES as $global ) {
				$tables[] = [ $b . $global, self::P . 'mainsite_' . $global ];
			}
			$tables[] = [ $b, self::P . 'basesite_' ];

			return new PrefixMap( $tables, array_merge( $blogs, $this->baseColumnPairs( self::P . 'basesite_' ) ) );
		}
		$tables[] = [ $b, self::P . 'mainsite_' ];

		return new PrefixMap( $tables, array_merge( $blogs, $this->baseColumnPairs( self::P . 'mainsite_' ) ) );
	}

	/**
	 * @param array<int, string> $tables Exported tables.
	 * @return array<string, string> Table => column whose values carry the prefix.
	 */
	public function prefixColumns( array $tables ): array {
		$out = [];
		foreach ( $this->optionsTables() as $options ) {
			if ( in_array( $options, $tables, true ) ) {
				$out[ $options ] = 'option_name';
			}
		}
		if ( in_array( $this->basePrefix . 'usermeta', $tables, true ) ) {
			$out[ $this->basePrefix . 'usermeta' ] = 'meta_key';
		}

		return $out;
	}

	/**
	 * @return array<int, string> Options table of every exported blog.
	 */
	public function optionsTables(): array {
		return array_map( static fn( string $p ): string => $p . 'options', $this->blogPrefixes() );
	}

	/**
	 * @param bool               $noSpam           Leave out spam comments.
	 * @param bool               $noRevisions      Leave out revisions.
	 * @param array<int, string> $excludedOptions  Option names never exported (status options).
	 * @param array<int, string> $excludedPrefixes Option name prefixes never exported (ours).
	 * @param \Closure           $quote            fn( string $value ): string — a quoted SQL literal.
	 * @return array<string, string> Table => WHERE condition; tables without a key are dumped whole.
	 */
	public function tableWhere( bool $noSpam, bool $noRevisions, array $excludedOptions, array $excludedPrefixes, \Closure $quote ): array {
		$where   = [];
		$options = $this->optionFilter( $excludedOptions, $excludedPrefixes, $quote );
		foreach ( $this->blogPrefixes() as $p ) {
			$where += $this->blogFilters( $p, $noSpam, $noRevisions );
			if ( '' !== $options ) {
				$where[ $p . 'options' ] = $options;
			}
		}
		$where[ $this->basePrefix . 'sitemeta' ] = "`meta_key` NOT IN ('active_sitewide_plugins')";

		return self::NETWORK === $this->mode() ? $where : $where + $this->userFilters( $quote );
	}

	/**
	 * Spam and revision filters of one blog, as RowSelector builds them on a single site.
	 *
	 * @param string $p           Blog table prefix.
	 * @param bool   $noSpam      Leave out spam comments.
	 * @param bool   $noRevisions Leave out revisions.
	 * @return array<string, string>
	 */
	private function blogFilters( string $p, bool $noSpam, bool $noRevisions ): array {
		$where = [];
		if ( $noSpam ) {
			$where[ $p . 'comments' ]    = "`comment_approved` != 'spam'";
			$where[ $p . 'commentmeta' ] = '`comment_ID` IN ( SELECT `comment_ID` FROM ' . DumpFormat::quoteIdentifier( $p . 'comments' ) . " WHERE `comment_approved` != 'spam' )";
		}
		if ( $noRevisions ) {
			$where[ $p . 'posts' ]    = "`post_type` != 'revision'";
			$where[ $p . 'postmeta' ] = '`post_id` IN ( SELECT `ID` FROM ' . DumpFormat::quoteIdentifier( $p . 'posts' ) . " WHERE `post_type` != 'revision' )";
		}

		return $where;
	}

	/**
	 * Status options and ours leave every exported options table (decision 6 deviation).
	 *
	 * @param array<int, string> $excludedOptions  Option names.
	 * @param array<int, string> $excludedPrefixes Option name prefixes.
	 * @param \Closure           $quote            Quotes a literal.
	 * @return string '' when nothing is excluded.
	 */
	private function optionFilter( array $excludedOptions, array $excludedPrefixes, \Closure $quote ): string {
		$clauses = [];
		if ( [] !== $excludedOptions ) {
			$clauses[] = '`option_name` NOT IN (' . implode( ',', array_map( $quote, $excludedOptions ) ) . ')';
		}
		foreach ( $excludedPrefixes as $prefix ) {
			$clauses[] = '`option_name` NOT LIKE ' . $quote( addcslashes( $prefix, '\\%_' ) . '%' );
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * Sites mode: only users with a role on an exported blog, and only their
	 * keys of those blogs plus the keys of no blog (reference rule R4). The base
	 * prefix is a plain identifier (constructor), so it stands in REGEXP unescaped;
	 * `_` is a literal there.
	 *
	 * @param \Closure $quote Quotes a literal.
	 * @return array<string, string>
	 */
	private function userFilters( \Closure $quote ): array {
		$b    = $this->basePrefix;
		$keys = [];
		foreach ( $this->blogPrefixes() as $p ) {
			$keys[] = $quote( $p . 'capabilities' );
			$keys[] = $quote( $p . 'user_level' );
		}
		$holders = 'SELECT `user_id` FROM `' . $b . 'usermeta` WHERE `meta_key` IN (' . implode( ', ', $keys ) . ')';
		$conds   = array_map( static fn( int $id ): string => "`meta_key` REGEXP '^" . $b . $id . "_'", $this->subsites() );
		$conds[] = $this->hasMain() ? "`meta_key` NOT REGEXP '^" . $b . "[0-9]+_'" : "`meta_key` NOT REGEXP '^" . $b . "'";

		return [
			$b . 'users'    => '`ID` IN (' . $holders . ')',
			$b . 'usermeta' => '`user_id` IN (' . $holders . ') AND (' . implode( ' OR ', $conds ) . ')',
		];
	}

	/**
	 * A base prefix of one character renames only the short keys (as on a single site).
	 *
	 * @param string $to Placeholder for the base prefix.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function baseColumnPairs( string $to ): array {
		if ( strlen( $this->basePrefix ) > 1 ) {
			return [ [ $this->basePrefix, $to ] ];
		}

		return array_map( fn( string $key ): array => [ $this->basePrefix . $key, $to . $key ], PrefixRules::SHORT_PREFIX_KEYS );
	}

	/**
	 * @param string             $name     Table name.
	 * @param array<int, string> $prefixes Prefixes.
	 * @return bool
	 */
	private static function startsWithAny( string $name, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, mixed> $ids Blog ids.
	 * @return array<int, int> Positive, unique, ascending.
	 */
	private static function sorted( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );

		return $ids;
	}
}
