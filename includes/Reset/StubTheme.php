<?php
/**
 * The minimal theme a theme reset installs when no default theme can be.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Storage\FolderIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * Written into a fresh folder of the themes folder (mkdir without parents,
 * files created exclusively, so nothing that is there is overwritten and no
 * link is followed; never chown). A folder that is already ours (its
 * style.css names this theme, or it is empty after a request died right
 * after creating it) is reused and its missing files are written; any other
 * folder or a link under that name is an error, also when the folder is
 * swapped while its files are written (its identity is checked before each).
 */
final class StubTheme {

	public const SLUG = 'lw-migration-blank';

	public const NAME = 'Blank (LW Migration)';

	/** The only template. */
	private const INDEX = <<<'TPL'
<?php
/**
 * The only template of the blank theme.
 *
 * @package lw-migration-blank
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main>
<?php
while ( have_posts() ) {
	the_post();
	the_title( '<h1>', '</h1>' );
	the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>

TPL;

	/** Theme setup. */
	private const FUNCTIONS = <<<'TPL'
<?php
/**
 * Theme setup of the blank theme.
 *
 * @package lw-migration-blank
 */

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'title-tag' );
	}
);

TPL;

	/**
	 * @param string $themeRoot The themes folder.
	 * @return string The slug.
	 * @throws JobException When the folder is not ours or cannot be written.
	 */
	public static function write( string $themeRoot ): string {
		$dir = rtrim( $themeRoot, '/' ) . '/' . self::SLUG;
		clearstatcache( true );
		if ( is_link( $dir ) || ( file_exists( $dir ) && ! self::ours( $dir ) ) ) {
			throw new JobException( __( 'A folder named lw-migration-blank already exists in the themes folder.', 'lw-migration' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is reported below; a warning would carry the path.
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755 ) ) {
			throw new JobException( __( 'The blank theme could not be written to the themes folder.', 'lw-migration' ) );
		}
		clearstatcache( true, $dir );
		$id = is_link( $dir ) ? null : FolderIdentity::entry( $dir );
		foreach ( self::files() as $name => $text ) {
			if ( null === $id || FolderIdentity::entry( $dir ) !== $id ) { // A link in its place has another identity (lstat).
				// The folder was swapped (for a link) while it was written: nothing more goes in.
				throw new JobException( __( 'A folder named lw-migration-blank already exists in the themes folder.', 'lw-migration' ) );
			}
			self::create( $dir . '/' . $name, $text );
		}

		return self::SLUG;
	}

	/**
	 * @param string $dir Existing folder (not a link).
	 */
	private static function ours( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable means not ours.
		$names = @scandir( $dir );
		if ( is_array( $names ) && [] === array_diff( $names, [ '.', '..' ] ) ) {
			return true;
		}
		$style = $dir . '/style.css';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file; unreadable means not ours.
		$text = is_file( $style ) && ! is_link( $style ) ? (string) @file_get_contents( $style, false, null, 0, 8192 ) : '';

		return 1 === preg_match( '/^\s*Theme Name:\s*' . preg_quote( self::NAME, '/' ) . '\s*$/m', $text );
	}

	/**
	 * Creates a file that is not there yet (an existing one is left as it is).
	 *
	 * @param string $path File.
	 * @param string $text Content.
	 * @throws JobException When it cannot be written.
	 */
	private static function create( string $path, string $text ): void {
		clearstatcache( true, $path );
		if ( is_file( $path ) && ! is_link( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- exclusive create never follows a link; a failure is reported below.
		$handle = @fopen( $path, 'xb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		$written = false !== $handle && strlen( $text ) === fwrite( $handle, $text );
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( ! $written ) {
			throw new JobException( __( 'The blank theme could not be written to the themes folder.', 'lw-migration' ) );
		}
	}

	/**
	 * @return array<string, string> File name => content; style.css first (it marks the folder as ours).
	 */
	private static function files(): array {
		return [
			'style.css'     => "/*\nTheme Name: " . self::NAME . "\nDescription: A minimal theme written by the LW Migration theme reset because no default theme could be installed. Install any theme and switch to it.\nVersion: 1.0.0\nRequires at least: 6.6\nRequires PHP: 8.0\nLicense: GPL-2.0-or-later\nText Domain: lw-migration-blank\n*/\n",
			'index.php'     => self::INDEX,
			'functions.php' => self::FUNCTIONS,
		];
	}
}
