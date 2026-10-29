import { defineConfig } from '@playwright/test';

// Run through bin/test-ui.sh (npm run test:ui): it builds the sites and sets the URLs.
export default defineConfig( {
	testDir: '.',
	testMatch: '*.spec.mjs',
	timeout: 240000,
	expect: { timeout: 15000 },
	workers: 1,
	retries: 0,
	forbidOnly: true,
	reporter: 'list',
	outputDir: '../../../.playwright-results',
	use: {
		baseURL: process.env.LWM_UI_URL,
		browserName: 'chromium',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		viewport: { width: 1280, height: 900 },
		acceptDownloads: true,
	},
} );
