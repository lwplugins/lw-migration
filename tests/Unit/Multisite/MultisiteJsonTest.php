<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\MultisiteJson;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use PHPUnit\Framework\TestCase;

final class MultisiteJsonTest extends TestCase {

	public function test_the_file_has_the_reference_layout_and_default_json_flags(): void {
		$site = new SiteEntry( 2, 1, 0, 'http://n.test/b2', 'http://n.test/b2', 'n.test', '/b2/', [], 'tt', 'tt', '', '', '/a/uploads/sites/2', 'http://n.test/b2/wp-content/uploads/sites/2/' );
		$json = MultisiteJson::encode( false, [ [ 'SiteID' => 1, 'Domain' => 'n.test', 'Path' => '/' ] ], [ $site ], '1.0.0', [ 'net/net.php' ], [ 'admin' ] );

		$this->assertStringStartsWith( '{"Network":false,"Networks":[{"SiteID":1,"Domain":"n.test","Path":"\/"}],"Sites":[{"BlogID":2,', $json );
		$this->assertStringEndsWith( '"Plugin":{"Version":"1.0.0"},"Plugins":["net\/net.php"],"Admins":["admin"]}', $json );
	}

	public function test_invalid_utf8_is_refused(): void {
		$site = new SiteEntry( 2, 1, 0, "http://n.test/\xff", '', 'n.test', '/', [], '', '', '', '', '', '' );
		$this->expectException( \RuntimeException::class );
		MultisiteJson::encode( false, [], [ $site ], '1.0.0', [], [] );
	}
}
