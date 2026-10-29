<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\ImportSummary;
use LightweightPlugins\Migration\Import\Steps\ConfirmStep;
use LightweightPlugins\Migration\Import\Steps\DecryptStep;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DecryptConfirmTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/wpress/';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_password_is_asked_until_it_verifies(): void {
		$job                   = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'encrypted.wpress' );
		$job->state['package'] = PackageCodec::read( self::FIXTURES . 'encrypted.wpress' )->toArray();
		$step                  = new DecryptStep();

		$first = $step->run( $job, new NoDeadline() );
		$this->assertSame( 'password', $first->needsInputKind() );
		$this->assertSame( [ 'reason' => 'encrypted' ], $first->inputData() );
		$job->secrets = [ 'password' => 'nope' ];
		$wrong        = $step->run( $job, new NoDeadline() );
		$this->assertSame( 'password', $wrong->needsInputKind() );
		$this->assertSame( [ 'reason' => 'wrong' ], $wrong->inputData() );
		$job->secrets = [ 'password' => 'lw-fixture-pass' ];
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		$plain                   = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'plain.wpress' );
		$plain->state['package'] = PackageCodec::read( self::FIXTURES . 'plain.wpress' )->toArray();
		$this->assertTrue( $step->run( $plain, new NoDeadline() )->isDone() );
	}

	public function test_an_unsigned_encrypted_archive_accepts_any_password_up_front(): void {
		// The wrong password then fails at the first data entry, before anything is put in place.
		$job                   = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'encrypted.wpress' );
		$job->state['package'] = [ 'HomeURL' => 'https://old.test', 'Encrypted' => true ];
		$step                  = new DecryptStep();

		$this->assertSame( [ 'reason' => 'encrypted' ], $step->run( $job, new NoDeadline() )->inputData() );
		$job->secrets = [ 'password' => 'anything' ];
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
	}

	public function test_confirm_shows_a_summary_until_confirmed(): void {
		Functions\when( 'size_format' )->alias( static fn( $b ) => $b . ' B' );
		$job                       = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'plain.wpress' );
		$job->state['package']     = [ 'HomeURL' => 'https://old.test', 'WordPress' => [ 'Version' => '6.8' ], 'PHP' => [ 'Version' => '8.1.2' ], 'NoMedia' => true ];
		$job->state['archiveSize'] = 1000;
		$asked                     = [];
		$step                      = new ConfirmStep(
			static function ( string $d ) use ( &$asked ): ?int {
				$asked[] = $d;
				return 1500;
			},
			static fn(): int => 700
		);

		$result  = $step->run( $job, new NoDeadline() );
		$summary = $result->inputData();
		$this->assertSame( 'confirm', $result->needsInputKind() );
		$this->assertSame( [ $this->dir . '/wp-content' ], $asked );
		$this->assertSame( 'https://old.test', $summary['from'] );
		$this->assertSame( 'https://new.test', $summary['to'] );
		$this->assertSame( [ 'archive' => '6.8', 'site' => $job->state['site']['wpVersion'] ], $summary['wordpress'] );
		$this->assertSame( [ 'archive' => '8.1.2', 'site' => PHP_VERSION ], $summary['php'] );
		$this->assertSame( [ 'database' => true, 'media' => false, 'plugins' => true, 'themes' => true ], $summary['overwrites'] );
		$this->assertFalse( $summary['encrypted'] );
		$this->assertSame( '', $summary['compression'] );
		$this->assertNull( $summary['subsite'] );
		$this->assertTrue( $summary['safetyBackup'] );
		$this->assertSame( 2700, $summary['diskNeeded'] );
		$this->assertSame( 1500, $summary['diskFree'] );
		$this->assertTrue( $summary['lowDiskSpace'] );
		$this->assertSame( 700, $summary['safetyBytes'] );

		$lines = ImportSummary::lines( $summary );
		$this->assertContains( 'Address: https://old.test -> https://new.test', $lines );
		$this->assertContains( 'Will be overwritten: database, plugins, themes', $lines );
		$this->assertContains( 'A backup of the current database is taken first.', $lines );
		$this->assertContains( 'Warning: the import may need about 2700 B of disk space, but only 1500 B is free.', $lines );

		$job->state['input']['confirm'] = 'yes';
		$this->assertSame( 'confirm', $step->run( $job, new NoDeadline() )->needsInputKind() );
		$job->state['input']['confirm'] = true;
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
	}

	public function test_without_a_safety_backup_the_estimate_leaves_it_out_but_reports_its_size(): void {
		$job                       = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'plain.wpress', [ 'safetyBackup' => false ] );
		$job->state['package']     = [ 'HomeURL' => 'https://old.test' ];
		$job->state['archiveSize'] = 1000;
		$step                      = new ConfirmStep( static fn( string $d ): ?int => null, static fn(): int => 700 );

		$summary = $step->run( $job, new NoDeadline() )->inputData();
		$this->assertSame( 2000, $summary['diskNeeded'] );
		$this->assertSame( 700, $summary['safetyBytes'], 'The screen can switch the safety backup on and recompute.' );
		$this->assertNull( $summary['diskFree'] );
		$this->assertFalse( $summary['lowDiskSpace'] );
		$lines = ImportSummary::lines( $summary );
		$this->assertContains( 'No backup of the current database is taken.', $lines );
		$this->assertSame( [], preg_grep( '/^Warning:/', $lines ) );
	}

	public function test_the_confirmation_may_switch_the_safety_backup(): void {
		$job                  = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'plain.wpress' );
		$job->state['input']  = [
			'confirm'      => true,
			'safetyBackup' => false,
		];
		$step                 = new ConfirmStep( static fn( string $d ): ?int => null, static fn(): int => 0 );

		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertFalse( ImportOptions::fromArray( $job->options )->safetyBackup );
		$this->assertArrayNotHasKey( 'safetyBackup', $job->state['input'] );

		$other                 = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . 'plain.wpress' );
		$other->state['input'] = [
			'confirm'      => true,
			'safetyBackup' => 'no',
		];
		$this->assertTrue( $step->run( $other, new NoDeadline() )->isDone() );
		$this->assertTrue( ImportOptions::fromArray( $other->options )->safetyBackup, 'Only a boolean answer changes the option.' );
	}
}
