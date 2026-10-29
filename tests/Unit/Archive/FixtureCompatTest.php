<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FixtureCompatTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress';

	/**
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return (array) json_decode( (string) file_get_contents( self::FIXTURES . '/manifest.json' ), true );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function archives(): array {
		$out = [];
		foreach ( array_keys( self::manifest()['archives'] ) as $file ) {
			$out[ $file ] = [ $file ];
		}

		return $out;
	}

	private function open( string $file ): Reader {
		$path   = self::FIXTURES . '/' . $file;
		$probe  = new Reader( $path );
		$probe->validate();
		$first   = $probe->firstEntry();
		$this->assertNotNull( $first );
		$package = PackageConfig::fromJson( $probe->readContents( $first ) );
		$probe->close();

		$cipher = null;
		if ( $package->isEncrypted() ) {
			$cipher = new Cipher( self::manifest()['password'] );
			$this->assertTrue( $cipher->verifySignature( $package->encryptedSignature() ) );
		}

		return new Reader( $path, new ChunkCodec( $package->compression(), $cipher ) );
	}

	/**
	 * @dataProvider archives
	 */
	public function test_reference_archive_extracts_byte_exact( string $file ): void {
		$manifest = self::manifest();
		$meta     = $manifest['archives'][ $file ];
		$reader   = $this->open( $file );
		$dir      = TempDir::create();

		try {
			$names = [];
			foreach ( $reader->entries() as $entry ) {
				$names[] = $entry->name;
				if ( $entry->isConfig() ) {
					continue;
				}
				$target = PathGuard::resolve( $dir, $entry->name );
				$this->assertTrue( $reader->extractTo( $entry, $target, new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() ) );
				$this->assertSame( $manifest['mtime'], filemtime( $target ) );
			}

			$expected = array_column( $manifest['entries'], 'name' );
			$config   = $meta['multisite'] ? [ 'package.json', 'multisite.json' ] : [ 'package.json' ];
			$this->assertSame( array_merge( $config, $expected ), $names );

			foreach ( $manifest['entries'] as $e ) {
				$this->assertSame( $e['sha1'], sha1_file( $dir . '/' . $e['name'] ), $e['name'] );
			}

			$cursor = new CrcCursor();
			$this->assertTrue( $reader->verifyChecksum( $cursor, new NoDeadline() ) );

			if ( $meta['multisite'] ) {
				$msEntry = $reader->find( 'multisite.json' );
				$this->assertNotNull( $msEntry );
				$ms = MultisiteConfig::fromJson( $reader->readContents( $msEntry ) );
				$this->assertFalse( $ms->isNetwork() );
				$this->assertSame( 2, $ms->sites()[0]['BlogID'] );
			}
		} finally {
			$reader->close();
			TempDir::remove( $dir );
		}
	}

	public function test_wrong_password_fails_cleanly(): void {
		$path   = self::FIXTURES . '/encrypted.wpress';
		$reader = new Reader( $path, new ChunkCodec( '', new Cipher( 'wrong-password' ) ) );
		$entry  = $reader->find( 'hello.txt' );
		$this->assertNotNull( $entry );
		$dir = TempDir::create();
		try {
			$this->expectException( CorruptedEntryException::class );
			$reader->extractTo( $entry, $dir . '/hello.txt', new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() );
		} finally {
			$reader->close();
			TempDir::remove( $dir );
		}
	}

	public function test_extract_resumes_and_truncates_partial_output(): void {
		$reader = $this->open( 'encrypted-gzip.wpress' );
		$entry  = $reader->find( 'plugins/sample/big.bin' );
		$this->assertNotNull( $entry );
		$dir    = TempDir::create();
		$target = $dir . '/big.bin';

		try {
			$cursor = new ExtractCursor();
			$this->assertFalse( $reader->extractTo( $entry, $target, $cursor, new CountdownDeadline( 0 ), WriteBudget::unlimited() ) );
			file_put_contents( $target, 'GARBAGE FROM A CRASH', FILE_APPEND );
			$cursor = ExtractCursor::fromArray( $cursor->toArray() );
			$this->assertTrue( $reader->extractTo( $entry, $target, $cursor, new NoDeadline(), WriteBudget::unlimited() ) );

			$this->assertSame( sha1_file( self::FIXTURES . '/source/plugins/sample/big.bin' ), sha1_file( $target ) );
		} finally {
			$reader->close();
			TempDir::remove( $dir );
		}
	}
}
