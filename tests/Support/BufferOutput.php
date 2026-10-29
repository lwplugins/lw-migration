<?php
/**
 * Output collector for CLI tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\CLI\Output;

final class BufferOutput implements Output {

	/** @var array<int, string> */
	public array $lines = [];

	public function line( string $text ): void {
		$this->lines[] = $text;
	}

	public function warning( string $text ): void {
		$this->lines[] = 'Warning: ' . $text;
	}
}
