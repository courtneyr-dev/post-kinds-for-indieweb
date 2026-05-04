=== Post Kinds for IndieWeb ===
Contributors: courane01
Tags: indieweb, post-kinds, microformats, block-editor, scrobbling
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track what you listen to, watch, read, play, and experience — all from your WordPress site, with full block editor support and IndieWeb microformats.

== Description ==

Post Kinds for IndieWeb is a modern, block-editor successor to David Shanske's [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin.

The original Post Kinds plugin brought IndieWeb interaction types to WordPress, but it was built for the Classic Editor. WordPress has moved on — block themes, the Block Bindings API, and the Interactivity API open new possibilities. This plugin bridges that gap.

= What You Get =

* **16 custom Gutenberg blocks** — not shortcodes or meta boxes
* **API-powered search** — find music, movies, books, and games directly from the editor
* **Bulk import** — pull in your history from Last.fm, Trakt, Hardcover, and more
* **Real-time scrobbling** — webhooks for Plex, Jellyfin, Trakt, and ListenBrainz
* **IndieWeb-first** — proper microformats2 markup on every post

= Post Kinds =

* **Listen** — Music, podcasts, and audio (MusicBrainz, ListenBrainz, Last.fm)
* **Watch** — Movies, TV shows, and videos (TMDB, Trakt, Simkl, TVMaze)
* **Read** — Books and articles (Open Library, Google Books, Hardcover)
* **Checkin** — Places and venues (Foursquare, OpenStreetMap)
* **Play** — Video games and board games (RAWG, BoardGameGeek)
* **Eat / Drink** — Food and beverages
* **Jam** — Music you love, with oEmbed previews
* **RSVP** — Event responses (yes, no, maybe, interested, remote)
* **Like, Reply, Repost, Bookmark** — IndieWeb interactions (works with IndieBlocks)
* **Favorite, Wish, Mood, Acquisition** — Personal tracking

= Custom Blocks =

* **Listen Card** — Album art, artist, track, rating, MusicBrainz lookup
* **Watch Card** — Poster, episode tracking, rewatch indicator, TMDB search
* **Read Card** — Cover image, reading progress bar, Open Library search
* **Checkin Card** — Venue details, OpenStreetMap embed, geo coordinates
* **RSVP Card** — Event response with yes/no/maybe/interested/remote states
* **Play Card** — Game cover art, platform info, RAWG integration
* **Eat Card** — Restaurant and cuisine details
* **Drink Card** — Venue and beverage type
* **Jam Card** — Music you're into, with oEmbed previews
* **Favorite Card** — Favorited content
* **Wish Card** — Wishlist items
* **Mood Card** — Current mood or feeling
* **Acquisition Card** — Items acquired
* **Star Rating** — Stars, hearts, circles, half-star support
* **Media Lookup** — Universal search across all integrated APIs
* **Checkin Dashboard** — Overview of recent check-ins

= Import and Sync =

Pull in your existing data from external services:

* **Bulk import** from ListenBrainz, Last.fm, Trakt, Simkl, Hardcover
* **Webhooks** for Plex, Jellyfin, Trakt, ListenBrainz (real-time scrobbling)
* **Background processing** via WP-Cron for large imports
* **Duplicate prevention** on re-imports

= Microformats =

Every block outputs proper [microformats2](http://microformats.org/wiki/microformats2) markup:

* `h-entry` for posts
* `h-cite` for cited media (listen-of, watch-of, read-of)
* `h-card` for people and artists
* `h-adr` / `h-geo` for locations
* `h-event` for RSVP responses
* `p-rating` for star ratings

Validate your output at [pin13.net/mf2](https://pin13.net/mf2/).

= Related Plugins =

**Recommended (works great together):**

* [IndieBlocks](https://wordpress.org/plugins/indieblocks/) — Core IndieWeb theme blocks (Facepile, Location, Syndication, Link Preview)
* [IndieWeb](https://wordpress.org/plugins/indieweb/) — The IndieWeb plugin suite foundation
* [Webmention](https://wordpress.org/plugins/webmention/) — Cross-site conversations
* [Syndication Links](https://wordpress.org/plugins/syndication-links/) — POSSE workflow support
* [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/) — Post format support in block themes
* [Link Extension for XFN](https://wordpress.org/plugins/link-extension-for-xfn/) — XFN relationship options

**Optional:**

* [ActivityPub](https://wordpress.org/plugins/activitypub/) — Federate with Mastodon and the Fediverse
* [Bookmark Card](https://wordpress.org/plugins/bookmark-card/) — Enhanced bookmark previews

**Conflicts:**

* [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) — This plugin replaces it. Use one or the other, not both.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for **Post Kinds for IndieWeb**
3. Click **Install Now**, then **Activate**

= Manual Installation =

1. Download the plugin from WordPress.org
2. Upload to `/wp-content/plugins/post-kinds-for-indieweb/`
3. Activate through the Plugins menu

= From GitHub =

1. Clone or download from [GitHub](https://github.com/courtneyr-dev/post-kinds-for-indieweb)
2. Upload to your plugins directory
3. Run `composer install` and `npm run build`
4. Activate the plugin

= After Activation =

1. Go to **Settings > Post Kinds for IndieWeb** to configure
2. Enter API keys for the services you want (TMDB, Last.fm, etc.)
3. MusicBrainz and Open Library work without API keys
4. Start creating posts with post kind blocks

== Frequently Asked Questions ==

= Do I need IndieBlocks installed? =

No, but it's recommended. IndieBlocks provides the core blocks for bookmarks, likes, replies, and reposts. Post Kinds for IndieWeb adds complementary post kinds (listen, watch, read, checkin, play, etc.) and enhanced media features.

= How do I get API keys? =

Go to **Settings > Post Kinds for IndieWeb > API Settings**. Each service has a link to register for an API key. Some services (MusicBrainz, Open Library) don't require keys.

= Can I import my existing data? =

Yes. Go to **Tools > Post Kinds Import** to import from Last.fm, Trakt, Hardcover exports, and more. Large imports run in the background via WP-Cron.

= Does this work with the Classic Editor? =

The custom blocks require the block editor (Gutenberg). Basic post kind taxonomy functionality works with the Classic Editor, but with limited UI.

= Can I use this alongside the original Post Kinds plugin? =

No. This plugin replaces the original Post Kinds plugin. Using both will cause conflicts. Deactivate the original before activating this one.

= Is my data private? =

All data is stored on your WordPress site. External API calls only retrieve public metadata (album info, movie details, etc.). Your posts are not shared with external services unless you explicitly syndicate them.

= How do I customize the block appearance? =

Use the block sidebar settings in the editor, Global Styles in the Site Editor, or add custom CSS to your theme. All blocks respect your theme's design tokens.

= Why isn't the media search finding anything? =

Check that your API keys are entered correctly in Settings. Verify your server can make outbound HTTPS requests. Try different search terms. Check **Settings > Post Kinds for IndieWeb > Debug** for API errors.

= How do I validate my microformats? =

Visit [pin13.net/mf2](https://pin13.net/mf2/) and enter your post URL. The tool parses your page and shows all detected microformats markup.

== Screenshots ==

1. Listen Card block displaying a song with album art and rating
2. Watch Card block showing a movie with poster and review
3. Read Card block with book cover and reading progress
4. Checkin Card with location and venue details
5. API configuration settings for external services
6. General settings page with plugin options
7. Block inserter showing all post kind blocks

== Changelog ==

= 1.0.0 =
* Initial release
* 16 custom Gutenberg blocks for post kinds
* Full support for 20+ post kinds
* API integrations: MusicBrainz, TMDB, Open Library, RAWG, and more
* Bulk import from Last.fm, Trakt, Hardcover, ListenBrainz, Simkl
* Webhook support for Plex, Jellyfin, Trakt, ListenBrainz
* Full microformats2 markup on every block
* Admin settings, import tools, and Quick Post interface

== Upgrade Notice ==

= 1.0.0 =
Initial release. Welcome to Post Kinds for IndieWeb!

== Privacy Policy ==

This plugin:

* Stores all post data locally in your WordPress database
* Makes API calls to external services only when you search for media or run imports
* Does not track users or send analytics
* API keys are stored in WordPress options (encrypted where possible)

External services used (when configured):

* MusicBrainz / ListenBrainz — Music metadata (no API key required)
* Last.fm — Music metadata and scrobbling history
* TMDB — Movie and TV show metadata
* Trakt / Simkl / TVMaze — Movie and TV tracking
* Open Library / Google Books / Hardcover — Book metadata
* RAWG / BoardGameGeek — Game metadata
* Podcast Index — Podcast metadata
* Foursquare — Venue information
* OpenStreetMap / Nominatim — Geocoding and map data

Each external service has its own privacy policy. API calls only retrieve public metadata.

== Credits ==

* **Author:** [Courtney Robertson](https://courtneyr.dev)
* **Original Plugin:** [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) by David Shanske
* Built for the [IndieWeb](https://indieweb.org/) community
* Uses open data from MusicBrainz, TMDB, Open Library, RAWG, OpenStreetMap, and other services
