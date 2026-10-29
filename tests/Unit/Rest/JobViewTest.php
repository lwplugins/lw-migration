<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Rest\JobView;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use PHPUnit\Framework\TestCase;

final class JobViewTest extends TestCase {

	private const KEYS = [ 'id', 'type', 'status', 'step', 'steps', 'stepIndex', 'progress', 'message', 'warnings', 'warningCount', 'error', 'createdAt', 'updatedAt', 'input', 'pastNoReturn', 'cancelDeferred', 'source', 'result', 'link' ];

	/** What Services::redactPaths() would hand over (storage root, ABSPATH, WP_CONTENT_DIR). */
	private const PATHS = [ '/srv/www/wp-content/lw-migration-0123456789ab', '/srv/www/', '/srv/www/wp-content' ];

	private const SUMMARY = [ 'from', 'to', 'wordpress', 'php', 'archiveSize', 'encrypted', 'compression', 'overwrites', 'subsite', 'safetyBackup', 'safetyBytes', 'diskNeeded', 'diskFree', 'lowDiskSpace', 'prefix', 'createdAt', 'expandedSize', 'parts', 'replacesAddress', 'keepsThisPlugin' ];

	private static function waitingUrlImport(): Job {
		$job          = new Job( '0123456789abcdef', 'import', [ 'url_resolve', 'url_download', 'validate', 'confirm' ], 3 );
		$job->status  = Job::NEEDS_INPUT;
		$job->options = [
			'source'       => 'url',
			'location'     => 'https://user:pw@files.test/share/abc/site.wpress?token=tok3n',
			'safetyBackup' => true,
		];
		$job->state   = [
			'download'     => [
				'url'    => 'https://cdn.files.test/x/site.wpress?sig=s1gn',
				'size'   => 10,
				'ranges' => true,
			],
			'archive'      => '/srv/www/wp-content/lw-migration-0123456789ab/jobs/0123456789abcdef/download.wpress',
			'safetyBackup' => [
				'name' => 'pre-import-20261007-101500.wpress',
				'path' => '/srv/www/wp-content/lw-migration-0123456789ab/backups/pre-import-20261007-101500.wpress',
			],
			'site'         => [ 'abspath' => '/srv/www/' ],
		];
		$job->cursor   = [ 'target' => '/srv/www/x' ];
		$job->secrets  = [ 'password' => 'hunter2-secret' ];
		$job->message  = 'Checking „/srv/www/wp-content/lw-migration-0123456789ab/jobs/0123456789abcdef/download.wpress”';
		$job->warnings = [
			'Skipped /srv/www/wp-content/uploads/a.jpg (unreadable).',
			'The server at https://user:pw@files.test/share/abc/site.wpress?token=tok3n answered 503.',
		];
		$job->error    = 'Earlier: cannot open /srv/www/wp-config.php; redirected to https://cdn.files.test/x/site.wpress?sig=s1gn';
		$job->input   = [
			'kind' => 'confirm',
			'data' => [
				'from'        => 'https://old.test',
				'to'          => 'https://new.test',
				'path'        => '/srv/www/leak',
				'overwrites'  => [ 'database' => true ],
				'diskFree'    => 5,
				'safetyBytes' => 7,
			],
		];

		return $job;
	}

	public function test_paths_addresses_and_secrets_never_leave_the_server(): void {
		$view = JobView::of( self::waitingUrlImport(), false, self::PATHS );
		$json = (string) json_encode( $view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		foreach ( [ '/srv/', 'tok3n', 's1gn', 'user:pw', 'hunter2', 'lw-migration-0123456789ab', 'cdn.files.test/x' ] as $private ) {
			$this->assertStringNotContainsString( $private, $json, $private );
		}
		$this->assertSame( self::KEYS, array_keys( $view ) );
		$this->assertSame( 'confirm', $view['step'] );
		$this->assertSame( [ 'kind' => 'url', 'name' => 'https://files.test/…/site.wpress' ], $view['source'] );
		$this->assertSame(
			[
				'safetyBackup' => 'pre-import-20261007-101500.wpress',
				'loginKept'    => null,
			],
			$view['result']
		);
		$this->assertSame( self::SUMMARY, array_keys( $view['input']['summary'] ) );
		$this->assertSame( [ 'database' => true, 'media' => false, 'plugins' => false, 'themes' => false ], $view['input']['summary']['overwrites'] );
		$this->assertSame( 5, $view['input']['summary']['diskFree'] );
		$this->assertSame( 7, $view['input']['summary']['safetyBytes'] );
		$this->assertSame( [ 'archive' => '', 'site' => '' ], $view['input']['summary']['php'] );
		$this->assertSame( 'Checking „jobs/0123456789abcdef/download.wpress”', $view['message'] );
		$this->assertSame( [ 'Skipped uploads/a.jpg (unreadable).', 'The server at https://files.test/…/site.wpress answered 503.' ], $view['warnings'] );
		$this->assertSame( 'Earlier: cannot open wp-config.php; redirected to https://cdn.files.test/…/site.wpress', $view['error'] );
	}

	public function test_a_summary_without_the_comparison_fields_marks_them_unknown(): void {
		$summary = JobView::of( self::waitingUrlImport(), false, self::PATHS )['input']['summary'];

		$this->assertSame( [ 'archive' => null, 'site' => '' ], $summary['prefix'] );
		$this->assertNull( $summary['createdAt'] );
		$this->assertNull( $summary['expandedSize'] );
		$this->assertNull( $summary['replacesAddress'] );
		$this->assertTrue( $summary['keepsThisPlugin'] );
		$unknown = [ 'included' => null, 'bytes' => null, 'count' => null ];
		$this->assertSame( array_fill_keys( [ 'database', 'media', 'plugins', 'themes', 'muPlugins' ], $unknown ), $summary['parts'] );
	}

	public function test_the_comparison_fields_pass_only_the_whitelisted_keys(): void {
		$job                  = self::waitingUrlImport();
		$job->input['data']  += [
			'prefix'          => [
				'archive' => 'wp_',
				'site'    => 'xyz_',
				'path'    => '/srv/www/leak',
			],
			'createdAt'       => '1791386979',
			'expandedSize'    => 1024,
			'parts'           => [
				'database'  => [ 'included' => true, 'bytes' => '40219', 'count' => null ],
				'media'     => [ 'included' => true, 'bytes' => 17, 'count' => 1, 'names' => [ '/srv/www/leak' ] ],
				'plugins'   => [ 'included' => false, 'bytes' => 0, 'count' => 0 ],
				'themes'    => 'not a part',
				'secret'    => [ 'included' => true ],
			],
			'replacesAddress' => false,
			'keepsThisPlugin' => true,
		];

		$summary = JobView::of( $job, false, self::PATHS )['input']['summary'];

		$this->assertSame( [ 'archive' => 'wp_', 'site' => 'xyz_' ], $summary['prefix'] );
		$this->assertSame( 1791386979, $summary['createdAt'] );
		$this->assertSame( 1024, $summary['expandedSize'] );
		$this->assertFalse( $summary['replacesAddress'] );
		$this->assertSame(
			[
				'database'  => [ 'included' => true, 'bytes' => 40219, 'count' => null ],
				'media'     => [ 'included' => true, 'bytes' => 17, 'count' => 1 ],
				'plugins'   => [ 'included' => false, 'bytes' => 0, 'count' => 0 ],
				'themes'    => [ 'included' => null, 'bytes' => null, 'count' => null ],
				'muPlugins' => [ 'included' => null, 'bytes' => null, 'count' => null ],
			],
			$summary['parts']
		);
		$this->assertStringNotContainsString( '/srv/', (string) json_encode( $summary, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_input_kinds_are_reduced_to_what_the_screen_needs(): void {
		$job         = new Job( '0123456789abcdef', 'import', [ 'decrypt' ] );
		$job->status = Job::NEEDS_INPUT;
		$cases       = [
			[ [ 'kind' => 'password', 'data' => [ 'reason' => '<b>x</b>' ] ], [ 'kind' => 'password', 'reason' => 'encrypted' ] ],
			[ [ 'kind' => 'password', 'data' => [ 'reason' => 'wrong' ] ], [ 'kind' => 'password', 'reason' => 'wrong' ] ],
			[ [ 'kind' => 'upload', 'data' => [ 'received' => '12', 'size' => 30, 'chunkBytes' => '262144', 'path' => '/srv' ] ], [ 'kind' => 'upload', 'received' => 12, 'size' => 30, 'chunkBytes' => 262144 ] ],
			[ [ 'kind' => 'teleport', 'data' => [ 'x' => 1 ] ], [ 'kind' => 'unknown' ] ],
		];
		foreach ( $cases as [ $input, $expected ] ) {
			$job->input = $input;
			$this->assertSame( $expected, JobView::of( $job, false, self::PATHS )['input'] );
		}
		$job->status = Job::RUNNING;
		$this->assertNull( JobView::of( $job, false, self::PATHS )['input'], 'A running job asks nothing.' );
	}

	public function test_an_export_names_its_backup_without_the_path(): void {
		$job               = new Job( '0123456789abcdef', 'export', [ 'store', 'clean' ], 2 );
		$job->status       = Job::DONE;
		$job->state['backup'] = [
			'name' => 'site-test-20261007-101500-001.wpress',
			'path' => '/srv/www/wp-content/lw-migration-0123456789ab/backups/site-test-20261007-101500-001.wpress',
			'size' => 123,
		];

		$view = JobView::of( $job, false, self::PATHS );
		$this->assertStringNotContainsString( '/srv/', (string) json_encode( $view, JSON_UNESCAPED_SLASHES ), 'state.backup.path stays on the server.' );
		$this->assertSame( [ 'backup' => [ 'name' => 'site-test-20261007-101500-001.wpress', 'size' => 123, 'local' => true ], 'cloud' => [] ], $view['result'] );
		$this->assertNull( $view['source'] );
		$this->assertSame( '', $view['step'] );
		$job->state = [];
		$this->assertSame( [ 'backup' => null, 'cloud' => [] ], JobView::of( $job, false, self::PATHS )['result'] );
	}

	public function test_a_reset_names_its_kind_and_its_backup_without_the_path(): void {
		$job          = new Job( '0123456789abcdef', 'reset', [ 'reset_backup', 'reset_media' ], 2 );
		$job->status  = Job::DONE;
		$job->options = [
			'type'   => 'media',
			'backup' => true,
		];
		$job->state   = [
			'backup' => [
				'name' => 'pre-reset-20261007-090000.wpress',
				'size' => 123,
				'path' => '/srv/www/wp-content/lw-migration-0123456789ab/backups/pre-reset-20261007-090000.wpress',
			],
		];

		$none = [
			'plugins'      => null,
			'themes'       => null,
			'media'        => null,
			'database'     => null,
			'defaultTheme' => null,
			'dropIns'      => [],
			'user'         => '',
		];
		$this->assertSame( [ 'kind' => 'media', 'backup' => [ 'name' => 'pre-reset-20261007-090000.wpress', 'size' => 123 ] ] + $none, JobView::of( $job, false, self::PATHS )['result'] );
		$job->state = [];
		$this->assertSame( [ 'kind' => 'media', 'backup' => null ] + $none, JobView::of( $job, false, self::PATHS )['result'], 'A reset without a backup.' );
	}

	public function test_a_finished_code_reset_tells_what_went_and_which_theme_is_active(): void {
		$job          = new Job( '0123456789abcdef', 'reset', [ 'reset_plugins', 'reset_themes' ], 2 );
		$job->status  = Job::DONE;
		$job->options = [
			'type'   => 'full',
			'backup' => false,
		];
		$job->state   = [
			'removed'      => [
				'plugins' => [
					'count'     => 2,
					'names'     => [ 'akismet/akismet.php', 'hello.php' ],
					'leftovers' => 1,
				],
				'themes'  => [
					'count' => 1,
					'names' => [ 'old' ],
				],
			],
			'defaultTheme' => [
				'slug'   => 'twentytwentyfive',
				'source' => 'download',
			],
			'dropIns'      => [ 'advanced-cache.php' ],
			'admin'        => [
				'id'    => 3,
				'login' => 'anna',
			],
		];
		$this->assertSame(
			[
				'kind'         => 'full',
				'backup'       => null,
				'plugins'      => [
					'count'     => 2,
					'names'     => [ 'akismet/akismet.php', 'hello.php' ],
					'leftovers' => 1,
				],
				'themes'       => [
					'count'     => 1,
					'names'     => [ 'old' ],
					'leftovers' => 0,
				],
				'media'        => null,
				'database'     => null,
				'defaultTheme' => [
					'slug'   => 'twentytwentyfive',
					'source' => 'download',
				],
				'dropIns'      => [ 'advanced-cache.php' ],
				'user'         => 'anna',
			],
			JobView::of( $job, false, self::PATHS )['result']
		);
	}

	public function test_a_finished_media_reset_tells_what_went_and_what_stayed(): void {
		$job          = new Job( '0123456789abcdef', 'reset', [ 'reset_media' ], 1 );
		$job->status  = Job::DONE;
		$job->options = [
			'type'   => 'media',
			'backup' => false,
		];
		$job->state   = [
			'removed' => [
				'media' => [
					'attachments' => 12,
					'files'       => '40',
					'kept'        => 1,
				],
			],
		];
		$this->assertSame(
			[
				'attachments' => 12,
				'files'       => 40,
				'failed'      => 0,
				'kept'        => 1,
			],
			JobView::of( $job, false, self::PATHS )['result']['media']
		);
	}

	public function test_a_finished_restore_says_whether_the_login_was_kept(): void {
		$job         = new Job( '0123456789abcdef', 'import', [ 'preserve', 'done' ], 2 );
		$job->status = Job::DONE;

		$job->state['loginKept'] = true;
		$this->assertTrue( JobView::of( $job, false, self::PATHS )['result']['loginKept'] );
		$job->state['loginKept'] = false;
		$this->assertFalse( JobView::of( $job, false, self::PATHS )['result']['loginKept'] );

		$job->state['loginKept'] = 'yes';
		$this->assertNull( JobView::of( $job, false, self::PATHS )['result']['loginKept'], 'Only a boolean is passed on.' );
		unset( $job->state['loginKept'] );
		$this->assertNull( JobView::of( $job, false, self::PATHS )['result']['loginKept'], 'Unknown when PreserveStep did not run.' );

		$job->state['loginKept'] = true;
		foreach ( [ Job::RUNNING, Job::FAILED, Job::CANCELLED ] as $status ) {
			$job->status = $status;
			$this->assertNull( JobView::of( $job, false, self::PATHS )['result']['loginKept'], $status );
		}
	}

	public function test_a_network_confirmation_passes_only_the_whitelisted_keys(): void {
		$job                        = self::waitingUrlImport();
		$job->input['data']['network'] = [
			'kind'     => 'sites',
			'secret'   => '/srv/www/leak',
			'sites'    => [
				[
					'index'    => 0,
					'from'     => [
						'blogId' => 2,
						'url'    => 'http://old.test/b2',
						'path'   => '/srv/www/leak',
					],
					'proposal' => 'http://net.test/b2',
					'address'  => 'http://net.test/shop',
					'existing' => 4,
					'error'    => null,
					'old'      => [ 'uploadsDir' => '/srv/www/leak' ],
				],
				[
					'index'    => '1',
					'from'     => [ 'blogId' => '3' ],
					'proposal' => 'http://net.test/b3',
					'address'  => 'http://net.test/b2',
					'existing' => null,
					'error'    => 'This address is already taken.',
				],
				'not a site',
			],
			'unlisted' => [
				[
					'id'   => 5,
					'url'  => 'http://net.test/other',
					'path' => '/srv/www/leak',
				],
			],
			'error'    => 'The archive has no site number 7.',
		];

		$summary = JobView::of( $job, false, self::PATHS )['input']['summary'];

		$this->assertSame( array_merge( self::SUMMARY, [ 'network' ] ), array_keys( $summary ) );
		$this->assertSame(
			[
				'kind'     => 'sites',
				'sites'    => [
					[
						'index'    => 0,
						'from'     => [
							'blogId' => 2,
							'url'    => 'http://old.test/b2',
						],
						'proposal' => 'http://net.test/b2',
						'address'  => 'http://net.test/shop',
						'existing' => 4,
						'error'    => null,
					],
					[
						'index'    => 1,
						'from'     => [
							'blogId' => 3,
							'url'    => '',
						],
						'proposal' => 'http://net.test/b3',
						'address'  => 'http://net.test/b2',
						'existing' => null,
						'error'    => 'This address is already taken.',
					],
				],
				'unlisted' => [
					[
						'id'  => 5,
						'url' => 'http://net.test/other',
					],
				],
				'error'    => 'The archive has no site number 7.',
			],
			$summary['network']
		);
		$this->assertStringNotContainsString( '/srv/', (string) json_encode( $summary, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_a_finished_network_restore_lists_its_sites(): void {
		$job                     = new Job( '0123456789abcdef', 'import', [ 'done' ], 1 );
		$job->status             = Job::DONE;
		$job->state['summary']   = [
			'safetyBackup' => null,
			'sites'        => [
				[
					'url'     => 'http://net.test/shop/',
					'created' => true,
					'path'    => '/srv/www/leak',
				],
				[
					'url'     => 'http://net.test/b3/',
					'created' => 'no',
				],
				'x',
			],
		];
		$result = JobView::of( $job, false, self::PATHS )['result'];

		$this->assertSame(
			[
				[
					'url'     => 'http://net.test/shop/',
					'created' => true,
				],
				[
					'url'     => 'http://net.test/b3/',
					'created' => false,
				],
			],
			$result['sites']
		);

		unset( $job->state['summary']['sites'] );
		$this->assertSame( [ 'safetyBackup', 'loginKept' ], array_keys( JobView::of( $job, false, self::PATHS )['result'] ), 'A single-site restore keeps its shape.' );
	}

	public function test_sources_and_flags(): void {
		$job          = new Job( '0123456789abcdef', 'import', [ 'upload' ] );
		$job->options = [ 'source' => 'upload', 'location' => 'site.wpress' ];
		$job->state   = [
			'upload'         => [ 'size' => 9000 ],
			'cancelDeferred' => true,
		];
		$view         = JobView::of( $job, true, self::PATHS );
		$this->assertSame( [ 'kind' => 'upload', 'name' => 'site.wpress', 'size' => 9000 ], $view['source'] );
		$this->assertTrue( $view['pastNoReturn'] );
		$this->assertTrue( $view['cancelDeferred'] );

		$job->options = [ 'source' => 'backup', 'location' => 'b.wpress', 'folder' => 'external' ];
		$this->assertSame( [ 'kind' => 'backup', 'name' => 'b.wpress', 'folder' => 'external' ], JobView::of( $job, false, self::PATHS )['source'] );
		$job->options = [ 'source' => 'file', 'location' => '/srv/www/backups/c.wpress' ];
		$this->assertSame( [ 'kind' => 'file', 'name' => 'c.wpress' ], JobView::of( $job, false, self::PATHS )['source'], 'A job started from WP-CLI shows only the file name.' );
	}

	public function test_a_job_names_its_connection_without_the_ref(): void {
		$job          = new Job( '0123456789abcdef', 'import', [ 'upload' ] );
		$job->options = [ 'source' => 'upload', 'location' => 'site.wpress' ];
		$this->assertNull( JobView::of( $job, false, self::PATHS )['link'], 'A plain job has no connection.' );

		$job->state['link'] = [
			'id'      => 'fedcba9876543210',
			'ref'     => 'aaaabbbbccccdddd',
			'name'    => 'Site A',
			'address' => 'https://a.example/blog?x=secret-query',
		];
		$view = JobView::of( $job, false, self::PATHS );
		$this->assertSame(
			[
				'id'      => 'fedcba9876543210',
				'name'    => 'Site A',
				'address' => 'https://a.example/blog',
			],
			$view['link']
		);
		$this->assertStringNotContainsString( 'aaaabbbbccccdddd', (string) json_encode( $view ), 'The ref stays on the server.' );
		$this->assertStringNotContainsString( 'secret-query', (string) json_encode( $view ) );

		$job->state['link'] = [ 'id' => 'fedcba9876543210' ];
		$this->assertSame( [ 'id' => 'fedcba9876543210', 'name' => '', 'address' => '(address)' ], JobView::of( $job, false, self::PATHS )['link'] );
		$job->state['link'] = [ 'id' => 7 ];
		$this->assertNull( JobView::of( $job, false, self::PATHS )['link'], 'A malformed connection is not shown.' );
		$job->state['link'] = 'x';
		$this->assertNull( JobView::of( $job, false, self::PATHS )['link'] );
	}

	public function test_a_pull_names_the_other_site_as_its_source(): void {
		$job                    = new Job( '0123456789abcdef', 'import', [ 'link_export', 'link_download', 'validate' ], 1 );
		$job->options           = [
			'source'       => 'link',
			'location'     => 'fedcba9876543210',
			'safetyBackup' => true,
		];
		$job->state['link']     = [
			'id'      => 'fedcba9876543210',
			'name'    => 'Site B',
			'address' => 'https://b.example/shop',
		];
		$job->state['remote']   = [
			'job'     => 'bbbbbbbbbbbbbbbb',
			'message' => 'Exporting the database',
		];
		$job->state['download'] = [
			'url'    => 'https://b.example/shop/index.php?rest_route=/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/archive',
			'size'   => 9000,
			'ranges' => true,
		];

		$view = JobView::of( $job, false, self::PATHS );

		$this->assertSame(
			[
				'kind' => 'link',
				'name' => 'Site B',
			],
			$view['source']
		);
		$json = (string) json_encode( $view );
		$this->assertStringNotContainsString( 'bbbbbbbbbbbbbbbb', $json, 'Neither the other site\'s job nor its archive address leaves the server.' );
		$this->assertStringNotContainsString( 'rest_route', $json );

		$job->state['link']['name'] = '';
		$this->assertSame( 'https://b.example/shop', JobView::of( $job, false, self::PATHS )['source']['name'], 'Without a name the address shows.' );
	}

	public function test_a_push_names_its_target_and_the_other_sites_safety_backup(): void {
		$job                  = new Job( '0123456789abcdef', 'push', [ 'link_upload' ] );
		$job->options         = [ 'noMedia' => true ];
		$job->state['link']   = [
			'id'      => 'fedcba9876543210',
			'name'    => 'Site B',
			'address' => 'https://user:pw@b.example/shop?token=abc',
		];
		$job->state['remote'] = [
			'job'              => 'bbbbbbbbbbbbbbbb',
			'safetyBackupName' => 'pre-import-20261009-120000.wpress',
		];

		$view = JobView::of( $job, false, self::PATHS );

		$this->assertSame(
			[
				'target'       => 'https://b.example/shop',
				'safetyBackup' => 'pre-import-20261009-120000.wpress',
			],
			$view['result']
		);
		$this->assertNull( $view['source'] );
		$this->assertSame( 'Site B', $view['link']['name'] );
		$this->assertStringNotContainsString( 'bbbbbbbbbbbbbbbb', (string) json_encode( $view ), 'The other site\'s job id stays on the server.' );
		$this->assertStringNotContainsString( 'token', (string) json_encode( $view ) );

		unset( $job->state['remote']['safetyBackupName'] );
		$this->assertNull( JobView::of( $job, false, self::PATHS )['result']['safetyBackup'] );
	}

	public function test_a_move_job_reports_what_moved(): void {
		$job          = new Job( '0123456789abcdef', 'move', [ 'move', 'finish' ], 1 );
		$job->status  = Job::DONE;
		$job->options = [
			'from'    => '/srv/old-backups',
			'fromDir' => '/srv/old-backups',
			'toDir'   => '/srv/www/lw-migration-backups-0123456789ab',
			'after'   => '{"path":"/srv/www/lw-migration-backups-0123456789ab","moving":null,"marker":"fedcba9876543210"}',
		];
		$job->state   = [
			'total'     => [
				'count' => 3,
				'bytes' => 30,
			],
			'moved'     => [
				'count' => 2,
				'bytes' => 20,
			],
			'fromFound' => true,
		];
		$job->cursor  = [ 'temp' => '.lwm-0123456789abcdef.part' ];

		$view = JobView::of( $job, false, self::PATHS );

		$this->assertSame( self::KEYS, array_keys( $view ) );
		$this->assertSame(
			[
				'moved' => 2,
				'bytes' => 20,
				'total' => 3,
			],
			$view['result']
		);
		$this->assertNull( $view['source'] );
		$json = (string) json_encode( $view, JSON_UNESCAPED_SLASHES );
		foreach ( [ '/srv', 'lw-migration-backups-0123456789ab', 'fedcba9876543210', '.lwm-' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_a_cloud_job_names_its_action_its_backup_and_each_destination_without_paths(): void {
		$job                  = new Job( '0123456789abcdef', 'cloud', [ 'cloud_upload' ], 2 );
		$job->options         = [ 'action' => 'upload' ];
		$job->state['backup'] = [
			'name' => 'a.wpress',
			'path' => '/srv/www/wp-content/lw-migration-0123456789ab/backups/a.wpress',
			'size' => 3,
		];
		$job->state['cloud']  = CloudPlan::upload( [ 'aaaa1111', 'bbbb2222' ] )->toArray();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::FAILED, 'One', 's3', 'Denied.' );

		$view = JobView::of( $job, false, self::PATHS );

		$this->assertSame(
			[
				'action' => 'upload',
				'backup' => [ 'name' => 'a.wpress', 'size' => 3 ],
				'cloud'  => [
					[ 'id' => 'aaaa1111', 'name' => 'One', 'type' => 's3', 'status' => 'failed', 'message' => 'Denied.', 'pruned' => 0 ],
					[ 'id' => 'bbbb2222', 'name' => '', 'type' => '', 'status' => 'pending', 'message' => '', 'pruned' => 0 ],
				],
			],
			$view['result']
		);
		$this->assertStringNotContainsString( '/srv/', (string) json_encode( $view, JSON_UNESCAPED_SLASHES ) );
		$fetch          = new Job( '0123456789abcdef', 'cloud', [ 'cloud_download' ], 2 );
		$fetch->options = [ 'action' => 'fetch' ];
		$this->assertSame( [ 'action' => 'fetch', 'backup' => null, 'cloud' => [] ], JobView::of( $fetch, false, self::PATHS )['result'] );
	}
}
