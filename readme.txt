=== Post Kinds for IndieWeb in Block Themes ===
Contributors: courane01
Tags: indieweb, post-kinds, microformats, block-editor, scrobbling
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track what you listen to, watch, read, play, and experience — all from your WordPress site, with full block editor support and IndieWeb microformats.

== Description ==

Post Kinds for IndieWeb in Block Themes is a block-editor successor to the classic [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin.

A post kind is a label that describes what a post *is* — a note, a check-in, a song you listened to, a movie you watched — rather than what it's about. The idea comes from the [IndieWeb](https://indieweb.org/) movement: publish your activity on your own site instead of (or in addition to) social networks and tracking apps.

The original Post Kinds plugin was built for the Classic Editor. This plugin is built for the block editor and block themes.

= What you get =

* **25 custom blocks** — 22 editor blocks (card blocks for most kinds, plus utilities like Star Rating, Media Lookup, Check-in Dashboard, Check-ins Feed, and Venue Detail) and 3 server-rendered blocks (Now Playing, Media Stats, Recent Kinds)
* **API-powered search** — find music, movies, books, games, and venues directly from the editor
* **Bulk import** — pull in your history from Last.fm, Trakt, Hardcover, and more
* **Real-time scrobbling** — webhooks for Plex, Jellyfin, Trakt, and ListenBrainz. Scrobbling means automatically logging each song or show as you play it.
* **microformats2 markup** on every post. Microformats are standard HTML classes that let other IndieWeb sites and tools read your posts as structured data — a listen, an RSVP, a check-in — instead of plain text.

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
3. Upload to `/wp-content/plugins/post-kinds-for-indieweb/` and activate

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

= How do I post from my phone or a Micropub app? =

Install the separate [Micropub](https://wordpress.org/plugins/micropub/) plugin and an IndieAuth setup (for example the [IndieAuth](https://wordpress.org/plugins/indieauth/) plugin). Micropub is a standard API that lets mobile and third-party apps publish to your site. Once it's active, this plugin converts incoming Micropub posts into the right card block and assigns the kind.

= Why isn't the media search finding anything? =

Check your API keys under **Reactions > API Connections** — each service has a link to register for one, and MusicBrainz and Open Library need no key. Verify your server can make outbound HTTPS requests. Try different search terms.

= Can I import my existing data? =

Yes. Go to **Reactions > Import** to import from Last.fm, Trakt, Hardcover, and more. Large imports run in the background via WP-Cron, with duplicate prevention on re-imports.

= Are my check-ins private? =

Each check-in has a location privacy level: public (full address and coordinates), approximate (city and region only), or private (no location shown). The published markup redacts location details to match, and you can set a site-wide default in settings.

= What data leaves my site? =

Media lookups and imports contact the services you use them with (MusicBrainz, TMDB, Open Library, and so on — see the Privacy Policy section below). Syndication sends posts to Last.fm, Trakt, or Foursquare only when you enable those toggles. Nothing else is sent.

= Does this work with the Classic Editor? =

The custom blocks require the block editor. With the Classic Editor you can still assign kinds through the taxonomy box, but with limited UI and no card blocks.

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

== Changelog ==

= 1.0.1 =
* Security: syndication handlers now require the per-post `edit_post` capability, closing an IDOR where a user with generic `edit_posts` could syndicate another user's post.
* Security: Letterboxd lookups use `wp_safe_remote_get` with `reject_unsafe_urls`, so a redirect target can't reach private or loopback hosts.
* Fixed: like, reply, repost, bookmark, favorite, listen, watch, and read posts now expose the correct microformats2 markup, so webmention receivers and feed readers recognize them as their kind.

= 1.0.0 =
* Initial WordPress.org release: 24 post kinds with card blocks, media lookup, imports and webhook scrobbling, microformats2 markup, syndication, and Micropub support. Development history for the pre-release builds lives in CHANGELOG.md in the GitHub repository.

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
