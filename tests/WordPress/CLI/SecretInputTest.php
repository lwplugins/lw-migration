<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\SecretInput;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SecretInputTest extends MonkeyTestCase {

	public function test_reads_one_stdin_line_per_secret_field_without_a_terminal(): void {
		$lines  = [ "first\n", "second\r\n" ];
		$fields = [ new Field( 'secretKey', Field::SECRET, 'Secret key', true ), new Field( 'passphrase', Field::SECRET, 'Passphrase' ) ];

		$values = SecretInput::read( $fields, false, static function () use ( &$lines ): ?string {
			return array_shift( $lines );
		} );

		$this->assertSame( [ 'secretKey' => 'first', 'passphrase' => 'second' ], $values );
	}

	public function test_asks_with_a_hidden_prompt_on_a_terminal_and_a_missing_required_one_stops(): void {
		$asked  = [];
		$hidden = static function ( string $label ) use ( &$asked ): ?string {
			$asked[] = $label;
			return '';
		};

		try {
			SecretInput::read( [ new Field( 'secretKey', Field::SECRET, 'Secret key', true ) ], true, null, $hidden );
			$this->fail( 'A required secret is needed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Secret key', $e->getMessage() );
		}
		$this->assertSame( [ 'Secret key' ], $asked );
	}

	/**
	 * P-I2: an optional secret is asked as optional, and an empty answer is not stored.
	 */
	public function test_an_optional_secret_left_empty_on_a_terminal_is_not_stored(): void {
		$asked  = [];
		$hidden = static function ( string $question, bool $optional ) use ( &$asked ): ?string {
			$asked[] = [ $question, $optional ];
			return 'Password' === $question ? 'pw' : '';
		};
		$fields = [ new Field( 'password', Field::SECRET, 'Password', true ), new Field( 'passphrase', Field::SECRET, 'Passphrase' ) ];

		$values = SecretInput::read( $fields, true, null, $hidden );

		$this->assertSame( [ 'password' => 'pw' ], $values );
		$this->assertSame( [ [ 'Password', false ], [ 'Passphrase (Enter if none)', true ] ], $asked );
	}
}
