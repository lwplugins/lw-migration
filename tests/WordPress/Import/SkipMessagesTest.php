<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use LightweightPlugins\Migration\Import\ContentRules;
use LightweightPlugins\Migration\Import\SharedCode;
use LightweightPlugins\Migration\Import\SkipMessages;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SkipMessagesTest extends MonkeyTestCase {

	public function test_one_line_per_reason_and_one_list_of_the_kept_plugins_and_themes(): void {
		$lines = SkipMessages::all(
			[
				ContentRules::DROPIN          => 1,
				'kept:plugins/akismet'        => 12,
				SharedCode::SHARED            => 3,
				SharedCode::EXISTING          => 2,
				SharedCode::NEW_CODE          => 1,
				'new:plugins/evil'            => 2,
				'new:themes/evil-theme'       => 3,
				'kept:themes/twentytwentysix' => 40,
			]
		);

		$this->assertSame(
			[
				'Did not restore 1 drop-in file: it belongs to the old server.',
				'Did not restore 3 must-use plugin or wp-content root files: on a network they would affect every site.',
				'Kept 2 files the network already had in its shared wp-content folders; the copies in the archive were not restored.',
				'Did not restore 1 new PHP or server configuration file into the shared wp-content folders: on a network it would run for every site.',
				'Not restored, because the network does not have them: plugins/evil, themes/evil-theme. Install them on the network if you trust them.',
				'Kept the network\'s own copies of these plugins and themes (other sites use them); the copies in the archive were not restored: plugins/akismet, themes/twentytwentysix.',
			],
			$lines
		);
	}

	public function test_no_skips_no_lines(): void {
		$this->assertSame( [], SkipMessages::all( [] ) );
	}
}
