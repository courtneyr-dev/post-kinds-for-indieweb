# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

## [1.0.0] - 2024-12-23

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

[Unreleased]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/releases/tag/v1.0.0
