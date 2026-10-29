/**
 * Unit tests (npm test): the admin's .js sources carry JSX, which Vite only
 * parses in .jsx files by default, so a component can be rendered in a test.
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	oxc: {
		include: /\/src\/.*\.js$/,
		exclude: /node_modules/,
		lang: 'jsx',
		jsx: { runtime: 'automatic' },
	},
} );
