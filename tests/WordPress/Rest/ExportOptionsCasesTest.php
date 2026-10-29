<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\JobInput;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The server half of tests/Fixtures/export-options-cases.json; the browser
 * half is src/views/exportRequest.test.js. A rule changed on one side only
 * fails one of the two.
 */
final class ExportOptionsCasesTest extends MonkeyTestCase {

	/** @return array<string, mixed> */
	private static function cases(): array {
		$json = file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/export-options-cases.json' );

		return json_decode( (string) $json, true, 16, JSON_THROW_ON_ERROR );
	}

	private function refusal( \Closure $call, string $label ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted: ' . $label );
	}

	public function test_the_case_table_is_not_empty(): void {
		$cases = self::cases();
		foreach ( [ 'accepted', 'refused', 'serverOnly' ] as $group ) {
			$this->assertNotEmpty( $cases['excludeFiles'][ $group ], 'excludeFiles ' . $group );
		}
		foreach ( [ 'accepted', 'refused', 'dropped' ] as $group ) {
			$this->assertNotEmpty( $cases['replace'][ $group ], 'replace ' . $group );
		}
	}

	public function test_excluded_paths_inside_wp_content_are_taken(): void {
		foreach ( self::cases()['excludeFiles']['accepted'] as $line ) {
			$this->assertCount( 1, JobInput::exportOptions( [ 'excludeFiles' => [ $line ] ] )->excludeFiles, $line );
			$this->assertCount( 1, ExportOptions::fromArray( [ 'excludeFiles' => [ $line ] ] )->excludeFiles, $line );
		}
	}

	public function test_excluded_paths_outside_wp_content_are_refused_without_a_field(): void {
		foreach ( self::cases()['excludeFiles']['refused'] as $line ) {
			$e = $this->refusal( static fn() => JobInput::exportOptions( [ 'excludeFiles' => [ $line ] ] ), $line );
			// No field: the browser's own check (checkForm) is what marks the text area.
			$this->assertSame( [ 400, ErrorMap::INVALID, [] ], [ $e->status, $e->errorCode, $e->data ], $line );

			try {
				ExportOptions::fromArray( [ 'excludeFiles' => [ $line ] ] );
				$this->fail( 'ExportOptions accepted: ' . $line );
			} catch ( \InvalidArgumentException $ignored ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_server_only_refusals_name_the_field(): void {
		foreach ( self::cases()['excludeFiles']['serverOnly'] as $line ) {
			$e = $this->refusal( static fn() => JobInput::exportOptions( [ 'excludeFiles' => [ $line ] ] ), $line );
			$this->assertSame( [ 'field' => 'excludeFiles', 'generic' => true ], $e->data, $line );
		}
	}

	public function test_pairs_with_both_sides_are_taken_untrimmed(): void {
		foreach ( self::cases()['replace']['accepted'] as $pair ) {
			$this->assertSame( [ $pair ], JobInput::exportOptions( [ 'replace' => [ $pair ] ] )->replace, implode( '|', $pair ) );
		}
	}

	public function test_pairs_with_a_blank_side_are_refused_by_field(): void {
		$cases = self::cases()['replace'];
		// "dropped" rows never leave the browser because the server would refuse them too.
		foreach ( array_merge( $cases['refused'], $cases['dropped'] ) as $pair ) {
			$e = $this->refusal( static fn() => JobInput::exportOptions( [ 'replace' => [ $pair ] ] ), implode( '|', $pair ) );
			$this->assertSame( [ 'field' => 'replace', 'generic' => true ], $e->data, implode( '|', $pair ) );
		}
	}
}
