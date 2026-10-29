<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Multisite\ExportScope;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * R24: a prefix the placeholder pairs would rename twice (or that is not a plain identifier) is refused with a translated message.
 */
final class ExportScopeRefusalTest extends MonkeyTestCase {

	/** @return array<string, array{0: string}> */
	public static function prefixes(): array {
		return [
			'empty'                  => [ '' ],
			'quote'                  => [ "wp'" ],
			'one letter of it'       => [ 's' ],
			'its start, upper case'  => [ 'SERV' ],
			'the placeholder itself' => [ 'servmask_prefix_' ],
			'longer than it'         => [ 'SERVMASK_PREFIX_wp_' ],
		];
	}

	/**
	 * @dataProvider prefixes
	 */
	public function test_an_unsafe_prefix_is_refused( string $prefix ): void {
		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'A network backup needs a table prefix of letters, digits and underscores that does not start like the archive placeholder.' );

		new ExportScope( $prefix, [ 1 ], null );
	}

	public function test_a_prefix_that_only_shares_letters_is_accepted(): void {
		$this->assertSame( ExportScope::NETWORK, ( new ExportScope( 'se_', [ 1 ], null ) )->mode() );
	}
}
