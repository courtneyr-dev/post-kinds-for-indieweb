/**
 * Lighthouse CI Configuration
 *
 * @see https://github.com/GoogleChrome/lighthouse-ci
 */

module.exports = {
	ci: {
		collect: {
			numberOfRuns: 3,
			url: [
				'http://localhost:8888/',
				'http://localhost:8888/wp-admin/',
			],
			settings: {
				preset: 'desktop',
				chromeFlags: '--no-sandbox --headless',
			},
		},
		assert: {
			assertions: {
				// Performance
				'categories:performance': [ 'warn', { minScore: 0.6 } ],
				'first-contentful-paint': [ 'warn', { maxNumericValue: 3000 } ],
				'largest-contentful-paint': [
					'warn',
					{ maxNumericValue: 4000 },
				],
				'cumulative-layout-shift': [
					'error',
					{ maxNumericValue: 0.1 },
				],
				'total-blocking-time': [ 'warn', { maxNumericValue: 500 } ],

				// Accessibility (strict)
				'categories:accessibility': [ 'error', { minScore: 0.9 } ],

				// Best Practices
				'categories:best-practices': [ 'warn', { minScore: 0.8 } ],

				// SEO
				'categories:seo': [ 'warn', { minScore: 0.8 } ],
			},
		},
		upload: {
			target: 'temporary-public-storage',
		},
	},
};
