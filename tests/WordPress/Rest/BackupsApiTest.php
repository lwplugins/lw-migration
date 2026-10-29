<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupKind;
use LightweightPlugins\Migration\Backups\DownloadToken;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\BackupsApi;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class BackupsApiTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress/';

	private const DOWNLOAD_URL = 'https://example.test/wp-json/lw-migration/v1/backups/download';

	private string $dir;

	private JobStore $jobs;

	private BackupsApi $api;

	/** @var array<string, mixed> */
	private array $transients = [];

	private string $session = 'session-of-user-7';

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		FakeWpdb::install();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
		Functions\when( 'wp_get_session_token' )->alias( fn(): string => $this->session );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value );
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/site-test-20261007-101500-001.wpress' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/pre-import-20261007-101600.wpress' );
		copy( self::FIXTURES . 'encrypted.wpress', $this->dir . '/ext/twin.wpress' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/twin.wpress' );
		touch( $this->dir . '/own/site-test-20261007-101500-001.wpress', 3000 );
		touch( $this->dir . '/own/pre-import-20261007-101600.wpress', 2000 );
		touch( $this->dir . '/own/twin.wpress', 1500 );
		touch( $this->dir . '/ext/twin.wpress', 1000 );
		$this->jobs = new JobStore( $this->dir . '/jobs' );
		$this->api  = new BackupsApi( new Repository( $this->dir . '/own', $this->dir . '/ext' ), $this->jobs );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	private function statusOf( \Closure $call ): int {
		return $this->refusal( $call )->status;
	}

	public function test_lists_names_folders_labels_and_kinds_without_paths(): void {
		Labels::set( 'twin.wpress', 'Before the redesign' );
		$items = $this->api->all();

		$this->assertSame( [ 'own:site-test-20261007-101500-001.wpress', 'own:pre-import-20261007-101600.wpress', 'own:twin.wpress', 'external:twin.wpress' ], array_column( $items, 'id' ) );
		$this->assertSame( [ 'id', 'name', 'folder', 'size', 'mtime', 'label', 'safety', 'beforeReset', 'kind', 'cloud', 'links', 'encrypted', 'fromSite' ], array_keys( $items[0] ) );
		$this->assertSame( filesize( self::FIXTURES . 'plain.wpress' ), $items[0]['size'] );
		$this->assertSame( filemtime( $this->dir . '/own/site-test-20261007-101500-001.wpress' ), $items[0]['mtime'] );
		$this->assertTrue( $items[1]['safety'] );
		$this->assertFalse( $items[0]['safety'] );
		$this->assertSame( 'Before the redesign', $items[3]['label'], 'Labels are keyed by name (decision 5).' );
		$this->assertStringNotContainsString( $this->dir, (string) json_encode( $items, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_each_item_says_whether_it_is_encrypted_and_which_other_site_made_it(): void {
		copy( self::FIXTURES . 'full-site.wpress', $this->dir . '/own/site-a-test-20261007-101700-002.wpress' );
		$items = array_column( $this->api->all(), null, 'id' );

		$this->assertSame( [ false, null ], [ $items['own:twin.wpress']['encrypted'], $items['own:twin.wpress']['fromSite'] ], 'A backup of this site.' );
		$this->assertTrue( $items['external:twin.wpress']['encrypted'] );
		$this->assertSame( 'site-a.test', $items['own:site-a-test-20261007-101700-002.wpress']['fromSite'] );
	}

	public function test_on_a_network_the_main_site_is_this_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'network_home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'home_url' )->justReturn( 'https://sub.example.test' );

		$this->assertNull( $this->api->all()[0]['fromSite'] );
	}

	public function test_each_item_says_where_it_was_uploaded(): void {
		( new ProfileRepository() )->save( new Profile( 'aaaa1111', 's3', 'One' ) );
		CloudCopies::mark( 'site-test-20261007-101500-001.wpress', 'aaaa1111', CloudCopies::UP, 5 );
		CloudCopies::mark( 'site-test-20261007-101500-001.wpress', 'bbbb2222', CloudCopies::UP, 6 );
		CloudCopies::mark( 'twin.wpress', '12345678', CloudCopies::FAILED, 7 );

		$items = array_column( $this->api->all(), null, 'name' );

		$this->assertSame( [ [ 'id' => 'aaaa1111', 'status' => 'up', 'at' => 5 ] ], $items['site-test-20261007-101500-001.wpress']['cloud'], 'A copy on a deleted profile is not shown.' );
		$this->assertSame( [], $items['twin.wpress']['cloud'] );
		$this->assertSame( [ [ 'id' => 'aaaa1111', 'status' => 'up', 'at' => 5 ] ], $this->api->label( [ 'folder' => 'own', 'name' => 'site-test-20261007-101500-001.wpress', 'label' => 'x' ] )['cloud'] );
	}

	public function test_an_all_digit_profile_id_stays_a_string(): void {
		( new ProfileRepository() )->save( new Profile( '12345678', 's3', 'Digits' ) );
		CloudCopies::mark( 'twin.wpress', '12345678', CloudCopies::PENDING, 9 );

		$items = array_column( $this->api->all(), null, 'name' );

		$this->assertSame( [ [ 'id' => '12345678', 'status' => 'pending', 'at' => 9 ] ], $items['twin.wpress']['cloud'] );
	}

	public function test_a_backup_before_a_reset_is_a_safety_backup_marked_as_such(): void {
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/pre-reset-20261007-101700.wpress' );
		$items = array_column( $this->api->all(), null, 'name' );

		$this->assertTrue( $items['pre-reset-20261007-101700.wpress']['safety'] );
		$this->assertTrue( $items['pre-reset-20261007-101700.wpress']['beforeReset'] );
		$this->assertFalse( $items['pre-import-20261007-101600.wpress']['beforeReset'] );
		$this->assertFalse( $items['site-test-20261007-101500-001.wpress']['beforeReset'] );
	}

	public function test_each_item_has_one_kind(): void {
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/site-test-20261007-101700-002-abcd1234.wpress' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/pre-reset-20261007-101800.wpress' );

		$kinds = array_column( $this->api->all(), 'kind', 'id' );
		ksort( $kinds );

		$this->assertSame(
			[
				'external:twin.wpress'                              => BackupKind::OTHER,
				'own:pre-import-20261007-101600.wpress'             => BackupKind::SAFETY_RESTORE,
				'own:pre-reset-20261007-101800.wpress'              => BackupKind::SAFETY_RESET,
				'own:site-test-20261007-101500-001.wpress'          => BackupKind::MANUAL,
				'own:site-test-20261007-101700-002-abcd1234.wpress' => BackupKind::SCHEDULED,
				'own:twin.wpress'                                   => BackupKind::MANUAL,
			],
			$kinds
		);
	}

	public function test_each_item_counts_its_live_links_bound_to_the_file_as_it_is(): void {
		$shares = new Shares();
		$own    = Fingerprint::of( $this->dir . '/own/twin.wpress' );
		$shares->create( Backup::OWN, 'twin.wpress', 7, time(), 3600, $own );
		$shares->create( Backup::OWN, 'twin.wpress', 7, time(), 86400, $own );
		$shares->create( Backup::OWN, 'twin.wpress', 7, time(), 3600, str_repeat( 'a', 32 ) );
		$shares->create( Backup::EXTERNAL, 'twin.wpress', 7, time(), 3600, Fingerprint::of( $this->dir . '/ext/twin.wpress' ) );
		$shares->create( Backup::OWN, 'site-test-20261007-101500-001.wpress', 7, time() - 7200, 3600, Fingerprint::of( $this->dir . '/own/site-test-20261007-101500-001.wpress' ) );

		$links = array_column( $this->api->all(), 'links', 'id' );

		$this->assertSame( 2, $links['own:twin.wpress'], 'A link made for a file since replaced does not count.' );
		$this->assertSame( 1, $links['external:twin.wpress'] );
		$this->assertSame( 0, $links['own:site-test-20261007-101500-001.wpress'], 'An expired link does not count.' );
		$this->assertSame( 0, $links['own:pre-import-20261007-101600.wpress'] );
		$this->assertSame( 2, $this->api->label( [ 'folder' => 'own', 'name' => 'twin.wpress', 'label' => 'x' ] )['links'] );
	}

	public function test_the_listing_sums_each_folder_and_tells_the_free_space(): void {
		$listing = $this->api->listing( 123456 );
		$plain   = (int) filesize( self::FIXTURES . 'plain.wpress' );

		$this->assertSame( [ 'items', 'storage' ], array_keys( $listing ) );
		$this->assertSame( $this->api->all(), $listing['items'] );
		$this->assertSame( [ 'ownBytes', 'otherBytes', 'free', 'keep' ], array_keys( $listing['storage'] ) );
		$this->assertSame( 3 * $plain, $listing['storage']['ownBytes'] );
		$this->assertSame( (int) filesize( self::FIXTURES . 'encrypted.wpress' ), $listing['storage']['otherBytes'] );
		$this->assertSame( 123456, $listing['storage']['free'] );
		$this->assertNull( $this->api->listing( null )['storage']['free'], 'The host does not tell.' );
	}

	public function test_without_rules_or_schedules_manual_backups_are_kept(): void {
		$this->assertSame( [ [ 'schedule' => null, 'name' => '', 'count' => 0, 'bytes' => 0, 'days' => 0 ] ], $this->api->listing( null )['storage']['keep'] );
		$this->assertArrayNotHasKey( Options::SCHEDULES, FakeOptions::$values, 'Reading never creates the default schedule.' );
	}

	public function test_the_retention_rules_come_from_the_settings_and_the_switched_on_schedules(): void {
		FakeOptions::$values[ Options::RETENTION ] = [
			'count' => 5,
			'bytes' => 0,
			'days'  => 30,
		];
		FakeOptions::$values[ Options::SCHEDULES ] = [
			[
				'id'        => 'abcd1234',
				'title'     => 'Nightly',
				'enabled'   => true,
				'retention' => [
					'count' => 0,
					'bytes' => 0,
					'days'  => 7,
				],
			],
			[
				'id'        => 'dcba4321',
				'title'     => 'Paused',
				'enabled'   => false,
				'retention' => [
					'count' => 3,
					'bytes' => 0,
					'days'  => 0,
				],
			],
		];

		$this->assertSame(
			[
				[ 'schedule' => null, 'name' => '', 'count' => 5, 'bytes' => 0, 'days' => 30 ],
				[ 'schedule' => 'abcd1234', 'name' => 'Nightly', 'count' => 0, 'bytes' => 0, 'days' => 7 ],
			],
			$this->api->listing( null )['storage']['keep'],
			'A switched-off schedule deletes nothing by itself.'
		);
	}

	public function test_label_and_delete_one_folder_at_a_time(): void {
		$item = $this->api->label( [ 'folder' => 'external', 'name' => 'twin.wpress', 'label' => '  <b>Old host</b> ' ] );
		$this->assertSame( 'Old host', $item['label'], 'The stored (sanitized) text is returned.' );
		$this->assertSame( 'external:twin.wpress', $item['id'] );
		$refused = $this->refusal( fn() => $this->api->label( [ 'folder' => 'external', 'name' => 'twin.wpress', 'label' => [ 'x' ] ] ) );
		$this->assertSame( 400, $refused->status );
		$this->assertSame( ErrorMap::INVALID, $refused->errorCode );
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->label( [ 'folder' => 'own', 'name' => 'gone.wpress', 'label' => 'x' ] ) ) );

		$this->api->delete( [ 'folder' => 'external', 'name' => 'twin.wpress' ] );
		$this->assertFileDoesNotExist( $this->dir . '/ext/twin.wpress' );
		$this->assertFileExists( $this->dir . '/own/twin.wpress' );
		$this->assertSame( 'Old host', Labels::get( 'twin.wpress' ), 'The same name still exists in our folder.' );

		$this->api->delete( [ 'folder' => 'own', 'name' => 'twin.wpress' ] );
		$this->assertSame( '', Labels::get( 'twin.wpress' ) );
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->delete( [ 'folder' => 'own', 'name' => 'twin.wpress' ] ) ) );
	}

	public function test_a_backup_a_restore_reads_cannot_be_deleted(): void {
		$job         = $this->jobs->create( 'import', [ 'source' ], [ 'source' => 'backup', 'location' => 'twin.wpress', 'folder' => 'own' ], 1 );
		$job->status = Job::RUNNING;
		$this->jobs->save( $job );

		$refused = $this->refusal( fn() => $this->api->delete( [ 'folder' => 'own', 'name' => 'twin.wpress' ] ) );
		$this->assertSame( 409, $refused->status );
		$this->assertSame( 'lw_migration_backup_in_use', $refused->errorCode );
		$this->assertFileExists( $this->dir . '/own/twin.wpress' );
		$this->api->delete( [ 'folder' => 'external', 'name' => 'twin.wpress' ] );
		$this->assertFileDoesNotExist( $this->dir . '/ext/twin.wpress', 'The restore reads the copy in our folder only.' );
	}

	public function test_a_delete_is_refused_while_backups_are_moved(): void {
		$move = $this->jobs->create( MoveJobs::TYPE, MoveJobs::STEPS, [], time() );

		$refused = $this->refusal( fn() => $this->api->delete( [ 'folder' => 'external', 'name' => 'twin.wpress' ] ) );
		$this->assertSame( 409, $refused->status );
		$this->assertSame( 'lw_migration_backup_in_use', $refused->errorCode );
		$this->assertSame( 'The backups are being moved. Delete this one when the move has finished.', $refused->getMessage() );
		$this->assertFileExists( $this->dir . '/ext/twin.wpress' );

		$move->status = Job::DONE;
		$this->jobs->save( $move );
		$this->api->delete( [ 'folder' => 'external', 'name' => 'twin.wpress' ] );
		$this->assertFileDoesNotExist( $this->dir . '/ext/twin.wpress' );
	}

	public function test_entries_page_through_the_archive(): void {
		$backup = [ 'folder' => 'own', 'name' => 'twin.wpress' ];
		$all    = $this->api->entries( $backup + [ 'limit' => '500' ] );
		$first  = $this->api->entries( $backup + [ 'limit' => '2' ] );
		$second = $this->api->entries( $backup + [ 'limit' => '2', 'after' => (string) $first['next'] ] );

		$this->assertSame( 'package.json', $all['entries'][0]['name'] );
		$this->assertSame( [ 'name', 'size', 'mtime' ], array_keys( $all['entries'][0] ) );
		$this->assertNull( $all['next'] );
		$this->assertFalse( $all['encrypted'] );
		$this->assertCount( 2, $first['entries'] );
		$this->assertIsInt( $first['next'] );
		$this->assertSame( array_slice( array_column( $all['entries'], 'name' ), 0, 4 ), array_merge( array_column( $first['entries'], 'name' ), array_column( $second['entries'], 'name' ) ) );
		$this->assertTrue( $this->api->entries( [ 'folder' => 'external', 'name' => 'twin.wpress' ] )['encrypted'] );
	}

	public function test_bad_requests(): void {
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->find( [ 'folder' => 'own', 'name' => '../twin.wpress' ] ) ) );
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->find( [ 'folder' => 'tmp', 'name' => 'twin.wpress' ] ) ) );
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->find( [ 'name' => 'twin.wpress' ] ) ) );
		$this->assertSame( 'lw_migration_backup_missing', $this->refusal( fn() => $this->api->find( [ 'folder' => 'own', 'name' => 'none.wpress' ] ) )->errorCode );
		$this->assertSame( 400, $this->statusOf( fn() => $this->api->entries( [ 'folder' => 'own', 'name' => 'twin.wpress', 'after' => '-1' ] ) ) );
		$this->assertSame( 400, $this->statusOf( fn() => $this->api->entries( [ 'folder' => 'own', 'name' => 'twin.wpress', 'limit' => 'all' ] ) ) );
		$after = $this->refusal( fn() => $this->api->entries( [ 'folder' => 'own', 'name' => 'twin.wpress', 'after' => '-1' ] ) );
		$this->assertSame( [ ErrorMap::INVALID, [ 'field' => 'after', 'generic' => true ] ], [ $after->errorCode, $after->data ] );
		$this->assertSame( 422, $this->statusOf( fn() => $this->api->entries( [ 'folder' => 'own', 'name' => 'twin.wpress', 'after' => '5' ] ) ), 'Not a header offset.' );
		file_put_contents( $this->dir . '/own/broken.wpress', 'not an archive' );
		$unreadable = $this->refusal( fn() => $this->api->entries( [ 'folder' => 'own', 'name' => 'broken.wpress' ] ) );
		$this->assertSame( 422, $unreadable->status );
		$this->assertSame( 'lw_migration_not_readable', $unreadable->errorCode );
		$this->assertStringNotContainsString( $this->dir, $unreadable->getMessage() );
	}

	public function test_a_download_token_names_one_backup_and_the_url_carries_only_the_token(): void {
		$before = time();
		$issued = $this->api->downloadToken( [ 'folder' => 'external', 'name' => 'twin.wpress' ], self::DOWNLOAD_URL );
		$after  = time();

		$this->assertSame( [ 'url', 'expires' ], array_keys( $issued ) );
		$this->assertSame( 1, preg_match( '#^' . preg_quote( self::DOWNLOAD_URL, '#' ) . '\?token=([a-f0-9]{64})\z#', $issued['url'], $match ), 'No nonce, folder or name in the link.' );
		$this->assertSame( [ 'folder' => 'external', 'name' => 'twin.wpress' ], ( new DownloadToken() )->validate( $match[1], 7 ) );
		$this->assertNull( ( new DownloadToken() )->validate( $match[1], 8 ), 'Bound to the user who asked.' );
		$this->assertGreaterThanOrEqual( $before + DownloadToken::DEFAULT_TTL, $issued['expires'] );
		$this->assertLessThanOrEqual( $after + DownloadToken::DEFAULT_TTL, $issued['expires'] );
	}

	public function test_the_link_expires_when_its_token_does(): void {
		$api    = new BackupsApi( new Repository( $this->dir . '/own', $this->dir . '/ext' ), $this->jobs, new DownloadToken( static fn(): int => 1000 ) );
		$issued = $api->downloadToken( [ 'folder' => 'own', 'name' => 'twin.wpress' ], self::DOWNLOAD_URL );

		$this->assertSame( 1000 + DownloadToken::DEFAULT_TTL, $issued['expires'], 'The token clock, not a second reading of the time.' );
		$this->assertSame( [ 1000 + DownloadToken::DEFAULT_TTL ], array_column( $this->transients, 'expires' ) );
	}

	public function test_no_download_token_for_a_missing_backup_or_without_a_login_session(): void {
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->downloadToken( [ 'folder' => 'own', 'name' => 'none.wpress' ], self::DOWNLOAD_URL ) ) );
		$this->assertSame( 404, $this->statusOf( fn() => $this->api->downloadToken( [ 'folder' => 'tmp', 'name' => 'twin.wpress' ], self::DOWNLOAD_URL ) ) );

		$this->session = '';
		$refused       = $this->refusal( fn() => $this->api->downloadToken( [ 'folder' => 'own', 'name' => 'twin.wpress' ], self::DOWNLOAD_URL ) );
		$this->assertSame( 403, $refused->status );
		$this->assertSame( 'lw_migration_download_refused', $refused->errorCode );
		$this->assertSame( [], $this->transients );
	}
}
