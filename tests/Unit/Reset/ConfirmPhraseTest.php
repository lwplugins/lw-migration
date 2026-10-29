<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\ConfirmPhrase;
use PHPUnit\Framework\TestCase;

final class ConfirmPhraseTest extends TestCase {

	public function test_the_phrase_is_the_lower_case_host_port_and_path(): void {
		$this->assertSame( 'example.com', ConfirmPhrase::of( 'https://Example.com' ) );
		$this->assertSame( 'example.com/blog', ConfirmPhrase::of( 'https://example.com/blog/' ) );
		$this->assertSame( 'localhost:8080', ConfirmPhrase::of( 'http://localhost:8080/' ) );
		$this->assertSame( '127.0.0.1:8897', ConfirmPhrase::of( ' http://127.0.0.1:8897 ' ) );
	}

	public function test_an_empty_or_unparsable_address_gives_no_phrase(): void {
		foreach ( [ '', '   ', 'https://', 'http:///', '/' ] as $home ) {
			$this->assertSame( '', ConfirmPhrase::of( $home ), var_export( $home, true ) );
		}
	}
}
