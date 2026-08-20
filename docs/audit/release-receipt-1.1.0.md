# Change-coverage receipt — 1.1.0

Per the change-coverage-receipt method in
[docs-and-release-rig](https://github.com/courtneyr-dev/docs-and-release-rig)
(`1-documentation-practice/03-change-coverage-receipt.md`): a deterministic
completeness check produced before declaring the release prep complete.
Not published to the docs site (audit files stay at `docs/` root).

## Reconciliation checklist

- [x] git changes reconciled with release notes — every non-dependency commit in
  `1.0.0..HEAD` maps to a `CHANGELOG.md` 1.1.0 entry or is doc-only (see below)
- [x] source reconciled with the released packages — `build/` rebuilt from `src/`
  this cycle (`npm run build:prod`), `.distignore` excludes dev configs (#116)
- [x] documented deprecations reconciled with `@deprecated` annotations and runtime —
  none in range
- [x] API changes reconciled with tests — 12 kinds: TaxonomyTest (36 + per-slug),
  CoreAbilitiesTest (36), MicroformatsTest (root + property providers),
  StreamCardTest (title-less + unknown-kind render), micropub-kinds e2e
  (follow/weather now expect real terms); abilities rename: AbilitiesRegistrationTest
- [x] dependency changes reconciled with lockfiles — 10 dependabot commits, lockfile
  bumps only, no API surface
- [x] feature flags reconciled with shipped defaults — none in range
- [x] internal plans reconciled with what actually shipped — per-kind card blocks for
  the 12 new kinds deliberately deferred; documented as "no dedicated card blocks yet"
  in CHANGELOG, readme.txt, faq, getting-started
- [x] shipped changes absent from public notes — **6 found and fixed**: Firehose feed
  (#120), standard.site records (#128, was in readme.txt but not CHANGELOG),
  block-bindings deferral (#124), empty-Embed-above-Bookmark-Card fix (#129),
  dist excludes (#116), MCP advertise-registered-only (#131)
- [x] announced changes that did not ship — none found
- [x] reverted or partially reverted work — none in range
- [x] changes present only in generated artifacts — `build/*.asset.php` version
  hashes only, regenerated from committed source

## Receipt

| Field | Value |
|---|---|
| Baseline → target | `1.0.0` (tag) → `1.1.0` (release/1.1.0 = main@98604d0 + docs/release prep) |
| Commits / tags reviewed | 27 non-merge commits (17 substantive incl. #135 squash and #136 event card, 10 dependabot); tags `1.0.0`, dev-era `v1.1.0–v1.4.3` (naming: new releases use unprefixed tags) |
| Releases reviewed | 1.0.0 (WordPress.org, 2026-07-20) |
| Files changed | ~150 across the range |
| Public APIs changed | +12 default kinds; +`post-kinds-indieweb/event-card` block (18th kind card, 26 blocks total); abilities renamed underscores→dashes (old names never registered, no aliases needed) |
| Hooks changed | +`pkiw_kind_label` (filter), +`pkiw_standard_site_resolved`, +`pkiw_pre_calendar_event`, +`pkiw_calendar_source_active` |
| Routes changed | +`/firehose`, `/feed/firehose/`, `?feed=firehose` |
| Schemas changed | none |
| Dependencies changed | 10 dependabot groups, dev-deps only |
| Deprecations reviewed | none in range |
| Removals reviewed | none in range (Enabled Reaction Types removal shipped in 1.0.0) |
| Undocumented changes found | 6 (all now in CHANGELOG 1.1.0 + readme.txt) |
| Entries NOT fully reviewed, and why | Doc-only commits (#117–119, #121, #129 docs half, #130) verified as no-runtime-change by path (`docs/`, `README.md`); pre-release manual IndieWeb validation pass (indiewebify.me, microformats.io, monocle preview) for the 12 new kinds not yet run — required before tagging per repo CLAUDE.md; MicroformatsRenderTest does not yet cover the 12 new kinds (existing gap noted in CLAUDE.md for rsvp + experimental kinds) |

## Evidence

- CI run 32293054333 on PR #135: all jobs green (phpcs, phpstan, phpunit ×4,
  eslint, stylelint, jest, build, plugin-check, e2e, a11y, i18n).
- Staging (41451.us6.myftpupload.com, deploy `b6ff33c`): `wp @staging eval`
  reports 36 default kinds and the title-less fix present in the deployed file;
  `/stream/` renders one card per kind for all 36, zero empty `<li>` items.
