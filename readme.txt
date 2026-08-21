=== Post Kinds for IndieWeb in Block Themes ===
Contributors: courane01
Tags: indieweb, post-kinds, microformats, block-editor, scrobbling
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track what you listen to, watch, read, play, and experience — all from your WordPress site, with full block editor support and IndieWeb microformats.

== Description ==

Post Kinds for IndieWeb in Block Themes is a block-editor successor to the classic [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin.

A post kind is a label that describes what a post *is* — a note, a check-in, a song you listened to, a movie you watched — rather than what it's about. The idea comes from the [IndieWeb](https://indieweb.org/) movement: publish your activity on your own site instead of (or in addition to) social networks and tracking apps.

The original Post Kinds plugin was built for the Classic Editor. This plugin is built for the block editor and block themes.

= What you get =

* **27 custom blocks** — 23 editor blocks (card blocks for most kinds, plus utilities like Star Rating, Media Lookup, Check-in Dashboard, Check-ins Feed, and Venue Detail), 3 server-rendered blocks (Now Playing, Media Stats, Recent Kinds), and the Stream Card, which is registered in PHP, hidden from the inserter, and stands in for Post Content inside the Stream query loop
* **API-powered search** — find music, movies, books, games, and venues directly from the editor
* **Bulk import** — pull in your history from Last.fm, Trakt, Hardcover, and more
* **Real-time scrobbling** — webhooks for Plex, Jellyfin, Trakt, and ListenBrainz. Scrobbling means automatically logging each song or show as you play it.
* **microformats2 markup** on every post. Microformats are standard HTML classes that let other IndieWeb sites and tools read your posts as structured data — a listen, an RSVP, a check-in — instead of plain text.
* **Standard.site records** — when a page you bookmark publishes its metadata to AT Protocol, read the author's own title, description, and tags instead of guessing from the page. No account or setup needed.

= Post kinds =

* **Listen** — music, podcasts, and audio (MusicBrainz, ListenBrainz, Last.fm)
* **Watch** — movies, TV shows, and videos (TMDB, Trakt, Simkl, TVMaze)
* **Read** — books and articles (Open Library, Google Books, Hardcover)
* **Checkin** — places and venues (Foursquare, OpenStreetMap), with per-post location privacy
* **Play** — video games and board games (RAWG, BoardGameGeek)
* **Eat / Drink** — food and beverages
* **Jam** — music you love, with oEmbed previews
* **RSVP** — event responses (yes, no, maybe, interested, remote)
* **Like, Reply, Repost, Bookmark** — IndieWeb interactions
* **Favorite, Wish, Mood, Acquisition** — personal tracking
* **Follow, Tag, Quote, Issue, Question** — more IndieWeb response types
* **Audio, Weather, Exercise, Sleep, Trip, Itinerary, Craft** — logs and experimental kinds

That's the full 36-kind vocabulary of the classic Post Kinds plugin. Kinds without a dedicated card block still render as cards on the Stream and carry correct microformats2 markup.

Each kind gets its own archive page (for example `/kind/listen/`), and the kind is set automatically from the first card block in a post — or pick it yourself in the Post Kind editor panel.

= Import and sync =

* **Bulk import** from ListenBrainz, Last.fm, Trakt, Simkl, and Hardcover
* **Webhooks** for Plex, Jellyfin, Trakt, and ListenBrainz
* **Background processing** via WP-Cron for large imports, with duplicate prevention on re-imports
* **Optional syndication (POSSE)** — post on your own site first, then send a copy to Last.fm, Trakt, or Foursquare. Off by default; nothing is sent unless you enable it.

= Works with =

* [Micropub](https://wordpress.org/plugins/micropub/) — **required for posting from Micropub apps.** This plugin doesn't implement the Micropub endpoint itself; install the Micropub plugin (plus [IndieAuth](https://wordpress.org/plugins/indieauth/) for authentication), and this plugin converts incoming posts into the right card block and kind.
* [IndieBlocks](https://wordpress.org/plugins/indieblocks/) — detected and recommended, not required. It provides companion blocks (Facepile, Location, Syndication, Link Preview); this plugin doesn't implement those features itself.
* [Webmention](https://wordpress.org/plugins/webmention/) — detected on the Integrations settings tab. Cross-site conversations come from that plugin, not this one.
* [Syndication Links](https://wordpress.org/plugins/syndication-links/), [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/), [Bookmark Card](https://wordpress.org/plugins/bookmark-card/) — detected and enhanced when present.
* [ActivityPub](https://wordpress.org/plugins/activitypub/) — a recommendation only; this plugin contains no ActivityPub integration.

= Conflicts =

* **Post Kinds (indieweb-post-kinds)** — hard conflict. Both plugins register the same `kind` taxonomy, so this plugin refuses to run while the classic Post Kinds plugin is active and shows an admin notice instead. Deactivate one of them.

== Installation ==

= Requirements =

* WordPress 7.0 or later
* PHP 8.2 or later

= From your WordPress admin =

1. Go to **Plugins > Add New**
2. Search for **Post Kinds for IndieWeb in Block Themes**
3. Click **Install Now**, then **Activate**

= From GitHub =

1. Clone or download from [GitHub](https://github.com/courtneyr-dev/post-kinds-for-indieweb)
2. Run `composer install` and `npm run build`
3. Upload to `/wp-content/plugins/post-kinds-for-indieweb-in-block-themes/` and activate

= After activation =

1. Go to **Reactions > Settings** to review defaults
2. Add API keys under **Reactions > API Connections** for the services you want (TMDB, Last.fm, etc.)
3. MusicBrainz and Open Library work without API keys
4. Create a post and add a card block — the post kind is assigned automatically

== Frequently Asked Questions ==

= Can I use this alongside the original Post Kinds plugin? =

No. Both plugins register the same `kind` taxonomy, so this plugin refuses to initialize while the classic Post Kinds plugin is active and shows an error notice. Deactivate the original before activating this one.

= Do I need IndieBlocks installed? =

No, but it's recommended and the plugin shows an admin notice suggesting it. IndieBlocks provides companion blocks for bookmarks, likes, replies, and reposts; this plugin detects it but doesn't implement its features.

= What is the "Standard.site record" panel on my bookmark cards? =

Some sites publish their posts' metadata to AT Protocol using the [standard.site](https://standard.site/) schemas. When a page you cite does, that panel reads the author's own title, description, and tags rather than guessing from the page, and tells you which publication it came from.

Choose "Check this URL" to look. Most of the web publishes no such record, and the panel says so plainly when that happens — it isn't an error. Nothing is checked until you ask, and the panel stays hidden until the card has a URL.

= Does the Standard.site panel publish my posts to AT Protocol? =

No. It only reads. Your posts are not sent anywhere, no account is involved, and there is nothing to connect or configure. Publishing your own posts as standard.site records needs an authenticated connection, which this plugin does not do.

= How do I post from my phone or a Micropub app? =

Install the separate [Micropub](https://wordpress.org/plugins/micropub/) plugin and an IndieAuth setup (for example the [IndieAuth](https://wordpress.org/plugins/indieauth/) plugin). Micropub is a standard API that lets mobile and third-party apps publish to your site. Once it's active, this plugin converts incoming Micropub posts into the right card block and assigns the kind.

For one-tap posting and bookmarklet-style sharing from any page or your phone's share sheet, [Outpost](https://github.com/courtneyr-dev/outpost) is a companion progressive web app that posts to your site over Micropub. This plugin doesn't ship its own bookmarklet — Micropub and Outpost cover that.

= Can I browse or subscribe to one kind at a time? =

Yes — every kind gets its own archive and RSS feed automatically. Visit `/kind/listen/` (swap `listen` for any kind) to see all posts of that kind, and `/kind/listen/feed/` — or `/feed/?kind=listen` — to subscribe to just that kind in a feed reader. This works as soon as the plugin is active and your permalinks are refreshed (visit **Settings &rarr; Permalinks** once if the links 404).

= Why isn't the media search finding anything? =

Check your API keys under **Reactions > API Connections** — each service has a link to register for one, and MusicBrainz and Open Library need no key. Verify your server can make outbound HTTPS requests. Try different search terms.

= Can I import my existing data? =

Yes. Go to **Reactions > Import** to import from Last.fm, Trakt, Hardcover, and more. Large imports run in the background via WP-Cron, with duplicate prevention on re-imports.

= Are my check-ins private? =

Each check-in has a location privacy level: public (full address and coordinates), approximate (city and region only), or private (no location shown). The published markup redacts location details to match, and you can set a site-wide default in settings.

= What data leaves my site? =

Media lookups and imports contact the services you use them with (MusicBrainz, TMDB, Open Library, and so on — see the Privacy Policy section below). Syndication sends posts to Last.fm, Trakt, or Foursquare only when you enable those toggles. Nothing else is sent.

= Does this work with classic themes or the Classic Editor? =

No. This plugin is built for block themes and the block editor — its card blocks and templates rely on them and won't work as intended on a classic (non-block) theme or in the Classic Editor. If you're on a classic theme or the Classic Editor, use the original [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin instead.

= Where can I read the full documentation? =

Long-form guides — installation, settings, common tasks, troubleshooting, privacy — live at [courtneyr-dev.github.io/post-kinds-for-indieweb](https://courtneyr-dev.github.io/post-kinds-for-indieweb/).

== Screenshots ==

1. Listen Card block displaying a song with album art and rating
2. Watch Card block showing a movie with poster and review
3. Read Card block with book cover and reading progress
4. Checkin Card with location and venue details
5. API configuration settings for external services
6. General settings page with plugin options
7. Block inserter showing all post kind blocks
8. Three published check-ins showing how each privacy level redacts location detail
9. Standard.site record panel on a Bookmark Card, showing the cited page's own title, publication, description, and tags read from AT Protocol

== Changelog ==

= 1.5.0 =
* Fixed: a kind post's microformats2 property (u-like-of, u-listen-of, and the rest) now attaches to an h-entry at the post's own URL — the page webmention receivers actually fetch. Block themes whose single template never calls post_class() (Twenty Twenty-Five, Twenty Twenty-Four) left the card parsing as an orphan citation; the plugin now supplies its own h-entry wrapper there, with the permalink and publish date included, and steps aside on themes that already provide one.
* Fixed: the Stream Card registers with block API version 3 like every other block.
* Tested up to WordPress 7.1, with the CI test matrix now covering 7.0 and 7.1 on PHP 8.2–8.4.
* Added: an Event Card block with h-event microformats. The event's start date renders under the title so themes can feature the event date instead of the publish date.
* Added: the Event Card can pull its details from The Events Calendar or My Calendar at render time — optional, feature-detected, and only publicly viewable events resolve.
* Added: the 12 remaining IndieWeb post kinds — audio, quote, tag, weather, exercise, trip, itinerary, follow, issue, question, sleep, and craft — completing the full 36-kind vocabulary of the classic Post Kinds plugin. Each appears in the kind pickers with its own icon and carries kind-appropriate microformats2 markup.
* Added: read standard.site records from pages you cite. When a bookmarked page publishes its metadata to AT Protocol, the block sidebar can show the author's own title, description, tags, and publication instead of guessing from the page. Read-only and unauthenticated — no account, no setup, and your posts are never published to AT Protocol. A record is only stored on your post once it points back at the page it was found on, so a page cannot claim someone else's writing.
* Added: a firehose RSS feed at /firehose that includes bulk-imported posts.
* Added: a pkiw_kind_label filter so themes can swap each card's visible kind label, and a pk-caption wrapper grouping each card's title, date, and sub lines for styling.
* Fixed: posts without a title no longer disappear from the Stream — the kind label stands in as the linked title, and any kind renders a complete card.
* Fixed: the plugin's abilities (Abilities API) now actually register; their old underscore names were rejected by WordPress core.
* Fixed: a bookmark post built with the Bookmark Card no longer gets an empty Embed block inserted above it in the editor.
* Fixed: no more "doing it wrong" notice when Post Formats for Block Themes also registers the shared block-bindings source.

= 1.0.0 =
* Initial WordPress.org release: 24 post kinds with card blocks, media lookup, imports and webhook scrobbling, microformats2 markup, syndication, and Micropub support. Development history for the pre-release builds lives in CHANGELOG.md in the GitHub repository.
* Security: syndication handlers require the per-post `edit_post` capability, closing an IDOR where a user with generic `edit_posts` could syndicate another user's post.
* Security: Letterboxd lookups use `wp_safe_remote_get` with `reject_unsafe_urls`, so a redirect target can't reach private or loopback hosts.
* Fixed: like, reply, repost, bookmark, favorite, listen, watch, and read posts expose the correct microformats2 markup, so webmention receivers and feed readers recognize them as their kind.

== External services ==

This plugin integrates with external services for media metadata lookups, history imports, scrobbling, and syndication. Every connection is optional: nothing is contacted until you enable a service, save its credentials, use its lookup, or paste one of its links. What follows lists each service, what is sent and when, and its terms and privacy policy.

= Media lookup services (used when you search from the editor, Quick Post, or the Media Lookup block) =

Each lookup sends your search text (a title, artist, venue name, or similar) plus your stored API key or credentials for that service, only at the moment you run a search or refresh metadata.

* **MusicBrainz** — music metadata (albums, artists, recordings). Also sends the app name/contact you configure, per their API etiquette. [Terms](https://metabrainz.org/social-contract), [Privacy](https://metabrainz.org/privacy).
* **TMDB (The Movie Database)** — movie and TV metadata and artwork. [Terms](https://www.themoviedb.org/terms-of-use), [Privacy](https://www.themoviedb.org/privacy-policy). This product uses the TMDB API but is not endorsed or certified by TMDB.
* **TVMaze** — TV show metadata. [Terms](https://www.tvmaze.com/site/tos), [Privacy](https://www.tvmaze.com/site/privacy).
* **Google Books** — book metadata. [Terms](https://developers.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **Open Library (Internet Archive)** — book metadata and covers. [Terms and privacy](https://archive.org/about/terms).
* **Hardcover** — book metadata and reading data. [Terms](https://hardcover.app/pages/terms-of-service), [Privacy](https://hardcover.app/pages/privacy-policy).
* **Podcast Index** — podcast and episode metadata. [Terms](https://podcastindex.org/tos), [Privacy](https://podcastindex.org/privacy).
* **RAWG** — video game metadata. [Terms](https://rawg.io/terms), [Privacy](https://rawg.io/privacy_policy).
* **BoardGameGeek** — board game metadata. [Terms](https://boardgamegeek.com/terms), [Privacy](https://boardgamegeek.com/privacy).
* **Foursquare** — venue search for check-ins (sends your search text and, when you allow it, coordinates to find nearby venues). [Terms](https://foursquare.com/legal/terms), [Privacy](https://foursquare.com/legal/privacy).

= Connected accounts (used for history imports, scheduled sync, scrobbling, and syndication) =

When you connect an account, the plugin stores your token and, on import, scheduled sync, or when you publish a post with that service's syndication toggle on, sends the data needed for that action (your listen/watch/check-in details, plus your token).

* **Last.fm** — listening history import and scrobbling your listen posts. [Terms](https://www.last.fm/legal/terms), [Privacy](https://www.last.fm/legal/privacy).
* **ListenBrainz** — listening history import and scrobble submission. [Terms](https://metabrainz.org/social-contract), [Privacy](https://metabrainz.org/privacy).
* **Trakt** — watch history import, sync, and check-ins. [Terms](https://trakt.tv/terms), [Privacy](https://trakt.tv/privacy).
* **Simkl** — watch history import and sync. [Terms](https://simkl.com/about/terms/), [Privacy](https://simkl.com/about/privacy/).
* **Untappd** — drink check-in import. [Terms](https://untappd.com/terms), [Privacy](https://untappd.com/privacy).
* **Foursquare/Swarm** — check-in import via OAuth. [Terms](https://foursquare.com/legal/terms), [Privacy](https://foursquare.com/legal/privacy).
* **Readwise** — reading highlights import. [Terms](https://readwise.io/tos), [Privacy](https://readwise.io/privacy).

= Geocoding and maps =

* **Nominatim (OpenStreetMap Foundation)** — converts a check-in's coordinates or place text into an address (and back). Sends the location you're checking in to and the contact email you configure, only when you create or edit a check-in that needs geocoding. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy).
* **OpenStreetMap embeds** — when a published check-in shows a map, the visitor's browser loads an embedded map from openstreetmap.org containing that check-in's coordinates. Site visitors' browsers connect to OpenStreetMap when viewing those posts. [Terms](https://wiki.osmfoundation.org/wiki/Terms_of_Use), [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy).

= Link identification =

* **Letterboxd** — when you paste a Letterboxd link into a watch post, the plugin fetches that page once to identify the film. Only the URL you pasted is requested. [Terms](https://letterboxd.com/legal/terms-of-use/), [Privacy](https://letterboxd.com/legal/privacy-policy/).

= Standard.site records on AT Protocol (used when you check a cited URL) =

When you choose "Check this URL" on a bookmark, like, reply, repost, favorite, jam, or wish card, or a few seconds after you publish a post containing one, the plugin looks for a [standard.site](https://standard.site/) record published by the page you cited. Nothing about you or your site is sent, and no account or credential is involved: these are public, unauthenticated reads.

* **The cited page** — requested once to read its `site.standard.document` tag. Only the URL you already linked to is requested, the same as the Letterboxd fetch above. Terms and privacy are whatever that site publishes.
* **plc.directory** — receives the identifier found in that tag and returns the address of the server holding the record. Operated by Bluesky Social PBC. Not contacted for `did:web` identifiers, which name their own host. [Terms](https://bsky.social/about/support/tos), [Privacy](https://bsky.social/about/support/privacy-policy).
* **The author's Personal Data Server** — receives the record's identifier and returns the record. This host is not a fixed service: it is whichever server the author of the page you cited uses, so which hosts are contacted depends on which pages you bookmark. Terms and privacy are whatever that server's operator publishes.

Results are cached for a day, including pages that publish no record, so a page is not re-fetched every time you save. The plugin only reads. It never publishes your posts to AT Protocol.

= Book previews =

* **Amazon Kindle previews** — when you paste an Amazon or Kindle book link into a read post (or a bulk import creates one), the post can embed that book's preview from read.amazon.com. The preview frame is loaded by the editor's and site visitors' browsers directly from Amazon, with only the book's ID in the URL; the plugin itself sends nothing to Amazon. [Conditions of Use](https://www.amazon.com/gp/help/customer/display.html?nodeId=GLSBYFE9MGKKQXXM), [Privacy Notice](https://www.amazon.com/gp/help/customer/display.html?nodeId=GX7NJQ4ZB8MHFRNJ).

= Inbound webhooks (no data sent) =

Plex, Jellyfin, Trakt, ListenBrainz, and OwnTracks scrobbling works through webhooks: those services send data *to* your site at the secret URL you configure. The plugin makes no outbound request to them for this feature.

= AI features =

The optional AI enhancements use the WordPress AI Client bundled with WordPress 7.0+, which routes requests to whatever AI provider the site administrator has configured in WordPress itself. This plugin does not contact any AI service directly and sends nothing unless you invoke an AI action.

== Privacy Policy ==

This plugin:

* Stores all post data locally in your WordPress database — no custom tables
* Makes API calls to external services only when you search for media, paste a media URL, run imports, or receive a webhook
* Does not track users or send analytics
* Stores API keys and OAuth tokens in the WordPress options table; they are removed when you uninstall the plugin
* Sends your activity to Last.fm, Trakt, or Foursquare only when you enable those syndication toggles

External services contacted (when you use the matching feature):

* MusicBrainz / ListenBrainz — music metadata (no API key required)
* Last.fm — music metadata and scrobbling history
* TMDB — movie and TV show metadata
* Trakt / Simkl / TVMaze — movie and TV tracking
* Open Library / Google Books / Hardcover — book metadata
* RAWG / BoardGameGeek — game metadata
* Podcast Index — podcast metadata
* Foursquare — venue information
* OpenStreetMap / Nominatim — geocoding and map data
* Letterboxd — fetched when you paste a Letterboxd URL into a Watch Card, to find the matching movie
* Amazon (read.amazon.com) — Kindle book previews embedded in read posts load in the browser directly from Amazon

Each external service has its own privacy policy. API calls retrieve public metadata for the items you look up.

== Credits ==

* **Author:** [Courtney Robertson](https://courtneyr.dev)
* **Original plugin:** [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/)
* Built for the [IndieWeb](https://indieweb.org/) community
* Uses open data from MusicBrainz, TMDB, Open Library, RAWG, OpenStreetMap, and other services
