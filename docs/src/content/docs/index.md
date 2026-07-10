---
title: Post Kinds for IndieWeb
description: "User documentation for Post Kinds for IndieWeb: log what you listen to, watch, read, play, and visit on your own WordPress site."
---

Post Kinds for IndieWeb lets you track what you listen to, watch, read, play, and experience — all from your own WordPress site, with full block editor support and IndieWeb microformats. These docs help you install the plugin, set it up, and log your first activity.

## What the plugin does

The plugin adds a "post kind" system to your posts. A post kind is a label that describes what a post *is* — a note, a check-in, a song you listened to, a movie you watched — rather than what it's about. The idea comes from the [IndieWeb](https://indieweb.org/) movement, where people publish this kind of activity on their own site instead of (or in addition to) social networks and tracking apps.

As of version 1.4.3, the plugin provides:

- A **kind taxonomy** with 24 kinds you can assign to posts, each with its own archive page (for example `/kind/listen/`).
- **25 custom blocks** — card blocks for most kinds, utility blocks like Star Rating and Media Lookup, and server-rendered blocks (Now Playing, Media Stats, Recent Kinds).
- **Media lookup** from the editor: search music, movies, TV, books, podcasts, games, and venues through services like MusicBrainz, TMDB, Open Library, and Foursquare.
- **Imports and scrobbling**: bulk-import your history from services like Last.fm, Trakt, and Readwise, and receive automatic posts via webhooks from Plex, Jellyfin, Trakt, and ListenBrainz.
- **[microformats2](https://microformats.org/wiki/microformats2) markup** on the front end, so other IndieWeb sites and tools can read your posts as structured data.
- **Syndication ([POSSE](https://indieweb.org/POSSE)) options** — post on your own site first, then optionally send a copy to services like Last.fm, Trakt, or Foursquare.
- **[Micropub](https://indieweb.org/Micropub) support** through the separate [Micropub plugin](https://wordpress.org/plugins/micropub/), converting incoming app posts into the right card block and kind.

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

Not yet. Post Kinds for IndieWeb is not available in the WordPress.org plugin directory — you install it from a release ZIP on GitHub. [Playground preview](/post-kinds-for-indieweb/playground/) lets you try it in your browser first without installing anything.

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
