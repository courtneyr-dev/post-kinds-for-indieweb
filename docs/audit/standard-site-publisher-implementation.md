# Standard.site publisher — implementation record

**Date:** 2026-08-21
**Branch:** `feature/atmosphere-standard-site` off `28d2802` (1.5.2), local only — not pushed.
**Baseline audit:** `docs/audits/standard-site-discovery-audit.md` (2026-08-21; the controlling gap analysis).
**Dependency pinned:** ATmosphere — wp.org release 2.1.0 (2026-07-22); repo `Automattic/wordpress-atmosphere` @ `bf8e267` (2.1.0 + 12 housekeeping commits) inspected as source of truth. Minimum supported: **2.1.0**.
**Companion doc:** `docs/integrations/atmosphere-standard-site.md` (developer contract). Upstream proposals: `docs/upstream/atmosphere/`.

## What changed relative to the audit

The audit specified building a publisher (auth, writes, verification,
lifecycle). ATmosphere ships all of it. Of the audit's six missing
pillars, **six are ATmosphere's**; Post Kinds implements only the Post
Kind layer the audit's §8–§9 mapping sections described, as enrichment of
ATmosphere's records rather than records of its own.

## Ownership matrix

| Audit requirement (§ = discovery audit) | Owner | Evidence |
|---|---|---|
| Publication record + `.well-known` (§8, §11) | ATmosphere | `class-publisher.php::sync_publication()`, `class-atmosphere.php` well-known handlers; runtime: `/.well-known/site.standard.publication` → 404 unconfigured (fails safe, verified in wp-env) |
| Document records, rkeys/CIDs, `applyWrites` (§9, §12) | ATmosphere | `Transformer\Document`, `Publisher::publish_post/update_post/delete_post` |
| OAuth (PKCE/DPoP/PAR), token encryption (§10) | ATmosphere | `includes/oauth/`; never referenced by Post Kinds |
| Verification link tags, AT Tags (§11) | ATmosphere | `wp_head` emitters in `class-atmosphere.php`; Post Kinds adds **no** `wp_head` hook — live test asserts exactly one document link tag |
| Lifecycle sync, retries, reconcile (§12) | ATmosphere | `transition_post_status` → cron lanes; `update_post()` doubles as reconcile |
| Backfill (§17 3.1) | ATmosphere | `wp atmosphere backfill`; dry run in wp-env honored the kind policy: "Would publish 3 of 4 posts (1 skipped)" — the listen post |
| Kind eligibility policy (audit §9) | **Post Kinds** | `Atmosphere_Eligibility` — metadata default on `atmosphere_disabled` |
| Derived titles for untitled kinds (audit §9, §20 risk 3) | **Post Kinds** | `Atmosphere_Titles` — 29 unit tests incl. privacy tiers and grapheme cap |
| Kind-as-tag (audit §9) | **Post Kinds** | `Atmosphere_Document_Map` via `atmosphere_transform_document` |
| Check-in privacy in derived fields (audit §13) | **Post Kinds** | private → "Checked in", no venue; rendered content is already privacy-tiered by the cards |
| Status surfaces (audit §17 2.5) | **Post Kinds** | settings field, posts-list column, dependency notice |
| Preview parity | **Post Kinds** | mf2 `the_content` guards on the `atproto` query var (found during this work — markers leaked into `?atproto` projections) |
| Publication **adoption** (audit §8 adopt-don't-duplicate) | **Upstream gap** | ATmosphere blind-mints its rkey; migration documented (pre-seed `atmosphere_publication_tid`), first-class flow proposed upstream |
| Per-kind Bluesky routing | **Upstream gap** | `atmosphere_should_publish_bluesky_post` passes no post; also absent from released 2.1.0 entirely |
| Existing Standard.site **consumer** | Post Kinds (unchanged) | `PKIW\Standard_Site` untouched; StandardSiteTest 20/20 in the full run |

Audit recommendations **not implemented because ATmosphere satisfies
them**: everything in audit §10 (auth), §11 (verification path), §12
(sync engine, rkey-first idempotency — ATmosphere reserves TIDs in meta
exactly as the audit specified), §14 (background writes, retry), §17
phases 1.1–1.3, 2.1–2.4, 2.6, 3.1–3.2, 3.4.

## Key design decisions

1. **Eligibility as a metadata default, not a parallel toggle.**
   `default_post_metadata` (priority 20) answers disabled for
   non-eligible kinds only when no stored value and no published-record
   meta exists. One consistent answer for `is_post_publishable()`,
   auto-publish, backfill, and the editor toggle; zero writes; the
   author's choice always wins; history is never retracted.
2. **Seed-once upgrade safety.** First boot with ATmosphere present: if
   any default-off kind post already carries `_atmosphere_doc_uri`, seed
   all kinds eligible (no silent behavior change); else seed the
   recommended defaults.
3. **No custom content parser.** `org.wordpress.html` renders the kind
   cards (dynamic blocks) with privacy tiers applied; verified through
   the real transformer.
4. **No editor panel.** ATmosphere's sidebar already owns the share
   toggle, custom text, and publish-error display; duplicating it risks
   conflicting UI. Post Kinds adds the posts-list column instead; a
   document-URI surface in ATmosphere's panel would be an upstream nicety.
5. **No new CLI command.** `wp atmosphere backfill` honors the policy
   end-to-end (verified); a `--kind` filter wrapper was considered and
   dropped as not needed for correctness.
6. **Bluesky untouched.** No option, no filter flips; behavior follows
   ATmosphere's settings in both directions. Document-only publishing is
   documented as a code-level, site-wide choice (trunk-only filter).

## Empirical findings recorded for posterity

- **Core enforces `Requires Plugins` at activation, including WP-CLI
  activation.** wp-env's mounted-plugin activation failed until
  ATmosphere entered the `plugins` array *ordered first*; lifecycle-time
  installs are too late. `.wp-env.json` now carries
  `atmosphere.2.1.0.zip` before `"."`.
- **WP-CLI can deactivate the dependency while the dependent stays
  active** ("Deactivated 1 of 1 plugins", observed) — the degraded state
  is reachable and is handled: front page served 200 with ATmosphere
  inactive and Post Kinds active.
- **Released 2.1.0 lacks trunk features the docs describe:**
  `atmosphere_should_publish_bluesky_post`, the document-only publish
  path, and the AT Tags meta emission are all `@since unreleased`
  (verified against the 2.1.0 tag). The integration depends on none of
  them.
- **Disconnected transformer output** uses `at:///…` (empty DID) in the
  document `site` field when a publication TID exists without an
  identity — ATmosphere's pre-connection artifact, never published
  (publishing requires connection).
- The `?atproto` preview leaked Post Kinds' hidden mf2 markers into
  `textContent` (singular-GET render vs cron-context publish render) —
  fixed with query-var guards; ordinary rendering pinned unchanged by the
  existing mf2 suites.

## Commands run and results (2026-08-21, local)

| Command | Result |
|---|---|
| `phpunit --testsuite=unit` (WP test lib, ATmosphere loaded) | **1204 tests, 3133 assertions, 0 failures**, 6 skipped (absent-env pins) |
| `phpunit --testsuite=integration` (ATmosphere loaded) | **203 tests, 541 assertions, 0 failures**, 2 risky — pre-existing at `28d2802` (`SyndicationPageAuthorizationTest` output buffers), verified on the baseline worktree |
| `phpunit --group atmosphere` without ATmosphere | 50 tests, 5 skipped (live suite), 0 failures |
| `phpunit --group atmosphere` with ATmosphere | 50 tests, 6 skipped (absent-env pins), 0 failures |
| `npm run test:unit` (Jest) | 26/26 |
| `composer lint` (PHPCS) | clean |
| `vendor/bin/phpstan analyse --memory-limit=2G` | 0 errors (ATmosphere symbols via `stubs/atmosphere-stubs.php`) |
| `npm run build:prod` + `npm run plugin-zip` | zip builds (607 KB); integration classes ship; `Requires Plugins: atmosphere` in the shipped header; stubs/ and docs/ excluded |
| wp-env runtime battery | both plugins active after start; eligibility live (`listen: no / note: yes / opt-in: yes`); real transformer emits derived title + kind tag; `.well-known` 404 unconfigured; backfill dry-run skips the listen post; degraded state (dependency CLI-deactivated) serves 200 |
| Final diff sweep for duplicated protocol behavior | only `pkiw_atmosphere_*` filter applications and descriptive docblocks match; no OAuth/DPoP/applyWrites/create/put/deleteRecord/uploadBlob/well-known/wp_head implementations added |

## Not run / not claimed

- **Real-PDS contract tests** (OAuth grant, publication + document on a
  live PDS, `.well-known` + link-tag verification against a real domain,
  `?atproto`-to-written-record parity online, indexer visibility).
  Procedure: disposable AT Protocol account (or self-hosted PDS) on a
  reachable test domain → activate both plugins → connect via Settings →
  ATmosphere → publish one post per representative kind → validate at
  https://site-validator.fly.dev → confirm appearance on docs.surf /
  Standard Search → edit, unpublish, delete, re-check. Gate any CI
  automation behind protected credentials; none were used here.
- **Playwright e2e / visual regression**: not run locally (Linux
  baselines are CI-runner-only). Risk: ATmosphere's editor panels are now
  present in the e2e environment; specs asserting sidebar composition may
  need a Visual Baselines workflow dispatch.
- **wp.org Playground preview**: blueprint updated (atmosphere installs
  first), but wp.org's injected install order for the previewed plugin
  itself is unverified — check the preview after the next deploy.
- **i18n POT regeneration**: new strings use the correct text domain; POT
  refresh happens in the release flow as usual.
- readme.txt changelog: written at release per repo convention;
  CHANGELOG.md `[Unreleased]` carries the entries.

## Mentioned, deliberately not done (out of integration scope)

- Consumer housekeeping from the audit: `@since 1.3.0` vs 1.5.0 in
  `class-standard-site.php`, the write-only `_pkiw_standard_site_uri`
  meta (surface it on cards, or clean on uninstall), and the settings
  copy claiming u-syndication markup that nothing emits.
- `Kind_Artwork` ignores `eat_photo`/`drink_photo`/`wish_photo`/
  `acquisition_photo`/`cite_photo` — widening it would improve cover
  images for those kinds everywhere (Yoast schema included), a change
  with blast radius beyond this integration.
- Second/legacy checkin meta schemas (`_pkiw_checkin_venue` et al.) and
  webhook-written watch-meta variants diverge from `Meta_Fields` — noted
  during mapping; derivation uses the registered keys only.

## Definition-of-done position

Every DoD item that can be satisfied without a live PDS is satisfied and
evidenced above; the remainder (real-record creation, verification, and
indexer visibility) is specified as the contract-test procedure and
explicitly **not** claimed as passed. No release was cut, no branch
pushed, no remote write performed.

---

# Correction and release-readiness pass — 2026-08-21 (same day)

The first implementation was treated as a release candidate and
corrected. Evidence markers: `reproduced` = executed this pass with
recorded output; `observed` = read from source or a live response;
`blocked` = requires infrastructure this environment cannot use.

## Preservation and provenance `reproduced`

- Preservation reference: annotated tag
  **`backup/atmosphere-standard-site-pre-correction`** at the original
  seventh commit (not pushed).
- Ancestry proven with read-only Git: merge-base of the branch and
  `origin/main` is exactly the audited `28d2802`; `98a4d08` is **not an
  ancestor** — it is the other session's one-file skill-doc commit,
  parked on its own branch (`docs/dev-skill-block-registration-order`),
  untouched by this work. The branch carried exactly the seven
  integration commits (34 files, +3076/−8), nothing else.
- `origin/main` had advanced by one commit (`95d9fa0`, the merged #158
  skill-doc PR touching only `.claude/skills/...`); the branch rebased
  onto it cleanly — the integration now sits on current `main`.

## Optional companion (architecture correction) `reproduced`

- `Requires Plugins: atmosphere` removed from the plugin header and
  readme; blueprints and CI activation choreography reverted; wp-env
  keeps ATmosphere as the integration-test environment; uninstall now
  removes `pkiw_atmosphere`.
- The coordinator registers the settings-tab status surface in every
  state and the publishing policy only when a compatible ATmosphere is
  active. States rendered: not installed (recommendation + install
  link), installed-inactive (capability-gated activate link),
  incompatible (minimum version), disconnected (connect link),
  connected. **No site-wide admin notices** (pinned by test).
- Earlier hard-dependency findings remain true and recorded above
  (core enforces the header at activation; WP-CLI can deactivate a
  dependency anyway) — they now simply no longer apply to this plugin.

## Kind inventory and eligibility `reproduced`

- Inventory extracted from `Taxonomy::$default_kinds` by reflection:
  **exactly 36 distinct public kinds**, no aliases/hidden/legacy
  entries; readme.txt's "full 36-kind vocabulary" reconciles. Registry
  is filterable; unknown kinds default opt-in (tested).
- Policy revised per product review: **22 on / 14 off** — consumption
  logs and substantive responses joined the defaults; thin signals and
  privacy-sensitive kinds stay opt-in. Full per-kind table:
  `docs/integrations/standard-site-kind-eligibility.md`.

## Metadata-default mechanism — proven, with two findings `reproduced`

New hardening tests cover REST writes in both directions, revisions,
autosaves, quick/bulk-edit-shaped updates, narrowing the site default
against a published record, and the reserved-but-unpublished TID state.
Two findings worth keeping:

1. **Core swallows a default-equal meta write.** On plain ATmosphere,
   writing `false` (the registered default) to a row-less
   `atmosphere_disabled` stores nothing — `update_metadata()`'s
   duplicate check compares against the metadata *default*. Harmless
   there (absent ≡ false), but it means this integration's default
   filter is precisely what makes an explicit opt-in stick on a
   default-off kind: with the filter active the compared default is
   `'1'`, so the row stores. Verified by probe and pinned by
   `test_rest_toggle_write_survives_the_kind_default`.
2. **The WP test framework wipes registered meta between tests**, so
   ATmosphere's init-time registration only survives the first test per
   process; REST meta writes silently no-op afterward. The live suite
   re-registers in `set_up`. Cost a debugging session; documented so it
   never costs another.

## Live courtneyr.dev — read-only `observed`

Authorized WP-CLI (`@live` alias), read-only commands only, no values
of any credential-bearing option printed:

- ATmosphere **2.1.0, Active**; its crons scheduled.
- `atmosphere_publication_tid` = `3mnmsxusdowol` — **identical** to the
  live publication record's rkey and the `.well-known` response.
- Classification: **already owned by ATmosphere. No migration needed.**
  The discovery audit's "unidentified non-PKIW mechanism" was
  ATmosphere itself. The former manual-option migration advice is
  withdrawn from setup documentation and survives only as labeled
  unsupported developer recovery.

## Publication adoption `reproduced`

The upstream gap is real independent of any site:
`test_publication_rkey_is_minted_blind_never_adopted` proves
`Publication::get_rkey()` mints with **zero network requests** (a
`pre_http_request` tripwire recorded none) and that a pre-seeded TID is
respected. Proposal A retained with the courtneyr.dev framing corrected.

## Released 2.1.0 vs trunk `reproduced`

The live suite now runs against **both** a pinned 2.1.0 clone and the
trunk checkout (58 tests green on each; CI mirrors this with a gating
2.1.0 leg and a non-gating trunk job). Differences established:

- `atmosphere_should_publish_bluesky_post`, the document-only publish
  path, and AT Tags are trunk-only (`@since unreleased`).
- **2.1.0 emits the document verification link only when the post also
  carries a Bluesky record** (rebuilds the URI from the stored TID);
  trunk emits from the stored document URI. Found when the link-tag
  test failed against 2.1.0 with document-only seeding; the test now
  seeds a dual-published post and passes on both.

## Quality gates after correction `reproduced`

| Gate | Result |
|---|---|
| Unit suite (ATmosphere trunk loaded) | 1206 tests, 3136 assertions, 0 failures, 7 skipped (env pins) |
| Integration suite | 209 tests, 556 assertions, 0 failures, 2 risky (pre-existing at `28d2802`, verified on the baseline worktree) |
| atmosphere group — without ATmosphere | 51 tests, 5 skips (live half) |
| atmosphere group — released 2.1.0 clone | 58 tests, 0 failures |
| atmosphere group — trunk checkout | 58 tests, 0 failures |
| Jest | 26/26 |
| PHPCS | clean |
| PHPStan (stubs) | 0 errors |
| ESLint / Stylelint | 0 errors, 26 warnings (pre-existing) |
| `lint:pkg-json` | 9 errors — **pre-existing at baseline** (docs/package.json; identical count on `28d2802`), out of scope |
| POT extraction | generates cleanly |
| Docs site build | 15 pages, clean |
| Playground blueprints | valid JSON, ordinary demo has no ATmosphere step |
| Playwright e2e + a11y + visual (darwin baselines), ATmosphere **active** | **68 passed, 4.8m** — no duplicate panels/tags/notices, no visual drift |
| Playwright e2e + a11y + visual, ATmosphere **inactive** | **67 passed, 4.4m** (one spec parametrizes on active plugins; both combos fully green) |
| Production build + zip | rebuilt post-correction; shipped header carries no Requires Plugins line; 4 integration classes ship; stubs/docs excluded |
| Final diff protocol sweep | only `pkiw_atmosphere_*` filter applications match |

## Honest status

**Release candidate — remote contract verification pending.** Every
locally feasible gate has run; the real-PDS contract matrix, the
Standard.site-only mode (blocked for released 2.1.0 anyway), and indexer
discovery remain blocked with exact procedures in
`docs/audit/standard-site-release-readiness.md`. Nothing was pushed,
filed, released, or changed remotely; courtneyr.dev was only read.

---

# Release-verification pass — 2026-08-21 (PR #160)

- Branch pushed (ordinary upstream-tracking first push; remote branch did
  not previously exist); draft PR:
  https://github.com/courtneyr-dev/post-kinds-for-indieweb/pull/160
- **CI matrix green** on `d3c2b0e`: CI + Docs workflows `completed
  success` — 7 PHP legs (each ~1,415 tests with the live ATmosphere 2.1.0
  suite; 7 env-pinned skips; 2 pre-existing risky), Linux e2e + **visual
  regression passed with no baseline updates needed**, accessibility,
  Build, WordPress Plugin Check, i18n, JS/CSS lint, Jest, PHPCS, PHPStan,
  security scans, ATmosphere-trunk compatibility (non-gating). First-run
  failures, both branch-caused and both fixed same day: (1) all PHP legs
  failed `DistributionManifestTest` because the CI step checks
  `wordpress-atmosphere/` out inside the workspace — a real contract
  catch, resolved by excluding the CI-only path in `.distignore` (local
  reproduction with a simulated directory, then green); (2) the Docs
  workflow's Vale prose lint rejected one "simply" in installation.md.
- **Playground (rendered, branch build):** activation without ATmosphere,
  the Integrations recommendation with exact copy and working Find
  ATmosphere link, front end and editor load, zero PHP errors. wp.org
  Live Preview remains blocked until a deploy publishes the SVN
  `assets/blueprints/` copy.
- **Upstream proposal:** GitHub search found no equivalent issue or PR in
  Automattic/wordpress-atmosphere; the review-ready issue draft is
  appended to `docs/upstream/atmosphere/02-bluesky-filter-post-context.md`.
  Not filed — filing awaits explicit authorization.
- **Contract boundary:** real-PDS matrix, indexer visibility, and the
  2.1.0-limitation live proof stop at the disposable-credential boundary
  (exact resource list in the readiness checklist). No production
  account, option, or record was touched at any point.

