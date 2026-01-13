=== Post Kinds for IndieWeb and Block Themes ===
Contributors: courane01
Tags: indieweb, post-kinds, scrobbling, microformats, block-editor
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern block editor support for IndieWeb post kinds and microformats. A successor to the classic IndieWeb Post Kinds plugin.

== Description ==

Post Kinds for IndieWeb and Block Themes is a **modern, block-editor compatible successor** to David Shanske's [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin. It brings the same post kinds functionality to the modern WordPress block editor, with enhanced media import and syndication capabilities.

Track what you're listening to, watching, reading, and where you've been—all on your own website with full Gutenberg support.

= Features =

**Post Kinds**
* Listen - Share music, podcasts, and audio you're enjoying
* Watch - Log movies, TV shows, and videos
* Read - Track books with reading progress
* Checkin - Share locations you've visited
* Play - Track games you're playing
* Eat/Drink - Log food and beverages
* RSVP - Respond to events
* And more: Jam, Favorite, Wish, Mood, Acquisition

**Custom Blocks**
* Listen Card - Beautiful music display with album art
* Watch Card - Movie posters with episode tracking
* Read Card - Book covers with progress bar
* Checkin Card - Location with venue details
* RSVP Card - Event response display
* Play Card - Game cards with platform info
* Eat/Drink Cards - Food and beverage logging
* Star Rating - Standalone rating component
* Media Lookup - Search and embed media info

**External Integrations**
* Music: MusicBrainz, Last.fm, ListenBrainz
* Movies/TV: TMDB, Trakt, TVMaze, Simkl
* Books: Open Library, Google Books, Hardcover
* Games: RAWG, BoardGameGeek, VideoGameGeek
* Podcasts: Podcast Index, Readwise Snipd imports
* Locations: Foursquare, OpenStreetMap
* Other: Readwise


**IndieWeb Features**
* Full microformats2 markup (h-entry, h-cite, h-card, etc.)
* Compatible with Webmention plugins
* Supports POSSE workflows
* Works with IndieBlocks blocks

= About This Plugin =

This plugin is a modern, block-editor-compatible successor to David Shanske's IndieWeb Post Kinds plugin. It maintains compatibility with the same post kind concepts and IndieWeb microformats while adding full Gutenberg support.

**Why a new plugin?**
* The original Post Kinds plugin is incompatible with the Block Editor
* WordPress has evolved—block themes and the Block Bindings API offer new possibilities
* Modern APIs and import capabilities enhance the experience

= Related Plugins =

**Recommended:**

* [IndieBlocks](https://wordpress.org/plugins/indieblocks/) - Core IndieWeb blocks for bookmarks, likes, replies, reposts, and context
* [Syndication Links](https://wordpress.org/plugins/syndication-links/) - Stores and displays syndication URLs for POSSE workflows
* [Webmention](https://wordpress.org/plugins/webmention/) - Cross-site conversations and notifications

**Optional:**

* [ActivityPub](https://wordpress.org/plugins/activitypub/) - Federation with Mastodon and the Fediverse
* [Bookmark Card](https://wordpress.org/plugins/bookmark-card/) - Enhanced bookmark previews

**Conflicts:**

* [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) - This plugin is the block editor successor to Post Kinds. Use one or the other, not both.

= Requirements =

* WordPress 6.5 or higher
* PHP 8.0 or higher
* IndieBlocks plugin (recommended but not required)

= Getting Started =

1. Install and activate the plugin
2. Optionally install IndieBlocks for additional blocks
3. Configure API keys in Settings → Post Kinds for IndieWeb
4. Start creating posts with post kind blocks!

= Documentation =

* [GitHub Repository](https://github.com/developer-advocacy/post-kinds-for-indieweb)
* [IndieWeb Wiki](https://indieweb.org/)
* [IndieBlocks](https://wordpress.org/plugins/indieblocks/)

== Installation ==

= Automatic Installation =

1. Go to Plugins → Add New in your WordPress admin
2. Search for "Post Kinds for IndieWeb"
3. Click Install Now, then Activate

= Manual Installation =

1. Download the plugin from WordPress.org
2. Upload to `/wp-content/plugins/post-kinds-for-indieweb/`
3. Activate through the Plugins menu

= From GitHub =

1. Download the latest release from GitHub
2. Upload to your plugins directory
3. Run `composer install` and `npm run build`
4. Activate the plugin

== Frequently Asked Questions ==

= Do I need IndieBlocks installed? =

No, but it's recommended. IndieBlocks provides core blocks for bookmarks, likes, replies, and reposts. Post Kinds for IndieWeb adds complementary post kinds (listen, watch, read, etc.).

= How do I get API keys? =

Go to Settings → Post Kinds for IndieWeb → API Settings. Each service has a link to get your API key. Some services (MusicBrainz, Open Library) don't require keys.

= Can I import my existing data? =

Yes! Go to Tools → Post Kinds Import to import from Last.fm, Trakt, Goodreads exports, and more.

= Does this work with the Classic Editor? =

The custom blocks require the block editor. Basic post kind functionality works with Classic Editor but with limited UI.

= How do I customize the block appearance? =

Use the block sidebar settings in the editor, Global Styles in the Site Editor, or add custom CSS to your theme.

= Is my data private? =

All data is stored on your WordPress site. External API calls only retrieve public metadata; your posts are not shared with external services unless you explicitly syndicate them.

= Can I use this alongside the original Post Kinds plugin? =

No. This plugin is designed as a replacement for the original Post Kinds plugin. Using both will cause conflicts. Deactivate the original Post Kinds plugin before activating this one.

== Screenshots ==

1. Listen Card block displaying a song with album art and rating
2. Watch Card block showing a movie with poster and review
3. Read Card block with book cover and reading progress
4. Checkin Card with location and venue details
5. Settings page with API configuration
6. Block inserter showing post kind blocks

== Changelog ==

= 1.0.0 =
* Initial release
* Modern block editor successor to IndieWeb Post Kinds
* Added 16 custom Gutenberg blocks
* Full support for 20+ post kinds
* Integrated with MusicBrainz, TMDB, Open Library, RAWG, and more
* Full microformats2 support
* Admin settings and import tools

== Upgrade Notice ==

= 1.0.0 =
Initial release. Welcome to Post Kinds for IndieWeb and Block Themes!

== Privacy Policy ==

This plugin:
* Stores all post data locally in your WordPress database
* Makes API calls to external services only when you search for media
* Does not track users or send analytics
* API keys are stored securely in WordPress options

External services used (when configured):
* MusicBrainz/ListenBrainz - Music metadata
* Last.fm - Music metadata and scrobbling
* TMDB - Movie and TV metadata
* Trakt/Simkl/TVMaze - Movie and TV tracking
* Open Library/Google Books - Book metadata
* RAWG/BoardGameGeek - Game metadata
* Podcast Index - Podcast metadata
* Foursquare - Venue information
* OpenStreetMap - Geocoding and map data

Each external service has its own privacy policy. API calls only retrieve public metadata.

== Credits ==

* **Author**: Courtney Robertson (https://courtneyr.dev)
* **Original Plugin**: IndieWeb Post Kinds by David Shanske
* Built to extend IndieBlocks
* Made with love for the IndieWeb community
