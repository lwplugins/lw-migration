<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Export\Estimate;

use LightweightPlugins\Migration\Export\Estimate\DatabaseSizes;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use PHPUnit\Framework\TestCase;

final class DatabaseSizesTest extends TestCase {

	private FakeConnection $db;

	protected function setUp(): void {
		$this->db       = new FakeConnection();
		$listed         = [ 'wp_options', 'wp_posts', 'wp_comments', 'wp_2_posts', 'wp_2_comments', 'wbk_items', 'wp_commentsx' ];
		$this->db->rows = [
			'SHOW FULL TABLES'                                     => array_map( static fn( string $t ): array => [ 'Tables_in_fake' => $t ], $listed ),
			'SELECT TABLE_NAME AS n'                               => [
				[ 'n' => 'wp_options', 'b' => '1000' ],
				[ 'n' => 'wp_posts', 'b' => '2000' ],
				[ 'n' => 'wp_comments', 'b' => '300' ],
				[ 'n' => 'wp_2_posts', 'b' => '40' ],
				[ 'n' => 'wp_2_comments', 'b' => '5' ],
				[ 'n' => 'wbk_items', 'b' => '600' ],
				[ 'n' => 'wp_commentsx', 'b' => '7' ],
				[ 'n' => 'other_site_posts', 'b' => '999999' ],
			],
			'SELECT COUNT(*) AS n FROM `wp_comments` WHERE `comment_approved`' => [ [ 'n' => '412' ] ],
			'SELECT COUNT(*) AS n FROM `wp_posts` WHERE `post_type`' => [ [ 'n' => '3270' ] ],
			'SELECT COUNT(*) AS n FROM `wp_2_comments` WHERE `comment_approved`' => [ [ 'n' => '8' ] ],
			'SELECT COUNT(*) AS n FROM `wp_2_posts` WHERE `post_type`' => [ [ 'n' => '30' ] ],
		];
	}

	public function test_sizes_the_listed_tables_and_counts_spam_and_revisions_of_a_site(): void {
		$sizes = new DatabaseSizes( $this->db );

		$state = $sizes->count( $sizes->measure( 'wp_', false ), new NoDeadline() );

		$this->assertSame( 3952, $state['bytes'], 'Only the tables the backup lists; another prefix in the schema is not counted.' );
		$this->assertSame( 7, $state['tables'] );
		$this->assertSame( [ 'wp_posts', 'wp_comments' ], $state['counted'], 'On a single site only its own comments and posts tables.' );
		$this->assertSame( 412, $state['spamComments'] );
		$this->assertSame( 3270, $state['revisions'] );
		$this->assertTrue( DatabaseSizes::done( $state ) );
	}

	public function test_an_empty_prefix_sizes_every_table_as_the_backup_lists_them(): void {
		$this->db->rows['SHOW FULL TABLES'][] = [ 'Tables_in_fake' => 'other_site_posts' ];

		$state = ( new DatabaseSizes( $this->db ) )->measure( '', false );

		$this->assertSame( 3952 + 999999, $state['bytes'] );
	}

	public function test_on_a_network_every_sites_tables_are_counted(): void {
		$sizes = new DatabaseSizes( $this->db );

		$state = $sizes->count( $sizes->measure( 'wp_', true ), new NoDeadline() );

		$this->assertSame( [ 'wp_posts', 'wp_comments', 'wp_2_posts', 'wp_2_comments' ], $state['counted'] );
		$this->assertSame( 420, $state['spamComments'] );
		$this->assertSame( 3300, $state['revisions'] );
	}

	public function test_counting_stops_at_the_deadline_and_continues_where_it_stopped(): void {
		$sizes = new DatabaseSizes( $this->db );
		$state = $sizes->measure( 'wp_', true );

		$state = $sizes->count( $state, new CountdownDeadline( 0 ) );
		$this->assertSame( 1, $state['index'] );
		$this->assertFalse( DatabaseSizes::done( $state ) );
		$state = $sizes->count( $state, new NoDeadline() );

		$this->assertTrue( DatabaseSizes::done( $state ) );
		$this->assertSame( 420, $state['spamComments'] );
		$this->assertSame( 3300, $state['revisions'], 'Each table counted once.' );
	}

	public function test_the_queries_are_limited_to_the_prefixes_and_name_only_listed_tables(): void {
		$queries = [];
		$db      = new class( $this->db, $queries ) implements \LightweightPlugins\Migration\Database\Connection {
			/**
			 * @param FakeConnection     $inner   Scripted connection.
			 * @param array<int, string> $queries Received queries.
			 */
			public function __construct( private FakeConnection $inner, public array &$queries ) {
			}
			public function query( string $sql ): array {
				$this->queries[] = $sql;
				return $this->inner->query( $sql );
			}
			public function stream( string $sql, callable $onRow ): int {
				return 0;
			}
			public function execute( string $sql ): void {
			}
			public function escape( string $value ): string {
				return $this->inner->escape( $value );
			}
			public function serverVersion(): string {
				return '8.0.36';
			}
			public function variable( string $name ): ?string {
				return null;
			}
			public function databaseName(): string {
				return 'fake';
			}
			public function host(): string {
				return 'localhost';
			}
		};
		$sizes   = new DatabaseSizes( $db );

		$sizes->count( $sizes->measure( 'wp_', false ), new NoDeadline() );

		$this->assertContains(
			"SELECT TABLE_NAME AS n, DATA_LENGTH + INDEX_LENGTH AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (TABLE_NAME LIKE 'wp\\\\_%' OR TABLE_NAME LIKE 'wbk\\\\_%' OR TABLE_NAME LIKE 'civicrm\\\\_%')",
			$queries,
			'The size query reads only the tables of the backup\'s prefixes, with the LIKE wildcards escaped.'
		);
		$this->assertSame(
			[
				"SELECT COUNT(*) AS n FROM `wp_posts` WHERE `post_type` = 'revision'",
				"SELECT COUNT(*) AS n FROM `wp_comments` WHERE `comment_approved` = 'spam'",
			],
			array_values( array_filter( $queries, static fn( string $q ): bool => str_starts_with( $q, 'SELECT COUNT' ) ) )
		);
	}
}
