<?php
/**
 * What the commands print.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Support\ControlChars;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Every line a command prints goes through here: messages quote names from
 * archives, destinations and other sites, so their control characters are
 * shown as escapes before WP-CLI writes them to the terminal.
 */
final class Console {

	public static function log( string $text ): void {
		WP_CLI::log( ControlChars::escape( $text ) );
	}

	public static function warning( string $text ): void {
		WP_CLI::warning( ControlChars::escape( $text ) );
	}

	public static function success( string $text ): void {
		WP_CLI::success( ControlChars::escape( $text ) );
	}

	/**
	 * Prints the error and stops the command (WP-CLI exits).
	 *
	 * @param string $text Error message.
	 * @return void
	 */
	public static function error( string $text ): void {
		WP_CLI::error( ControlChars::escape( $text ) );
	}

	/**
	 * The question of a prompt. php-cli-tools prints it through colorize()
	 * when colours are on, which turns "%k", "%0" and the like into colour
	 * codes and "%%" into "%": a quoted name keeps its percent signs only
	 * when they are doubled. Without colours it prints the text as it is.
	 *
	 * @param string $text     Question.
	 * @param bool   $colorize Whether php-cli-tools colours the output (\cli\Colors::shouldColorize()).
	 * @return string
	 */
	public static function promptText( string $text, bool $colorize ): string {
		$text = ControlChars::escape( $text );

		return $colorize ? str_replace( '%', '%%', $text ) : $text;
	}

	/**
	 * A table (or csv, json, yaml) of rows; string cells are escaped.
	 *
	 * @param string                           $format Output format.
	 * @param array<int, array<string, mixed>> $items  Rows.
	 * @param array<int, string>               $fields Columns.
	 * @return void
	 */
	public static function items( string $format, array $items, array $fields ): void {
		$rows = [];
		foreach ( $items as $item ) {
			$rows[] = array_map( static fn( $cell ) => is_string( $cell ) ? ControlChars::escape( $cell ) : $cell, $item );
		}
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
