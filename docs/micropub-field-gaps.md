# Micropub field gaps

`includes/class-micropub-content-builder.php` turns an incoming Micropub
h-entry into one of this plugin's card blocks. Each card block exposes more
attributes than any Micropub property currently fills — this document is the
definitive ledger of those gaps, kind by kind, enforced by
`tests/phpunit/unit/MicropubContentBuilderTest.php::test_wire_matrix` (the
completeness assertion fails if a gap attribute is ever silently mapped
without this doc and the ledger being updated together).

Columns: **card attribute** (from the block's `block.json`) | **Micropub
property today** (`—` = nothing maps) | **proposed property for Outpost**
(the sender-side change needed to close the gap).

## eat

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `restaurant` | — | `mp-place-name` already maps to `locationName`; `restaurant` is a distinct free-text field — reuse `mp-place-name` or add `mp-restaurant` if the two need to diverge |
| `cuisine` | — | `mp-cuisine` (vendor extension, mirrors the existing `mp-place-name` pattern) |
| `photo` / `photoAlt` | — (photos land as a separate `core/image`/`core/gallery` block after the card, not on the card itself) | no change needed — Micropub's standard `photo`/`mp-photo-alt` already round-trip via the append-photo path |
| `ateAt` | — | `published` (mf2 `dt-published`) — the h-entry's own timestamp, not a new property |
| `restaurantUrl` | — | parse mf2 `location` as an h-card and use its `url` property, instead of only extracting `geo:` URIs |
| `locationAddress` | — | parse mf2 `location` as an h-adr object (`street-address`) instead of geo-only |
| `locationLocality` | — | h-adr `locality` |
| `locationRegion` | — | h-adr `region` |
| `locationCountry` | — | h-adr `country-name` |

## drink

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `drinkType` | — | `mp-drink-type` (vendor extension) |
| `brand` | — | `mp-brand` (vendor extension) |
| `photo` / `photoAlt` | — (same as eat: handled by the separate photo-append path) | no change needed |
| `drankAt` | — | `published` |
| `venueUrl` | — | h-adr/h-card `url` from `location`, same as eat's `restaurantUrl` |
| `locationAddress` | — | h-adr `street-address` |
| `locationLocality` | — | h-adr `locality` |
| `locationRegion` | — | h-adr `region` |
| `locationCountry` | — | h-adr `country-name` |

## checkin

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `venueType` | — | `mp-venue-type` (vendor extension) |
| `address` | — | h-adr `street-address` from `location` |
| `locality` | — | h-adr `locality` |
| `region` | — | h-adr `region` |
| `country` | — | h-adr `country-name` |
| `postalCode` | — | h-adr `postal-code` |
| `locationPrivacy` | — | `mp-location-privacy` (vendor extension; no mf2 equivalent) |
| `osmId` | — | `mp-osm-id` (vendor extension) |
| `venueUrl` | — | h-card `url` from `location` |
| `foursquareId` | — | `mp-foursquare-id` (vendor extension) |
| `checkinAt` | — | `published` |
| `photo` / `photoAlt` | — (handled by the separate photo-append path) | no change needed |
| `showMap` | — | display preference, not sender data — leave as a block default |

## listen

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `albumTitle` | — | `mp-album-title` (vendor extension) or parse from an h-cite `name` on a nested `listen-of` object |
| `releaseDate` | — | `mp-release-date` (vendor extension) |
| `coverImage` / `coverImageAlt` | — | `mp-cover-image` / `mp-cover-image-alt`, or reuse Micropub's standard `photo` if Outpost already fetches cover art as a photo |
| `musicbrainzId` | — | `mp-musicbrainz-id` (vendor extension) |
| `listenedAt` | — | `published` |

## watch

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `mediaType` | — | `mp-media-type` (movie/tv/episode — vendor extension) |
| `showTitle` | — | `mp-show-title` (only relevant when `mp-media-type=episode`) |
| `seasonNumber` / `episodeNumber` | — | `mp-season-number` / `mp-episode-number` |
| `episodeTitle` | — | `mp-episode-title` |
| `releaseYear` | — | `mp-release-year` |
| `posterImage` / `posterImageAlt` | — | `mp-poster-image` / `mp-poster-image-alt` |
| `tmdbId` | — | `mp-tmdb-id` |
| `imdbId` | — | `mp-imdb-id` |
| `isRewatch` | — | `mp-is-rewatch` (boolean vendor extension) |
| `watchedAt` | — | `published` |

## read

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `isbn` | — | `mp-isbn` (vendor extension) |
| `publisher` | — | `mp-publisher` |
| `publishDate` | — | `mp-publish-date` (book's publish date — distinct from the h-entry's own `published`) |
| `pageCount` / `currentPage` | — | `mp-page-count` / `mp-current-page` |
| `coverImage` / `coverImageAlt` | — | `mp-cover-image` / `mp-cover-image-alt` |
| `openlibraryId` | — | `mp-openlibrary-id` |
| `startedAt` / `finishedAt` | — | `mp-started-at` / `mp-finished-at` (distinct from the h-entry's own `published`, since `readStatus` transitions happen across multiple Micropub updates) |

## play

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `platform` | — | `mp-platform` (vendor extension) |
| `cover` / `coverAlt` | — | `mp-cover` / `mp-cover-alt` |
| `status` | — | `mp-status` (playing/completed/... — vendor extension) |
| `hoursPlayed` | — | `mp-hours-played` |
| `playedAt` | — | `published` |
| `review` | — | `content` already maps for every other of-kind post — extend `play_card()` to also read `content` into `review` (a builder gap, not a sender gap) |
| `bggId` / `rawgId` / `steamId` | — | `mp-bgg-id` / `mp-rawg-id` / `mp-steam-id` |
| `officialUrl` / `purchaseUrl` | — | `mp-official-url` / `mp-purchase-url` |

## rsvp

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `eventName` | — | mf2 nests the event as an h-cite/h-event object under `in-reply-to`; use its `name` |
| `eventStart` / `eventEnd` | — | the same nested h-event's `start`/`end` |
| `eventLocation` | — | the same nested h-event's `location` |
| `eventDescription` | — | the same nested h-event's `summary`/`content` |
| `rsvpAt` | — | `published` |
| `eventImage` / `eventImageAlt` | — | the nested h-event's `photo` |
| `rel` | — | not an mf2 concept for RSVPs — leave as a block default |

## like / repost / bookmark / reply

These four share one h-cite shape (`title`/`url`/`author`/`description` map
today via `like-of`/`repost-of`/`bookmark-of`/`in-reply-to`, `name`,
`author`, `content` — reply intentionally excludes `description`, see
`reply_card()`'s doc comment: the reply body belongs in `e-content`, not the
cited post's own description).

| Card attribute | Kinds affected | Micropub property today | Proposed property for Outpost |
|---|---|---|---|
| `image` / `imageAlt` | all four | — | mf2 nests the cited post as an h-cite object under the `-of`/`in-reply-to` property; use its `photo` |
| `likedAt` / `repostedAt` / `bookmarkedAt` / `repliedAt` | all four | — | `published` |
| `rel` | all four | — | not an mf2 concept here — leave as a block default |
| `description` | reply only | — (by design) | not a gap: the reply body is the author's own words and is already preserved in the `e-content` paragraph, never on the card |

## mood

| Card attribute | Micropub property today | Proposed property for Outpost |
|---|---|---|
| `emoji` | — | `mp-emoji` (vendor extension) |
| `intensity` | — | `mp-intensity` |
| `moodAt` | — | `published` |

## follow / weather / photo

These three kinds have no dedicated card block — `follow` and `weather` emit
a plain `core/paragraph` carrying a microformats2 class (`u-follow-of`,
`p-weather`), and `photo` emits a plain `core/image` or `core/gallery`. There
is no card attribute schema to reconcile against, so these kinds are outside
the scope of the wire-matrix completeness assertion.

## Follow-on work

Every "proposed property" above is a **sender-side** change: Outpost (the
Micropub client) would need to start sending these properties, either as
mf2-standard fields (`published`, h-adr location components, nested h-cite/
h-event objects) or as `mp-*` vendor extensions following the plugin's
existing `mp-place-name` / `mp-photo-alt` convention. Implementing that is
out of scope for this plan — it lives in the Outpost repo. This document is
the receiving side's contract: once Outpost sends a property, the builder
must be extended to map it, and the corresponding gap row removed from this
table and from `wire_matrix()`'s `known_gaps` list in the same change.
