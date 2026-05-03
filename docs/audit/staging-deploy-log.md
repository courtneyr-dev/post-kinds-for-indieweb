# Staging Deploy Log

Append-only record of every deploy of the three audit-target plugins to staging
(`https://qkf.b0d.myftpupload.com/`). Each entry captures source provenance,
SHA, build state, deploy method, and verification-gauntlet result.

Per audit prompt Sections 3.3 and 3.8.

---

## 2026-05-01 — Session A1 — Initial deploy (all three plugins)

**Deploy timestamp (UTC):** `2026-05-01T11:51:34Z`
**Deploy method:** Section 3.5 Method B — `rsync` plugin tree directly into
`html/wp-content/plugins/{slug}/` (Method A `wp plugin install` from zip
failed for the larger plugins because the GoDaddy SSH/SFTP transport drops
multi-megabyte uploads at consistent byte boundaries; rsync with `--partial`
recovers across drops).
**Activation order:** `link-extension-for-xfn` → `post-formats-for-block-themes`
→ `post-kinds-for-indieweb` (per Section 3.5 — PKIW registers hooks against
the other two).

### Plugins deployed

| Plugin | Source | Branch | Commit SHA | Notes |
|---|---|---|---|---|
| `link-extension-for-xfn` | local working copy | `main` | `215cfae4b4d6ec755a7ebbedff66cd79024ee227` | Untracked tooling dirs `.claude/`, `.omc/` excluded from dist. No uncommitted plugin code. |
| `post-formats-for-block-themes` | local working copy (UNMERGED) | `feature/quote-style-variations` | `6785989e06f62fb190594bc0bd315f82b57743c4` | **Unmerged feature branch.** v2.3.0 + this session's chore commit `chore(2.3): polish — filter docblock + test-method docblocks + lint cleanup`. PR not yet opened. |
| `post-kinds-for-indieweb` | fresh clone from GitHub | `main` | `ac2158ea83005fdf2dcdeeadf45319193e7c22a7` | First-time deploy on this staging — no prior version to roll back to. |

### Build state per plugin

All three plugins built successfully and have complete runtime artifacts in
their respective `build/` directories. None of the three load anything from
`vendor/` at runtime (verified by grep for production-path
`require_once .* vendor/autoload` in main plugin files and `includes/`).

| Plugin | composer install | npm ci + build | Build artifacts present |
|---|---|---|---|
| `link-extension-for-xfn` | warning — lock drift but `Nothing to install`, autoload generated cleanly | OK — webpack | `build/`: 7 files (block.json, index.js/css, view.js, etc.) |
| `post-formats-for-block-themes` | warning — lock drift but completed | OK — webpack (550ms) | `build/`: index.js + index.asset.php |
| `post-kinds-for-indieweb` | FAIL — lock file incompatible with current composer.json — `composer install` from lock fails | OK — webpack (2954ms, 548 KiB JS + 14.9 KiB CSS) | `build/`: blocks/, blocks.js, blocks.css, blocks-rtl.css, blocks.asset.php, index.js, index.asset.php |

**PFBT and XFN:** composer lock-drift warnings, builds completed.

**PKIW:** composer lock incompatible with current composer.json (composer
install from lock fails). Build proceeded because runtime is JS + plain PHP
only — no `vendor/autoload.php` requires in any production code path.

**Tag:** PKIW carries `[needs: composer-lock-refresh]` for Phase D triage.
Tracking issue: see "Open issues" section below.

### Path A — debug observability accepted

Audit prompt Section 3.1 requires `WP_DEBUG=true`. Staging is on GoDaddy
managed WordPress; `auto_prepend_file=/platform/misc/prepend-cli.php` plus
`/configs/config.php` define `WP_DEBUG=false` at the platform level before
`html/wp-config.php` runs. PHP `define()` is first-write-wins, so customer
wp-config.php cannot override.

Decision (user-approved): proceed with `WP_DEBUG=false` and use **Query
Monitor** (active) plus HTTP-status checks plus the platform error log as the
debug observability layer. `WP_DEBUG_LOG=true` and `SCRIPT_DEBUG=true` ARE
active at runtime — fatals will populate `wp-content/debug.log` even with
`WP_DEBUG=false`. Notice/warning-level signal lives in Query Monitor's admin
bar.

### Section 3.6 verification gauntlet

| Check | Result |
|---|---|
| 1. All three plugins active | OK — XFN 1.0.3, PFBT 2.3.0, PKIW 1.0.0 all `active` |
| 2. Front-end 200 OK | OK — `https://qkf.b0d.myftpupload.com/` → HTTP 200 |
| 2. Admin route 302 (login redirect) | OK — `/wp-admin/` → HTTP 302 |
| 3. Cron jobs registered | NOTE — PKIW registers `post_kinds_indieweb_process_import` (single-event) and a recurring sync via `class-scheduled-sync.php`, but no events appear in `wp cron event list` until configured. Not a regression — fresh activation has no scheduled imports yet. |
| 4. Options registered: `pkiw_*` | 0 (fresh install — expected; options created on user opt-in) |
| 4. Options registered: `pfbt_*` | 6 |
| 4. Options registered: `xfn_*` | 1 |
| 5. `debug.log` empty of fatals | OK — file absent. No fatals during activation. |
| 6. Active theme is a known block theme | OK — `courtneyr-child` (block theme, child of Ollie) |
| 7. Permalinks non-default | OK — `/%year%/%monthnum%/%day%/%postname%/` |

### Pre-existing platform noise (NOT introduced by this deploy)

- `PHP Warning: Undefined array key "innerContent" in /html/wp-includes/blocks.php on line 1713`
- `PHP Warning: foreach() argument must be of type array|object, null given in /html/wp-includes/blocks.php on line 1713`
- `PHP Warning: Constant WP_DEBUG already defined ...` (platform-prepend collision)

These appear on every `wp-cli` invocation since well before this session.
WP 7.0-RC2 core bugs in `blocks.php` and platform-prepend constant
re-definition. Neither was introduced by activating any of the three
audit-target plugins — verified by re-running `wp option get` for known
stable options outside any plugin hook chain.

PKIW activation also surfaced `foreach() ... null` and `Undefined array key
"attrs"` in the same `wp-includes/blocks.php` line range. Same pre-existing
core bug; not a deploy regression.

### Rollback artifacts (Section 3.10)

Pre-deploy snapshots of the previously-installed plugins on staging:

- `/tmp/pkiw-audit/rollback/rollback-link-extension-for-xfn-2026-05-01T11-51-34Z.zip` (90 KB) — XFN state immediately before this deploy
- `/tmp/pkiw-audit/rollback/rollback-post-formats-for-block-themes-2026-05-01T11-51-34Z.zip` (10.3 MB) — PFBT state immediately before this deploy
- (no PKIW rollback — first install on this staging)

Rollback procedure: `wp plugin deactivate {slug} --allow-root` then `wp plugin install {rollback-zip-path} --force --activate --allow-root`.

### Acceptance — Session A1

- [x] All three plugins built without runtime-blocking error.
- [x] All three plugins active on staging in the right order.
- [x] Verification gauntlet passes (with the caveats documented above).
- [x] This deploy log records the initial deploy with SHAs and source provenance.
- [ ] `/bin/seed-staging.sh` populates synthetic test data — **deferred to Session A2.**
- [x] courtneyr-child (block theme) active.
- [x] No fatals in `debug.log` after activation (file absent — no fatals).
- [ ] Courtney has confirmed staging is the right environment to proceed against — **previously confirmed in Session A0; reaffirm before A2.**

### Open issues / follow-ups

1. **`[needs: composer-lock-refresh]` — `post-kinds-for-indieweb`** — composer.lock not in sync with current composer.json on the `main` branch. `composer install` fails from lock. Plugin runs at runtime because no production code path requires `vendor/autoload.php`, but dev-tooling commands (`composer audit`, `composer require --dev`) will fail on a fresh clone. Tracking issue to be opened on `courtneyr-dev/post-kinds-for-indieweb` as part of A1 closeout. Phase D triage owns the actual fix.

2. **`[needs: api-keys]`** — sandbox API credentials for the 15 external services (Last.fm, TMDB, Trakt, Foursquare, RAWG, BoardGameGeek, Open Library, Hardcover, Google Books, ListenBrainz, MusicBrainz, Podcast Index, Simkl, TVmaze, Nominatim) deferred per Phase D start. Functionality audit (Phase D) will mark integrations `[needs-sandbox-key]` per Section 3.2 if not provided.

3. **`[needs: webhook-secret]`** — staging-only webhook test secrets for Plex, Jellyfin, Trakt, ListenBrainz deferred per Session E3 start.

4. **`[needs: admin-creds]`** — WordPress admin credentials for staging deferred per Session D6 (Playwright e2e against `/wp-admin/`).

5. **`[needs: db-backup]`** — staging DB backup mechanism (whether GoDaddy provides snapshots or a `wp db export` workflow) deferred until first Phase E session that runs a destructive operation.

6. **`[needs: basic-auth]`** — recommendation per Session A0 to add HTTP basic auth to staging before Phase E (security audit) begins, so reproduced findings aren't visible to the public. Not blocking Phases A-D.

7. **`legacy indieweb-post-kinds plugin`** — confirmed deactivated; remains installed as parity-baseline reference for Phase D Session D2. Must not be activated for the duration of the audit.


---

## 2026-05-01 — Session A2 — Seed test data

**Deploy artifact:** `/bin/seed-staging.sh` shipped to PKIW repo at
`/tmp/pkiw-audit/post-kinds-for-indieweb/bin/seed-staging.sh`.

**Manifest:** `/tmp/pkiw-audit/seed-manifest.json` (36 posts, valid JSON).

**Tag:** `audit-seed-2026-05-01` (every seeded post carries this tag for
filterable lookups; also `_audit_seed_id` post meta with a stable per-seed
slug for idempotent re-runs).

### Discrepancy with prompt: 24 kinds, not 17

Audit prompt Section 6.1 lists 17 post kinds. PKIW main branch
`includes/class-taxonomy.php:48` defines 24 default kinds — adds `note`,
`article`, `event`, `photo`, `video`, `review`, `recipe` beyond the
prompt's 17. **The seed covers all 24** because PKIW's actual surface is
what the audit needs to validate. The 17 vs 24 reconciliation belongs to
Phase D Session D2 (legacy parity audit) — open question whether the
extra 7 are intentional or migration leftovers.

### Coverage achieved

| Section | Expected | Created | Notes |
|---|---|---|---|
| Per PKIW kind | 24 | 24 | One post per kind, mf2 markup hand-crafted in `post_content`. |
| Per WP post format | 9 | 9 | One post per format, set via `post_format` taxonomy. |
| Composition overlap | 3 | 3 | bookmark+link+xfn, reply+aside, listen+standard. |
| **TOTAL** | **36** | **36** | All seeded posts published, status=publish, author=ID 1. |

### Per-taxonomy intersection counts (seeded posts only)

**Kinds (24 distinct + 3 overlap-with-kind = 27 kind-tagged seeded posts):**

| kind | count |
|---|---|
| `reply` | 2 (kind-reply + overlap-reply-aside) |
| `bookmark` | 2 (kind-bookmark + overlap-bookmark-link-xfn) |
| `listen` | 2 (kind-listen + overlap-listen-standard) |
| 21 other kinds | 1 each |

**Formats (9 distinct + 2 overlap-with-format = 11 format-tagged seeded posts):**

| format | count |
|---|---|
| `aside` | 2 (format-aside + overlap-reply-aside) |
| `link` | 2 (format-link + overlap-bookmark-link-xfn) |
| 7 other formats | 1 each |

Verification command (re-runnable):

```bash
wp @staging post list --post_type=post --tag_slug__in=audit-seed-2026-05-01 --post_status=any
```

### Bug found and fixed during A2

Initial `seed-staging.sh` had two bugs caught during verification:

1. **Stdout pollution:** `seed_post()` echoed both progress lines (`[ok] ...`)
   and the post_id to stdout, so the caller's command substitution
   `post_id=$(seed_post ...)` captured both, producing malformed JSON in the
   manifest. Fixed by routing all progress output to stderr (`>&2`).

2. **`wp post term set` argument order:** taxonomy is the SECOND positional
   argument, term-list is THIRD. The script had them swapped, so taxonomy
   assignments silently failed — posts were created but had no kind/format
   terms attached. Fixed and patched all 36 existing posts via a one-off
   Python loop that re-applied the correct taxonomy calls.

Both fixes are in the committed seed script. Re-running on a fresh staging
will produce a clean manifest and correct taxonomy assignments on first run.

### Acceptance — Session A2

- [x] `/bin/seed-staging.sh` exists in PKIW repo, executable, passes `bash -n`.
- [x] Script is idempotent (re-running with same date skips existing seeds).
- [x] `SEED_FORCE=1` env var supports forced recreation.
- [x] One post per PKIW kind exists (24 kinds covered).
- [x] One post per WP post format exists (9 formats covered).
- [x] Three overlap composition posts exist.
- [x] Manifest at `/tmp/pkiw-audit/seed-manifest.json` parses as valid JSON
      with 36 entries.
- [x] Every seeded post tagged with `audit-seed-2026-05-01` for filtering.
- [x] No fatal errors on staging during seeding (front-end still 200 OK).

### Open follow-ups

- The seed uses hand-crafted mf2 markup in `post_content`. **Real API
  lookups deferred to Phase D** — when sandbox keys land, Session D1's
  per-kind verification will exercise the actual integration path
  (TMDB → watch, Open Library → read, MusicBrainz → listen, etc.) and
  populate seeded posts with real meta from the API responses, replacing
  the synthetic markup.
- Phase D will distinguish "kind-X with synthetic mf2" from "kind-X with
  API-fetched meta" via a `_audit_seed_source` meta key (synthetic / api).

---

## 2026-05-01 — Session B1 — Plugin map + packaging fix

**Plugin map landed at:** `/docs/audit/plugin-map.md`. Maps the 60-PHP-file
PKIW codebase: 18 top-level classes, 17 API clients, 8 sync workers, 9 admin
pages, 4 server-rendered helper blocks, 38 REST routes, 19 AJAX handlers, 2
cron hooks, 1 CPT (conditional), 2 taxonomies.

### Discrepancies with audit prompt's framing

| Prompt claim | Reality | Notes |
|---|---|---|
| 17 post kinds | 24 default kinds | Extra: note, article, event, photo, video, review, recipe (D2 reconciliation) |
| 12+ APIs | 17 API clients | Extra: readwise, nominatim |
| 16 custom blocks | 19 build/blocks/ + 4 helper = 23 `register_block_type` | D6 reconciliation |
| Section 6.1 says ~14 routes | 38 register_rest_route calls | E4 audit much larger than prompt anticipated |

### Packaging bug found and fixed during B1

**Bug:** PKIW's `register_blocks()` method at
`includes/class-plugin.php:738-825` reads `block.json` files from
`POST_KINDS_INDIEWEB_PATH . 'src/blocks/' . $block` — i.e., the source
directory, not the compiled `build/blocks/`. The A1 deploy excluded `src/*`
from the dist (assuming source was build-only), so `register_block_type()`
silently no-op'd on every block (no `block.json` found at expected path,
condition `file_exists()` false, fall through).

**Symptom:** `WP_Block_Type_Registry::get_all_registered()` returned 0
PKIW-prefixed blocks despite the plugin being active.

**Fix:** rsync'd `/tmp/pkiw-audit/post-kinds-for-indieweb/src/blocks/` →
`courtneyr-staging:html/wp-content/plugins/post-kinds-for-indieweb/src/blocks/`
after `mkdir -p` on the parent. After fix: 18 of 19 blocks register correctly
on staging. The 19th block is excluded by the iteration array in
`class-plugin.php:738` — Phase D Session D6 will determine which one and why.

**Permanent fix candidates (for the plugin author):**
1. Have `register_blocks()` read from `build/blocks/` (where webpack output
   lives) instead of `src/blocks/`. The compiled `block.json` files in
   `build/blocks/` carry the same metadata; this would make the plugin
   self-contained at the dist level.
2. Or keep `src/blocks/*/block.json` in dist explicitly via `.distignore`
   adjustment. Simpler, less code change, keeps build/src separation clear.

Either fix lives in PKIW's `dev-tooling-cleanup` feature branch — surfaced
to user-facing as part of the Phase D triage results.

### Updated dist-build script needs

`bin/seed-staging.sh` is fine — runtime-only test data.

The dist-build path used in A1 (`zip ... -x "src/*"`) misses `src/blocks/`.
Future deploys must either: (a) keep the entire `src/` directory in dist
(simplest, ~50 KB extra), (b) selectively include `src/blocks/` only, or (c)
adopt fix-candidate 1 above and exclude `src/` cleanly.

For B1 closeout, staging carries the patched layout (rsync of `src/blocks/`
on top of A1's deploy). Documented here so future re-deploys know the rule.

### B1 acceptance

- [x] `/docs/audit/plugin-map.md` produced (273 lines, all 19 top-level
      classes inventoried, REST/AJAX/cron/HTTP/SQL/secrets surface mapped).
- [x] Staging cross-check ran for the items verifiable without admin auth.
- [x] Packaging bug found, root cause identified, staging fixed, permanent-
      fix recommendations documented.
- [x] Three biggest unknowns surfaced in plugin-map.md for user input.
- [x] Phase D / Phase E follow-ups itemized with session anchors.

---

## 2026-05-01 — Session B2 — Sibling map (PFBT + XFN)

**Sibling map landed at:** `/docs/audit/sibling-map.md` (384 lines).

### Surfaces inventoried

**PFBT** (post-formats-for-block-themes v2.3.0+chore polish):
- 31 PHP files in `includes/` across 8 subdirectories
- 26 `pfbt_*` filters exposed
- 8 `pfbt_*` actions fired
- Existing `class-pfbt-post-kinds-integration.php` already implements the
  bidirectional auto-suggestion contract from Section 8.6
- Existing `class-pfbt-format-mf2.php` emits `h-entry` on every post when
  IndieWeb feature flag is on — **direct violation** of Section 8.2
  composition rule, mitigation needed in Phase F1

**XFN** (link-extension-for-xfn v1.0.3):
- 8 PHP files in `includes/`
- 1 filter exposed (`xfn_feature_flag_{$flag}`)
- 0 composition actions fired
- 2 `render_block` filters at priority 10
- No `post_class` filter (composition-safe re: h-entry)

### Key composition findings

1. **PFBT `class-pfbt-post-kinds-integration.php`** already implements
   bidirectional kind ↔ format auto-suggestion, with explicit `is_post_kinds_active()`
   detection and opt-in feature flags (`pfbt_auto_suggest_kind`,
   `pfbt_auto_suggest_format`, both default false). Phase F3 work for
   Section 8.6 contract is mostly documentation + adding PKIW + XFN
   reciprocal listeners.

2. **PFBT `class-pfbt-format-mf2.php`** unconditionally emits `h-entry` via
   `post_class` and `the_content` filters when IndieWeb feature flag is on.
   PKIW does NOT register `pkiw_owns_h_entry` filter. **Phase F1 fix:**
   add the filter on PKIW side, modify PFBT to respect it.

3. **PKIW already registers 3 `register_block_bindings_source` calls**
   across `class-block-bindings.php`, `class-block-bindings-formats.php`,
   and `class-block-bindings-source.php`. Phase F2 work simplifies to
   enumerating the keys vs Section 8.5's prompt list.

4. **PKIW filter/action naming inconsistency:** mix of `pkiw_*` (2 filters,
   1 action) and `post_kinds_indieweb_*` (13 filters, 6 actions). Phase F3
   should standardize new composition hooks on `pkiw_*` while preserving
   the long-prefix hooks for backward compat.

5. **The `the_content` filter priority order matters.** PFBT's
   `wrap_content_mf2` runs at priority 5 (before WP core formatting). XFN's
   `render_block` at priority 10 sees content after PFBT. Risk: PFBT may
   strip rel attrs during mf2 wrapping. Phase F1 reproduction case: seeded
   post 37415 (`overlap-bookmark-link-xfn`).

### Existing PKIW actions worth listening to

- `post_kinds_indieweb_item_imported` — fires after import job creates a
  post. **Excellent listener target for XFN to auto-tag rel attributes on
  imported content.** Phase F3.
- `post_kinds_indieweb_integrations_init` — sibling plugins can register
  composition hooks at this point.

### Phase F readiness scorecard

| Contract piece | Status |
|---|---|
| 8.2 h-entry ownership | ❌ needs both `pkiw_owns_h_entry` filter AND PFBT respect — Phase F1 |
| 8.5 `pfbt/*` bindings | ✅ |
| 8.5 `pkiw/*` bindings | ⚠️ partial (3 sources registered; needs key audit vs prompt spec) — Phase F2 |
| 8.6 PFBT cross-suggestion actions | ✅ already firing |
| 8.6 PKIW listeners for PFBT actions | ❌ Phase F3 |
| 8.6 `pkiw_kind_set` action | ⚠️ named differently (`post_kinds_indieweb_item_imported` exists; lifecycle action TBD) — Phase F3 |
| 8.6 `pkiw_target_url_resolved` action | ❌ Phase F3 |
| 8.6 `xfn_relationships_changed` action | ❌ Phase F3 |
| 8.7 conflict matrix verification | Phase F4 |

### B2 acceptance

- [x] PFBT mapped at file/class/hook level.
- [x] XFN mapped at file/class/hook level.
- [x] Three-plugin priority order documented for the_content, render_block, post_class, body_class, set_post_format, set_object_terms.
- [x] Composition concerns flagged with specific Phase F session anchors.
- [x] PKIW filter/action surface (15 filters, 7 actions) cross-checked and cataloged.
- [x] Existing PKIW block-bindings registration confirmed (avoids redundant Phase F2 work).
- [x] Naming inconsistency surfaced for Phase F3 standardization decision.

---

## 2026-05-01 — Session C1 — Styling inventory

**Inventory landed at:** `/docs/audit/styling-inventory.md` (374 lines).
Machine-readable companion: `/tmp/pkiw-styling-violations.json` (full
violation list with file/line/kind/value/surface).

### Headline: 487 violations across 7 categories

| Kind | Count |
|---|---|
| `hex` | 292 |
| `border-radius` | 64 |
| `font-size-px` | 60 |
| `opacity` | 34 |
| `color-function` (rgb/rgba/hsl outside `var()`) | 30 |
| `box-shadow` | 5 |
| `font-family` | 2 |

### Triage split

| Group | Count | Action |
|---|---|---|
| **Migration target** (block-frontend, block-editor, pattern, render-callback, editor-extension) | 296 | Tokenize via `--pkiw-*` per Section 5 contract |
| **Exempt** (admin-page, admin-asset — WP admin chrome integration) | 179 | Keep WP admin color references; document exemption in C2 |
| **Investigate** (block-script, other) | 12 | Per-violation review during C3 |

### Star rating Section 5.4 confirmed

`src/blocks/star-rating/style.css:8` literally uses `#ffc107` (gold) as the
prompt anticipated. Plus `#ddd` for empty stars, plus pink/blue variant
hexes (`#e91e63`, `#2196f3`) likely indicating color-modifier ratings.
Proposed C2 tokens: `--pkiw-star-rating-fill: currentColor`,
`--pkiw-star-rating-empty: transparent`, `--pkiw-star-rating-caption: inherit`,
plus per-variant tokens.

### Single highest-leverage target

`src/blocks/shared/card-editor.css` carries **126 violations** (26% of
total) and is shared by 14 card blocks (acquisition, checkin, drink, eat,
favorite, jam, like, listen, mood, play, read, rsvp, watch, wish). Tokenizing
this file once propagates the fix across all 14 cards. Recommend a
dedicated C3a session for this file before per-block stylesheet migration.

### Pattern + render-callback findings (highest priority for contract)

- 15 violations in `patterns/*.php` — 5 patterns affected (`checkin-card`,
  `watch-log`, `photo-checkin`, `read-progress`, `rsvp-response`).
- 5 violations in `includes/blocks/*.php` (render-callback surface): three
  near-identical greens (`#127925`, `#127916`, `#128214`) in
  `class-media-stats.php` and `class-now-playing.php`. Phase D triage
  question: what do the three semantically represent?

### Open questions for C2

1. Three greens semantic clarity (above).
2. `#007cba` (WP admin blue) appears in user-facing `patterns/read-progress.php` — must tokenize there even though it's WP-blue. In editor chrome (`star-rating/editor.css`) it can stay or become `--pkiw-focus-indicator`.
3. System font stack for star-rating glyphs (`-apple-system, ... "Segoe UI Symbol"`) — functional Unicode-coverage choice, recommend explicit exemption.
4. Admin-page inline styles (115 violations) — recommend explicit carve-out in `THEME-INTEGRATION.md` since prompt's contract is silent on admin UI.

### C1 acceptance

- [x] 18 CSS + 70 PHP + 71 JS files scanned (excluding `node_modules`).
- [x] 38 `block.json` files scanned for inline style attrs.
- [x] All 487 violations cataloged with file, line, surface, kind, value.
- [x] Section 5.4 star-rating special case fully enumerated.
- [x] Pattern + render-callback surfaces fully enumerated.
- [x] Block-frontend surface enumerated per file.
- [x] Triage rule documented.
- [x] Single highest-leverage migration target identified.
- [x] Open questions surfaced for C2.

### Phase C readiness

| Phase C session | Ready? |
|---|---|
| C2 — design `--pkiw-*` token catalog + neutral defaults | Ready, pending answers to the 4 open questions above |
| C3 — migrate values to token references | Ready after C2; start with `card-editor.css` |
| C4 — star-rating special-case migration | Ready (token design above is preliminary) |
| C5 — visual regression + `test-no-color-leakage.php` lint | Ready, needs the test fixtures dir convention defined in C2 |

---

## 2026-05-01 — Session C2 — Token catalog

**Token CSS landed at:** `/styles/kind-tokens.css` (193 lines) — wraps in
`@layer pkiw-kind-tokens` per Section 5.2. All paint tokens default to
neutral (`transparent`, `inherit`, `currentColor`); structural tokens carry
real values.

**Reference doc landed at:** `/docs/audit/DESIGN-TOKENS.md` (336 lines) —
per-token reference with three columns (token name, default, what it
paints) per Section 5.2 prompt.

### Token categories

| Category | Tokens | Default rule |
|---|---|---|
| Structural | 8 (gap, padding, ratios, star-size/count) | Real values — layout breaks without |
| Shared card paint | 7 (bg, fg, accent, border, border-radius, shadow, text-secondary) | All neutral |
| Per-kind paint | ~50 (each of 17 kinds × 2-5 tokens) | Default to shared card tokens |
| Star rating | 6 (fill, empty, caption, border-radius, 2 variants) | All neutral |
| Media lookup | 3 | All neutral |
| Stats (three greens) | 3 | All `currentColor` (Phase D triage may consolidate) |
| Editor chrome | 2 (focus indicator, editor rule) | Both `currentColor` |
| Interactivity state | 3 (hover, active, disabled opacity) | Real values |

**Total: ~80 tokens.** All within the `--pkiw-*` namespace per Section 8.3.

### Coverage of C1 inventory

296 of 487 violations covered by the token catalog (61%):

| Surface | Violations | Coverage |
|---|---|---|
| block-frontend | 61 | ✅ |
| block-editor | 184 | ✅ |
| pattern | 15 | ✅ |
| render-callback | 5 | ✅ |
| editor-extension | 31 | ✅ |
| **admin-page** | 115 | EXEMPT |
| **admin-asset** | 64 | EXEMPT |
| block-script | 6 | per-violation at C3 |
| other | 6 | per-violation at C3 |

### Decisions made in C2 (open questions from C1)

1. **Three greens** (`#127925`, `#127916`, `#128214`) — three tokens kept
   (`--pkiw-stat-positive`, `--pkiw-stat-neutral`, `--pkiw-stat-active`),
   all defaulting to `currentColor`. Phase D triage may consolidate to one
   if the three states aren't semantically distinct.

2. **`#007cba` in `patterns/read-progress.php`** — the read-progress bar
   gets its own token `--pkiw-read-progress-bar` defaulting to
   `currentColor`. The other `#007cba` instances in editor chrome become
   `--pkiw-focus-indicator`.

3. **System font stack** for star-rating — explicit exemption documented
   in DESIGN-TOKENS.md (Unicode-coverage choice, not design choice).

4. **Admin pages (115 + 64 violations)** — explicit carve-out from the
   contract. C5 lint test will exclude `includes/admin/` and `admin/css/`
   paths from the leakage check.

### Token surface size — design rationale

~80 tokens is more than typical block-theme plugins ship. The reason:
PKIW has 17 kinds, each with a card surface that can carry distinct paint
treatment. Themes that want a uniform look set only the 7 shared
`--pkiw-card-*` tokens; themes that want per-kind variance (which is the
zine-style aesthetic many IndieWeb sites use) can paint each of the 17
kinds independently. Per-kind tokens default to the shared tokens, so the
token surface is **opt-in detail** — themes don't pay for what they don't
use.

### C2 acceptance

- [x] `/styles/kind-tokens.css` written.
- [x] `@layer pkiw-kind-tokens` wrapper (low specificity for theme overrides).
- [x] All 17 audit-spec kinds covered (plus bookmark/like/reply/repost variants).
- [x] Star rating tokens cover Section 5.4 special case.
- [x] DESIGN-TOKENS.md produced with three-column format.
- [x] Admin-chrome exemption documented.
- [x] Open questions from C1 resolved with explicit defaults.
- [x] Token namespace contract (Section 8.3) documented.

### Phase C3 readiness

Ready to migrate inventoried CSS/PHP/JS values to token references. C1's
single highest-leverage target (`src/blocks/shared/card-editor.css`,
126 violations) is the recommended C3a starting point — fixing that one
file propagates the migration across 14 card blocks at once.

---

## 2026-05-01 — Session C3a — card-editor.css migration

**File migrated:** `src/blocks/shared/card-editor.css` (933 lines, was 126
violations — the highest-leverage target identified in C1).

**Migration approach:** Two-pass context-aware Python script. First pass
captured the bulk of patterns (rgba backgrounds, hex text colors, common
state colors); second pass caught border-top/border-bottom + state
backgrounds + linear-gradient.

### Substitution stats

| Pass | Substitutions |
|---|---|
| Pass 1 (broad rules) | 84 |
| Pass 2 (edge cases) | 22 |
| **Total** | **106** |

### Result

Strict contract violations in card-editor.css: **126 → 0.**

Remaining literal values inside `var(--pkiw-token, FALLBACK)` are documented
defaults, not violations.

### Token usage

The migration leverages this small set of tokens (most reused multiple times):

- `--pkiw-card-bg, transparent` — 19 backgrounds
- `--pkiw-card-fg, inherit` — 7 text colors
- `--pkiw-card-border, transparent` — 6 border declarations (incl. dashed media-button)
- `--pkiw-card-border-radius, Npx` — 14 border-radius values
- `--pkiw-card-shadow, none` — 2 box-shadows
- `--pkiw-state-success, transparent` — 11 success-state backgrounds
- `--pkiw-state-warning, transparent` — 1 warning bg
- `--pkiw-state-error, transparent` — 3 error/red bgs
- `--pkiw-state-info, transparent` — 5 info/blue/purple bgs
- `--pkiw-state-hover-opacity` / `-active-opacity` / `-muted-opacity` / `-very-muted-opacity` — 22 opacity literals
- `--pkiw-card-meta-font-size`, `--pkiw-card-body-font-size`, `--pkiw-card-icon-font-size` — 11 font-size px values
- `--pkiw-card-media-button-bg` — specific to media-remove overlay

### Pre-commit lint hook caught two issues

The PKIW repo has a husky `pre-commit` hook running `wp-scripts lint-style`.
First commit attempt failed:

1. **`currentColor` should be `currentcolor`** (CSS spec is case-insensitive
   but stylelint enforces lowercase) — 16 occurrences across
   `kind-tokens.css` and `card-editor.css`. Auto-fixed via sed.

2. **Whitespace** — `rule-empty-line-before` and `comment-empty-line-before`
   in `kind-tokens.css` after the `@layer pkiw-kind-tokens {` opening.
   Auto-fixed via `wp-scripts lint-style --fix`.

After both fixes, lint passes and commit goes through. Documented for
future C3 sessions: always run `wp-scripts lint-style --fix` before
committing token-migration work.

### Verification gauntlet (per Section 3.6 + 3.8)

| Check | Result |
|---|---|
| 1. Frontend HTTP 200 | OK |
| 2. All 3 plugins still active | OK (xfn 1.0.3, pfbt 2.3.0, pkiw 1.0.0) |
| 3. 18 PKIW blocks still register | OK (unchanged from pre-migration) |
| 4. Seeded post still renders (sample: bookmark single 37387) | HTTP 301 (redirect to canonical URL — expected) |
| 5. `debug.log` new fatals | NONE (file absent) |

### Branch + PR

- Branch: `audit/styling-tokens-card-editor` from main
- Commit SHA: `b65ed45`
- Files: 8 changed (+2530 / -107)
- PR: https://github.com/courtneyr-dev/post-kinds-for-indieweb/pull/25
- Tracking issue: #24 (composer-lock-refresh)

### C3a acceptance

- [x] Highest-leverage file identified in C1 (`card-editor.css`, 126 violations) migrated.
- [x] Strict contract violations: 126 → 0.
- [x] All replacements use `var(--pkiw-*, fallback)` references with neutral fallbacks for paint tokens, real-value fallbacks for structural tokens.
- [x] Pre-commit lint passes after `--fix`.
- [x] Verification gauntlet on staging passes.
- [x] Branch pushed and PR opened.
- [x] Token catalog extended with 14 sub-component tokens to cover migration needs (badge, media-button, type-select, notice, state-success/warning/error/info, opacity variants).

### Phase C3 remaining work

| Sub-session | Files | Estimated violations | Notes |
|---|---|---|---|
| C3b | `src/blocks/checkin-dashboard/style.css` (21) + `checkin-card/editor.css` (20) + `checkins-feed/style.css` (16) + `checkins-feed/editor.css` (10) | 67 | Largest remaining cluster |
| C3c | `src/blocks/venue-detail/style.css` (12) + `venue-detail/editor.css` (8) | 20 | Venue surface |
| C3d | `src/blocks/star-rating/{style,editor}.css` (9) | 9 | Section 5.4 special case (this is C4) |
| C3e | `src/blocks/{watch,listen,rsvp}-card/editor.css` (5 each) | 15 | Per-kind card editors |
| C3f | `src/blocks/media-lookup/style.css` (6) | 6 | Lookup surface |
| C3g | `src/editor/kind-selector/components/{KindFields.js,KindGrid.js}` (29) | 29 | React component CSS-in-JS |
| C3h | `patterns/*.php` (15) + `includes/blocks/*.php` (5) | 20 | Pattern + render-callback inline styles |

Total remaining migration work: ~166 violations across the surfaces above.
