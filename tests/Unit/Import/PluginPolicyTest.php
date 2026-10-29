<?php
/**
 * Tests for PluginPolicy.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\PluginPolicy;
use PHPUnit\Framework\TestCase;

final class PluginPolicyTest extends TestCase {

	private const INSTALLED = [
		'lw-migration/lw-migration.php'                      => '1.0.0',
		'sample/sample.php'                                  => '1.0',
		'wps-hide-login/wps-hide-login.php'                  => '1.9',
		'really-simple-ssl-renamed/rlrsssl-really-simple-ssl.php' => '9.0',
		'revslider/revslider.php'                            => '5.4.8',
	];

	public function test_http_target(): void {
		$result = PluginPolicy::decide(
			[ 'sample/sample.php', 'wps-hide-login/wps-hide-login.php', 'really-simple-ssl-renamed/rlrsssl-really-simple-ssl.php', 'revslider/revslider.php', 'gone/gone.php', 'lw-migration/lw-migration.php' ],
			self::INSTALLED,
			false,
			'lw-migration/lw-migration.php'
		);

		$this->assertSame( [ 'lw-migration/lw-migration.php', 'sample/sample.php' ], $result['active'] );
		$this->assertSame(
			[
				'wps-hide-login/wps-hide-login.php'                       => 'lockout',
				'really-simple-ssl-renamed/rlrsssl-really-simple-ssl.php' => 'ssl',
				'revslider/revslider.php'                                 => 'outdated',
				'gone/gone.php'                                           => 'missing',
			],
			$result['removed']
		);
	}

	public function test_https_target_keeps_ssl_plugins_and_new_sliders(): void {
		$installed                            = self::INSTALLED;
		$installed['revslider/revslider.php'] = '6.7.0';
		$result                               = PluginPolicy::decide( [ 'really-simple-ssl-renamed/rlrsssl-really-simple-ssl.php', 'revslider/revslider.php' ], $installed, true, 'lw-migration/lw-migration.php' );
		$this->assertSame( [ 'lw-migration/lw-migration.php', 'really-simple-ssl-renamed/rlrsssl-really-simple-ssl.php', 'revslider/revslider.php' ], $result['active'] );
	}

	public function test_a_second_copy_of_this_plugin_is_never_activated(): void {
		$installed                                    = self::INSTALLED;
		$installed['lw-migration-old/lw-migration.php'] = '0.9.0';
		$result = PluginPolicy::decide( [ 'lw-migration-old/lw-migration.php', 'sample/sample.php' ], $installed, true, 'lw-migration/lw-migration.php' );

		$this->assertSame( [ 'lw-migration/lw-migration.php', 'sample/sample.php' ], $result['active'] );
		$this->assertSame( [ 'lw-migration-old/lw-migration.php' => 'duplicate' ], $result['removed'] );
	}

	public function test_renamed_folders_match_by_prefix_or_whole_token_only(): void {
		$installed = [
			'wps-hide-login-2/wps-hide-login.php'       => '1.9',
			'my-wps-hide-login-copy/wps-hide-login.php' => '1.9',
			'notwps-hide-login/wps-hide-login.php'      => '1.9',
			'wps-hide-loginx-old/wps-hide-login.php'    => '1.9',
			'mywpide/WPide.php'                         => '1.0',
		];
		$result    = PluginPolicy::decide( array_keys( $installed ), $installed, true, 'lw-migration/lw-migration.php' );

		$this->assertSame( [ 'lw-migration/lw-migration.php', 'notwps-hide-login/wps-hide-login.php', 'mywpide/WPide.php' ], $result['active'] );
		$this->assertSame(
			[
				'wps-hide-login-2/wps-hide-login.php'       => 'lockout',
				'my-wps-hide-login-copy/wps-hide-login.php' => 'lockout',
				'wps-hide-loginx-old/wps-hide-login.php'    => 'lockout',
			],
			$result['removed']
		);
	}

	public function test_slider_with_an_unknown_version_is_removed(): void {
		foreach ( [ '', 'unknown' ] as $version ) {
			$result = PluginPolicy::decide( [ 'revslider/revslider.php' ], [ 'revslider/revslider.php' => $version ], true, 'lw-migration/lw-migration.php' );
			$this->assertSame( [ 'revslider/revslider.php' => 'unknown-version' ], $result['removed'], $version );
		}
	}
}
