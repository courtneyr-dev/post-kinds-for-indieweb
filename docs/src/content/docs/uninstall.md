---
title: Uninstall
description: "How to deactivate and delete Post Kinds for IndieWeb, what its uninstaller removes, and what stays: your posts, kind terms, and post meta."
---

How to remove Post Kinds for IndieWeb, what the uninstaller cleans up, and what stays on your site. Your posts are never touched — everything you logged remains as standard WordPress posts.

## Deactivate the plugin

1. In wp-admin, go to **Plugins → Installed Plugins**.
2. Find **Post Kinds for IndieWeb** and select **Deactivate**.

Deactivation is reversible and deletes no data. The Reactions menu disappears, kind archives stop rendering their templates, and the card blocks show as unavailable in the editor until you reactivate.

## Delete the plugin

1. With the plugin deactivated, select **Delete** on the Plugins screen.
2. Confirm the deletion.

WordPress removes the plugin files and runs the uninstall script.

## What the uninstaller removes

The uninstall script deletes the plugin's stored configuration and service connections:

- All settings options (`post_kinds_indieweb_*`), including webhook settings, import history, and sync bookkeeping.
- OAuth tokens for Trakt, Simkl, Foursquare, and Untappd.
- Stored API keys for lookup services (TMDB, Last.fm, ListenBrainz, and the rest).
- Cached lookup transients.
- Scheduled import and sync cron events.

## What stays on your site

- **Your posts** — every listen, watch, read, and check-in you published.
- **The `kind` taxonomy terms** and their assignments to posts. The archives stop working without the plugin, but the term data stays in the database.
- **Post meta** written by imports, webhooks, and check-ins.
- **Card block markup** inside post content. The blocks render as their saved HTML; editing an old post shows them as unrecognized blocks unless the plugin is active.

If you're switching to the classic Post Kinds plugin, the shared `kind` taxonomy means your kind assignments carry over — see [FAQ](/post-kinds-for-indieweb/faq/) for the differences.

## Expected result

After deletion, the Reactions menu and settings screens are gone, `/kind/…` archives fall back to your theme's default archive handling, and your logged history remains readable on the site.
