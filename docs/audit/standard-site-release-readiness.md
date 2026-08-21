# Standard.site integration — release-readiness checklist

**Status: release candidate — remote contract verification pending.**
Branch `feature/atmosphere-standard-site` (rebased onto current `main`;
pre-correction state preserved at tag
`backup/atmosphere-standard-site-pre-correction`).

Evidence markers: `reproduced` (test/command run this pass with recorded
output) · `observed` (read directly from source or a live response) ·
`blocked` (needs infrastructure this environment cannot use) ·
`unverified`.

## Architecture and provenance

- [x] Branch ancestry proven: merge-base with `origin/main` is the
  audited `28d2802`; `98a4d08` is **not** an ancestor (it is another
  session's one-file skill-doc commit on its own branch, untouched);
  rebased cleanly onto current `main` (`95d9fa0`, the merged #158
  skill-doc PR — no overlap). `reproduced`
- [x] Original implementation preserved: annotated tag
  `backup/atmosphere-standard-site-pre-correction`, not pushed. `observed`
- [x] ATmosphere is optional: no `Requires Plugins` header; plugin
  installs/activates/works alone; every companion state degrades to one
  contextual settings-tab line. `reproduced` (bootstrap tests + runtime)
- [x] No publisher-protocol code in Post Kinds: final-diff sweep clean
  for OAuth/PKCE/DPoP/PAR/token storage/PDS writes/applyWrites/
  createRecord/putRecord/deleteRecord/uploadBlob/well-known routes/
  duplicate head tags/duplicate `_atmosphere_*` ownership. `reproduced`
- [x] Minimum version evidence-backed: every production API verified
  present in the 2.1.0 tag; trunk-only APIs (`atmosphere_should_publish_
  bluesky_post`, document-only lane, AT Tags) documented as unreleased
  and not depended on. Non-gating trunk CI job added. `observed`

## Policy and privacy

- [x] Kind inventory verified: exactly 36 distinct public kinds
  extracted from the registry by reflection; readme reconciles; no
  aliases/hidden/legacy entries; unknown registry kinds default opt-in
  (tested). `reproduced`
- [x] Per-kind eligibility decisions: 22 on / 14 off with individual
  reasoning, title source, public fields, and privacy grading —
  `docs/integrations/standard-site-kind-eligibility.md`. `observed`
- [x] Explicit per-post choice wins over defaults, both directions.
  `reproduced`
- [x] Published records never retracted by default/setting changes
  (guard tested, including narrowing the site default to empty).
  `reproduced`
- [x] Old archives never auto-publish: routine edits of unsynced legacy
  posts are skipped by ATmosphere (`atmosphere_update_skipped_unsynced_
  post` path); backfill is manual and idempotent. `observed`/`reproduced`
- [x] Metadata-default mechanism proven: REST writes both directions,
  revisions, autosaves, quick/bulk-edit-shaped updates, reserved-TID
  failed-publish state, later site-default changes. Includes the finding
  that core's `update_metadata` swallows default-equal writes on plain
  ATmosphere and that this integration's filter is what makes opt-in
  stick for default-off kinds. `reproduced`
- [x] Test-framework caveat documented: WP's test suite wipes registered
  meta between tests; the live suite re-registers in `set_up`. `reproduced`

## Live production

- [x] courtneyr.dev inspected read-only (authorized WP-CLI): ATmosphere
  2.1.0 **Active**; `atmosphere_publication_tid` = `3mnmsxusdowol`,
  matching the live `.well-known` record — classification: **already
  owned by ATmosphere; no migration necessary**. No production values
  changed; no secrets printed. `observed`
- [x] Manual option mutation removed from setup guidance; retained only
  as labeled unsupported developer recovery with backup + DID/rkey
  verification requirements. `observed`

## Quality gates (all local)

- [x] Unit suite `reproduced` — see the implementation record's
  correction-pass table for current counts
- [x] Integration suite `reproduced` (2 risky pre-exist at baseline)
- [x] Atmosphere group with and without a real ATmosphere checkout
  `reproduced`
- [x] Jest, ESLint, Stylelint, PHPCS, PHPStan `reproduced`
- [x] Production build + distribution zip inspected (integration files
  ship; no dependency header; stubs/docs excluded) `reproduced`
- [x] Playwright e2e + a11y (chromium, wp-env with ATmosphere active
  and disconnected) `reproduced` — see record for counts
- [x] Visual regression against darwin baselines `reproduced`
- [x] **GitHub CI matrix green end to end** on `d3c2b0e` (PR #160):
  all 7 PHP legs incl. the live ATmosphere suite, **Linux e2e + visual
  regression passed with no baseline changes**, accessibility, plugin
  check, i18n, docs build + prose lint, non-gating trunk job.
  `reproduced`. Two branch-caused failures found on the first run and
  fixed: the CI companion checkout tripping DistributionManifestTest
  (excluded via .distignore) and one Vale prose-lint error ("simply").
- [x] **Rendered Playground verified on the branch build** (Chromium,
  in-app browser, blueprint pinned to the branch ref): activation
  without ATmosphere, Integrations-tab recommendation with the exact
  copy + Find ATmosphere link, front end and block editor load, zero
  PHP errors in page output. `reproduced`. The wp.org Live Preview
  reads blueprints from SVN `assets/blueprints/` and is `blocked`
  until the next wp.org deploy.

## Blocked — exact remaining procedure

- [ ] **Real-PDS contract tests** `blocked` at the credential boundary.
  Needed from Courtney: (1) a disposable public HTTPS WordPress site
  (throwaway host — not courtneyr.dev or its staging) with admin access,
  running the PR branch build + ATmosphere 2.1.0; (2) a disposable AT
  Protocol account created for this test; (3) the OAuth connect
  performed once in that site's Settings → ATmosphere (account creation
  and credential entry are out of scope for the assistant). From there
  the 26-step matrix below is drivable end to end, including cleanup of
  the records the test creates. Procedure: disposable
  WordPress site + disposable AT Protocol account → activate Post Kinds
  alone and verify ordinary behavior → add ATmosphere 2.1.0 → connect →
  publication sync → verify `/.well-known/site.standard.publication` →
  publish the fixture matrix (titled article; untitled note; listen;
  watch S/E; read; reply; bookmark; public + private check-in opted in;
  mood opted in; image post; imported post; webhook post; explicit
  opt-out; explicit opt-in on a default-off kind) → inspect `?atproto`
  previews and PDS records → verify link tags → validator
  (site-validator.fly.dev) + indexer (docs.surf / Standard Search) →
  edit (same record identity) → unpublish (removal) → restore
  (re-publish; note fresh TIDs by ATmosphere design) → delete →
  backfill twice (idempotent) → disconnect/reconnect (no duplicates) →
  clean up the disposable records created by the test.
- [ ] **Standard.site-only mode** `blocked for released 2.1.0` (the
  filter is trunk-only); test on trunk separately when exercising the
  contract procedure; never describe it as released behavior.
- [ ] **Indexer discovery** `blocked` with the contract tests.

## Release steps that remain Courtney's call

- Push branch / open PR; version bump + `@since` normalization (1.6.0
  assumed); readme.txt changelog + screenshots; POT refresh; file the two
  upstream proposals; dispatch Visual Baselines if CI's Linux run drifts.
