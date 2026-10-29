<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SerializedReplacer;
use LightweightPlugins\Migration\Database\SiteTarget;
use LightweightPlugins\Migration\Database\SqlEscape;
use LightweightPlugins\Migration\Database\UrlPairs;
use LightweightPlugins\Migration\Package\PackageConfig;
use PHPUnit\Framework\TestCase;

/**
 * Security review: search-replace never instantiates anything from the
 * archive, never reads an old or new value as a pattern, and keeps every
 * length prefix of nested and multibyte serialized data.
 */
final class SearchReplaceSafetyTest extends TestCase {

	private const PAIRS = [ 'http://old.test' => 'https://new.example.com' ];

	/** Files of the search-replace. */
	private const FILES = [ 'Database/Replacer.php', 'Database/SerializedReplacer.php', 'Database/UrlPairs.php', 'Database/BuilderValues.php', 'Database/SqlLiterals.php' ];

	public function test_three_levels_of_nested_multibyte_serialization_keep_every_length(): void {
		$level3 = serialize( [ 'u' => 'http://old.test/ű/日本' ] );
		$level2 = serialize( [ 'inner' => $level3, 'u' => 'Ő http://old.test' ] );
		$level1 = serialize( [ 'mid' => $level2, 'n' => 1 ] );

		$out = unserialize( ( new SerializedReplacer( self::PAIRS ) )->replace( $level1 ) );
		$two = unserialize( $out['mid'] );

		$this->assertSame( 'Ő https://new.example.com', $two['u'] );
		$this->assertSame( [ 'u' => 'https://new.example.com/ű/日本' ], unserialize( $two['inner'] ) );
		$this->assertSame( 1, $out['n'] );
	}

	public function test_no_class_from_the_data_is_ever_loaded(): void {
		$asked = [];
		$spy   = static function ( string $class ) use ( &$asked ): void {
			$asked[] = $class;
		};
		spl_autoload_register( $spy );
		try {
			$object = 'O:22:"LwReviewProbeNotLoaded":1:{s:3:"url";s:15:"http://old.test";}';
			$custom = 'C:22:"LwReviewProbeNotLoaded":4:{abcd}';
			$enum   = 'E:28:"LwReviewProbeNotLoaded:Case1";';
			$data   = 'a:3:{i:0;' . $object . 'i:1;' . $custom . 'i:2;' . $enum . '}';

			$out = ( new SerializedReplacer( self::PAIRS ) )->replace( $data );
			( new Replacer( self::PAIRS ) )->statement( "INSERT INTO `t` VALUES ('" . SqlEscape::escape( $data ) . "');" );
		} finally {
			spl_autoload_unregister( $spy );
		}

		$this->assertSame( [], $asked );
		$this->assertStringContainsString( 's:3:"url";s:23:"https://new.example.com";', $out );
	}

	public function test_the_search_replace_never_calls_unserialize(): void {
		foreach ( self::FILES as $file ) {
			$this->assertSame( [], self::calls( $file, 'unserialize' ), $file );
		}
	}

	public function test_every_unserialize_of_the_plugin_names_its_allowed_classes(): void {
		$dir   = dirname( __DIR__, 3 ) . '/includes/';
		$found = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $path ) {
			$file = substr( (string) $path, strlen( $dir ) );
			if ( ! str_ends_with( $file, '.php' ) || str_starts_with( $file, 'Vendor/' ) ) {
				continue;
			}
			foreach ( self::calls( $file, 'unserialize' ) as $arguments ) {
				++$found;
				$this->assertStringContainsString( "'allowed_classes'", $arguments, $file );
				$this->assertStringNotContainsString( "'allowed_classes' => true", $arguments, $file );
			}
		}
		$this->assertGreaterThan( 0, $found, 'The scan sees the known calls.' );
	}

	public function test_old_and_new_values_are_literal_text_never_patterns(): void {
		$pairs = [
			'a.c'           => 'X',
			'(old)+'        => '$1\\1${0}',
			'http://o.t/#/' => '/#\\',
		];
		$r     = new SerializedReplacer( $pairs );

		$this->assertSame( 'abc ooold', $r->replace( 'abc ooold' ) );
		$this->assertSame( 'X $1\\1${0} /#\\', $r->replace( 'a.c (old)+ http://o.t/#/' ) );
		$this->assertSame( [ 'k' => '$1\\1${0}' ], unserialize( $r->replace( serialize( [ 'k' => '(old)+' ] ) ) ) );
		$this->assertSame( "INSERT INTO `t` VALUES ('X \\\\$1');", ( new Replacer( [ 'a.c' => 'X \\$1' ] ) )->statement( "INSERT INTO `t` VALUES ('a.c');" ) );
	}

	public function test_a_package_value_with_pattern_characters_builds_literal_pairs(): void {
		$to = new SiteTarget( 'https://new.test', 'https://new.test', 'https://new.test/wp-content/uploads/', 'wp-content/uploads', '/srv/', '/srv/wp-content' );
		$u  = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'http://o.t/$1', 'HomeURL' => 'http://o.t/$1', 'Replace' => [ 'OldValues' => [ '(.*)' ], 'NewValues' => [ '\\0' ] ] ] ), $to );
		$r  = new Replacer( $u->pairs(), $u->raw() );

		$this->assertSame( "INSERT INTO `t` VALUES ('\\\\0 https://new.test','plain');", $r->statement( "INSERT INTO `t` VALUES ('(.*) http://o.t/$1','plain');" ) );
	}

	/**
	 * Argument lists of every call to a function in a file under includes/.
	 *
	 * @param string $file     Path under includes/.
	 * @param string $function Function name.
	 * @return array<int, string>
	 */
	private static function calls( string $file, string $function ): array {
		$tokens = token_get_all( (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $file ) );
		$out    = [];
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || strtolower( $token[1] ) !== $function ) {
				continue;
			}
			$j = $i + 1;
			while ( is_array( $tokens[ $j ] ?? null ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
				continue;
			}
			$depth = 0;
			$args  = '';
			for ( ; isset( $tokens[ $j ] ); $j++ ) {
				$text   = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				$depth += '(' === $text ? 1 : ( ')' === $text ? -1 : 0 );
				$args  .= $text;
				if ( 0 === $depth ) {
					break;
				}
			}
			$out[] = $args;
		}

		return $out;
	}
}
