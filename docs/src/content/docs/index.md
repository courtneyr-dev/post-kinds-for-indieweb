---
title: Post Kinds for IndieWeb in Block Themes
description: "User documentation for Post Kinds for IndieWeb in Block Themes: log what you listen to, watch, read, play, and visit on your own WordPress site."
---

Post Kinds for IndieWeb in Block Themes lets you track what you listen to, watch, read, play, and experience — all from your own WordPress site, with full block editor support and IndieWeb microformats. These docs help you install the plugin, set it up, and log your first activity.

## What the plugin does

The plugin adds a "post kind" system to your posts. A post kind is a label that describes what a post *is* — a note, a check-in, a song you listened to, a movie you watched — rather than what it's about. The idea comes from the [IndieWeb](https://indieweb.org/) movement, where people publish this kind of activity on their own site instead of (or in addition to) social networks and tracking apps.

The plugin provides:

- A **kind taxonomy** with 36 kinds you can assign to posts — the full vocabulary of the classic Post Kinds plugin — each with its own archive page (for example `/kind/listen/`).
- **27 custom blocks** — 23 editor blocks (card blocks for most kinds plus utilities like Star Rating and Media Lookup), 3 server-rendered blocks (Now Playing, Media Stats, Recent Kinds), and the Stream Card. The [FAQ](/post-kinds-for-indieweb/faq/) has the full breakdown.
- **Media lookup** from the editor: search music, movies, TV, books, podcasts, games, and venues through services like MusicBrainz, TMDB, Open Library, and Foursquare.
- **Imports and scrobbling**: bulk-import your history from services like Last.fm, Trakt, and Readwise, and receive automatic posts via webhooks from Plex, Jellyfin, Trakt, and ListenBrainz.
- **[microformats2](https://microformats.org/wiki/microformats2) markup** on the front end, so other IndieWeb sites and tools can read your posts as structured data.
- **Syndication ([POSSE](https://indieweb.org/POSSE)) options** — post on your own site first, then optionally send a copy to services like Last.fm, Trakt, or Foursquare.
- **[Micropub](https://indieweb.org/Micropub) support** through the separate [Micropub plugin](https://wordpress.org/plugins/micropub/), converting incoming app posts into the right card block and kind.
- **[Standard.site](https://standard.site/) on AT Protocol** — read cited pages' own records, and publish your posts as standard.site documents through the optional [ATmosphere](https://wordpress.org/plugins/atmosphere/) companion plugin.
- **Automatic featured images** — album art, posters, and book covers from media posts become the featured image when a post has none.
- **A firehose feed** at `/firehose` — your site's RSS including the bulk-imported posts that normal feeds leave out.

## Who it's for

- IndieWeb users who want to own their listening, watching, reading, and check-in history.
- Bloggers migrating from the classic Post Kinds plugin to the block editor.
- Anyone who wants a personal media log (books, movies, games, music) on WordPress.

## Before you install

- **WordPress 7.0 or later** — an unusually high minimum; the plugin checks on activation and shows a notice instead of running on older versions.
- **PHP 8.2 or later**, with the same activation check.
- **The block editor** — with the Classic Editor you can still assign kinds, but without the card blocks.
- **No classic Post Kinds plugin** — both use the same `kind` taxonomy, so this plugin refuses to initialize while `indieweb-post-kinds` is active.

[Installation](/post-kinds-for-indieweb/installation/) covers requirements, install methods, and conflicts in full.

## Is it on WordPress.org?

Yes — install it from the [WordPress.org plugin directory](https://wordpress.org/plugins/post-kinds-for-indieweb-in-block-themes/) (**Plugins → Add New**, search for "Post Kinds for IndieWeb in Block Themes"). [Playground preview](/post-kinds-for-indieweb/playground/) lets you try it in your browser first without installing anything.

## Get started

1. [Installation](/post-kinds-for-indieweb/installation/) — requirements, install methods, and plugin conflicts.
2. [Getting started](/post-kinds-for-indieweb/getting-started/) — your first post kind after activation.
3. [Settings](/post-kinds-for-indieweb/settings/) — every option under the Reactions admin menu.

## Get help

- [Troubleshooting](/post-kinds-for-indieweb/troubleshooting/) — symptoms, causes, and fixes.
- [FAQ](/post-kinds-for-indieweb/faq/) — quick answers to common questions.
- [Report an issue](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues) on GitHub.

## Source code

The plugin is developed in the open at [github.com/courtneyr-dev/post-kinds-for-indieweb](https://github.com/courtneyr-dev/post-kinds-for-indieweb). Developer documentation (design tokens, plugin map, specs) lives in the repository, separate from these user docs.
