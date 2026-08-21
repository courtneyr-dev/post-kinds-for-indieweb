# Change-coverage receipt — 1.6.0 documentation pass (2026-08-21)

Baseline → target: `7027593` (1.1.0, Aug 19) → `c5bf4e6` (current main,
post-#160). 18 commits; releases 1.5.0, 1.5.1, 1.5.2 plus the merged
ATmosphere integration. Surfaces evaluated: `docs/src/content/docs/`
(15 pages), `README.md`, `readme.txt`.

## Per-change coverage

| Change (commit/PR) | User-facing? | Docs surface state found | Action |
|---|---|---|---|
| 36-kind vocabulary + event kind (#135, #136) | yes | docs covered (#137/#141); readme.txt kind list missing Event + 7 kinds implied; README tables missing Event | readme.txt + README lists completed |
| Kind labels filter + pk-caption (#134) | yes (devs/themers) | changelog-only | FAQ customize-cards answer now names both |
| Kind artwork → featured images + Yoast schema (#140) | yes | changelog-only (CHANGELOG.md); absent from readme.txt 1.5.0 entry, docs, README | new common-tasks section (incl. filter + `wp postkind featured-artwork backfill`); feature bullets both readmes; readme.txt 1.5.0 entry backfilled |
| TV lookup + meta-cast fixes (#138, #139) | bug fixes | changelogs | none needed |
| Card-meta mirroring (Card_Meta_Sync, 1.5.0) | yes (devs) | CHANGELOG.md only | readme.txt 1.5.0 entry backfilled |
| h-entry wrapper (#146) | yes (subtle) | changelog + mf2 docs neighborhood | crash/“fixed in 1.0.0” rewrite covers the era; no separate section (judgment: changelog + common-tasks mf2 section suffice) |
| /firehose feed (1.5.0) + 404 fix (#151) | yes — a shipped URL | changelog-only on every surface | new common-tasks section, feature bullets both readmes, new troubleshooting entry |
| Kind-archives 404 fix (#151) | yes | changelog-only | new troubleshooting entry |
| Editor crash fixes (#152, #156, 1.5.2) | yes | troubleshooting said "fixed in 1.0.0" | rewritten to 1.5.2 with the four crash sites |
| Slim download (#157) | yes | changelog | Upgrade Notice entry |
| ATmosphere integration (#160) | yes | documented same-day with the feature | verified present; one stale eligibility line in troubleshooting corrected to 22-on/14-off |
| Docs-vs-reality contradictions | — | index.md said “not on WordPress.org”, “as of 1.0.0”, 25 blocks; README said 22 blocks; installation.md hedged | all corrected; counts now match the measured registry (27 blocks, 36 kinds) |

## Reconciliation checklist

- [x] git changes ↔ release notes (readme.txt 1.5.0 entry backfilled with 4 omitted items)
- [x] source ↔ released claims (block/kind counts measured from build/blocks and the taxonomy registry)
- [x] shipped-but-unannounced — firehose, featured artwork, kind-label hooks (now announced)
- [x] announced-but-didn't-ship — none found
- [x] reverted work — none in window
- [x] feature flags ↔ defaults (atmosphere_integration default-on, documented)

## Intentionally unchanged

- `@since` docblocks crediting pre-release versions (1.1.0/1.3.0) for
  features wp.org saw in 1.5.0 — historical, defensible, noted in the
  implementation record.
- screenshots.md — no Event Card / Integrations-tab captures yet; specs
  exist in the docs screenshot pipeline. Named open item.
- docs/package.json lint errors — pre-existing at baseline, out of scope.

## Evidence

Docs site builds clean (15 pages); added prose screened for the prose
lint's rejected wording; smoke tests green; full CI runs on PR #161.
