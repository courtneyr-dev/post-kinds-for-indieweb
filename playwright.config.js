/**
 * Playwright E2E test configuration
 *
 * @see https://playwright.dev/docs/test-configuration
 */

const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: process.env.CI ? 1 : undefined,
	reporter: [
		[ 'html', { outputFolder: 'playwright-report' } ],
		[ 'list' ],
		...( process.env.CI ? [ [ 'github' ] ] : [] ),
	],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			name: 'firefox',
			use: { ...devices[ 'Desktop Firefox' ] },
		},
		{
			name: 'webkit',
			use: { ...devices[ 'Desktop Safari' ] },
		},
		{
			name: 'mobile-chrome',
			use: { ...devices[ 'Pixel 5' ] },
		},
	],
	webServer: process.env.CI
		? undefined
		: {
				command: 'npm run env:start',
				// Honor WP_BASE_URL like the tests do — the 8888 default
				// belongs to Stream Deck on this machine (.wp-env.override
				// moves the env to 8890), and readiness-polling a port that
				// something else answers intermittently makes every run a
				// coin flip.
				url: process.env.WP_BASE_URL || 'http://localhost:8888',
				reuseExistingServer: true,
				timeout: 120000,
		  },
	expect: {
		toHaveScreenshot: {
			maxDiffPixelRatio: 0.05,
		},
	},
} );
