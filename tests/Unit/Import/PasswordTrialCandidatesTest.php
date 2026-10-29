<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Import\PasswordTrial;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class PasswordTrialCandidatesTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @param array<string, string> $entries Name => contents, after package.json.
	 * @return array{0: mixed, 1: mixed}
	 */
	private function candidates( array $entries ): array {
		$path   = $this->dir . '/a.wpress';
		$writer = new Writer( $path );
		$writer->addString( 'package.json', '{}', 1 );
		foreach ( $entries as $name => $contents ) {
			$writer->addString( $name, $contents, 1 );
		}
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		$reader = new Reader( $path );
		try {
			return PasswordTrial::candidates( $reader );
		} finally {
			$reader->close();
		}
	}

	public function test_the_trial_prefers_a_small_checksummed_entry(): void {
		[ $database, $trial ] = $this->candidates(
			[
				'big.bin'      => random_bytes( PasswordTrial::TRIAL_MAX_BYTES + 1 ),
				'empty.txt'    => '',
				'small.txt'    => 'hello',
				'database.sql' => '-- dump',
			]
		);

		$this->assertSame( 'database.sql', $database->name );
		$this->assertSame( 'small.txt', $trial->name );
	}

	public function test_without_a_small_entry_the_first_checksummed_one_is_tried(): void {
		[ $database, $trial ] = $this->candidates(
			[
				'big.bin'    => random_bytes( PasswordTrial::TRIAL_MAX_BYTES + 1 ),
				'bigger.bin' => random_bytes( PasswordTrial::TRIAL_MAX_BYTES + 2 ),
			]
		);

		$this->assertNull( $database );
		$this->assertSame( 'big.bin', $trial->name );
	}
}
