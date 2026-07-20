---
name: dev-post-kinds-for-indieweb
description: Development workflow for the Post Kinds for IndieWeb WordPress plugin — setup, build, lint, test (PHPUnit/Jest/Playwright), wp-env, branch/PR conventions, and release steps. Use when building, testing, or releasing this plugin, or when picking up work in a fresh session/worktree.
---

# Post Kinds for IndieWeb — dev workflow

Block editor support for IndieWeb post kinds (listen, watch, read, checkin,
play, eat, drink, like, reply, repost, bookmark, RSVP, and more). Repo:
https://github.com/courtneyr-dev/post-kinds-for-indieweb

## Setup

```bash
composer install
npm install
```

Node: `>=18.0.0` required by `package.json` engines; CI uses Node 20.
PHP: `>=8.2` (composer.json `require.php`); CI matrix covers 8.2/8.3/8.4.

## Build

```bash
npm run start        # watch mode
npm run build         # dev build
npm run build:prod    # production build (what CI and plugin-zip use)
```

Block source lives in `src/blocks/`, but the plugin **registers each block from
`build/blocks/<block>/`** — the build step (`bin/sync-block-assets.mjs`, run
after every `npm run build`) copies each block's `block.json`, `render.php`,
`style.css`, and `editor.css` from `src/blocks/` into `build/blocks/`, so the
shipped zip works without `src/` (which `.distignore` excludes). `build/` is
compiled output but is tracked in git because it ships as part of the
distribution. Edit `block.json`/`render.php` in `src/blocks/` and rebuild —
`build/blocks/` is what actually loads, so a stale `build/` copy WILL take
effect until you rebuild.

## Lint

```bash
composer lint          # PHPCS, ruleset .phpcs.xml.dist (excludes vendor/, node_modules/, build/, tests/, assets/, stubs/)
composer lint:fix       # PHPCBF, auto-fixes what it can
npm run lint:js         # wp-scripts lint-js src/ (eslint.config.js flat config)
npm run lint:css        # wp-scripts lint-style
npm run lint:pkg-json   # wp-scripts lint-pkg-json
npm run lint            # all three JS/CSS/pkg-json lints together
```

`eslint.config.js` (flat config) is already in place — `@wordpress/scripts`
32+ silently ignores a legacy `.eslintrc.js`, so don't reintroduce one. This
migration already happened (`@wordpress/scripts` bump to 32.6.0 in 1.1.0) and
fixed ~1,500 resulting lint findings; if a future dependency bump reopens
that gap, expect a similar-sized lint pass.

## Static analysis

```bash
composer analyze            # PHPStan level 5, phpstan.neon
composer analyze:baseline    # regenerate phpstan-baseline.neon
```

Level 5, not 6 — `phpstan.neon` sets `parameters.level: 5`. New code is held
to level 5 with no new violations; pre-existing errors are tracked in
`phpstan-baseline.neon` and worked off incrementally.

## Test — PHP (PHPUnit)

```bash
composer test              # full suite (unit + integration)
composer test:unit          # tests/phpunit/unit only
composer test:integration   # tests/phpunit/integration only
composer test:coverage      # HTML coverage to coverage/php
```

PHPUnit `^9.6` (composer.json require-dev), bootstrap `tests/phpunit/bootstrap.php`.

Test layout:
- `tests/phpunit/unit/` — unit tests
- `tests/phpunit/integration/` — `WP_UnitTestCase`-based integration tests
- `tests/phpunit/fixtures/` — per-API fixture data (foursquare, google-books,
  hardcover, lastfm, listenbrainz, musicbrainz, nominatim, openlibrary,
  podcastindex, rawg, readwise, simkl, tmdb, trakt, tvmaze)

Integration tests need a WP test install. CI provisions one via
`bin/install-wp-tests.sh` against a MySQL 8.0 service container — run
`bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 <wp-version> true`
locally against a local MySQL if you need to run integration tests outside
wp-env (the trailing `true` skips the interactive "reinstall?" prompt).

CI matrix: PHP 8.2, 8.3, 8.4 against WP 7.0, plus an extra PHP 8.4 + WP
`trunk` combination (`.github/workflows/ci.yml`, `phpunit` job). Coverage
uploads to Codecov only from the PHP 8.4 / WP 7.0 leg.

## Test — JS (Jest)

```bash
npm run test:unit            # wp-scripts test-unit-js --config jest.config.js
npm run test:unit:watch
npm run test:unit:coverage    # what CI runs, uploads coverage/js/lcov.info to Codecov
```

Test files under `tests/js/` (e.g. `tests/js/blocks/`).

## Test — e2e / accessibility / visual regression (Playwright)

```bash
npm run test:e2e          # full Playwright suite, tests/e2e/
npm run test:e2e:debug
npm run test:e2e:ui
npm run test:a11y          # tests/e2e/accessibility.spec.js (axe-core)
npm run test:visual        # updates snapshots locally — see caveat below
```

`playwright.config.js`: 4 projects (chromium, firefox, webkit, mobile-chrome);
CI only runs `--project=chromium`. Base URL defaults to
`http://localhost:8888`, overridable via `WP_BASE_URL`.

**Visual regression baselines are platform-scoped.** Screenshots live at
`tests/e2e/__screenshots__/<spec>/<name>-{darwin,linux}.png` (custom
`snapshotPathTemplate`) because font rendering differs enough between macOS
and Linux to blow the 0.05 `maxDiffPixelRatio` budget. **Never regenerate
Linux baselines locally** — CI (and any Mac) will produce non-matching
pixels. Linux baselines are only regenerated via the `Visual Baselines`
workflow (`.github/workflows/visual-baselines.yml`, `workflow_dispatch`
only), which runs on the same `ubuntu-latest` image CI's e2e job uses, then
commits the regenerated PNGs back to whatever branch it was dispatched on.

`checkins-feed` is excluded from the visual matrix — it's query-driven, so
DB state and run-date make its rendered output non-deterministic and it
can't produce a stable pixel baseline.

**Editor welcome-guide dismissal:** before any spec that loads the block
editor, the welcome guide must be dismissed server-side, or it renders over
the canvas in screenshots and steals focus in interaction tests:

```bash
npx wp-env run cli wp user meta update admin wp_persisted_preferences --format=json '{"core":{"welcomeGuide":false},"core/edit-post":{"welcomeGuide":false},"_modified":"2026-01-01T00:00:00.000Z"}'
```

Both `ci.yml`'s `e2e` job and `visual-baselines.yml` run this before tests.
Don't rely on dismissing it in-page (e.g. clicking through the modal, or a
`dispatch()` call after the editor mounts) — on a fresh database the in-page
preferences dispatch races store hydration, and losing that race is exactly
what baked the welcome-guide modal into the very first set of Linux
baselines (fixed in commit `a5d889b`, 2026-07-04).

## wp-env

```bash
npm run env:start
npm run env:stop
npm run env:clean
npm run env:cli -- <wp-cli-args>    # e.g. npm run env:cli -- plugin list
npm run env:logs
```

`.wp-env.json`: core `WordPress/WordPress#7.0`, PHP 8.2, this plugin mounted
as `.`, `wp-content/uploads` mapped to `tests/uploads`, `wp-content/mu-plugins`
mapped to `tests/env`. `afterStart` lifecycle script sets pretty permalinks
and installs + activates the `indieauth` and `micropub` companion plugins
(needed for Micropub bridge e2e tests).

Default port 8888. **If port 8888 is already taken locally** (Stream Deck
commonly claims it on this machine), create a gitignored
`.wp-env.override.json` with alternate ports and point `WP_BASE_URL` at it —
`.gitignore` already excludes `.wp-env.override.json`, and
`playwright.config.js`'s local `webServer.url` already honors `WP_BASE_URL`
instead of hardcoding 8888.

**wp-env from a worktree:** the worktree directory must live under `$HOME`
(Docker Desktop / colima + virtiofs doesn't share `/tmp` into containers),
and the worktree's directory basename must equal the plugin slug
(`post-kinds-for-indieweb`) — `wp-env` uses the mounted directory's basename
to resolve the plugin slug for activation, so a mismatched worktree
directory name causes `wp plugin activate` to silently fail to find it.

## CI overview (`.github/workflows/ci.yml`)

Triggers on push/PR to `main`, `develop`, `feature/wp70-api-integration`.
Jobs: `phpcs`, `phpstan`, `phpunit` (matrix above), `eslint`, `stylelint`,
`jest`, `build`, `security` (composer audit + npm audit + Trivy, all
`continue-on-error`), `plugin-check` (runs WordPress Plugin Check against a
built distribution zip), `e2e` (chromium only, needs `build`), `accessibility`
(needs `build`), `lighthouse` (main branch only, needs `build`), `i18n`
(text-domain + POT generation check).

No workflow triggers on tags or GitHub releases — cutting a version here
never auto-deploys anything.

`dependabot-auto-merge.yml` auto-merges Dependabot PRs on `pull_request`.
`visual-baselines.yml` is manual-dispatch only (see above).

## Branch / PR conventions

- Feature/fix work happens on a branch off `main`; the WP 7.0 upgrade work
  specifically lives on `feature/wp70-api-integration` (also a CI trigger
  branch).
- Use a git worktree per concurrent session — **never** work directly in a
  shared checkout that other sessions may also be using. Commit early;
  check `git reflog` before assuming work is lost — a worktree collision
  looks like lost commits but usually isn't.
- Commits: see repo `CLAUDE.md` for the Emoji-Log convention
  (`📦 NEW:` / `👌 IMPROVE:` / `🐛 FIX:` / `📖 DOC:` / `🚀 RELEASE:` /
  `🤖 TEST:` / `‼️ BREAKING:`), imperative mood, adopted going forward
  (earlier history uses Conventional Commits — don't rewrite it).
- Pre-commit hook (Husky + lint-staged, `.lintstagedrc.json`): PHP files run
  `phpcs --standard=.phpcs.xml.dist`; JS/JSX/TS/TSX run `wp-scripts lint-js`;
  CSS/SCSS run `wp-scripts lint-style`; `package.json` runs
  `wp-scripts lint-pkg-json`. Markdown isn't matched by any of these globs.
- PR into `main`; CI must be green (`phpcs`, `phpstan`, `phpunit` matrix,
  `eslint`, `stylelint`, `jest`, `build`, `plugin-check`, `e2e`,
  `accessibility`, `i18n` — `security` and `lighthouse` don't gate merge).

## Release steps

1. Confirm CI green on `main`.
2. Bump `Stable tag` / plugin header version and `package.json` `version`.
3. Move `CHANGELOG.md`'s `[Unreleased]` section into a new dated
   `[x.y.z] - YYYY-MM-DD` entry (Keep a Changelog format); backfill the
   corresponding `readme.txt` changelog entry (readme.txt's changelog is
   maintained separately and by hand — don't script-sync it without
   checking both stay consistent).
4. **Never cut the actual release, tag, or deploy without Courtney's
   explicit go**, even once every other step above is done. Release
   machinery being ready is not the same as being told to ship.

## Gotchas

- **Shared-checkout session races.** Multiple sessions/agents may be
  working `~/Projects/post-kinds-for-indieweb` concurrently. Always work in
  a dedicated git worktree, commit early and often, and check `git reflog`
  before concluding anything is lost.
- **wp-env + worktrees:** worktree must be under `$HOME` (colima/virtiofs
  doesn't share `/tmp`), and the worktree directory's basename must be
  `post-kinds-for-indieweb` for `wp-env plugin activate` to find it.
- **Port 8888 conflict:** Stream Deck commonly owns 8888 on this machine —
  use a gitignored `.wp-env.override.json` with alternate ports plus
  `WP_BASE_URL` rather than fighting for the default port.
- **Blocks register from `build/blocks/`, not `src/blocks/`.** The build step
  (`bin/sync-block-assets.mjs`) copies each block's `block.json`/`render.php`/
  `style.css`/`editor.css` from `src/blocks/` into `build/blocks/`, and the
  plugin registers from there — so the shipped zip works without `src/`
  (`.distignore` excludes it). Edit in `src/blocks/` and rebuild; `build/blocks/`
  is what loads, so ship a fresh `build/`.
- **Block category slug is `post-kinds-indieweb`** (no "for") across every
  `block.json`. Don't "fix" it to match the plugin slug — existing saved
  posts reference this category slug, and changing it breaks them.
- **`@wordpress/scripts` 32+ needs `eslint.config.js`, not `.eslintrc.js`.**
  A legacy `.eslintrc.js` is silently ignored by the flat-config ESLint that
  ships with wp-scripts 32, which looks like lint passing clean right up
  until you bump the dependency and get ~1,500 findings at once (this
  already happened once, in the 1.1.0 cycle).
- **Welcome-guide dismissal must happen server-side, before the editor
  loads** (seed `wp_persisted_preferences` via `wp user meta update`), not
  via an in-page dispatch after mount — the in-page dispatch races store
  hydration on a fresh database and lost that race hard enough to bake the
  welcome-guide modal into an entire set of Linux visual baselines on
  2026-07-04 (fixed in commit `a5d889b`).
- **Visual regression baselines are platform-scoped and Linux baselines are
  CI-runner-only.** Never regenerate `-linux.png` baselines from a local
  (macOS) run — dispatch the `Visual Baselines` workflow instead, which runs
  on `ubuntu-latest` and commits the results back.
- **`checkins-feed` is excluded from visual-regression** — it's
  query-driven, so its rendered content isn't deterministic across runs.
- **PHPStan runs at level 5, not 6** — an earlier CLAUDE.md draft claimed
  level 6; `phpstan.neon` has always said 5 (corrected in commit
  `97a711b`, which is also the origin of the "verify docs against the repo
  before trusting them" habit this retrofit continues).
