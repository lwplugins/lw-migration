<?php
/**
 * The user merge of a sites or single-site restore into a network, on a real
 * MySQL/MariaDB: a live network (NetworkTables) with the restored site 7
 * swapped in, and the archive's users held under lwtabcd_lwmsrc_.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Multisite\UserMerge;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;

final class UserMergeTest extends DatabaseTestCase {

	private const STAGE = 'lwtabcd_lwmsrc_';

	/** What the swap brought in (state.swappedTables). */
	private const SWAPPED = [ 'wp_7_options', 'wp_7_posts', 'wp_7_comments', 'wp_7_wc_download_log' ];

	protected function setUp(): void {
		parent::setUp();
		$this->exec( ...NetworkTables::create( 'wp_', [ 1, 2, 7 ] ) );
		$this->exec(
			"INSERT INTO wp_site VALUES (1,'n.test','/')",
			"INSERT INTO wp_blogs (blog_id, site_id, domain, path) VALUES (1,1,'n.test','/'),(2,1,'n.test','/b2/'),(7,1,'n.test','/shop/')",
			"INSERT INTO wp_sitemeta VALUES (1,1,'site_admins','a:1:{i:0;s:5:\"admin\";}'),(2,1,'site_name','N')",
			"INSERT INTO wp_users (ID, user_login, user_pass, user_email) VALUES (1,'admin','live-hash','admin@n.test'),(2,'editor2','live-2','editor@n.test')",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'nickname','admin'),(1,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(2,'nickname','editor2')",
			WpCoreTables::create( self::STAGE )[1],
			WpCoreTables::create( self::STAGE )[2],
			'CREATE TABLE `' . self::STAGE . 'sitemeta` (meta_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)',
			'INSERT INTO `' . self::STAGE . "users` (ID, user_login, user_pass, user_email) VALUES (5,'admin','archive-hash','admin@old.test'),(6,'newbie','new-hash','editor@n.test'),(7,'Editor2','archive-2','e2@old.test')",
			"INSERT INTO wp_7_posts (ID, post_author, post_title, post_content) VALUES (1,5,'a',''),(2,6,'b',''),(3,7,'c',''),(4,0,'d',''),(5,99,'e','')"
		);
	}

	public function test_new_users_are_added_and_existing_ones_never_change(): void {
		$merge = $this->merge();
		$merge->insertUsers();
		$merge->insertUsers(); // Again: nothing more.

		$this->assertSame( 3, $this->countRows( 'wp_users' ) );
		$this->assertSame( 'live-hash', $this->value( "SELECT user_pass FROM wp_users WHERE user_login = 'admin'" ) );
		$this->assertSame( 'live-2', $this->value( "SELECT user_pass FROM wp_users WHERE ID = 2" ), 'Logins match the way the live column compares them (Editor2 = editor2).' );
		$this->assertSame( [ '3', 'new-hash', 'editor@n.test' ], array_values( self::db()->query( "SELECT ID, user_pass, user_email FROM wp_users WHERE user_login = 'newbie'" )[0] ) );
	}

	public function test_two_archive_logins_the_live_column_sees_as_one_add_one_user(): void {
		$this->exec( 'INSERT INTO `' . self::STAGE . "users` (ID, user_login, user_pass) VALUES (8,'NEWBIE','other')" );

		$this->merge()->insertUsers();

		$this->assertSame( 3, $this->countRows( 'wp_users' ) );
		$this->assertSame( 'new-hash', $this->value( "SELECT user_pass FROM wp_users WHERE user_login = 'newbie'" ), 'The first one by archive id wins.' );
	}

	public function test_an_added_user_with_an_existing_e_mail_is_named(): void {
		$this->assertSame( [ 'newbie' => 'editor2' ], $this->merge()->emailClashes() );
	}

	public function test_renumbering_maps_staged_ids_to_live_ids_once(): void {
		$merge = $this->merge();
		$merge->insertUsers();
		$merge->shift( 'wp_7_posts', 'post_author', $merge->maxStagedId() );
		$merge->remap( 'wp_7_posts', 'post_author' );
		$merge->unshift( 'wp_7_posts', 'post_author' );

		$this->assertSame( 7, $merge->maxStagedId() );
		$this->assertSame( [ 1, 3, 2, 0, 99 ], $this->authors() );
	}

	public function test_a_replay_after_each_statement_ends_in_the_same_state(): void {
		$merge = $this->merge();
		$merge->insertUsers();
		$max   = $merge->maxStagedId();
		$steps = [
			static fn() => $merge->shift( 'wp_7_posts', 'post_author', $max ),
			static fn() => $merge->remap( 'wp_7_posts', 'post_author' ),
			static fn() => $merge->unshift( 'wp_7_posts', 'post_author' ),
		];
		foreach ( array_keys( $steps ) as $replayed ) {
			$this->resetAuthors();
			foreach ( $steps as $i => $step ) {
				$step();
				if ( $i === $replayed ) {
					$step(); // The request died before the cursor moved on: the statement runs again.
				}
			}

			$this->assertSame( [ 1, 3, 2, 0, 99 ], $this->authors(), 'Statement ' . $replayed . ' replayed.' );
		}
	}

	public function test_renumbering_twice_changes_nothing(): void {
		$merge = $this->merge();
		$merge->insertUsers();
		$merge->shift( 'wp_7_posts', 'post_author', $merge->maxStagedId() );
		$merge->remap( 'wp_7_posts', 'post_author' );
		$merge->unshift( 'wp_7_posts', 'post_author' );

		// The step never re-enters shift once remap started; remap and unshift alone are no-ops now.
		$merge->remap( 'wp_7_posts', 'post_author' );
		$merge->unshift( 'wp_7_posts', 'post_author' );

		$this->assertSame( [ 1, 3, 2, 0, 99 ], $this->authors() );
	}

	public function test_only_restored_site_keys_and_a_new_users_profile_keys_are_copied(): void {
		$this->exec(
			'INSERT INTO `' . self::STAGE . "users` (ID, user_login, user_pass) VALUES (8,'third','h')",
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES
			(6,'wp_lwmsrc_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(6,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}'),
			(6,'WP_Capabilities','a:1:{s:13:\"administrator\";b:1;}'),(6,'wp_user_level','10'),(6,'_application_passwords','a:0:{}'),
			(6,'_Application_Passwords','a:0:{}'),(6,'wp_7_capabilities','a:1:{s:6:\"editor\";b:1;}'),(6,'wp_70_x','1'),(6,'WP_7_capabilities','x'),
			(6,'primary_blog','2'),(6,'source_domain','old.test'),(6,'session_tokens','a:0:{}'),(6,'nickname','Newbie'),(6,'nickname','second'),
			(6,'first_name','N'),(6,'wp_2_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(6,'Nickname','case'),
			(5,'wp_capabilities','a:0:{}'),(5,'wp_user_level','10'),(5,'_application_passwords','a:1:{i:0;s:3:\"key\";}'),(5,'nickname','archive-nick'),
			(5,'first_name','A'),(5,'wp_7_capabilities','a:1:{s:6:\"author\";b:1;}'),
			(7,'primary_blog','9'),(7,'locale','hu_HU'),(7,'wp_7_capabilities','e2'),
			(8,'primary_blog','9'),(8,'source_domain','elsewhere.test')"
		);
		$merge   = $this->merge();
		$liveMax = $merge->liveMaxId();
		$merge->insertUsers();
		$merge->insertUserMeta( [ 2 => 7 ], [ 'old.test' => 'new.test' ], 1, 'n.test', $liveMax );
		$rows = $this->countRows( 'wp_usermeta' );
		$merge->insertUserMeta( [ 2 => 7 ], [ 'old.test' => 'new.test' ], 1, 'n.test', $liveMax );

		$this->assertSame( 2, $liveMax );
		$this->assertSame( $rows, $this->countRows( 'wp_usermeta' ), 'A second insertUserMeta() adds nothing.' );
		$this->assertSame(
			[
				'first_name'        => 'N',
				'nickname'          => 'Newbie',
				'primary_blog'      => '7',
				'source_domain'     => 'new.test',
				'wp_7_capabilities' => 'a:1:{s:6:"editor";b:1;}',
			],
			$this->meta( 3 ),
			'A new user: its role on the restored site and its profile (first row per key), no main-site role, level, application password, session or other site.'
		);
		$this->assertSame(
			[
				'nickname'          => 'admin',
				'wp_7_capabilities' => 'a:1:{s:6:"author";b:1;}',
				'wp_capabilities'   => 'a:1:{s:13:"administrator";b:1;}',
			],
			$this->meta( 1 ),
			'The existing admin gets only its role on the restored site: no profile, level or application password.'
		);
		$this->assertSame(
			[
				'nickname'          => 'editor2',
				'wp_7_capabilities' => 'e2',
			],
			$this->meta( 2 )
		);
		$this->assertSame(
			[
				'primary_blog'  => '1',
				'source_domain' => 'n.test',
			],
			$this->meta( 4 ),
			'An unknown home site falls back to the main site and the network host.'
		);
	}

	public function test_network_meta_is_never_touched(): void {
		$this->exec(
			'INSERT INTO `' . self::STAGE . "sitemeta` (site_id, meta_key, meta_value) VALUES
			(1,'menu_items','a:1:{s:7:\"plugins\";s:1:\"1\";}'),(1,'Site_Admins','a:1:{i:0;s:6:\"newbie\";}'),(1,'site_admins','a:1:{i:0;s:6:\"newbie\";}'),(1,'registration','all')"
		);
		$before = self::db()->query( 'SELECT * FROM wp_sitemeta ORDER BY meta_id' );
		$merge  = $this->merge();
		$live   = $merge->liveMaxId();
		$merge->insertUsers();
		$merge->insertUserMeta( [ 2 => 7 ], [], 1, 'n.test', $live );

		$this->assertSame( $before, self::db()->query( 'SELECT * FROM wp_sitemeta ORDER BY meta_id' ) );
	}

	public function test_added_users_never_bring_a_pending_activation_key(): void {
		$this->exec( 'UPDATE `' . self::STAGE . "users` SET user_activation_key = '1700000000:\$P\$known' WHERE ID = 6" );

		$this->merge()->insertUsers();

		$this->assertSame( '', $this->value( "SELECT user_activation_key FROM wp_users WHERE user_login = 'newbie'" ) );
	}

	public function test_look_alike_logins_and_shared_profile_addresses_are_named(): void {
		$this->exec(
			"UPDATE wp_users SET user_nicename = 'editor2' WHERE ID = 2",
			'UPDATE `' . self::STAGE . "users` SET user_nicename = 'editor2' WHERE ID = 6"
		);
		$merge = $this->merge();

		$this->assertSame( [ 'newbie' => 'editor2' ], $merge->nicenameClashes() );
		$this->assertSame( [ 'Editor2' => 'editor2' ], $merge->loginMatches(), 'Same login for the network, not the same bytes.' );
	}

	public function test_a_value_above_the_offset_marks_the_column_and_is_never_moved_onto_a_live_user(): void {
		$crafted = UserMerge::OFFSET + 1;
		$this->exec( "INSERT INTO wp_7_posts (ID, post_author, post_title, post_content) VALUES (6,{$crafted},'f','')" );
		$merge = $this->merge();

		$this->assertTrue( $merge->holdsShifted( 'wp_7_posts', 'post_author' ) );
		$this->assertFalse( $merge->holdsShifted( 'wp_7_comments', 'user_id' ) );
		$this->assertSame( (string) $crafted, $this->value( 'SELECT post_author FROM wp_7_posts WHERE ID = 6' ), 'The step leaves such a column out, so nothing moves it.' );
	}

	public function test_the_largest_live_id_is_read(): void {
		$this->assertSame( 2, $this->merge()->liveMaxId() );
		$this->exec( 'INSERT INTO wp_users (ID, user_login) VALUES (' . UserMerge::OFFSET . ",'huge')" );

		$this->assertSame( UserMerge::OFFSET, $this->merge()->liveMaxId() );
	}

	public function test_roles_on_a_restored_site_are_kept_only_when_they_are_roles_of_that_site(): void {
		$this->exec(
			"INSERT INTO wp_7_options (option_name, option_value) VALUES ('wp_7_user_roles','a:2:{s:13:\"administrator\";a:2:{s:4:\"name\";s:5:\"Admin\";s:12:\"capabilities\";a:1:{s:10:\"edit_users\";b:1;}}s:6:\"editor\";a:2:{s:4:\"name\";s:6:\"Editor\";s:12:\"capabilities\";a:1:{s:4:\"read\";b:1;}}}')",
			'INSERT INTO `' . self::STAGE . "users` (ID, user_login, user_pass) VALUES (8,'third','h'),(9,'fourth','h'),(10,'fifth','h')",
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES
			(6,'wp_7_capabilities','a:1:{s:20:\"manage_network_users\";b:1;}'),
			(5,'wp_7_capabilities','a:2:{s:13:\"administrator\";b:1;s:10:\"edit_users\";b:1;}'),
			(7,'wp_7_capabilities','a:1:{s:6:\"editor\";b:1;}'),
			(8,'wp_7_capabilities','a:1:{s:6:\"editor\";O:8:\"stdClass\":0:{}}'),
			(9,'wp_7_Capabilities','a:1:{s:6:\"author\";b:1;}'),
			(10,'wp_7_capabilities','a:1:{s:13:\"administrator\";b:1;}')"
		);
		$merge   = $this->merge();
		$liveMax = $merge->liveMaxId();
		$merge->insertUsers();

		$this->assertSame( [ 'newbie', 'admin', 'third', 'fourth' ], $merge->sanitizeCapabilities( [ 7 ] ) );
		$this->assertSame( [], $merge->sanitizeCapabilities( [ 7 ] ), 'A repeat finds nothing more.' );
		$merge->insertUserMeta( [ 2 => 7 ], [], 1, 'n.test', $liveMax );

		$this->assertSame( 'a:0:{}', $this->meta( 3 )['wp_7_capabilities'], 'A raw network capability is refused.' );
		$this->assertSame( 'a:0:{}', $this->meta( 1 )['wp_7_capabilities'], 'A role plus a raw capability is refused, for an existing user too.' );
		$this->assertSame( 'a:1:{s:6:"editor";b:1;}', $this->meta( 2 )['wp_7_capabilities'], 'A role of the site is kept.' );
		$this->assertSame( 'a:0:{}', $this->meta( 4 )['wp_7_capabilities'], 'An object in the value is refused.' );
		$this->assertSame( 'a:1:{s:13:"administrator";b:1;}', $this->meta( 6 )['wp_7_capabilities'] );
		$this->assertStringNotContainsString( 'author', $this->value( "SELECT meta_value FROM wp_usermeta WHERE user_id = 5 AND meta_key = 'wp_7_Capabilities'" ), 'Any letter case of the key is checked.' );
	}

	public function test_a_role_named_after_a_network_right_is_never_kept(): void {
		$this->exec(
			"INSERT INTO wp_7_options (option_name, option_value) VALUES ('wp_7_user_roles','a:1:{s:20:\"manage_network_users\";a:2:{s:4:\"name\";s:1:\"x\";s:12:\"capabilities\";a:0:{}}}')",
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES (6,'wp_7_capabilities','a:1:{s:20:\"manage_network_users\";b:1;}')"
		);
		$merge = $this->merge();
		$merge->insertUsers();

		$this->assertSame( [ 'newbie' ], $merge->sanitizeCapabilities( [ 7 ] ) );
		$this->assertSame( 'a:0:{}', $this->value( 'SELECT meta_value FROM `' . self::STAGE . "usermeta` WHERE user_id = 6 AND meta_key = 'wp_7_capabilities'" ) );
	}

	public function test_two_rows_of_the_live_roles_count_as_the_default_roles(): void {
		$this->exec(
			'ALTER TABLE wp_7_options DROP INDEX option_name',
			"INSERT INTO wp_7_options (option_name, option_value) VALUES ('wp_7_user_roles','a:1:{s:4:\"shop\";a:2:{s:4:\"name\";s:1:\"S\";s:12:\"capabilities\";a:0:{}}}'),('wp_7_user_roles','a:0:{}')",
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES (6,'wp_7_capabilities','a:1:{s:4:\"shop\";b:1;}'),(7,'wp_7_capabilities','a:1:{s:6:\"editor\";b:1;}')"
		);
		$merge = $this->merge();
		$merge->insertUsers();

		$this->assertSame( [ 'newbie' ], $merge->sanitizeCapabilities( [ 7 ] ), 'Only the default roles count: shop is refused, editor kept.' );
	}

	public function test_only_keys_that_are_copied_are_named(): void {
		$this->exec(
			"INSERT INTO wp_7_options (option_name, option_value) VALUES ('wp_7_user_roles','" . addslashes( \LightweightPlugins\Migration\Multisite\SiteRoles::CORE_DEFAULT ) . "')",
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES (6,'WP_7_capabilities','a:1:{s:4:\"evil\";b:1;}')"
		);
		$merge = $this->merge();
		$merge->insertUsers();

		$this->assertSame( [], $merge->sanitizeCapabilities( [ 7 ] ), 'WP_7_… is never copied, so nobody is named for it.' );
		$this->assertSame( 'a:0:{}', $this->value( 'SELECT meta_value FROM `' . self::STAGE . "usermeta` WHERE user_id = 6" ), 'It is still emptied.' );
	}

	public function test_logins_the_network_sees_as_one_bring_one_row_per_key(): void {
		$this->exec(
			'INSERT INTO `' . self::STAGE . "users` (ID, user_login, user_pass) VALUES (8,'NEWBIE','other')",
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES (6,'nickname','first'),(8,'nickname','second'),(8,'first_name','X'),(6,'wp_7_capabilities','a:0:{}'),(8,'wp_7_capabilities','a:0:{}')"
		);
		$merge   = $this->merge();
		$liveMax = $merge->liveMaxId();
		$merge->insertUsers();
		$merge->insertUserMeta( [ 2 => 7 ], [], 1, 'n.test', $liveMax );

		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM wp_usermeta WHERE user_id = 3 AND meta_key = 'nickname'" ) );
		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM wp_usermeta WHERE user_id = 3 AND meta_key = 'wp_7_capabilities'" ) );
		$this->assertSame( [ 'nickname' => 'first', 'wp_7_capabilities' => 'a:0:{}' ], $this->meta( 3 ), 'Only the first archive user of the login brings meta.' );
	}

	public function test_columns_that_cannot_hold_the_offset_are_left_out(): void {
		$this->exec(
			'CREATE TABLE wp_7_wc_download_log (download_log_id BIGINT NOT NULL PRIMARY KEY, user_id TINYINT NOT NULL DEFAULT 0)',
			'CREATE TABLE wp_7_wc_orders (id BIGINT NOT NULL PRIMARY KEY, customer_id BIGINT UNSIGNED NULL)'
		);

		$this->assertSame(
			[ [ 'wp_7_comments', 'user_id' ], [ 'wp_7_posts', 'post_author' ] ],
			$this->merge()->targets( [ 1, 7 ], array_merge( self::SWAPPED, [ 'wp_posts', 'wp_comments' ] ) ),
			'TINYINT cannot hold the offset; the base prefix tables (main site) never take part.'
		);
	}

	public function test_a_live_table_of_a_reused_site_the_archive_did_not_bring_keeps_its_ids(): void {
		$this->exec( 'CREATE TABLE wp_7_wc_orders (id BIGINT NOT NULL PRIMARY KEY, customer_id BIGINT UNSIGNED NULL)' );

		$targets = $this->merge()->targets( [ 7 ], self::SWAPPED );

		$this->assertNotContains( [ 'wp_7_wc_orders', 'customer_id' ], $targets );
		$this->assertContains( [ 'wp_7_wc_orders', 'customer_id' ], $this->merge()->targets( [ 7 ], array_merge( self::SWAPPED, [ 'wp_7_wc_orders' ] ) ) );
	}

	public function test_staging_in_another_character_set_merges(): void {
		$this->exec(
			'ALTER TABLE wp_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
			'ALTER TABLE wp_usermeta CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
			'ALTER TABLE wp_sitemeta CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
			'ALTER TABLE `' . self::STAGE . 'users` CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci',
			'ALTER TABLE `' . self::STAGE . 'usermeta` CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci',
			'ALTER TABLE `' . self::STAGE . 'sitemeta` CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci',
			'INSERT INTO `' . self::STAGE . "usermeta` (user_id, meta_key, meta_value) VALUES (6,'wp_7_capabilities','e'),(6,'nickname','n'),(5,'nickname','x')"
		);
		$merge = $this->merge();
		$live  = $merge->liveMaxId();

		$this->assertSame( [ 'newbie' => 'editor2' ], $merge->emailClashes() );
		$merge->insertUsers();
		$merge->shift( 'wp_7_posts', 'post_author', $merge->maxStagedId() );
		$merge->remap( 'wp_7_posts', 'post_author' );
		$merge->unshift( 'wp_7_posts', 'post_author' );
		$merge->insertUserMeta( [ 2 => 7 ], [], 1, 'n.test', $live );

		$this->assertSame( [ 1, 3, 2, 0, 99 ], $this->authors() );
		$this->assertSame( [ 'nickname' => 'n', 'wp_7_capabilities' => 'e' ], $this->meta( 3 ) );
		$this->assertSame( 'admin', $this->value( "SELECT meta_value FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'nickname'" ) );
	}

	public function test_without_staged_tables_nothing_is_staged_and_nothing_runs(): void {
		$this->exec( 'DROP TABLE `' . self::STAGE . 'users`', 'DROP TABLE `' . self::STAGE . 'usermeta`', 'DROP TABLE `' . self::STAGE . 'sitemeta`' );
		$merge = $this->merge();

		$this->assertFalse( $merge->hasStaging() );
		$merge->insertUsers();
		$merge->insertUserMeta( [ 2 => 7 ], [], 1, 'n.test', 2 );
		$this->assertSame( 0, $merge->maxStagedId() );
		$this->assertSame( [], $merge->emailClashes() );
		$this->assertSame( 2, $this->countRows( 'wp_users' ) );
	}

	public function test_a_held_table_alone_counts_as_staging(): void {
		$this->exec( 'DROP TABLE `' . self::STAGE . 'users`', 'DROP TABLE `' . self::STAGE . 'usermeta`' );

		$this->assertTrue( $this->merge()->hasStaging() );
	}

	public function test_prefixes_are_checked(): void {
		foreach ( [ [ 'wp_', 'lwtABCD_' ], [ 'wp_', 'wp_' ], [ 'wp`_', 'lwtabcd_' ], [ '', 'lwtabcd_' ] ] as [ $base, $tmp ] ) {
			try {
				new UserMerge( self::db(), $base, $tmp );
				$this->fail( 'Refused: ' . $base . ' ' . $tmp );
			} catch ( DatabaseException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	private function merge(): UserMerge {
		return new UserMerge( self::db(), 'wp_', 'lwtabcd_' );
	}

	private function countRows( string $table ): int {
		return (int) $this->value( 'SELECT COUNT(*) FROM `' . $table . '`' );
	}

	private function value( string $sql ): string {
		return (string) array_values( self::db()->query( $sql )[0] )[0];
	}

	/** @return array<int, int> */
	private function authors(): array {
		return array_map( static fn( array $row ): int => (int) $row['post_author'], self::db()->query( 'SELECT post_author FROM wp_7_posts ORDER BY ID' ) );
	}

	private function resetAuthors(): void {
		$this->exec(
			'DELETE FROM wp_7_posts',
			"INSERT INTO wp_7_posts (ID, post_author, post_title, post_content) VALUES (1,5,'a',''),(2,6,'b',''),(3,7,'c',''),(4,0,'d',''),(5,99,'e','')"
		);
	}

	/** @return array<string, string> Meta key => value of a live user, by key. */
	private function meta( int $userId ): array {
		$meta = [];
		foreach ( self::db()->query( 'SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = ' . $userId . ' ORDER BY meta_key' ) as $row ) {
			$meta[ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		return $meta;
	}
}
