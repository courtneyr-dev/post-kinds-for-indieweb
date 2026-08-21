---
title: Troubleshooting
description: "Fixes for common Post Kinds problems: missing kind cards, failed lookups, import stalls, webhook errors, and admin notices explained."
---

Symptoms, likely causes, and fixes for the most common problems, based on the plugin's FAQ, changelog, and admin notices.

## The plugin won't run and shows a "Post Kinds" error

**Symptom:** An error notice says Post Kinds for IndieWeb in Block Themes cannot run while Post Kinds is active, and none of the plugin's features appear.

**Likely cause:** The classic [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin (`indieweb-post-kinds`) is active — including network-activated on multisite. Both plugins use the same `kind` taxonomy, so this plugin deliberately refuses to initialize.

**Fix:**

1. Go to **Plugins** and deactivate **Post Kinds** (the classic one).
2. Reload wp-admin; the Reactions menu and blocks should appear.

**What to check next:** On multisite, check **Network Admin → Plugins** for a network activation.

## Activation blocked by a PHP or WordPress version notice

**Symptom:** A notice says the plugin requires PHP 8.2 or WordPress 7.0 and names your current version.

**Likely cause:** Your environment is below the plugin's minimums (WordPress 7.0+, PHP 8.2+ — both unusually recent).

**Fix:** Update WordPress and/or ask your host to move you to PHP 8.2+. There is no supported way to run the plugin below these versions.

## Media search returns no results

**Symptom:** Card block or Media Lookup searches come back empty, or the Quick Post search finds nothing.

**Likely causes** (from the plugin FAQ):

- Missing or incorrect API keys for the service (TMDB, RAWG, Hardcover, and most others need keys; MusicBrainz and Open Library don't).
- Your server can't make outbound HTTPS requests (firewall or host restriction).
- The search term has no match in that service.

**Fix:**

1. Go to **Reactions → API Connections**, confirm the key for the relevant service, and run its connection test.
2. Ask your host whether outbound HTTPS is blocked.
3. Try broader search terms.

**What to check next:** The plugin FAQ points to a Debug view under the plugin's settings for API errors. Also try **Reactions → Settings → Tools → Clear API Cache** if results seem stale.

## A persistent notice recommends IndieBlocks

**Symptom:** An admin notice says the plugin "works best with IndieBlocks installed" (or "requires IndieBlocks... for full functionality").

**Likely cause:** This is expected behavior when IndieBlocks isn't active — a recommendation, not an error. IndieBlocks provides companion blocks for bookmarks, likes, replies, and reposts.

**Fix:** Install and activate [IndieBlocks](https://wordpress.org/plugins/indieblocks/) to make the notice go away, or ignore it — the plugin runs without IndieBlocks.

## Cards look broken on the front end or in the editor

**Symptom:** Card contents scattered into a strange layout in the editor, or published cards missing padding and shadow.

**Likely cause:** Known regressions in pre-release builds, all fixed in 1.0.0: an editor grid layout that scattered card contents, front-end padding and shadow stripped by an unscoped editor rule, and a fatal error with hooked blocks in some block themes.

**Fix:** Update to the latest version (1.0.0 or later). If cards still look off afterward, hard-refresh to clear cached CSS.

**What to check next:** Your theme's design tokens — cards take color and typography from block-theme styles, so a theme without editor styles can look plainer than the screenshots.

## Posts from a Micropub app don't arrive

**Symptom:** Publishing from a mobile/Micropub client fails or nothing shows up on the site.

**Likely cause:** Micropub is not something this plugin provides by itself — it requires the separate [Micropub plugin](https://wordpress.org/plugins/micropub/) (and IndieAuth for authentication). Post Kinds for IndieWeb in Block Themes only converts posts the Micropub plugin has already received.

**Fix:**

1. Install and activate the Micropub plugin and an IndieAuth setup.
2. Sign in to your Micropub app again and retry.

**What to check next:** Version 1.1.0 of this plugin fixed Micropub posts failing to appear ("phantom posts"), and 1.2.0 fixed fields being silently dropped — make sure you're on a current version.

## OAuth service shows "Not connected" after authorizing

**Symptom:** You complete the Trakt/Simkl/Foursquare/Last.fm authorization flow but the API Connections page still says not connected.

**Likely cause:** A bug fixed in the 1.4.x cycle — OAuth callback action names didn't match the redirect URI, so tokens were never stored.

**Fix:** Update the plugin, then re-copy the redirect URI shown on the API Connections page into your provider app settings and authorize again.

## Imports seem stuck or slow

**Symptom:** An import sits in Active Imports for a long time.

**Likely cause:** Large imports intentionally run in the background in batches via WP-Cron, throttled by the Rate Limit Delay setting. WP-Cron only fires when your site gets traffic.

**Fix:** Give it time and visits, or lower the Rate Limit Delay / raise the Import Batch Size on **Reactions → Settings → Performance** with care (aggressive values can hit provider rate limits).

## The Integrations tab says ATmosphere is missing, inactive, or too old

Standard.site publishing runs through the optional ATmosphere companion plugin. Post Kinds works fully without it — the status line on Settings → Post Kinds → Integrations just tells you what would enable publishing: install ATmosphere (2.1.0 or later), activate it, or update it, then connect an account on its own settings screen.

## A kind post isn't publishing to Standard.site

In order of likelihood:

1. ATmosphere isn't connected — check Settings → ATmosphere (its Site Health section reports connection problems too).
2. The kind is off by default — reaction and log kinds are opt-in. Check the kind under Settings → Post Kinds → Integrations, or flip the post's own sharing toggle in the editor, which always wins.
3. The post isn't public — drafts, private, and password-protected posts never publish.
4. The post predates the connection — routine edits don't retro-publish old posts by design. Bring existing posts over deliberately with `wp atmosphere backfill` (`--dry-run` first).

The Standard.site column on the posts list shows each post's state at a glance: Published (hover for the record address), Pending, or Off.

## When to open an issue

If none of the above fixes your problem:

1. Check existing reports at the [GitHub issues page](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues).
2. Read [SUPPORT.md](https://github.com/courtneyr-dev/post-kinds-for-indieweb/blob/main/docs/../SUPPORT.md) — it lists the support channels (GitHub Issues and Discussions, plus IndieWeb Chat) and the details to include: WordPress, PHP, plugin, and IndieBlocks versions, and steps to reproduce.
3. Open a new issue with those details.
