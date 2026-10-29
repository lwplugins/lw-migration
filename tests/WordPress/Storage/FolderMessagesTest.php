<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage;

use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FolderMessagesTest extends MonkeyTestCase {

	public function test_every_reason_has_its_own_text_without_a_path(): void {
		$fallback = FolderMessages::of( 'no-such-reason' );
		$texts    = [];
		foreach ( ( new \ReflectionClass( FolderRefusedException::class ) )->getConstants() as $reason ) {
			$text = FolderMessages::of( (string) $reason );
			$this->assertNotSame( $fallback, $text, (string) $reason );
			$this->assertDoesNotMatchRegularExpression( '#/[a-z]#i', $text, (string) $reason );
			$texts[] = $text;
		}
		$this->assertSame( $texts, array_values( array_unique( $texts ) ) );
	}

	public function test_run_as_owner_names_the_web_servers_user(): void {
		$this->assertStringEndsWith( 'sudo -u www-data wp …', FolderMessages::of( FolderRefusedException::RUN_AS_OWNER, 'www-data' ) );
		$this->assertStringEndsWith( 'sudo -u <web server user> wp …', FolderMessages::of( FolderRefusedException::RUN_AS_OWNER ) );
	}

	public function test_a_failed_copy_names_only_the_backup(): void {
		$this->assertSame( 'The backup a.wpress disappeared while it was moved.', FolderMessages::copyFailed( 1, 'a.wpress' ) );
		$this->assertSame( 'A backup disappeared while it was moved.', FolderMessages::copyFailed( 1 ) );
		$this->assertSame( 'The new folder is full. Nothing was removed; free up space and start again.', FolderMessages::copyFailed( 2, 'a.wpress' ) );
		$this->assertSame( 'The copy of a.wpress did not match the original. Nothing was removed; start again.', FolderMessages::copyFailed( 3, 'a.wpress' ) );
		$this->assertStringStartsWith( 'A backup could not be copied.', FolderMessages::copyFailed( 4, 'a.wpress' ) );
	}
}
