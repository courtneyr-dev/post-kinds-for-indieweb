---
title: FAQ
description: "Short answers about Post Kinds for IndieWeb in Block Themes: WordPress.org availability, requirements, API keys, microformats, and how kinds relate to formats."
---

Answers to the questions users actually ask, based on the plugin's behavior and its shipped FAQ.

## What's a "post kind"?

A label describing what a post *is* — a note, a listen, a check-in, a book you read — rather than its topic. Kinds are terms in a taxonomy, so each has its own archive (for example `/kind/watch/`), and each maps to standard [microformats2](https://microformats.org/wiki/microformats2) markup that other [IndieWeb](https://indieweb.org/) sites and tools can parse.

## How many blocks does the plugin include?

27: 23 blocks registered from the editor source (18 kind cards plus Star Rating, Media Lookup, Check-in Dashboard, Check-ins Feed, and Venue Detail), 3 server-rendered blocks (Now Playing, Media Stats, Recent Kinds), and Stream Card, which is registered in PHP and hidden from the inserter — it replaces Post Content inside the Stream query loop, so you never place it by hand. Older plugin copy said "16 custom blocks" — that figure was outdated.

## Do all 36 kinds have their own block?

No. Eighteen kinds have dedicated card blocks (the Event Card is the newest). The rest — note, article, photo, video, review, recipe, and the twelve kinds added for full classic Post Kinds parity (audio, quote, tag, weather, exercise, trip, itinerary, follow, issue, question, sleep, craft) — are taxonomy-and-microformats kinds: you assign them in the Post Kind panel and build the content with regular blocks (recipe integrates with WP Recipe Maker; review-style data like star ratings lives inside the media cards). Every kind, with or without a block, renders as a card on the Stream.

## Do I need IndieBlocks?

No, but it's recommended, and the plugin shows a notice until it's active. IndieBlocks provides the core blocks for bookmarks, likes, replies, and reposts; Post Kinds for IndieWeb in Block Themes adds the media/activity kinds and lookup features alongside it.

## Can I use this with the original Post Kinds plugin?

No. This plugin is its block editor successor, uses the same `kind` taxonomy, and refuses to initialize while the classic plugin is active. Deactivate one of them.

## Does it work with the Classic Editor?

Partially. Basic kind assignment through the taxonomy box works, but the card blocks require the block editor, and the classic UI is limited.

## Why does it require WordPress 7.0?

The plugin header sets both "Requires at least" and "Tested up to" at 7.0 (with PHP 8.2+). Activation is blocked with a notice on older versions. If you're on WordPress 6.x, update WordPress before installing.

## Which services work without API keys?

MusicBrainz (music) and Open Library (books). Everything else on the API Connections page — TMDB, Trakt, Simkl, Last.fm, RAWG, BoardGameGeek, Hardcover, Google Books, Readwise, Foursquare — needs a key, token, or OAuth connection. Nominatim (OpenStreetMap venue search and geocoding) needs only a contact email.

## How do I post from my phone?

Install the separate [Micropub plugin](https://wordpress.org/plugins/micropub/) plus IndieAuth, then use any Micropub app. Post Kinds for IndieWeb in Block Themes converts incoming posts into the right card block and kind automatically.

## Does the plugin send my posts to other services?

Only if you turn that on. Syndication ([POSSE](https://indieweb.org/POSSE)) to Last.fm, Trakt, or Foursquare each has its own opt-in toggle in Settings. Lookups do fetch public metadata from external APIs — see [Privacy and data](/post-kinds-for-indieweb/privacy-and-data/).

## Can I hide where I am on check-ins?

Yes. Each check-in has a privacy level — public, approximate (city only), or private (stored but never displayed) — and a site-wide default. On top of that, coordinate handling can hide, round, or discard coordinates. See [Settings](/post-kinds-for-indieweb/settings/#checkin-tab).

## Can I import my existing history?

Yes. **Reactions → Import** pulls history from connected services (Last.fm, Trakt, Hardcover, Readwise, Foursquare, and more). Large imports run in the background via WP-Cron.

## How do I validate my microformats?

Enter a post URL at [pin13.net/mf2](https://pin13.net/mf2/); it shows every microformat detected on the page.

## How do I customize how cards look?

Use the block settings sidebar in the editor, Global Styles in the Site Editor, or theme CSS. The cards are built on `--pkiw-*` design tokens so block themes can restyle them; see the [design tokens reference](https://github.com/courtneyr-dev/post-kinds-for-indieweb/blob/main/docs/audit/DESIGN-TOKENS.md). For deeper changes: every card's visible kind label passes through the `pkiw_kind_label` filter, and each card groups its title, date, and sub-lines in a `pk-caption` wrapper you can target with CSS.

## What happens to my data if I delete the plugin?

Your posts and their meta stay (they're normal WordPress content). Deleting the plugin runs its uninstall routine, which removes the plugin's options — settings, API credentials, OAuth tokens, webhook secrets and logs — plus its transients and scheduled tasks. See [Privacy and data](/post-kinds-for-indieweb/privacy-and-data/).
