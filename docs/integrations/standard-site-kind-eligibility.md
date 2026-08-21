# Standard.site kind eligibility — decision table

Per-kind decisions for which Post Kinds publish `site.standard.document`
records by default through the ATmosphere integration. The inventory is
the **verified 36-kind registry** (`PKIW\Taxonomy::$default_kinds`,
extracted by reflection on 2026-08-21: exactly 36 slugs, each a distinct
public kind with its own name and description — no aliases, hidden,
legacy, or migration-only entries; readme.txt's "full 36-kind vocabulary"
claim reconciles). The registry is filterable (`pkiw_default_kinds`);
**kinds unknown to this policy default to opt-in by construction**
(tested: `test_unknown_registry_kinds_default_to_disabled`).

Shared facts, true for every row:

- **User override:** ATmosphere's per-post sharing toggle (the
  `atmosphere_disabled` meta) always wins over any default, in both
  directions; posts that already published a record are never affected
  by later default changes (both tested).
- **Bluesky behavior:** follows ATmosphere's settings unchanged, for
  every kind. Site-wide document-only publishing exists on ATmosphere
  trunk (`atmosphere_should_publish_bluesky_post`), not in released
  2.1.0; per-kind routing is upstream-blocked (no post context in the
  filter).
- **Backfill behavior:** `wp atmosphere backfill` applies the identical
  eligibility answer (verified: dry run skips ineligible kind posts), is
  never automatic, and never revisits already-synced posts.
- **Statuses:** drafts, scheduled, private, and password-protected posts
  never publish regardless of kind (ATmosphere's gate).

Defaults: **22 kinds on, 14 off.**

| Post Kind | Category | Default | Reason | Title source | Public fields (beyond title/URL/date/tags/text) | Privacy risk | User override |
|---|---|---|---|---|---|---|---|
| note | content | **on** | Short public content; the page is the point | content trim | content | low | toggle |
| article | content | **on** | Long-form public content | post title | content, excerpt | low | toggle |
| photo | content | **on** | Image-centric content | post title / content trim | images (cover) | low — media the author placed | toggle |
| video | content | **on** | Video-centric content | post title / content trim | media links | low | toggle |
| audio | content | **on** | Audio-centric content | post title / content trim | media links | low | toggle |
| review | content | **on** | Evaluative content; discovery-useful | "Review: {item}" | item name, rating | low | toggle |
| recipe | content | **on** | Instructional content | post title | ingredients/steps in content | low | toggle |
| event | content | **on** | Public announcement by nature | post title | date, location the author published | low-med — location is author-chosen | toggle |
| quote | content | **on** | Commentary on cited work | "Quote from {cite\|host}" | cited source | low | toggle |
| question | content | **on** | Solicits public answers | post title / content trim | content | low | toggle |
| craft | content | **on** | Made-thing showcase | post title / content trim | content, images | low | toggle |
| listen | log | **on** | Public scrobble; publishing these logs is the plugin's purpose | "Listened to {track} by {artist}" | track, artist, album | low — taste data the page already shows | toggle |
| watch | log | **on** | Public watch log | "Watched {title}" / show S#E# | title, season/episode, rating | low | toggle |
| read | log | **on** | Public reading log | "Read {title} by {author}" | book, author, progress in content | low | toggle |
| play | log | **on** | Public gaming log | "Played {game}" | game, platform | low | toggle |
| eat | log | **on** | Public food log | "Ate {name}" | dish; venue/location fields if the author filled them (rendered untiered — the record carries what the page shows) | med — optional location, no privacy tier; flagged in docs | toggle |
| drink | log | **on** | Public drink log | "Drank {name}" | drink, brewery; same venue caveat as eat | med — same as eat | toggle |
| jam | log | **on** | Deliberate music endorsement | "Jam: {track} by {artist}" | track, artist | low | toggle |
| reply | response | **on** | Substantive response content; discovery adds conversation context | "Replied to {cite\|host}" | cited page, reply content | low — already sent as webmention | toggle |
| bookmark | response | **on** | Deliberate save, often annotated ("saved link with optional annotation") | "Bookmarked {cite\|host}" | cited page, annotation | low | toggle |
| rsvp | response | **on** | Deliberate public event response | "RSVP: {event}" | event, yes/no/maybe status | low-med — announces attendance the page already announces | toggle |
| issue | response | **on** | "Article-length reply filed against a source" — content | content trim / cite | cited source, content | low | toggle |
| like | signal | off | Thin signal, high volume; discovery value low, surprise factor real | "Liked {cite\|host}" | cited page | low | toggle / site setting |
| repost | signal | off | Thin reshare signal | "Reposted {cite\|host}" | cited page | low | toggle / site setting |
| favorite | signal | off | Thin star signal | "Favorited {name\|cite}" | item | low | toggle / site setting |
| follow | signal | off | Social-graph announcement; low discovery value | "Followed {cite\|host}" | followed profile | med — third-party identity | toggle / site setting |
| tag | signal | off | Tags external content **or a person** | content trim | tagged target | med-high — third-party identity | toggle / site setting |
| checkin | sensitive | off | Location log | "Checked in at {venue}" (privacy-tiered: private → "Checked in") | venue/locality per the existing `geo_privacy` tiers; coordinates only when the page shows them | high — location patterns | toggle / site setting |
| mood | sensitive | off | Emotional-state log | "Mood: {emoji} {label}" | mood | high — mental-state data | toggle / site setting |
| wish | sensitive | off | Wishlist reveals purchase intent | "Wish: {name}" | item | med | toggle / site setting |
| acquisition | sensitive | off | Possessions log | "Acquired {name}" | item, optionally price | med-high — property information | toggle / site setting |
| weather | sensitive | off | Implies location over time | content trim / kind label | conditions | med — location proxy | toggle / site setting |
| exercise | sensitive | off | Health + route data | content trim / kind label | activity | high — health, location patterns | toggle / site setting |
| sleep | sensitive | off | Health metrics | content trim / kind label | metrics | high — health data | toggle / site setting |
| trip | sensitive | off | Travel — announces absence from home | content trim / kind label | route | high — away-from-home signal | toggle / site setting |
| itinerary | sensitive | off | Future travel plans | content trim / kind label | legs, dates | high — future whereabouts | toggle / site setting |

## Notes on the reasoning

- The prompt's priors were adopted where they held up (consumption logs
  on, check-ins/moods/wishes/acquisitions off) and each reaction kind was
  decided individually: reply/bookmark/RSVP/issue carry author-written
  substance a reader benefits from discovering; like/repost/favorite/
  follow/tag are one-bit signals whose network-wide indexing surprises
  more than it serves. Disagreement is one checkbox away.
- "Privacy risk" grades the *record's* marginal exposure. Every field
  listed is already on the public web page; Standard.site adds
  network-wide indexing and permanence on a PDS, which is why sensitive
  kinds stay opt-in even though their pages are public.
- Derived titles never widen exposure: the check-in title honors
  `geo_privacy`, and text content comes from the very card render the
  privacy tiers already govern. Eat/drink venue fields have **no**
  privacy tier in the cards today — noted in the table, called out in
  the privacy docs, and a reason those kinds' records only ever carry
  what the page displays.
- Defaults are enforced as a metadata *default* on ATmosphere's own
  toggle — zero writes, so "changing a default" never edits any post.
  Site setting: Settings → Post Kinds → Integrations. Filters:
  `pkiw_atmosphere_default_eligible_kinds`,
  `pkiw_atmosphere_post_default_disabled`.
