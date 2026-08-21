# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **WordPress.org versioning note:** the public WordPress.org debut is **[1.0.0] dated 2026-07-20**, which bundles the full feature set from the pre-release GitHub builds (1.4.3 and earlier, listed below) plus the final pre-launch fixes. The 1.4.x–1.1.0 entries and the earlier `[1.0.0] - 2024-12-23 (pre-release GitHub build)` were GitHub-only and never shipped on WordPress.org. The first update after the debut ships as **[1.5.0]** — 1.1.0 through 1.4.3 are skipped because those numbers (tags, GitHub releases, and the entries below) were used by the pre-launch builds.

## [Unreleased]

## [1.5.1] - 2026-08-21

### Fixed

- `/firehose` no longer 404s on updated sites — the one defect here that never self-corrected. Rewrite rules were flushed only from the activation hook and on a storage-mode change, and WordPress does not fire activation on an in-place update, so the feed registered in 1.5.0 never entered the persisted ruleset for anyone who updated rather than installed fresh. `/firehose` and `/feed/firehose` both 404'd indefinitely while `?feed=firehose` worked. `maybe_flush_rewrite_rules()` now also flushes when the stored rewrite version differs from the plugin version. Kind archives were unaffected — they resolve through a generic `kind/([^/]+)` rule stored since 1.0.0 — which is what made this easy to miss.
- Kinds added since a site's install now appear without waiting for an admin visit. `ensure_all_terms_exist()` already back-filled them, but only on `is_admin()` requests, so a freshly-updated site served 24 kinds and 404'd `/kind/weather/` and friends to visitors until someone loaded wp-admin. The first-run seeder is now version-stamped rather than guarded by a write-once boolean, so the back-fill happens on the next request of any kind. Measured on the published builds: updating 1.0.0 to 1.5.0 showed 24 kinds on the front end and 36 after one wp-admin load.

## [1.5.0] - 2026-08-21

### Fixed

- A kind post's canonical microformats2 property now attaches to an `h-entry` at the permalink — the URL webmention receivers fetch. The `h-entry` root only ever came from the `post_class` filter, which Twenty Twenty-Five's and Twenty Twenty-Four's single templates never call, so the card parsed as a top-level orphan `h-cite` and the entry-level property never existed (on TT4 it attached nowhere at all, since its archives render excerpts without the card). `wrap_singular_content()` on `the_content` now wraps singular kind content in the kind's root classes — with hidden `u-url` and `dt-published`, and `p-name` only for kinds whose vocabulary names entries — and steps aside when `post_class` already ran for the post, so themes that provide their own wrapper are untouched. Covered by `SingularEntryWrapperTest`, which renders the real TT5 single template instead of fabricating the wrapper the way `MicroformatsRenderTest` does. (#142, #143, #146)
- The Stream Card registers with block API version 3. It has no block.json — the PHP registration omitted `api_version`, silently defaulting to 1 while the other 26 blocks declare 3. `BlockApiVersionTest` now sweeps the live registry across both plugin namespaces so the next omission fails in CI. (#144, #145)

- Title-less posts no longer vanish from the Stream. `render_generic_stream_card()` returned `''` for a post with no title — and title-less is the IndieWeb convention for most experimental kinds, so a published weather or follow post made an empty stream item. The kind label now stands in as the linked title (without `p-name`, so mf2 implied-name parsing is unaffected), and any kind term — known or future — renders the full badge/label/date/excerpt card.
- All 13 abilities now actually register. Their names used underscores (`post_kinds/list_kinds`, `post_kinds/lookup_book`), and core's `WP_Abilities_Registry::register()` only accepts `/^[a-z0-9-]+\/[a-z0-9-]+$/` — lowercase alphanumerics, dashes, one slash. Every one was refused with a `_doing_it_wrong()` notice and nothing else, so with `WP_DEBUG` off they had been missing since 1.1.0 without a trace. Renamed to dashes throughout (`post-kinds/list-kinds`, `post-kinds/lookup-book`), including the MCP server list and the `post_kinds/` prefix check in `Abilities_Manager::filter_ability_args()`. No aliases: the old names never registered, so nothing can be calling them.
- The WP Pinch MCP server list advertises only abilities that actually registered, instead of the full declared list.
- Block bindings registration defers when another plugin already owns the `post-formats/format-data` source (Post Formats for Block Themes registers it earlier with a superset of keys), silencing the `_doing_it_wrong()` notice on every request for sites running both plugins.
- A bookmark post built with this plugin's own Bookmark Card no longer gets an empty core/embed block inserted above it on every editor load.
- Dev configs and scripts are excluded from the distribution zip.

- `Card_Meta_Sync` never reached cards nested inside wrapper blocks — including the h-entry `core/group` the Micropub bridge wraps around every card it generates, so Micropub-created posts got no `_pkiw_*` meta at all. The block walk now descends into `innerBlocks` (first card in document order still wins).

- Kind auto-detection (`Taxonomy::get_first_block_kind`) now sees a card nested first inside a wrapper block — the shape the Micropub bridge writes — instead of leaving those posts with no kind term. A wrapper whose first visible child is not a card still gets no auto-kind, same as a paragraph-first post.
- Card links show a focus indicator again. `.pk-card :focus-visible` set `outline: 2px solid var(--pk-accent)`, and no stylesheet in the plugin defines `--pk-accent` — an undefined custom property invalidates the whole `outline` shorthand, so the computed style was `outline-style: none` and keyboard users had no visible focus ring on any theme that did not happen to supply the token (WCAG 2.2 AA, SC 2.4.7). Now falls back to `currentColor`. `FocusIndicatorTest` scans every focus rule in `src/` and `build/` for fallback-less custom properties so the class of bug cannot return.
- The distribution zip ships `styles/` and `admin/` again. `wp-scripts plugin-zip` builds from the `files` array in `package.json`, not `.distignore`, and that array had never been updated as the plugin grew — so the packaged plugin was missing `styles/kind-tokens.css` (every card's design tokens) and `admin/css/admin.css` + `admin/js/admin.js` (the entire admin UI), both enqueued at runtime. `DistributionManifestTest` now holds the two manifests to the same contract and separately asserts that every runtime-enqueued directory ships.
- Kinds with no entry in the microformats format map — favorite, eat, drink, play, jam, mood, acquisition, wish — get the default `h-entry` root at their permalink like every other kind, matching what `post_class()` already did. Their cards emit properties (`u-favorite-of` and friends), which previously had no root to attach to.

### Changed

- Tested up to WordPress 7.1; the CI matrix gates 7.0 and 7.1 on PHP 8.2–8.4, so the readme claim stays verified. Docs screenshots re-captured against 7.1. (#141)
- Docs: the microformats verification guide now explains archive-vs-permalink parsing and theme `post_class()` requirements, and the block count is corrected to 27. (#141)

### Added

- Event card block (`post-kinds-indieweb/event-card`) for the event kind. Renders an `h-event` (`p-name`, `dt-start`, `dt-end`, `p-location`, `u-url`) in the standard pk-card structure with a `k-event` class, and puts the event start date in a dedicated `.pk-event-date` element directly under the title so a theme can promote the EVENT date — not the publish date — to the big gig-poster date without future-dating the post (future-dated posts drop out of queries). The `event` kind also gets its own badge glyph.
- Calendar plugin integrations for the event card. `calendarSource` + `calendarEventId` attributes pull name, start, end, venue, and URL at render time from The Events Calendar (`tribe_events` posts, `_EventStartDate`/`_EventEndDate`/`_EventVenueID` meta, tribe helpers when present) or My Calendar (via `mc_get_event_core()`/`mc_get_event()` only — never its tables directly). Both plugins stay optional: everything is feature-detected, calendar data wins over the block's attribute snapshot when available, and an inactive plugin means the attributes render as-is — never a fatal. Only publicly viewable events resolve — a draft, private, pending, password-protected, or unapproved calendar event referenced by ID returns nothing rather than leaking its data to anonymous visitors. New seams: `pkiw_pre_calendar_event` short-circuits resolution (tests, or wiring up other calendar plugins) and `pkiw_calendar_source_active` overrides detection.
- The 12 remaining IndieWeb post kinds — audio, quote, tag, weather, exercise, trip, itinerary, follow, issue, question, sleep, and craft — completing the 36-kind vocabulary of the classic Post Kinds plugin. Each registers as a default `kind` term with a description, shows up in the admin and quick-post kind pickers and the editor kind selector with its own icon, gets a badge glyph on cards, and carries kind-appropriate microformats mirroring classic Post Kinds' property choices (`u-audio`, `u-quotation-of`, `u-tag-of`, `u-follow-of`, `u-in-reply-to` for issues, `u-craft-of`). No dedicated card blocks yet — the generic Stream card renders all of them.
- `AbilitiesRegistrationTest` asserts every declared ability is present in `wp_get_abilities()` after init, that declared names satisfy core's grammar, and that the declared list and the registry agree in both directions. A rejected name now fails CI instead of vanishing into a notice.
- `pkiw_kind_label` filter. Every card's visible `.pk-kindlabel` text — the block templates and the generic Stream card — now flows through `PKIW\get_kind_label( $label, $kind, $context )`, so a theme can swap the kind noun ("Watch", "Acquisition") for a status verb ("WATCHED", "GOT IT") per kind or per render context. Default output is unchanged when nothing hooks the filter.
- Card templates group the title, date, and sub lines (year, players, venue, …) in a `<div class="pk-caption">` wrapper so themes can style them as one tight caption strip. Additive markup only; every existing class and microformat stays where it was.
- Standard.site records behind cited URLs. When a bookmarked, liked, replied-to, or read page carries a `rel=site.standard.document` link, the block sidebar can read the author's own title, description, tags, and publication straight from their PDS instead of guessing from Open Graph tags. Read-only and unauthenticated; a record is only stored once it points back at the page it was found on.
- Firehose RSS feed at `/firehose`, `/feed/firehose/`, and `?feed=firehose` that includes bulk-imported posts, reusing the `pkiw_include_imported` escape hatch.

- Yoast SEO integration: kind posts without a featured image now expose their representative media (album cover, movie poster, book cover, game art, checkin photo) as the schema.org primary image. Kind cards are dynamic blocks, so their artwork never appears as an `<img>` in raw post content and Yoast's featured-image/first-content-image resolution couldn't see it — Article schema on kind micro-posts lost its optional `image` and site audits warned about it. The integration hooks Yoast's documented `wpseo_schema_graph` filter, fills in only when Yoast itself found no image, reuses Yoast's native `#primaryimage` node shape (Article `image`/`thumbnailUrl`, WebPage `primaryImageOfPage`), reads normalized `_pkiw_*` meta with a block-attribute fallback for posts saved before the meta sync covered their kind, accepts only valid http(s) URLs, and is completely inert when Yoast is inactive. A featured image always wins untouched; posts with no real artwork truthfully emit no image; `wordCount` is never altered.

- `Card_Meta_Sync` now mirrors listen, watch, jam, and play cards into `_pkiw_*` meta (previously only read and checkin): track/artist/album/cover for listen, title/year/poster and show/episode fields for watch, and title/platform/status/art for jam and play.

- Featured images from kind artwork: a kind post saved without a featured image now gets one from its representative media — remote artwork is sideloaded into the media library once per URL (with the post title as alt text when the image has none), and artwork that is already a local attachment is reused without any fetch. Editorial choice stays in charge: an image you picked is never replaced, removing the auto-set image sticks (the same URL is not re-applied), a failed fetch is not retried until the artwork URL changes, and `add_filter( 'pkiw_set_featured_from_artwork', '__return_false' )` turns the behavior off. `wp postkind featured-artwork backfill [--dry-run]` applies it to existing posts. Shared resolution lives in the new `Kind_Artwork` class, so the featured image and the Yoast schema image always agree.

## [1.0.0] - 2026-07-20

Initial public WordPress.org release — the full feature set from the pre-release GitHub builds (through 1.4.3, listed below), plus the final pre-launch changes noted here.

### Removed

- The "Enabled Reaction Types" control on the General settings tab. The `enabled_kinds` option it saved had no runtime consumers — disabling a kind never removed it from the editor, taxonomy, or blocks, despite the on-screen promise. The renderer and any saved values are kept so the control can return together with real enforcement.

### Fixed

- Response-kind cards (like, reply, repost, bookmark, favorite, listen, watch, read) now carry their microformats2 property (`u-like-of`, `u-in-reply-to`, `u-repost-of`, and so on) on the card's `h-cite` root instead of a nested element, so the post's `h-entry` actually exposes the property. Webmention receivers and feed readers now recognize these posts as their kind. Added an integration test that parses each rendered card with a real microformats2 parser to guard against regressions.
- Letterboxd lookups now use WordPress's safe HTTP fetch and reject unsafe redirect targets.
- The public OAuth callback now rejects requests with a missing or malformed `code`/`state` as a clean 400 instead of hitting `hash_equals()` with a non-string (a PHP 8 fatal → 500). State validation itself was already sound: single-use transient, constant-time comparison.
- The syndication admin handlers (`ajax_syndicate_now`, `handle_actions`) now require the per-post `edit_post` capability before syndicating, closing an IDOR where any user with the generic `edit_posts` capability could syndicate another user's post.

## [1.4.3] - 2026-07-07

### Fixed

- Front-end card padding + shadow regression: the 1.4.1 editor-parity rule that neutralizes the front-end grid on the editor wrapper was unscoped, and since the shared card stylesheet loads on the front end too (where the published card also carries `pk-card` + `wp-block-post-kinds-indieweb-*`), it stripped the real cards' padding, box-shadow, surface, and radius. Scoped that rule to `.editor-styles-wrapper` (editor canvas only), so front-end cards keep their intended spacing and shadow.

## [1.4.2] - 2026-07-07

### Changed

- Default category (stream): the category is now applied only to "stream-shaped" posts -- those created through a Micropub client (the composer), or with a Status/Aside post format, or carrying a genuine activity kind (listen, watch, checkin, …). It is no longer applied to article/review/recipe kinds or plain admin-written posts, so long-form content stays out of the stream. The bare `note` kind alone no longer qualifies (it is auto-assigned to plain posts); a note needs a Status/Aside format or a Micropub origin to count. New `pkiw_default_category_stream_kinds` filter.

## [1.4.1] - 2026-07-07

### Fixed

- Editor card parity: the 1.4.0 pass imposed a two-column grid on the editor card, which scattered the card children (a tall empty badge column, a wrapping title, a stray element). Reverted to paint-only -- the existing flex layout stands and the theme tokens now recolor the badge, title, artist, and rating without restructuring.

## [1.4.0] - 2026-07-07

### Added

- Editor card parity: every kind-card block now reads like its published card in the block editor instead of a plain form. Each block wrapper carries `pk-card k-<kind>`, so a theme's per-kind `--pk-*` paint tokens (loaded into the editor via `add_editor_style`) cascade onto the editor markup, and the shared `card-editor.css` paints the badge, title, artist, and rating from those tokens. Plugin owns the structure, theme owns the paint; fields stay inline-editable, and a theme that ships no `--pk-*` tokens still gets a clean, neutral card.

## [1.3.0] - 2026-07-06

### Changed

- Stream card: every item now renders as a card. Articles, notes, and any long-form kind that isn't a self-contained card block previously fell back to a bare linked title; they now show a compact card with the kind badge, linked title, date, featured image, and excerpt (never the full body). Media micro-posts and long-form watch posts keep their existing rich cards.

### Added

- Post-surface classification: a `pkiw_stream_kinds` filter marks kinds as ephemeral (`stream`) vs `main`, cached in `_pkiw_surface`; a `pkiw_promote` override is settable via the editor toggle, the Micropub `pkiw-promote` property, or `wp postkind surfaces backfill`. The plugin emits the signal only — themes decide how to use it. Default `pkiw_stream_kinds` is empty, so existing sites are unaffected.

### Fixed

- Block editor icons render with `currentColor` so they stay legible against the List View selection highlight.
- Card embeds (Spotify player, Able Player video, maps) no longer sit inside a "paper" box — the padding, border, and `--pk-paper` background are gone, so the embed reads as its own object with clean rounded corners.

### Removed

- Duplicate registration of the `post-formats/format-badge` block (owned by the Post Formats for Block Themes plugin); the badge is now provided solely by that plugin.

## [1.2.0] - 2026-07-04

### Added

- **Book fields are now bindable kind-meta keys.** `isbn`, `publisher`, `pages`, `publish_date`, and `asin` join the Block Bindings source (with `pk_*` aliases), backed by matching `_postkind_read_*` post meta. Any block attribute can bind to book metadata the same way it already could to title, author, and cover.
- **Book completion cascade** (`includes/class-book-completion.php`). Given a partial book (say, just an ISBN), fills the missing fields by querying Open Library, then Google Books, then Hardcover — first source with an answer wins, existing values are never overwritten. Runs on editor save, on Micropub-created read posts (opt-out via the `pkiw_micropub_book_completion` filter), and on demand from the Read Card's editor button via the `/pkiw/v1/book-complete` REST route.
- **Kindle embed bridge.** A computed `kindle_embed_url` binding key builds a Kindle reader embed URL from the post's book meta — from the ASIN when present, otherwise derived from the ISBN (ISBN-13 → ISBN-10). Read posts with the opt-in marker render the embed as a lazy-loaded iframe on the front end at render time; nothing is stored in post content.
- **Read + Kindle preview block pattern and inspector toggle.** A registered pattern pairs the Read Card with the Kindle preview, and the Read Card's inspector gets a toggle that adds or removes the embed marker.
- **Card meta sync** (`includes/class-card-meta-sync.php`). On save, mirrors the post's first card block attributes into `_postkind_` meta (map-driven; read-card first), so Block Bindings always have a server-side source of truth that matches what the card shows.
- **Field-matrix test coverage across all 22 blocks (~290 attributes).** A generated fixture (`tests/phpunit/fixtures/field-matrix.json`) with a drift guard is the single source of truth for block attributes; matrix-driven suites cover server render (every attribute must reach the markup), static serialize, editor save/reload round-trips, visual-regression baselines for every card block, and the Micropub wire format per kind (mapped attributes asserted, declared gaps enforced).
- **Editor saves now set the kind term from the post's first block.** When a post's first block is one of the kind card blocks (eat, drink, listen, watch, read, play, checkin, RSVP, like, favorite, jam, wish, mood, acquisition), saving assigns the matching `kind` taxonomy term automatically — no separate pick in the taxonomy panel needed. Manual choices always win: only the `note` default (never chosen by a person) or a kind this sync itself assigned earlier (tracked in `_pkiw_kind_auto_assigned` post meta, so a swapped first block re-syncs) is ever replaced. Companion to the Micropub-side kind assignment, which covers posts arriving via the Micropub bridge.
- **Micropub bridge coverage for the four response kinds.** Likes render the Like Card (falling back to the liked URL as the linked title); reposts, bookmarks, and replies emit paragraphs carrying `u-repost-of` / `u-bookmark-of` / `u-in-reply-to` microformats2 classes until dedicated card blocks exist. Endpoint-level e2e tests cover all four shapes plus the rsvp/reply precedence rule.
- `docs/micropub-field-gaps.md` documents, kind by kind, which block fields Micropub clients cannot set yet — the concrete list for sender-side (Outpost) follow-on work.

### Fixed

- **Eat Card's restaurant and Play Card's steamId never rendered.** Both attributes existed in the editor and saved fine, but `render.php` never read them, so the values silently vanished on the front end. Caught by the new render matrix.
- **Mood Card and Play Card wiped freshly-entered values on editor mount.** Their "sync post meta → block attributes" effects read `_postkind_mood_*` / `_postkind_play_*` meta keys that were never registered, so every mount saw an empty meta value and stomped the attribute back to blank. Both effects now only apply non-empty meta values. Caught by the new round-trip matrix.
- **Micropub play and RSVP posts dropped fields to attribute-name mismatches.** The bridge's `play_card()` wrote `playUrl`/`gameTitle` and `rsvp_card()` wrote `response`/`note`, but the block schemas define `gameUrl`/`title` and `rsvpStatus`/`rsvpNote` — the values were silently discarded on render. Caught by the wire-matrix completeness assertion.
- **"Phantom posts" for contentless likes, reposts, bookmarks, and replies.** Same failure class fixed for eat/drink/follow/weather in 1.1.0: `like-of`, `repost-of`, `bookmark-of`, and (content-free) `in-reply-to` posts died inside `wp_insert_post()` with `empty_content` while the Micropub endpoint answered 2xx with no Location — on installs without IndieBlocks, the four response shapes Outpost sends created nothing at all. The bridge now recognizes all four in `detect_kind()` and supplies markup before insert.
- **Like Card block registered server-side.** `like-card` existed in `src/blocks/` and registered in the editor, but was missing from the server registration list in `class-plugin.php`.
- **Card block renders no longer make live oEmbed HTTP requests.** `wp_oembed_get()` performs an uncached discovery fetch on every call, so jam/listen/watch card renders outside the post-content cache path (REST, widgets, `do_blocks()`) blocked page render on a remote request. The three call sites now share a cached-embed helper backed by WordPress's oEmbed cache, with link-tag discovery disabled so only registered providers are ever fetched.
- **ASIN detection no longer trusts spoofable hostnames.** The Kindle bridge's Amazon host guard matched Amazon-looking substrings anywhere in the hostname; it now anchors to real Amazon domains.
- **The book-complete REST route no longer reflects unknown parameters.** `rest_complete()` built the book from all request params, so unrecognized keys bypassed the registered-args sanitization and echoed back verbatim in the response. Input is now intersected against the canonical book keys.

### Changed

- **Like Card is now a dynamic block** (`render.php`, jam-card precedent), so attribute-only blocks written by the Micropub bridge render on the front end. Existing statically-saved like cards migrate via a block deprecation; no content changes needed.

## [1.1.0] - 2026-07-03

### Fixed

- **"Phantom posts": contentless Micropub kind posts now actually create posts.** Kind posts without a `content` property — exactly what Outpost sends for eat, drink, follow, and weather — died inside `wp_insert_post()` (`empty_content`: content, title, and excerpt all empty) while the Micropub endpoint still answered 200 with no Location header. The bridge now supplies the card-block markup on the `micropub_post_content` filter, before insert, so the post is never empty. Endpoint-level integration suite (`tests/e2e/micropub-kinds.spec.js`) runs against a real wp-env with micropub + indieauth active. (#56)
- **Checkins (and every other kind) with an attached photo now include the image.** The bridge appended photo/gallery blocks only for pure photo posts; a checkin carrying a `photo` property dropped it entirely. Photo markup now appends to any kind's card. (#38, #56)

### Added

- **Follow and weather kinds recognized by the Micropub bridge.** `follow-of` renders a paragraph whose link carries `u-follow-of`; `weather` renders its reading in a `p-weather` span. Previously both were unrecognized and (being contentless) created nothing at all. (#56)

### Changed

- **Design-token migration: all block colors now flow through the `--pkiw-*` token API** (`styles/kind-tokens.css`, enqueued as a dependency of every block style). **Not a breaking change:** blocks that ship with colors (checkin dashboard, venue detail, checkins feed, media lookup, star rating) now default to the active theme's palette (`var(--wp--preset--color--*, previous-color)`), so they follow the theme automatically and look exactly as before on themes without those presets. Themes can override any `--pkiw-*` token for full control (examples in `docs/audit/DESIGN-TOKENS.md`, "Bridge decision" section). `NoColorLeakageTest` enforces the contract (94 hardcoded-color violations at baseline, now 0). (#56, #59)
- **Dependency refresh:** all seven pending Dependabot updates merged — five GitHub Actions bumps, the js-dev group (Playwright 1.61, axe-core 4.12), and the 15-package `@wordpress` group. The `@wordpress/scripts` 32 bump switches ESLint to flat config (`eslint.config.js` replaces `.eslintrc.js`); ~1,500 lint findings from the migration were fixed across the JS source. (#23, #48–#51, #53, #54)

### Security

- Transitive dev-dependency bumps for fast-uri (CVE-2026-6321, CVE-2026-6322) and js-yaml (CVE-2026-53550). (#23)

### Changed

- **WordPress 7.0 is now the minimum supported version** (previously 6.9). The AI enhancement layer calls `wp_ai_client_prompt()`, which only ships in WP 7.0+, and declaring 6.9 support alongside that call tripped Plugin Check's `wp_function_not_compatible_with_requires_wp` error. Rather than suppress the check, the support floor moves to 7.0: `Requires at least` (readme.txt + plugin header), the `POST_KINDS_INDIEWEB_MIN_WP` activation guard, the wp-env core pin, the CI test matrix, and the docs now all say 7.0. The plugin header's `Tested up to` also catches up with readme.txt at 7.0 (bumped there in #43).

### Fixed

- **Plugin Check compliance.** `includes/class-cli-commands.php` now uses a standalone `ABSPATH` guard (the combined ABSPATH + WP_CLI guard was behavior-identical, but the `missing_direct_file_access_protection` sniff only recognizes a standalone check), and the `uninstall.php` variables use the full `post_kinds_for_indieweb_` prefix so `WordPress.NamingConventions.PrefixAllGlobals` stops warning.
- **Photo gallery posts no longer render the same images twice.** The Micropub-to-block bridge's `photo_card()` now deduplicates the `$input['photo']` array before emitting `core/image` blocks. The upstream Micropub plugin (David Shanske's `wordpress-micropub`) enriches `$input['photo']` post-sideload with a 2× version of the original array — when an Outpost gallery uploads 3 images and posts them as `photo[]=url1&...`, the array arriving at `after_micropub` priority 30 has 6 entries (originals + canonical URLs, both resolving to the same local URL on a single-server install) while `mp-photo-alt[]` still has only 3. Without dedupe, a 3-photo gallery rendered 6 image blocks: the first 3 with matching alts, the last 3 with empty alts. Dedupe is by URL, first occurrence wins (which is also the occurrence with its aligned alt text). 3 new PHPUnit tests in `MicropubContentBuilderTest.php` cover the staging-reproduced symptom (6→3 dedupe), the alt-alignment ("first occurrence keeps alt"), and the single-image collapse (3 identical URLs collapse to 1 image, gallery wrapper drops away).

### Added

- **Photo / gallery handling in the Micropub-to-block content bridge.** Photo posts (Micropub `photo` property without one of the specific of-kinds like `eat-of`, `watch-of`) now emit a `core/image` block (single photo) or a `core/gallery` wrapper (multi-photo) inside the same `h-entry` envelope as the other card kinds. Each `core/image` block resolves the photo URL back to its attached media ID via `attachment_url_to_postid()` so the block carries the canonical reference + `class="wp-image-{id}"` for srcset rendering. Alt text comes from the parallel `mp-photo-alt[]` array. Single-photo posts skip the gallery wrapper for a cleaner shape; multi-photo posts get `core/gallery` with `linkTo: none`. The user's typed body text still appears as `e-content` alongside the gallery.
  - 13 new PHPUnit tests covering: photo kind detection (alone + precedence vs other of-kinds), single vs multi-photo card output, attachment ID resolution, missing alt array, missing photo property, `flatten_string_array` helper across scalar/array/missing/non-string entries, and end-to-end `apply()` for both single and multi-photo posts.
  - Bridges any Micropub client (Outpost, Quill, Indigenous, etc.) to native Gutenberg gallery blocks without requiring the client to know about block markup.

### Fixed

- **Single posts no longer render the venue archive template.** `add_plugin_templates` was injecting `taxonomy-venue` into every `get_block_templates()` query result, including the hierarchy lookups that WordPress runs while resolving the single-post template (`slug__in => ['single-post', 'single', 'singular', 'index']`). When `singular` reached the resolver with no theme template registered for that slug, the plugin's `taxonomy-venue` template won the match and rendered for ordinary single posts — producing an empty `<main>` plus an unrelated query pagination block instead of the post body. The filter now respects `slug__in` and only injects when the requested slugs actually include `taxonomy-venue`. Adds four regression tests in `PluginTest.php`.

### Added

- **Micropub-to-block content bridge** (`includes/class-micropub-content-builder.php`). Hooks `after_micropub` priority 30 and rewrites Micropub-created `post_content` from plain text to the registered card blocks (Checkin Card, Eat Card, Drink Card, Listen Card, Watch Card, Read Card, Play Card, RSVP Card, Mood Card) when the incoming h-entry shape matches a recognized post kind. Bridges any Micropub client (Outpost, Quill, Indigenous, etc.) to this plugin's block-editor cards without requiring the client to know about Gutenberg block markup.
  - **Idempotent.** Sets a `_pkiw_block_content_generated` post meta marker on first generation; subsequent Micropub updates leave (potentially user-edited) content alone.
  - **h-entry envelope.** Wraps each card in a `wp:group` with `class="h-entry"` and an inner `e-content` group for the user's typed body text, so microformats2 readers see one h-entry root and the body in `e-content`.
  - **Geo extraction.** Parses `location: geo:lat,lon` (RFC 5870) into `latitude`/`longitude` (or `geoLatitude`/`geoLongitude` for Eat/Drink) attributes on the card block.
  - **Outpost-friendly.** Recognizes Outpost's `mp-place-name` extension property as the venue name attribute. Outpost-made checkin/eat/drink/listen posts now render with proper card UI on the front-end.
  - 17 PHPUnit unit tests covering kind detection, geo parsing, per-kind builders, idempotency, and the plain-note skip path.
- Jam Card, Eat Card, Drink Card, Favorite Card, Wish Card, Mood Card, Acquisition Card blocks
- Play Card block with RAWG integration
- Checkin Dashboard block for location overview
- Block Bindings source for post kind meta fields
- Block Bindings format helpers for dynamic content display
- Format Badge block for displaying post kind labels
- Media Stats block for collection summaries
- Now Playing block for current listening activity
- Recent Kinds block for latest post kind entries
- AI Enhancements class for optional WP AI Client integration
- oEmbed support for Jam Card block
- Code of Conduct (CODE_OF_CONDUCT.md)
- Project documentation (CONTRIBUTING.md, SECURITY.md, SUPPORT.md)
- GitHub issue templates (bug report, feature request, question)
- Pull request template with comprehensive checklists
- QA checklist for pre-release testing

### Changed

- Converted Jam Card, Listen Card, and Watch Card to dynamic server-side rendering
- Rewrote README.md with improved structure, badges, and feature documentation
- Rewrote readme.txt (WordPress.org) with expanded description and FAQ
- Rewrote CONTRIBUTING.md with streamlined development workflow
- Rewrote SUPPORT.md with clearer troubleshooting guidance
- Upgraded pull request template with security, accessibility, and i18n checklists
- Prefixed render.php variables with `pkiw_` for wp.org plugin checker compliance
- Converted CSS indentation from spaces to tabs for WordPress coding standards
- Updated copyright year to 2026
- Synchronized platform requirements (WP 6.9+, PHP 8.2+) across all documentation

### Fixed

- CSS specificity ordering issues in checkin-dashboard.css
- ESLint exhaustive-deps warnings in Jam Card useEffect hooks
- CSS selector formatting in shared card-editor.css

## [1.0.0] - 2024-12-23 (pre-release GitHub build)

### Added

#### Core Features

- Reaction Kind taxonomy for categorizing posts (listen, watch, read, checkin, rsvp)
- Custom meta fields for reaction metadata
- Block bindings for dynamic content display
- Microformats2 markup enhancement for IndieWeb compatibility

#### Custom Blocks

- **Listen Card**: Music/podcast scrobbling with album art, artist, rating, MusicBrainz integration
- **Watch Card**: Movies/TV shows with poster, episode tracking, TMDB/IMDb links, rewatch indicator
- **Read Card**: Books with cover, author, reading progress bar, Open Library integration
- **Checkin Card**: Location checkins with venue details, OpenStreetMap embed, geo coordinates
- **RSVP Card**: Event responses (yes/no/maybe/interested/remote) with h-event microformats
- **Star Rating**: Standalone rating component with stars/hearts/circles styles, half-star support
- **Media Lookup**: Universal media search across all integrated APIs

#### Block Patterns

- Listen Log pattern for music posts
- Watch Log pattern for movie/TV posts
- Read Progress pattern for book posts
- Checkin Card pattern for location posts
- RSVP Response pattern for event responses

#### External API Integrations

- **Music**: MusicBrainz, ListenBrainz, Last.fm
- **Movies/TV**: TMDB, Trakt, Simkl, TVMaze
- **Books**: Open Library, Google Books, Hardcover
- **Podcasts**: Podcast Index
- **Locations**: Foursquare, OpenStreetMap Nominatim

#### REST API

- Custom endpoints for media search
- Import endpoints for external services
- Webhook handlers for real-time sync

#### Admin Features

- Settings page with tabbed interface
- API key management with secure storage
- Import tools for bulk data migration
- Webhook configuration for scrobbling services
- Meta boxes for post editing
- Quick Post interface for rapid posting

#### Shared Components

- StarRating component with interactive editing
- CoverImage component with fallback handling
- MediaSearch component with API integration
- ProgressBar component for reading progress
- BlockPlaceholder for empty states
- DateDisplay with relative times
- LocationDisplay with address formatting

### Technical

- WordPress Block API v3 compatibility
- PHP 8.2+ with strict types
- Full internationalization support
- WordPress Coding Standards compliance
- Comprehensive PHPDoc documentation

### Dependencies

- Requires WordPress 6.9+
- Requires PHP 8.2+
- Recommends IndieBlocks plugin

---

## Version History Notes

### Versioning

This project uses Semantic Versioning:

- **Major** (X.0.0): Breaking changes
- **Minor** (0.X.0): New features, backward compatible
- **Patch** (0.0.X): Bug fixes, backward compatible

### Links

- [Repository](https://github.com/courtneyr-dev/post-kinds-for-indieweb)
- [Issues](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues)
- [IndieWeb Wiki](https://indieweb.org/)

[Unreleased]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.5.1...HEAD
[1.5.1]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/1.0.0...v1.5.0
[1.2.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.1.0...v1.2.0
[1.0.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/releases/tag/v1.0.0
