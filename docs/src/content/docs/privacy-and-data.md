---
title: Privacy and data
description: "What Post Kinds stores, which external media and tracking services it contacts, and what appears in your public markup — verified against 1.0.0."
---

What the plugin stores, what it sends to other services, and what appears in your site's public markup. Everything here is verified against the plugin code as of version 1.0.0; open questions are listed at the end.

## What the plugin stores on your site

- **Posts and post meta.** All content lives in regular WordPress posts and post meta (meta keys prefixed `_postkind_`). The plugin creates no custom database tables.
- **Check-in location data.** Check-ins store venue details, latitude/longitude, and a per-post `geo_privacy` value (public / approximate / private). Depending on the Coordinate Handling setting, coordinates can also be rounded to ~1 km before storing or discarded entirely.
- **Options.** Settings, import history/state, webhook secrets and logs, and API credentials are stored in the WordPress options table. Credentials include API keys and OAuth access/refresh tokens (Trakt, Simkl, Foursquare, Last.fm session key). **Keys and tokens are stored as plugin options in the database; this documentation makes no encryption claim** (the plugin readme's "encrypted where possible" wording is flagged for maintainer review below).
- **Transients** for cached API responses (clearable from Settings → Tools).
- **Taxonomies.** The plugin adds the `kind` taxonomy (with 24 terms) and a `venue` taxonomy with term meta (Foursquare/OpenStreetMap ids, coordinates). It can also register a `reaction` post type if the import storage mode is switched from its default.
- **Scheduled tasks** (WP-Cron) for background imports and syncs.

On uninstall (deleting the plugin from the Plugins screen), the uninstall routine deletes all plugin options — including API credentials, OAuth tokens for each service, webhook secrets and logs — plus plugin transients, and unschedules its cron events. Your posts, meta, and taxonomy terms remain, since they're your content.

## Check-in location privacy

Each check-in has a privacy level (per post, with a site default under Settings → Checkin), and the plugin enforces it in the public microformats markup:

- **Public** — full venue name, street address, and exact coordinates appear in the markup.
- **Approximate** — locality/region/country appear; street address and coordinates are withheld.
- **Private** — the location is stored in your database but venue, address, and coordinates are all withheld from the public page.

Separately, **Coordinate Handling** governs storage itself: store-and-show, store-but-hide, round to ~1 km, or discard coordinates entirely (discarded coordinates can't be recovered later).

## What the plugin sends to external services

**Lookups, imports, and scrobble ingestion.** When you search for media, import history, or auto-fetch metadata, the plugin makes outbound requests to the relevant service. Services present in the plugin's code:

- Music: MusicBrainz, ListenBrainz, Last.fm, Cover Art Archive
- Movies/TV: TMDB (including its image host), Trakt, Simkl, TVmaze
- Books/articles: Open Library (including covers), Google Books, Readwise
- Podcasts: Podcast Index
- Games: RAWG, BoardGameGeek/VideoGameGeek
- Places: Foursquare, Nominatim (OpenStreetMap)
- Other: Untappd (code present; its API currently requires a commercial agreement), and oEmbed providers (YouTube, Spotify, and similar) for embeds
- **Letterboxd page fetches:** pasting a Letterboxd URL into a watch lookup makes the plugin fetch that Letterboxd page's HTML to extract the film's TMDB id — an outbound request to letterboxd.com worth knowing about.

These requests carry your search terms or media identifiers (and your API credentials for that service). The plugin readme states API calls retrieve only public metadata and that the plugin includes no analytics or tracking.

**POSSE syndication (outbound publishing).** The plugin sends your activity to Last.fm, Trakt, or Foursquare **only when you enable the matching toggle** (Scrobble to Last.fm, Sync to Trakt, Sync to Foursquare). All three default to off.

**Webhooks (inbound).** Plex, Jellyfin, Trakt, ListenBrainz, and generic webhooks push data *to* your site; deliveries are verified with an HMAC-SHA256 signature against your webhook secret.

**AI features.** AI enhancements (auto-populate, tag suggestions, review prompts) are doubly gated: they require the WordPress AI Client (WordPress 7.0+) to be available *and* the plugin's AI option to be enabled. When active, they send media metadata to whichever AI provider your site's WP AI Client is configured to use. Exactly what is sent, and to which provider, depends on that site-level configuration — see the maintainer-review list below.

## Frontend markup

Yes, the plugin adds markup to your public pages: microformats2 classes on kind posts (`h-entry` roots, `kind-<slug>` post classes, properties like `u-listen-of`, `u-checkin`, `p-rating`), hidden `<data>` elements for RSVP/check-in/review/event details, and the rendered card/dashboard blocks themselves. Check-in markup is redacted per the privacy level described above. Posts using IndieBlocks blocks are left to IndieBlocks' own microformats.

## Other WordPress data the plugin touches

- **Posts:** kind assignment on save (from the first card block, never overriding a manual choice), optional default category on first save, and optional post-format ↔ kind syncing.
- **Post meta:** card and location fields under `_postkind_`, plus `pkiw_promote` (public, REST-visible) and `_pkiw_surface` (protected) for stream/main routing. The plugin never filters your site's queries for surfaces — it only records the signal.
- **Media library:** with the default "Download to Media Library" image handling, cover art from external services is sideloaded into your uploads.
- It does not modify comments, users, or links.

## What was verified, and what needs maintainer review

Verified from code: options and meta storage, no custom tables, the uninstall cleanup list, privacy-level redaction in markup, the outbound hosts above, the POSSE and AI opt-in gates, and webhook signature verification.

Needs maintainer review (also listed in the [documentation plan](https://github.com/courtneyr-dev/post-kinds-for-indieweb/blob/main/docs/documentation-plan.md)):

- **"API keys encrypted where possible" (readme claim).** The code stores credentials as sanitized plaintext values in the options table; no encryption path was found. The claim should be confirmed or softened by the maintainer — until then, assume keys are stored unencrypted and protect your database accordingly.
- **AI data flows.** The precise payload and destination provider depend on the site's WP AI Client configuration and weren't traced in full.
- **ActivityPub.** Listed in the readme as an optional companion; no code integration was found, so no data flows to it from this plugin as far as verified.
