=== Post Kinds for IndieWeb ===
Contributors: courane01
Tags: indieweb, post-kinds, microformats, block-editor, scrobbling
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track what you listen to, watch, read, play, and experience — all from your WordPress site, with full block editor support and IndieWeb microformats.

== Description ==

Post Kinds for IndieWeb is a block-editor successor to David Shanske's [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin.

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
2. Search for **Post Kinds for IndieWeb**
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

= 1.4.3 =
* Fixed: front-end card padding and shadow were stripped by an unscoped editor-parity rule (1.4.1) that also applied on the front end. Scoped it to the editor canvas so published cards keep their spacing and shadow.

= 1.4.2 =
* Default category (stream) now applies only to stream-shaped posts — those posted via Micropub, with a Status/Aside format, or carrying an activity kind. New `pkiw_default_category_stream_kinds` filter.

= 1.4.1 =
* Editor card parity fix: reverted the 1.4.0 grid layout to paint-only so the existing layout stands and theme tokens recolor the badge, title, artist, and rating.

= 1.4.0 =
* Editor card parity: every kind-card block now reads like its published card in the block editor. Plugin owns the structure, theme owns the paint.

= 1.3.0 =
* Stream card: every item now renders as a compact card (kind badge, linked title, date, featured image, excerpt)
* Post-surface classification: a `pkiw_stream_kinds` filter marks kinds as stream vs main, with a `pkiw_promote` per-post override
* Fixed: card embeds no longer sit inside a "paper" box; editor icons stay legible in List View
* Removed: duplicate registration of the format-badge block (owned by Post Formats for Block Themes)

= 1.2.0 =
* Book fields are now bindable kind-meta keys; book completion from Open Library, Google Books, or Hardcover; opt-in Kindle preview embed
* Micropub coverage for likes, reposts, bookmarks, and replies; editor saves set the kind term from the first card block
* Fixed: Eat Card restaurant and Play Card Steam ID display; Mood/Play Card value wipes; dropped Micropub fields; live oEmbed requests during render
* Security: hardened Amazon hostname detection and the book-complete REST route

= 1.1.0 =
* Fixed: contentless Micropub posts (eat, drink, follow, weather) now create posts; attached photos are included
* All block colors flow through the `--pkiw-*` design-token API
* WordPress 7.0 is now the minimum supported version (previously 6.9)

= 1.0.4 =
* Fix site-wide PHP fatal alongside block themes that resolve hooked blocks during init (e.g. Ollie)

= 1.0.0 =
* Initial release: post kind blocks, API integrations, bulk import, webhooks, microformats2 markup, admin settings

Full version history: [CHANGELOG.md](https://github.com/courtneyr-dev/post-kinds-for-indieweb/blob/main/CHANGELOG.md)

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

Each external service has its own privacy policy. API calls retrieve public metadata for the items you look up.

== Credits ==

* **Author:** [Courtney Robertson](https://courtneyr.dev)
* **Original plugin:** [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) by David Shanske
* Built for the [IndieWeb](https://indieweb.org/) community
* Uses open data from MusicBrainz, TMDB, Open Library, RAWG, OpenStreetMap, and other services
