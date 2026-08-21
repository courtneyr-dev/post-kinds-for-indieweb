---
name: dev-post-kinds-for-indieweb
description: Development workflow for the Post Kinds for IndieWeb WordPress plugin — setup, build, lint, test (PHPUnit/Jest/Playwright), wp-env, branch/PR conventions, release steps, and discovery audits (microformats2 + Standard.site/AT Protocol). Use when building, testing, or releasing this plugin, when picking up work in a fresh session/worktree, or when a change touches head output, rewrites, permalinks, feeds, lifecycle hooks, or anything AT Protocol-shaped.
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

## Discovery audits — run with every session in this repo

Any change touching head output, rewrite rules, permalinks, feeds,
lifecycle hooks (`save_post` / `transition_post_status` / delete paths),
uninstall, or rendered card markup affects one or both discovery surfaces.
Audit both before calling the change done:

### IndieWeb (microformats2) discovery

- Automated gate: `tests/phpunit/integration/MicroformatsRenderTest.php`
  parses each response-kind card with `mf2/mf2` and asserts the canonical
  property lands on the `h-entry`. Extend it when adding a kind.
- Manual per-kind validator pass (pre-release) is listed in the repo
  `CLAUDE.md` "IndieWeb validation" section — indiewebify.me, pin13.net/mf2,
  monocle preview, webmention.rocks, micropub.rocks. That section is the
  source of truth; don't restate it, run it.
- The hidden `u-url`/`dt-published` markers come from
  `class-microformats.php::wrap_singular_content` (`the_content` @100) —
  any plaintext/content extraction must exclude them.

### Standard.site (AT Protocol) discovery

Full audit + implementation plan:
`docs/audits/standard-site-discovery-audit.md` (in-repo). Durable facts:

- **Canonical lexicons are AT Protocol records, not a GitHub repo**: the
  `com.atproto.lexicon.schema` collection of
  `did:plc:re3ebnp5v7ffagz6rb6xfei4` (8 records: publication, document,
  theme.basic, theme.color, graph.subscription, graph.recommend, authFull,
  authSocial). CID = revision. Pin a baseline with:
  `curl "$PDS/xrpc/com.atproto.repo.getRecord?repo=did:plc:re3ebnp5v7ffagz6rb6xfei4&collection=com.atproto.lexicon.schema&rkey=site.standard.document"`
  (resolve `$PDS` via `https://plc.directory/<did>`). The docs-site field
  tables lag the records — trust the records.
- **Verification model**: publications verify via
  `/.well-known/site.standard.publication` returning a plain-text AT-URI
  (authoritative); documents verify via
  `<link rel="site.standard.document" href="at://…">` in the canonical
  page's `<head>`. Publication record must exist before documents.
- **What the plugin ships (as of 1.5.2): consumer only.**
  `includes/class-standard-site.php` reads other sites' records (PR #128);
  reuse its `parse_at_uri`/`resolve_pds`/`same_url` for anything AT-shaped.
  There is NO publishing side: no PDS writes, no atproto auth, no
  `.well-known` route, no link-tag emission, no `wp_head` hook anywhere in
  the plugin, and `_pkiw_standard_site_uri` meta is write-only (and leaks
  through uninstall). Verify these against the tree before relying on them
  — the audit doc's traceability matrix has file:line evidence.
- **Live-site trap**: courtneyr.dev already has a publication record
  (`did:plc:zpnx6i5fecbk2ni2g2qx5amx`, zero document records) and serves
  `.well-known` via a non-PKIW mechanism — any publishing work must adopt
  existing records, never create duplicates, and must not fight the
  existing `.well-known` responder.
- Quick end-to-end resolver check (live network, no WP needed):
  `php tests/manual/standard-site-resolve.php [url]`.
- External validator for records: site-validator.fly.dev.

### Audit checklist per change

1. Does the change alter any rendered card, wrapper, or head output? →
   run `MicroformatsRenderTest` + eyeball parsed mf2 for the touched kind.
2. Does it add/modify a rewrite, feed, or `.well-known` route? → it must
   ride `maybe_flush_rewrite_rules`' `pkiw_rewrite_version` stamp (bump
   `PKIW_VERSION`) or it 404s for upgrading users (the 1.5.0 `/firehose`
   bug).
3. Does it touch permalinks, post lifecycle, or uninstall? → check both
   the mf2 `u-url` source (`get_permalink()`) and the Standard.site audit
   doc's lifecycle section for what would drift.
4. Anything AT Protocol-shaped? → re-pin the lexicon CIDs before trusting
   cached knowledge; `StandardSiteTest` (20 tests) must stay green:
   `WP_TESTS_DIR=~/.wp-tests/wordpress-tests-lib php -d memory_limit=1G vendor/bin/phpunit --filter StandardSiteTest`.
