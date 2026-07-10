#!/usr/bin/env node
/**
 * Capture documentation screenshots for Post Kinds for IndieWeb.
 *
 * Boots a disposable WordPress via WordPress Playground CLI (no Docker needed),
 * mounts this plugin, and captures the screens listed in docs/screenshots.md
 * into docs/assets/screenshots/. Requires a Playground WordPress of 7.0+
 * (the CLI's "latest" already satisfies this).
 *
 * Prerequisites:
 *   - Node.js 18+
 *   - npm install (installs Playwright from devDependencies)
 *   - npx playwright install chromium (once, to download the browser)
 *
 * Usage:
 *   node scripts/capture-docs-screenshots.js
 *
 * Environment variables:
 *   WP_BASE_URL      Capture against an already-running WordPress instead of
 *                    launching Playground (must be logged-in-accessible or a
 *                    Playground --login server). No credentials are stored here.
 *   PLAYGROUND_PORT  Port for the disposable Playground server (default 9400).
 */

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(REPO_ROOT, 'docs', 'assets', 'screenshots');
const PORT = process.env.PLAYGROUND_PORT || '9400';
const EXTERNAL_URL = process.env.WP_BASE_URL || '';
const BASE = EXTERNAL_URL || `http://127.0.0.1:${PORT}`;

function resolveChromium() {
	try {
		return require('playwright').chromium;
	} catch (e) {
		try {
			return require('@playwright/test').chromium;
		} catch (e2) {
			console.error(
				'Playwright is not installed. Run `npm install` in the repo root, then `npx playwright install chromium`.'
			);
			process.exit(1);
		}
	}
}

async function waitForServer(url, timeoutMs) {
	const deadline = Date.now() + timeoutMs;
	while (Date.now() < deadline) {
		try {
			const res = await fetch(url, { redirect: 'manual' });
			if (res.status > 0 && res.status < 500) {
				return;
			}
		} catch (e) {
			// Not up yet.
		}
		await new Promise((r) => setTimeout(r, 2000));
	}
	throw new Error(`WordPress did not become reachable at ${url} within ${timeoutMs / 1000}s.`);
}

function launchPlayground() {
	console.log(`Starting WordPress Playground on port ${PORT} (downloads WordPress on first run)...`);
	const child = spawn(
		'npx',
		['--yes', '@wp-playground/cli@latest', 'server', '--auto-mount', REPO_ROOT, '--login', '--port', PORT],
		{ stdio: ['ignore', 'pipe', 'pipe'] }
	);
	child.stdout.on('data', (d) => process.stdout.write(`[playground] ${d}`));
	child.stderr.on('data', (d) => process.stderr.write(`[playground] ${d}`));
	child.on('exit', (code) => {
		if (code && code !== 0 && !shuttingDown) {
			console.error(`Playground exited unexpectedly with code ${code}.`);
			process.exit(1);
		}
	});
	return child;
}

let shuttingDown = false;

(async () => {
	fs.mkdirSync(OUT_DIR, { recursive: true });
	const chromium = resolveChromium();

	let playground = null;
	if (!EXTERNAL_URL) {
		playground = launchPlayground();
	}

	try {
		await waitForServer(BASE + '/', 240000);
		console.log(`WordPress is up at ${BASE}`);

		const browser = await chromium.launch();
		const ctx = await browser.newContext({
			viewport: { width: 1280, height: 800 },
			deviceScaleFactor: 2,
		});
		const page = await ctx.newPage();

		// Prime the logged-in admin session (Playground --login authenticates the first visit).
		await page.goto(BASE + '/wp-admin/', { waitUntil: 'networkidle' });
		if (!/wp-admin/.test(page.url()) || /wp-login/.test(page.url())) {
			throw new Error(
				`Could not reach a logged-in wp-admin at ${BASE}. If you passed WP_BASE_URL, make sure the session does not require interactive login.`
			);
		}

		// Dismiss the editor welcome guide before any editor visit.
		await page.addInitScript(() => {
			const prefs = {
				'core/edit-post': { welcomeGuide: false, fullscreenMode: false },
				core: { welcomeGuide: false },
			};
			try {
				localStorage.setItem('WP_PREFERENCES_USER_1', JSON.stringify(prefs));
			} catch (e) {
				/* no-op */
			}
		});

		const shoot = async (file) => {
			const target = path.join(OUT_DIR, file);
			await page.screenshot({ path: target });
			console.log(`captured ${path.relative(REPO_ROOT, target)}`);
		};

		// 1. Reactions → Settings (General tab).
		await page.goto(BASE + '/wp-admin/admin.php?page=post-kinds-for-indieweb', { waitUntil: 'networkidle' });
		await shoot('admin-general-settings.png');

		// 2. Reactions → API Connections.
		await page.goto(BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-apis', { waitUntil: 'networkidle' });
		await shoot('admin-api-connections.png');

		// 3. Reactions → Import.
		await page.goto(BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-import', { waitUntil: 'networkidle' });
		await shoot('admin-import-page.png');

		// 4. Reactions → Webhooks.
		await page.goto(BASE + '/wp-admin/admin.php?page=post-kinds-indieweb-webhooks', { waitUntil: 'networkidle' });
		await shoot('admin-webhooks-page.png');

		// 5. Block editor with the Post Kind panel in the document sidebar.
		await page.goto(BASE + '/wp-admin/post-new.php', { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('.editor-sidebar, .interface-complementary-area', { timeout: 30000 }).catch(() => {});
		await page.waitForTimeout(6000);
		// Close any modal (e.g. a pattern or format chooser) that could cover the editor.
		const closeButton = page.locator('.components-modal__header button[aria-label="Close"]');
		if (await closeButton.count()) {
			await closeButton.first().click().catch(() => {});
			await page.waitForTimeout(1000);
		}
		// Bring the Post Kind panel into view if it renders collapsed lower in the sidebar.
		const kindPanel = page.getByRole('button', { name: /post kind/i }).first();
		if (await kindPanel.count()) {
			await kindPanel.scrollIntoViewIfNeeded().catch(() => {});
			const expanded = await kindPanel.getAttribute('aria-expanded').catch(() => null);
			if (expanded === 'false') {
				await kindPanel.click().catch(() => {});
			}
			await page.waitForTimeout(1500);
		}
		await shoot('editor-post-kind-panel.png');

		await browser.close();
		console.log(`Done. Screenshots are in ${path.relative(REPO_ROOT, OUT_DIR)}/`);
	} finally {
		if (playground) {
			shuttingDown = true;
			playground.kill('SIGTERM');
		}
	}
})().catch((e) => {
	console.error(e.message || e);
	process.exit(1);
});
