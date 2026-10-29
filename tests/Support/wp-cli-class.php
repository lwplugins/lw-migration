<?php
/**
 * Minimal global WP_CLI for tests of command wiring (load only in a separate process).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Records registrations and errors; error() stops like the real one (by throwing).
	 */
	final class WP_CLI {

		/** @var array<int, array{0: string, 1: mixed, 2: array<string, mixed>}> */
		public static array $commands = [];

		/** @var array<int, string> */
		public static array $errors = [];

		/** @var array<string, mixed> Global config values (get_config()). */
		public static array $config = [];

		/** @var array<string, array<int, callable>> Hooks by name. */
		public static array $hooks = [];

		/** @var array<int, string> Lines of log(), warning() ("Warning: " prefix) and success() ("Success: " prefix), in order. */
		public static array $lines = [];

		/**
		 * @param string               $name     Command name.
		 * @param mixed                $callable Command.
		 * @param array<string, mixed> $args     Registration arguments.
		 */
		public static function add_command( string $name, $callable, array $args = [] ): bool {
			self::$commands[] = [ $name, $callable, $args ];
			return true;
		}

		/**
		 * @param string   $hook     Hook name.
		 * @param callable $callback Callback.
		 */
		public static function add_hook( string $hook, $callback ): void {
			self::$hooks[ $hook ][] = $callback;
		}

		/**
		 * @param string $key Config key.
		 * @return mixed
		 */
		public static function get_config( string $key = '' ) {
			return self::$config[ $key ] ?? null;
		}

		/**
		 * @param string $message Line.
		 */
		public static function log( string $message ): void {
			self::$lines[] = $message;
		}

		/**
		 * @param string $message Warning.
		 */
		public static function warning( string $message ): void {
			self::$lines[] = 'Warning: ' . $message;
		}

		/**
		 * @param string $message Success message.
		 */
		public static function success( string $message ): void {
			self::$lines[] = 'Success: ' . $message;
		}

		/**
		 * @param string $message Error message.
		 * @throws \RuntimeException Always (WP-CLI exits here).
		 */
		public static function error( string $message ): void {
			self::$errors[] = $message;
			throw new \RuntimeException( 'WP_CLI::error: ' . $message );
		}
	}
}
