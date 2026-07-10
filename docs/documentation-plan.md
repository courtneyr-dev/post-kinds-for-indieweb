# Documentation plan

Planning record for the user documentation in this directory: scope, assumptions, open questions, and validation status.

## Plugin summary

Post Kinds for IndieWeb (version 1.4.3, plugin header) adds IndieWeb post kinds to the WordPress block editor: a 24-term `kind` taxonomy, 22 editor blocks plus 3 server-rendered blocks, media lookup across external APIs, background imports and webhook scrobbling, microformats2 output, opt-in POSSE syndication, and Micropub conversion (via the separate Micropub plugin). Requires WordPress 7.0+ and PHP 8.2+. Hard conflict with the classic Post Kinds plugin.

## Audience

WordPress site owners, admins, editors, and IndieWeb users — people publishing and configuring, not developing. Personas: IndieWeb lifeloggers/POSSE publishers, bloggers migrating off classic Post Kinds, Micropub app users, media trackers, and site admins configuring keys/imports/webhooks.

## Key user tasks

1. Install and activate (avoiding the classic Post Kinds conflict).
2. Create a first kind post with a card block; understand auto-detection.
3. Configure API keys so media search works.
4. Set check-in location privacy defaults.
5. Import history from external services; set up webhooks for scrobbling.
6. Enable POSSE syndication deliberately.
7. Post via Micropub from mobile apps.
8. Display check-ins/media stats with the dashboard and dynamic blocks.

## Proposed docs pages

The standard ten-page set, all written: [index](index.md), [installation](installation.md), [getting-started](getting-started.md), [settings](settings.md), [common-tasks](common-tasks.md), [screenshots](screenshots.md), [troubleshooting](troubleshooting.md), [faq](faq.md), [privacy-and-data](privacy-and-data.md), [accessibility](accessibility.md), plus this plan. Existing developer docs (`docs/audit/`, `docs/plans/`, `docs/specs/`, `docs/superpowers/`, `docs/micropub-field-gaps.md`) are untouched and linked from index.md.

## Screenshot inventory

Ten screenshots are in place and marked "generated": five existing repo images (`img/*.png`, matching the readme.txt captions) copied to `docs/assets/screenshots/` under standard names, and five captured fresh by the repeatable script (`npm run screenshots:docs`, `scripts/capture-docs-screenshots.js`). Eight more captures are listed as "manual needed". Full table with alt text and demo-data notes: [screenshots.md](screenshots.md). The Playwright visual-regression suite and the Playground blueprint (`blueprints/`) are the recommended tooling for the remaining manual captures.

## Hosting recommendation

GitHub Pages from `/docs` on the `main` branch, plain Markdown with the Primer Jekyll theme (`docs/_config.yml` added). index.md is the navigation hub. No MkDocs or extra tooling.

## Validation checklist

- [x] Version, WP/PHP minimums cited from the plugin header (1.4.3, WP 7.0, PHP 8.2).
- [x] Block counts verified against `includes/class-plugin.php` and `src/blocks/` (22 registered + 3 PHP dynamic); stale "16 blocks" figure not repeated.
- [x] Kinds without dedicated card blocks verified against `src/blocks/`: note, article, event, photo, video, review, recipe (favorite **does** have a card block).
- [x] Settings tabs and fields verified against `includes/admin/class-settings-page.php` and `class-admin.php` (tab names, field labels, defaults from `get_default_settings()`).
- [x] Admin menu structure verified against `class-admin.php` (Reactions → Settings, API Connections, Import, Webhooks, Quick Post, Syndication, Check-ins; capability per page).
- [x] Conflict/recommendation notices quoted from `includes/class-plugin.php`.
- [x] Privacy behavior (location privacy enforcement, coordinate handling options, outbound hosts, uninstall cleanup) verified against `class-microformats.php`, `class-settings-page.php`, `includes/apis/`, and `uninstall.php`.
- [x] Accessibility evidence limited to repo facts (axe/Playwright suite, Lighthouse 0.9 gate, changelog fixes, Able Player); no WCAG conformance claim.
- [x] Internal links relative; screenshots referenced only where files exist; nav footers on every page.
- [ ] Screenshots marked "manual needed" captured.
- [ ] Maintainer review of the items below.

## Assumptions

- The plugin header (1.4.3) is the source of truth for the version; `package.json` still says 1.2.0 (stale, cosmetic).
- The seven `img/*.png` files match the readme.txt screenshot captions one-to-one (filenames strongly suggest it) and are current enough to reuse.
- Because built assets are committed in `build/` and the plugin uses its own autoloader (Composer requires only PHP ≥ 8.2, no packages), a plain clone or repo ZIP runs without a build step; the readme's `composer install` / `npm run build` instructions were treated as development steps.
- The "Reactions" menu label is the intended user-facing name for the plugin's admin area.

## Needs maintainer review

1. **"API keys encrypted where possible" (readme.txt).** Credentials are stored as sanitized plaintext options; no encryption found in code. Confirm or soften. The docs state plaintext options storage.
2. **Enabled Reaction Types — resolved 2026-07-10: control removed.** Tracing confirmed `enabled_kinds` has no runtime consumers — the option saved but nothing enforced it. The settings field is unregistered (renderer and saved values kept) until enforcement across the kind grid, block registration, and taxonomy is actually built.
3. **WordPress.org listing.** readme.txt (stable tag 1.4.3) describes directory installation, but a published listing wasn't verified. installation.md leads with ZIP/GitHub installs and mentions the directory conditionally.
4. **Stale copy:** "16 custom blocks" in README.md/readme.txt vs 22+3 in code; `package.json` version 1.2.0 vs 1.4.3. Update the source copy.
5. **ActivityPub** is listed as an optional companion in the readme but has no code integration — confirm it's a recommendation only.
6. **AI Enhancements data flow.** Payload and provider depend on the site's WP AI Client config; confirm wording for privacy documentation.
7. **Import storage mode.** The `import_storage_mode` setting (standard / cpt / hidden) exists in code with real behavior differences (reaction CPT, query filtering), but no settings-page control rendering it was found. Confirm whether it's user-configurable and what `hidden` does to the main loop before documenting it for users.
8. **Audit correction:** the audit's "no dedicated card block" list included favorite and omitted review; `src/blocks/` shows `favorite-card` exists and there is no review card. Docs follow the source.
9. **Nominatim/MusicBrainz on API Connections.** A code comment says MusicBrainz and ListenBrainz were removed from the API Connections page ("complicated setup"), while ListenBrainz remains a Listen import source and webhook target — confirm the intended credential flow for ListenBrainz imports.

---

[Documentation home](index.md) · Previous: [Accessibility](accessibility.md)
