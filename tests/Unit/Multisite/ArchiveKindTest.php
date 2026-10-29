<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use PHPUnit\Framework\TestCase;

/**
 * The refusal (translated) is in tests/WordPress/Multisite/NetworkPlanRefusalTest.php.
 */
final class ArchiveKindTest extends TestCase {

	public function test_kinds(): void {
		$this->assertSame( ArchiveKind::SINGLE, ArchiveKind::of( null ) );
		$this->assertSame( ArchiveKind::NETWORK, ArchiveKind::of( MultisiteConfig::fromJson( '{"Network":true,"Sites":[{"BlogID":1},{"BlogID":2}]}' ) ) );
		$this->assertSame( ArchiveKind::SITES, ArchiveKind::of( MultisiteConfig::fromJson( '{"Network":false,"Sites":[{"BlogID":2}]}' ) ) );
		$this->assertSame( ArchiveKind::SITES, ArchiveKind::of( MultisiteConfig::fromJson( '{"Network":false,"Sites":[{"BlogID":1},{"BlogID":3}]}' ) ) );
		$this->assertSame( ArchiveKind::NETWORK, ArchiveKind::of( MultisiteConfig::fromJson( '{"Network":"false","Sites":[{"BlogID":1}]}' ) ), 'A non-empty string is a network, as the reference reads the flag (empty()).' );
		$this->assertSame( ArchiveKind::SITES, ArchiveKind::of( MultisiteConfig::fromJson( '{"Network":0,"Sites":[{"BlogID":2}]}' ) ) );
	}
}
