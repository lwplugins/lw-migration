<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\Profile;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase {

	public function test_a_new_id_is_eight_hex_digits(): void {
		$this->assertMatchesRegularExpression( Profile::ID_PATTERN, Profile::newId() );
	}

	/**
	 * An all-digit id would become an integer key once a JSON row is decoded.
	 */
	public function test_a_new_id_is_never_all_digits(): void {
		$draws = [ "\x12\x34\x56\x78", "\x00\x00\x00\x00", "\xab\x12\x34\x56" ];

		$this->assertSame( 'ab123456', Profile::newId( static function () use ( &$draws ): string {
			return (string) array_shift( $draws );
		} ) );
		$this->assertSame( [], $draws );
	}
}
