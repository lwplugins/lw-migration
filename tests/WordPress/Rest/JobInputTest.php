<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\JobInput;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class JobInputTest extends MonkeyTestCase {

	private string $dir;

	private Repository $backups;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_main_network_id' )->justReturn( 1 ); // NetworkReader keeps to the main network.
		Functions\when( 'size_format' )->alias( static fn( $bytes ) => $bytes . ' B' );
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		file_put_contents( $this->dir . '/ext/old.wpress', 'x' );
		$this->backups = new Repository( $this->dir . '/own', $this->dir . '/ext' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * Only our RestException counts as a refusal: a plain InvalidArgumentException
	 * would reach the user as ErrorMap's generic text, so it fails the test.
	 */
	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	/** @return int HTTP status of the refusal. */
	private function refused( \Closure $call ): int {
		return $this->refusal( $call )->status;
	}

	public function test_export_options_keep_only_known_keys(): void {
		$o = JobInput::exportOptions(
			[
				'noMedia'      => true,
				'compression'  => 'gzip',
				'excludeFiles' => [ 'cache/x', '' ],
				'replace'      => [ [ 'old.test', 'new.test' ] ],
				'encrypt'      => true,
			]
		);

		$this->assertTrue( $o->noMedia );
		$this->assertFalse( $o->noPlugins );
		$this->assertSame( 'gzip', $o->compression );
		$this->assertSame( [ 'cache/x' ], $o->excludeFiles );
		$this->assertSame( [ [ 'old.test', 'new.test' ] ], $o->replace );
		$this->assertTrue( $o->encrypt );
		$this->assertSame( 'backup', $o->kind );
		$this->assertFalse( $o->noFiles );

		$this->assertSame( ' keep ', JobInput::exportOptions( [ 'replace' => [ [ ' keep ', 'x' ] ] ] )->replace[0][0], 'Replace values are never trimmed.' );
		$this->assertSame( '', JobInput::exportOptions( [] )->compression );
	}

	public function test_export_options_take_the_chosen_sites_of_a_network(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$db        = \Mockery::mock();
		$db->blogs = 'wp_blogs';
		$db->shouldReceive( 'prepare' )->andReturn( 'SELECT' );
		$db->shouldReceive( 'get_col' )->andReturn( [ '2', '3' ] );
		$GLOBALS['wpdb'] = $db;
		try {
			$this->assertSame( [ 2, 3 ], JobInput::exportOptions( [ 'sites' => [ 3, 2 ] ] )->sites );
			$this->assertSame( [], JobInput::exportOptions( [ 'sites' => [] ] )->sites, 'An empty choice is the whole network.' );
			foreach ( [ [ '2' ], [ 2, 2 ], [ 0 ], [ 2, 99 ], 'all' ] as $sites ) {
				$this->assertSame( 'sites', $this->refusal( static fn() => JobInput::exportOptions( [ 'sites' => $sites ] ) )->data['field'], (string) json_encode( $sites ) );
			}
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_a_single_site_export_takes_no_site_choice(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertSame( [], JobInput::exportOptions( [ 'sites' => [] ] )->sites );
		$this->assertSame( 'sites', $this->refusal( static fn() => JobInput::exportOptions( [ 'sites' => [ 1 ] ] ) )->data['field'] );
	}

	public function test_export_options_refuse_unknown_keys(): void {
		$bad = [
			'safety naming' => [ 'kind' => 'pre-import' ],
			'database only' => [ 'noFiles' => true ],
			'typo'          => [ 'nomedia' => true ],
			'a list'        => [ true ],
		];
		foreach ( $bad as $label => $raw ) {
			$this->assertSame( 400, $this->refused( static fn() => JobInput::exportOptions( $raw ) ), $label );
		}
	}

	public function test_export_options_refuse_wrong_types_and_sizes(): void {
		$bad = [
			'not an object'       => 'all',
			'flag as text'        => [ 'noMedia' => 'yes' ],
			'flag as zero text'   => [ 'noMedia' => '0' ],
			'flag as false text'  => [ 'encrypt' => 'false' ],
			'flag as number'      => [ 'noMedia' => 1 ],
			'flag as null'        => [ 'noMedia' => null ],
			'list as text'        => [ 'excludeFiles' => 'cache' ],
			'list as object'      => [ 'excludeFiles' => [ 'a' => 'cache' ] ],
			'list item as array'  => [ 'excludeTables' => [ [ 'wp_x' ] ] ],
			'list item as number' => [ 'excludeTables' => [ 5 ] ],
			'too many entries'    => [ 'excludeTables' => array_fill( 0, 501, 'wp_x' ) ],
			'too long an entry'   => [ 'excludeTables' => [ str_repeat( 'x', 1025 ) ] ],
			'control character'   => [ 'excludeTables' => [ "wp_a\nwp_b" ] ],
			'half a pair'         => [ 'replace' => [ [ 'old.test' ] ] ],
			'pair as object'      => [ 'replace' => [ [ 'from' => 'a', 'to' => 'b' ] ] ],
			'three in a pair'     => [ 'replace' => [ [ 'a', 'b', 'c' ] ] ],
			'empty old value'     => [ 'replace' => [ [ '', 'new.test' ] ] ],
			'empty new value'     => [ 'replace' => [ [ 'old.test', '' ] ] ],
			'blank old value'     => [ 'replace' => [ [ '  ', 'new.test' ] ] ],
			'too many pairs'      => [ 'replace' => array_fill( 0, 101, [ 'a', 'b' ] ) ],
			'compression as bool' => [ 'compression' => true ],
			'unknown codec'       => [ 'compression' => 'zip' ],
			'path out'            => [ 'excludeFiles' => [ '../wp-config.php' ] ],
		];
		foreach ( $bad as $label => $raw ) {
			$this->assertSame( 400, $this->refused( static fn() => JobInput::exportOptions( $raw ) ), $label );
		}
	}

	public function test_refusals_carry_our_translated_text(): void {
		$e = $this->refusal( static fn() => JobInput::exportOptions( [ 'compression' => 'zip' ] ) );
		$this->assertSame( ErrorMap::INVALID, $e->errorCode );
		$this->assertStringContainsString( 'Unknown compression type', $e->getMessage() );

		$e = $this->refusal( static fn() => JobInput::exportOptions( [ 'noMedia' => 'yes' ] ) );
		$this->assertSame( ErrorMap::INVALID, $e->errorCode );
		$this->assertStringContainsString( 'noMedia', $e->getMessage() );

		$e = $this->refusal( fn() => JobInput::importSource( [ 'source' => 'url', 'url' => 'ftp://files.test/a.wpress' ], $this->backups, 0 ) );
		$this->assertSame( ErrorMap::INVALID, $e->errorCode );
		$this->assertStringContainsString( 'http', $e->getMessage() );
	}

	public function test_validation_refusals_have_one_code_and_name_their_field(): void {
		$job         = new Job( '0123456789abcdef', 'import', [ 'decrypt' ] );
		$job->status = Job::NEEDS_INPUT;
		$job->input  = [ 'kind' => 'password' ];
		$cases       = [
			'options'      => static fn() => JobInput::exportOptions( 'x' ),
			'noMedia'      => static fn() => JobInput::exportOptions( [ 'noMedia' => 'yes' ] ),
			'source'       => fn() => JobInput::importSource( [ 'source' => 'file' ], $this->backups, 10000 ),
			'name'         => fn() => JobInput::importSource( [ 'source' => 'upload', 'name' => '../x.wpress', 'size' => 9000 ], $this->backups, 10000 ),
			'size'         => fn() => JobInput::importSource( [ 'source' => 'upload', 'name' => 'x.wpress', 'size' => 100 ], $this->backups, 10000 ),
			'url'          => fn() => JobInput::importSource( [ 'source' => 'url', 'url' => 'ftp://files.test/a.wpress' ], $this->backups, 0 ),
			'safetyBackup' => fn() => JobInput::importSource( [ 'source' => 'url', 'url' => 'https://files.test/a.wpress', 'safetyBackup' => 'no' ], $this->backups, 0 ),
			'password'     => static fn() => JobInput::answer( $job, [ 'password' => '' ] ),
		];
		// A specific text is shown as is; the generic "not accepted" one is marked, so the browser shows its own hint.
		$specific = [ 'source', 'name', 'size', 'url' ];
		foreach ( $cases as $field => $call ) {
			$e    = $this->refusal( $call );
			$data = in_array( $field, $specific, true ) ? [ 'field' => $field ] : [
				'field'   => $field,
				'generic' => true,
			];
			$this->assertSame( [ 400, ErrorMap::INVALID, $data ], [ $e->status, $e->errorCode, $e->data ], $field );
		}
	}

	public function test_whole_numbers(): void {
		$this->assertSame( 0, JobInput::wholeNumber( 0, 'offset' ) );
		$this->assertSame( 42, JobInput::wholeNumber( 42, 'offset' ) );
		$this->assertSame( 8388608, JobInput::wholeNumber( '8388608', 'after' ) );
		foreach ( [ -1, '-1', '1.5', 1.0, '', ' 5', '5 ', '0x10', str_repeat( '9', 19 ), null, [ 1 ], true ] as $bad ) {
			$e = $this->refusal( static fn() => JobInput::wholeNumber( $bad, 'limit' ) );
			$this->assertSame( [ 400, ErrorMap::INVALID, [ 'field' => 'limit', 'generic' => true ] ], [ $e->status, $e->errorCode, $e->data ], var_export( $bad, true ) );
		}
	}

	public function test_passwords(): void {
		$this->assertNull( JobInput::password( null ) );
		$this->assertNull( JobInput::password( '' ) );
		$this->assertSame( ' spaced pw ', JobInput::password( ' spaced pw ' ), 'Passwords are never trimmed.' );
		foreach ( [ 5, 0, false, [ 'pw' ], str_repeat( 'p', 1025 ), "pw\0" ] as $raw ) {
			$this->assertSame( 400, $this->refused( static fn() => JobInput::password( $raw ) ) );
		}
	}

	public function test_import_sources(): void {
		$upload = JobInput::importSource( [ 'source' => 'upload', 'name' => 'site.wpress', 'size' => 9000 ], $this->backups, 10000 );
		$this->assertSame( [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => true ], $upload['options']->toArray() );
		$this->assertSame( [ 'upload' => [ 'size' => 9000 ] ], $upload['state'] );

		$backup = JobInput::importSource( [ 'source' => 'backup', 'name' => 'old.wpress', 'folder' => 'external', 'safetyBackup' => false ], $this->backups, 0 );
		$this->assertSame( [ 'source' => 'backup', 'location' => 'old.wpress', 'safetyBackup' => false, 'folder' => 'external' ], $backup['options']->toArray() );
		$this->assertSame( [], $backup['state'] );

		$url = JobInput::importSource( [ 'type' => 'import', 'password' => 'pw', 'source' => 'url', 'url' => 'https://files.test/site.wpress' ], $this->backups, 0 );
		$this->assertSame( [ 'source' => 'url', 'location' => 'https://files.test/site.wpress', 'safetyBackup' => true ], $url['options']->toArray(), 'The job request fields JobsApi checks itself may sit next to the source.' );

		$refusals = [
			'server file'        => [ [ 'source' => 'file', 'location' => '/etc/passwd.wpress' ], 400 ],
			'no source'          => [ [], 400 ],
			'source as array'    => [ [ 'source' => [ 'url' ] ], 400 ],
			'unknown key'        => [ [ 'source' => 'url', 'url' => 'https://files.test/a.wpress', 'location' => '/etc/passwd.wpress' ], 400 ],
			'foreign source key' => [ [ 'source' => 'upload', 'name' => 'x.wpress', 'size' => 9000, 'folder' => 'own' ], 400 ],
			'upload name'        => [ [ 'source' => 'upload', 'name' => '../x.wpress', 'size' => 9000 ], 400 ],
			'upload name type'   => [ [ 'source' => 'upload', 'name' => [ 'x.wpress' ], 'size' => 9000 ], 400 ],
			'upload size type'   => [ [ 'source' => 'upload', 'name' => 'x.wpress', 'size' => '9000' ], 400 ],
			'upload size float'  => [ [ 'source' => 'upload', 'name' => 'x.wpress', 'size' => 9000.0 ], 400 ],
			'upload no size'     => [ [ 'source' => 'upload', 'name' => 'x.wpress' ], 400 ],
			'upload too small'   => [ [ 'source' => 'upload', 'name' => 'x.wpress', 'size' => 100 ], 400 ],
			'upload too large'   => [ [ 'source' => 'upload', 'name' => 'x.wpress', 'size' => 10001 ], 400 ],
			'missing backup'     => [ [ 'source' => 'backup', 'name' => 'old.wpress', 'folder' => 'own' ], 404 ],
			'default folder'     => [ [ 'source' => 'backup', 'name' => 'old.wpress' ], 404 ],
			'unknown folder'     => [ [ 'source' => 'backup', 'name' => 'old.wpress', 'folder' => 'tmp' ], 404 ],
			'folder as array'    => [ [ 'source' => 'backup', 'name' => 'old.wpress', 'folder' => [ 'own' ] ], 400 ],
			'not http'           => [ [ 'source' => 'url', 'url' => 'file:///etc/passwd.wpress' ], 400 ],
			'too long an url'    => [ [ 'source' => 'url', 'url' => 'https://files.test/' . str_repeat( 'a', 4096 ) ], 400 ],
			'safety as text'     => [ [ 'source' => 'url', 'url' => 'https://files.test/a.wpress', 'safetyBackup' => 'no' ], 400 ],
			'safety as zero'     => [ [ 'source' => 'url', 'url' => 'https://files.test/a.wpress', 'safetyBackup' => '0' ], 400 ],
			'safety as null'     => [ [ 'source' => 'url', 'url' => 'https://files.test/a.wpress', 'safetyBackup' => null ], 400 ],
		];
		foreach ( $refusals as $label => [ $body, $status ] ) {
			$this->assertSame( $status, $this->refused( fn() => JobInput::importSource( $body, $this->backups, 10000 ) ), $label );
		}
	}

	public function test_answers_only_what_the_job_asked_for(): void {
		$job         = new Job( '0123456789abcdef', 'import', [ 'decrypt' ] );
		$job->status = Job::NEEDS_INPUT;
		$job->input  = [
			'kind' => 'password',
			'data' => [],
		];
		$this->assertSame( [ 'password' => 'pw' ], JobInput::answer( $job, [ 'password' => 'pw' ] ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'password' => 'pw', 'confirm' => true ] ) ) );
		$this->assertSame( 400, $this->refused( static fn() => JobInput::answer( $job, [ 'password' => '' ] ) ) );
		$this->assertSame( 400, $this->refused( static fn() => JobInput::answer( $job, [ 'password' => 5 ] ) ) );

		$job->input = [
			'kind' => 'confirm',
			'data' => [],
		];
		$this->assertSame( [ 'confirm' => false ], JobInput::answer( $job, [ 'confirm' => false ] ) );
		$this->assertSame( [ 'confirm' => true, 'safetyBackup' => false ], JobInput::answer( $job, [ 'safetyBackup' => false, 'confirm' => true ] ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => 'yes' ] ) ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => 1 ] ) ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => true, 'upload' => true ] ) ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ true ] ) ) );
		$this->assertSame( 400, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => true, 'safetyBackup' => 'no' ] ) ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'password' => 'x' ] ) ) );

		Functions\when( 'is_multisite' )->justReturn( false );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => true, 'sites' => [ 0 => 'http://x.test/a' ] ] ) ), 'No addresses on a single site.' );
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->assertSame(
			[
				'confirm' => true,
				'sites'   => [ 0 => 'http://net.test/shop' ],
			],
			JobInput::answer(
				$job,
				[
					'confirm' => true,
					'sites'   => [ 0 => 'http://net.test/shop' ],
				]
			)
		);
		$this->assertSame( 400, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => true, 'sites' => [ 0 => 5 ] ] ) ) );
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'sites' => [ 0 => 'http://net.test/shop' ] ] ) ), 'Addresses only next to confirm.' );

		$job->input = [
			'kind' => 'upload',
			'data' => [],
		];
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'upload' => true ] ) ), 'Uploads finish through the upload route only.' );

		$job->input  = [
			'kind' => 'confirm',
			'data' => [],
		];
		$job->status = Job::RUNNING;
		$this->assertSame( 409, $this->refused( static fn() => JobInput::answer( $job, [ 'confirm' => true ] ) ), 'Only a waiting job takes an answer.' );
	}
}
