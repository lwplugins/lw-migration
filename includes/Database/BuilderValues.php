<?php
/**
 * URL replacement inside base64-encoded page-builder content.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Three formats seen in the wild: raw-HTML shortcodes, escaped JSON code blocks,
 * and whole base64 SQL literals. Each match is decoded, replaced, re-encoded —
 * only when it is canonical base64.
 */
final class BuilderValues {

	private const RAW_HTML = '/\[vc_raw_html\]([a-zA-Z0-9\/+]+={0,2})\[\/vc_raw_html\]/S';

	private const CODE_BLOCK = '/\\\\"(code-php|code-css|code-js)\\\\":\\\\"([a-zA-Z0-9\/+]+={0,2})\\\\"/S';

	private const LITERAL_BODY = '/^[a-zA-Z0-9\/+]+={0,2}$/D';

	public function __construct(
		private bool $visualComposer = false,
		private bool $oxygen = false,
		private bool $base64Literals = false
	) {
	}

	/**
	 * @param array<int, string> $packagePlugins  Active plugins listed in package.json.
	 * @param string             $template        package.json Template.
	 * @param string             $stylesheet      package.json Stylesheet.
	 * @param callable           $pluginInstalled fn( string $basename ): bool.
	 * @param callable           $themeInstalled  fn( string $slug ): bool.
	 * @return self
	 */
	public static function detect( array $packagePlugins, string $template, string $stylesheet, callable $pluginInstalled, callable $themeInstalled ): self {
		$plugin = static fn( string $p ): bool => in_array( $p, $packagePlugins, true ) || (bool) $pluginInstalled( $p );
		$theme  = static fn( string $t ): bool => $template === $t || $stylesheet === $t || (bool) $themeInstalled( $t );

		return new self(
			$plugin( 'js_composer/js_composer.php' ),
			$plugin( 'oxygen/functions.php' ),
			$theme( 'betheme' ) || $plugin( 'optimizePressPlugin/optimizepress.php' ) || $plugin( 'fusion-builder/fusion-builder.php' )
		);
	}

	public function isActive(): bool {
		return $this->visualComposer || $this->oxygen || $this->base64Literals;
	}

	public function apply( string $sql, Replacer $replacer ): string {
		if ( $this->visualComposer ) {
			$sql = $this->each(
				self::RAW_HTML,
				$sql,
				static fn( array $m, string $b ): string => '[vc_raw_html]' . base64_encode( $replacer->plain( $b ) ) . '[/vc_raw_html]',
				1
			);
		}
		if ( $this->oxygen ) {
			$sql = $this->each(
				self::CODE_BLOCK,
				$sql,
				static fn( array $m, string $b ): string => '\\"' . $m[1] . '\\":\\"' . base64_encode( $replacer->plain( $b ) ) . '\\"',
				2
			);
		}
		if ( $this->base64Literals ) {
			$sql = SqlLiterals::map(
				$sql,
				static function ( string $body, int $literal ) use ( $replacer ): string {
					if ( 1 !== preg_match( self::LITERAL_BODY, $body ) ) {
						return $body;
					}
					$decoded = base64_decode( $body, true );
					if ( false === $decoded || base64_encode( $decoded ) !== $body ) {
						return $body;
					}
					return base64_encode( $replacer->valueAt( $decoded, $literal ) );
				}
			);
		}

		return $sql;
	}

	/**
	 * Regex-based rewrite; on a PCRE failure the statement is kept unchanged.
	 *
	 * @param string   $pattern Regex.
	 * @param string   $sql     Statement.
	 * @param callable $rebuild fn( array $match, string $decoded ): string.
	 * @param int      $group   Capture group holding the base64.
	 * @return string
	 */
	private function each( string $pattern, string $sql, callable $rebuild, int $group ): string {
		$out = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $rebuild, $group ): string {
				$decoded = base64_decode( $m[ $group ], true );
				if ( false === $decoded || base64_encode( $decoded ) !== $m[ $group ] ) {
					return $m[0];
				}
				return $rebuild( $m, $decoded );
			},
			$sql
		);

		return null === $out ? $sql : $out;
	}
}
