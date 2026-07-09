# Post Kinds for IndieWeb documentation

Post Kinds for IndieWeb lets you track what you listen to, watch, read, play, and experience — all from your own WordPress site, with full block editor support and IndieWeb microformats. This page is the hub for all user documentation.

## What the plugin does

The plugin adds a "post kind" system to your posts. A post kind is a label that describes what a post *is* — a note, a check-in, a song you listened to, a movie you watched — rather than what it's about. The idea comes from the [IndieWeb](https://indieweb.org/) movement, where people publish this kind of activity on their own site instead of (or in addition to) social networks and tracking apps.

As of version 1.4.3 (plugin header), the plugin provides:

- A **kind taxonomy** with 24 kinds you can assign to posts, each with its own archive page (for example `/kind/listen/`).
- **25 custom blocks** — 22 blocks built for the editor (card blocks for most kinds, plus utility blocks like Star Rating, Media Lookup, and check-in displays) and 3 server-rendered blocks (Now Playing, Media Stats, Recent Kinds).
- **Media lookup** from the editor: search music, movies, TV, books, podcasts, games, and venues through services like MusicBrainz, TMDB, Open Library, and Foursquare.
- **Imports and scrobbling**: bulk-import your history from services like Last.fm, Trakt, and Readwise, and receive automatic posts via webhooks from Plex, Jellyfin, Trakt, and ListenBrainz. "Scrobbling" means automatically logging each song or show as you play it.
- **microformats2 markup** on the front end. Microformats2 is a set of standard HTML classes that lets other IndieWeb sites and tools read your posts as structured data (a listen, an RSVP, a check-in) instead of plain text.
- **Syndication (POSSE) options**. POSSE stands for "Publish on your Own Site, Syndicate Elsewhere" — you post on your own site first, then optionally send a copy to services like Last.fm, Trakt, or Foursquare.
- **Micropub support** through the separate [Micropub plugin](https://wordpress.org/plugins/micropub/). Micropub is a standard API that lets mobile and third-party apps publish to your site; when the Micropub plugin is installed, this plugin converts incoming posts into the right card block and kind.

## Who it's for

- IndieWeb users who want to own their listening, watching, reading, and check-in history.
- Bloggers migrating from the classic Post Kinds plugin to the block editor.
- Anyone who wants a personal media log (books, movies, games, music) on WordPress.

## Read this first

1. [Installation](installation.md) — requirements, install methods, and plugin conflicts to know about.
2. [Getting started](getting-started.md) — your first post kind after activation.
3. [Settings](settings.md) — every option under the Reactions admin menu.

## All pages

- [Installation](installation.md)
- [Getting started](getting-started.md)
- [Settings](settings.md)
- [Common tasks](common-tasks.md)
- [Screenshots](screenshots.md)
- [Playground preview](playground.md)
- [Troubleshooting](troubleshooting.md)
- [FAQ](faq.md)
- [Privacy and data](privacy-and-data.md)
- [Accessibility](accessibility.md)
- [Documentation plan](documentation-plan.md)

## Compatibility notes

- **WordPress 7.0 or later is required.** This is an unusually high minimum — if your site runs WordPress 6.x, you can't use this plugin until you update WordPress. The plugin checks the version on activation and shows an admin notice instead of running if the requirement isn't met.
- **PHP 8.2 or later is required**, with the same activation check and notice.
- **The custom blocks require the block editor.** With the Classic Editor you can still assign kinds through the taxonomy box, but with limited UI and no card blocks.
- **Hard conflict with the classic Post Kinds plugin** (`indieweb-post-kinds`). Both plugins use the same `kind` taxonomy, so this plugin refuses to initialize while the classic plugin is active and shows an error notice. Deactivate one of them.
- **IndieBlocks is recommended, not required.** The plugin shows an admin notice recommending [IndieBlocks](https://wordpress.org/plugins/indieblocks/), which provides companion blocks for bookmarks, likes, replies, and reposts. This plugin detects IndieBlocks and Webmention but doesn't implement their features itself.
- **Micropub publishing requires the separate [Micropub plugin](https://wordpress.org/plugins/micropub/)** (and an IndieAuth setup for authentication).
- No block theme is strictly required, but the card blocks are designed around block-theme design tokens, and the venue archive template is a block template.

## For developers

Developer-facing documentation lives elsewhere in this repository:

- [Design tokens reference](audit/DESIGN-TOKENS.md) — the `--pkiw-*` CSS custom properties themes can style.
- [Plugin map](audit/plugin-map.md) — file and class inventory.
- [Micropub field gaps](micropub-field-gaps.md) — per-kind ledger of card attributes vs Micropub properties.
- [Specs](specs/) and [plans](plans/) — design documents, including the post-surfaces (stream vs main) design.
- Root-level [CONTRIBUTING.md](../CONTRIBUTING.md), [TESTING.md](../TESTING.md), [SECURITY.md](../SECURITY.md), and [CHANGELOG.md](../CHANGELOG.md).

---

[Documentation home](index.md) · Next: [Installation](installation.md)
