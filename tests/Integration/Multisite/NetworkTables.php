<?php
/**
 * A small three-site network schema and seed for the network dump tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\Dumper;
use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Multisite\ExportScope;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;

final class NetworkTables {

	/**
	 * @param string          $base    Base prefix.
	 * @param array<int, int> $blogIds Blog ids (1 = main site).
	 * @return array<int, string> CREATE TABLE statements.
	 */
	public static function create( string $base, array $blogIds ): array {
		$sql = array_slice( WpCoreTables::create( $base ), 1 ); // users, usermeta.
		array_push(
			$sql,
			"CREATE TABLE `{$base}blogs` (blog_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id BIGINT NOT NULL DEFAULT 0, domain VARCHAR(200) NOT NULL DEFAULT '', path VARCHAR(100) NOT NULL DEFAULT '', registered DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00', last_updated DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00', public TINYINT NOT NULL DEFAULT 1, archived TINYINT NOT NULL DEFAULT 0, mature TINYINT NOT NULL DEFAULT 0, spam TINYINT NOT NULL DEFAULT 0, deleted TINYINT NOT NULL DEFAULT 0, lang_id INT NOT NULL DEFAULT 0)",
			"CREATE TABLE `{$base}site` (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(200) NOT NULL DEFAULT '', path VARCHAR(100) NOT NULL DEFAULT '')",
			"CREATE TABLE `{$base}sitemeta` (meta_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)",
			"CREATE TABLE `{$base}blogmeta` (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, blog_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)",
			"CREATE TABLE `{$base}signups` (signup_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(200) NOT NULL DEFAULT '', path VARCHAR(100) NOT NULL DEFAULT '', user_login VARCHAR(60) NOT NULL DEFAULT '')",
			"CREATE TABLE `{$base}registration_log` (ID BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL DEFAULT '', blog_id BIGINT NOT NULL DEFAULT 0)"
		);
		foreach ( $blogIds as $id ) {
			$p = 1 === $id ? $base : $base . $id . '_';
			array_push(
				$sql,
				WpCoreTables::create( $p )[0], // options.
				"CREATE TABLE `{$p}posts` (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_author BIGINT UNSIGNED NOT NULL DEFAULT 0, post_title TEXT NOT NULL, post_type VARCHAR(20) NOT NULL DEFAULT 'post', post_content LONGTEXT NOT NULL)",
				"CREATE TABLE `{$p}postmeta` (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)",
				"CREATE TABLE `{$p}comments` (comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, comment_approved VARCHAR(20) NOT NULL DEFAULT '1', comment_content TEXT NOT NULL)",
				"CREATE TABLE `{$p}commentmeta` (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)"
			);
		}

		return $sql;
	}

	/**
	 * Blogs 1–3 of n.test, one user per blog with a role there, one post per blog,
	 * a spam comment and a revision on blog 2, our lock and a status option everywhere.
	 *
	 * @param string $base Base prefix.
	 * @return array<int, string> INSERT statements.
	 */
	public static function seed( string $base ): array {
		$sql = [
			"INSERT INTO `{$base}blogs` (blog_id, site_id, domain, path) VALUES (1,1,'n.test','/'),(2,1,'n.test','/b2/'),(3,1,'n.test','/b3/')",
			"INSERT INTO `{$base}site` VALUES (1,'n.test','/')",
			"INSERT INTO `{$base}sitemeta` VALUES (1,1,'site_admins','a:1:{i:0;s:5:\"admin\";}'),(2,1,'active_sitewide_plugins','a:1:{s:9:\"n/n.php\";i:1;}'),(3,1,'site_name','N')",
			"INSERT INTO `{$base}users` (ID, user_login) VALUES (1,'admin'),(2,'editor2'),(3,'author3')",
		];
		$meta = [];
		foreach ( [ 1 => 'admin', 2 => 'editor2', 3 => 'author3' ] as $id => $login ) {
			$p      = 1 === $id ? $base : $base . $id . '_';
			$meta[] = "({$id},'{$p}capabilities','a:1:{s:6:\"editor\";b:1;}')";
			$meta[] = "({$id},'{$p}user_level','7')";
			$meta[] = "({$id},'nickname','{$login}')";
			$meta[] = "({$id},'primary_blog','{$id}')";
			$meta[] = "({$id},'source_domain','n.test')";
			$sql[]  = "INSERT INTO `{$p}posts` VALUES (1,{$id},'Post {$id}','post','Hello {$id}')";
			$sql[]  = "INSERT INTO `{$p}options` (option_name, option_value) VALUES ('siteurl','http://n.test/{$id}'),('home','http://n.test/{$id}'),('active_plugins','a:1:{i:0;s:7:\"a/a.php\";}'),('{$p}user_roles','a:0:{}'),('lw_migration_lock','x'),('ai1wm_status','y')";
		}
		$sql[] = "INSERT INTO `{$base}usermeta` (user_id, meta_key, meta_value) VALUES " . implode( ',', $meta );
		$sql[] = "INSERT INTO `{$base}2_posts` VALUES (2,2,'Post 2 revision','revision','Old')";
		$sql[] = "INSERT INTO `{$base}2_postmeta` (post_id, meta_key, meta_value) VALUES (1,'kept','1'),(2,'of_revision','1')";
		$sql[] = "INSERT INTO `{$base}2_comments` VALUES (1,0,'1','Nice'),(2,0,'spam','Buy')";
		$sql[] = "INSERT INTO `{$base}2_commentmeta` (comment_id, meta_key, meta_value) VALUES (1,'kept','1'),(2,'of_spam','1')";

		return $sql;
	}

	/**
	 * Dumps the scope's tables the way a network backup does.
	 *
	 * @param Connection  $db      Database.
	 * @param ExportScope $scope   Scope.
	 * @param string      $path    database.sql.
	 * @param bool        $noSpam  Leave out spam comments.
	 * @param bool        $noRevs  Leave out revisions.
	 * @return string The dump.
	 */
	public static function dump( Connection $db, ExportScope $scope, string $path, bool $noSpam = false, bool $noRevs = false ): string {
		$listed  = ( new TableLister( $db ) )->list( $scope->listPrefixes() );
		$tables  = $scope->tables( $listed['tables'] );
		$quote   = static fn( string $value ): string => "'" . $db->escape( $value ) . "'";
		$options = new DumpOptions(
			'wp_',
			$noSpam,
			$noRevs,
			DumpOptions::STATUS_OPTIONS,
			Options::DUMP_EXCLUDED_PREFIXES,
			$scope->renamer(),
			$scope->prefixColumns( $tables ),
			$scope->tableWhere( $noSpam, $noRevs, DumpOptions::STATUS_OPTIONS, Options::DUMP_EXCLUDED_PREFIXES, $quote ),
			$scope->optionsTables()
		);
		$dumper  = new Dumper( $db, $options, $tables, [], $path );
		$cursor  = new DumpCursor();
		for ( $i = 0; $i < 100; $i++ ) {
			if ( $dumper->run( $cursor, new NoDeadline() ) ) {
				return (string) file_get_contents( $path );
			}
		}
		throw new \RuntimeException( 'The dump did not finish.' );
	}

	/**
	 * @param string $sql A dump.
	 * @return array<int, string> CREATE TABLE names in order.
	 */
	public static function createdTables( string $sql ): array {
		preg_match_all( '/^CREATE TABLE `([^`]+)`/m', $sql, $m );

		return $m[1];
	}
}
