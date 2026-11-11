=== Post Kinds for IndieWeb in Block Themes ===
Contributors: courane01
Tags: indieweb, post-kinds, microformats, block-editor, scrobbling
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track what you listen to, watch, read, play, and experience — all from your WordPress site, with full block editor support and IndieWeb microformats.

== Description ==

Post Kinds for IndieWeb in Block Themes is a block-editor successor to the classic [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin.

A post kind is a label that describes what a post *is* — a note, a check-in, a song you listened to, a movie you watched — rather than what it's about. The idea comes from the [IndieWeb](https://indieweb.org/) movement: publish your activity on your own site instead of (or in addition to) social networks and tracking apps.

The original Post Kinds plugin was built for the Classic Editor. This plugin is built for the block editor and block themes.

= What you get =

* **67 custom blocks** — card blocks for most kinds, blocks that build kind archives, utilities (Star Rating, Media Lookup, Check-in Dashboard, Check-ins Feed, Venue Detail), three server-rendered blocks (Now Playing, Media Stats, Recent Kinds), and the Stream Card, which is registered in PHP, hidden from the inserter, and stands in for Post Content inside the Stream query loop
* **API-powered search** — find music, movies, books, games, and venues directly from the editor
* **Bulk import** — pull in your history from Last.fm, Trakt, Hardcover, and more
* **Real-time scrobbling** — webhooks for Plex, Jellyfin, Trakt, and ListenBrainz. Scrobbling means automatically logging each song or show as you play it.
* **microformats2 markup** on every post. Microformats are standard HTML classes that let other IndieWeb sites and tools read your posts as structured data — a listen, an RSVP, a check-in — instead of plain text.
* **Automatic featured images** — album art, posters, and book covers from your media posts become the featured image when a post has none.
* **A firehose feed** at /firehose — your site's RSS plus the bulk-imported posts that normal feeds leave out.
* **Standard.site records** — when a page you bookmark publishes its metadata to AT Protocol, read the author's own title, description, and tags instead of guessing from the page. No account or setup needed.
* **Standard.site publishing** — add the optional [ATmosphere](https://wordpress.org/plugins/atmosphere/) companion plugin (2.1.0+) and your own posts publish as Standard.site documents on AT Protocol. Post Kinds adds what ATmosphere can't know: which kinds publish by default, readable titles for untitled posts ("Listened to … by …", "Checked in at …"), the kind as a tag, and check-in privacy rules. Everything else in Post Kinds works without it.

= Post kinds =

* **Listen** — music, podcasts, and audio (MusicBrainz, ListenBrainz, Last.fm)
* **Watch** — movies, TV shows, and videos (TMDB, Trakt, Simkl, TVMaze)
* **Read** — books and articles (Open Library, Google Books, Hardcover)
* **Checkin** — places and venues (Foursquare, OpenStreetMap), with per-post location privacy (public, approximate, or private)
* **Play** — video games and board games (RAWG, BoardGameGeek)
* **Eat / Drink** — food and beverages
* **Jam** — music you love, with oEmbed previews
* **RSVP** — event responses (yes, no, maybe, interested, remote)
* **Event** — events with date, time, and location; the Event Card can pull details from The Events Calendar or My Calendar
* **Like, Reply, Repost, Bookmark** — IndieWeb interactions
* **Favorite, Wish, Mood, Acquisition** — personal tracking
* **Follow, Tag, Quote, Issue, Question** — more IndieWeb response types
* **Audio, Weather, Exercise, Sleep, Trip, Itinerary, Craft** — logs and experimental kinds
* **Chicken, Comics, Collection, Presentation** — a flock log, comics, collections, and talks

Along with Note, Article, Photo, Video, Review, and Recipe, that's 40 kinds. Kinds without a dedicated card block still render as cards on the Stream and carry correct microformats2 markup.

Each kind gets its own archive page (for example `/kind/listen/`), and the kind is set automatically from the first card block in a post — or pick it yourself in the Post Kind editor panel.

= Import and sync =

* **Bulk import** from ListenBrainz, Last.fm, Trakt, Simkl, and Hardcover
* **Webhooks** for Plex, Jellyfin, Trakt, and ListenBrainz
* **Background processing** via WP-Cron for large imports, with duplicate prevention on re-imports
* **Optional syndication (POSSE)** — post on your own site first, then send a copy to Last.fm, Trakt, or Foursquare. Off by default; nothing is sent unless you enable it.

= Works with =

* [Micropub](https://wordpress.org/plugins/micropub/) — **required for posting from Micropub apps.** This plugin doesn't implement the Micropub endpoint itself; install the Micropub plugin (plus [IndieAuth](https://wordpress.org/plugins/indieauth/) for authentication), and this plugin classifies every incoming post as its kind — all registered kinds, not just the common ones — and converts it into the right card block or microformats paragraph. Clients that know their kind can name it outright with a `pkiw-kind` property; [Outpost](https://github.com/courtneyr-dev/outpost) does.
* [IndieBlocks](https://wordpress.org/plugins/indieblocks/) — detected and recommended, not required. It provides companion blocks (Facepile, Location, Syndication, Link Preview); this plugin doesn't implement those features itself.
* [Webmention](https://wordpress.org/plugins/webmention/) — detected on the Integrations settings tab. Cross-site conversations come from that plugin, not this one.
* [Syndication Links](https://wordpress.org/plugins/syndication-links/), [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/), [Bookmark Card](https://wordpress.org/plugins/bookmark-card/) — detected and enhanced when present.
* [ATmosphere](https://wordpress.org/plugins/atmosphere/) — optional companion (2.1.0+) for Standard.site publishing on AT Protocol. ATmosphere owns the connection and the records; this plugin decides which kinds publish, derives readable titles for untitled posts, and applies check-in privacy rules. Everything else works without it.
* [Link Extension for XFN](https://wordpress.org/plugins/link-extension-for-xfn/) — detected on the Integrations settings tab. Adds XFN relationship options to the block editor's link popover, so a reply or mention can say how you know the person.
* [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) and [My Calendar](https://wordpress.org/plugins/my-calendar/) — either one feeds the Event card. Event name, start, end, location, and URL are read from whichever is installed; with neither, the card falls back to its own fields.
* [WP Recipe Maker](https://wordpress.org/plugins/wp-recipe-maker/) — a post containing a WPRM recipe is detected, and the recipe kind is suggested for it.
* [ActivityPub](https://wordpress.org/plugins/activitypub/) — a recommendation only; this plugin contains no ActivityPub integration.
* [RSS Chat](https://github.com/pfefferle/wordpress-rss-chat) — a recommendation only; this plugin contains no rss.chat integration, and RSS Chat is on GitHub rather than WordPress.org. It syndicates posts with the core **chat** post format, not kinds. A kind-aware check is open upstream: [issue #6](https://github.com/pfefferle/wordpress-rss-chat/issues/6).

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

The panel itself only reads — checking a URL sends nothing about you or your site. Publishing your own posts is separate and optional: install the [ATmosphere](https://wordpress.org/plugins/atmosphere/) companion plugin, connect an AT Protocol account there, and eligible posts publish as Standard.site documents when you publish them. Without ATmosphere, or until you connect, nothing is ever sent.

= Which posts publish to Standard.site? =

Public content and public logs publish by default once ATmosphere is connected — notes, articles, photos, videos, reviews, recipes, events, and your listens, watches, reads, plays, eats, drinks, and jams, plus replies, bookmarks, and RSVPs. Thin signals (likes, reposts, favorites, follows) and privacy-sensitive kinds (check-ins, moods, wishes, acquisitions, and the health and travel logs) stay off by default; turn any kind on under Reactions → Settings → Integrations, or per post with ATmosphere's sharing toggle in the editor, which always has the final say. Posts published before you change a setting are never removed by it.

= Do my posts also go to Bluesky? =

That's ATmosphere's choice, not this plugin's: Bluesky cross-posting follows ATmosphere's own settings unchanged. Standard.site documents and Bluesky posts are related but separate — see the plugin documentation for publishing documents without Bluesky companions.

= How do I post from my phone or a Micropub app? =

Install the separate [Micropub](https://wordpress.org/plugins/micropub/) plugin and an IndieAuth setup (for example the [IndieAuth](https://wordpress.org/plugins/indieauth/) plugin). Micropub is a standard API that lets mobile and third-party apps publish to your site. Once it's active, this plugin assigns the kind and builds the content for every incoming Micropub post — each registered kind is reachable, whether the client sends the kind's canonical property or names the kind directly with the `pkiw-kind` property.

For one-tap posting and bookmarklet-style sharing from any page or your phone's share sheet, [Outpost](https://github.com/courtneyr-dev/outpost) is a companion progressive web app that posts to your site over Micropub. This plugin doesn't ship its own bookmarklet — Micropub and Outpost cover that.

= Can I browse or subscribe to one kind at a time? =

Yes — every kind gets its own archive and RSS feed automatically. Visit `/kind/listen/` (swap `listen` for any kind) to see all posts of that kind, and `/kind/listen/feed/` — or `/feed/?kind=listen` — to subscribe to just that kind in a feed reader. This works as soon as the plugin is active and your permalinks are refreshed (visit **Settings &rarr; Permalinks** once if the links 404).

= Why isn't the media search finding anything? =

Check your API keys under **Reactions > API Connections** — each service has a link to register for one, and MusicBrainz and Open Library need no key. Verify your server can make outbound HTTPS requests. Try different search terms.

= Can I import my existing data? =

Yes. Go to **Reactions > Import** to import from Last.fm, Trakt, Hardcover, and more. Large imports run in the background via WP-Cron, with duplicate prevention on re-imports.

= Are my check-ins private? =

Each check-in has a location privacy level: public (full address, coordinates, and map pin), approximate (venue name, city, region, and country, with no street address, postal code, coordinates, or map pin), or private (no location shown). The published markup and the REST API redact location details to match, and you can set a site-wide default under **Reactions → Settings → Checkin**. Signed-in editors see a check-in's location on the front end the way a visitor does; admin screens and the block editor show them the full location.

= What data leaves my site? =

Media lookups and imports contact the services you use them with (MusicBrainz, TMDB, Open Library, and so on — see the Privacy Policy section below). Syndication sends posts to Last.fm, Trakt, or Foursquare only when you enable those toggles. On pages that show a check-in map, visitors' browsers load the map tiles or the embedded map from OpenStreetMap. The External services section below lists every service, what it receives, and when.

= Does this work with classic themes or the Classic Editor? =

No. This plugin is built for block themes and the block editor — its card blocks and templates rely on them and won't work as intended on a classic (non-block) theme or in the Classic Editor. If you're on a classic theme or the Classic Editor, use the original [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin instead.

= Can mood labels use American or British spelling? =

Yes. On the General tab of Reactions → Settings, "Mood label spelling" follows your Site Language by default, or you can choose English (United States) or English (United Kingdom). It changes the mood suggestions and the mood names Post Kinds generates. Labels you type yourself, notes and saved posts stay exactly as written.

= Where can I read the full documentation? =

Long-form guides — installation, settings, common tasks, troubleshooting, privacy — live at [courtneyr-dev.github.io/post-kinds-for-indieweb](https://courtneyr-dev.github.io/post-kinds-for-indieweb/).

== Screenshots ==

1. Listen Card block displaying a song with album art and rating
2. Watch Card block showing a movie with poster and review
3. Read Card block with book cover and reading progress
4. Checkin Card with location and venue details
5. API Connections screen with Last.fm, TMDB, and Trakt, where Trakt connects with a Client ID and no client secret
6. General tab of Reactions → Settings with default category, mood label spelling, and plugin options
7. Block inserter showing all post kind blocks
8. Three published check-ins: public shows the address and a map pin, approximate shows only the venue, city, region, and country, and private shows no location
9. Standard.site record panel on a Bookmark Card, showing the cited page's own title, publication, description, and tags read from AT Protocol

== Changelog ==

= 1.9.1 =
* Fixed: Trakt stays connected past 7 days. A twice-daily job refreshes the token before it expires, and the Refresh Token button refreshes on demand. If Trakt rejects the refresh, PKIW disconnects Trakt and API Connections asks you to Connect again.
* Fixed: saving API Connections no longer writes back a spent Trakt token, and tokens no longer appear in the page source.
* Fixed: the Check-in Card's privacy panel describes Approximate correctly: venue name, city, region and country, with no street address, coordinates or map. It had said Approximate showed the exact location.
* Fixed: presentation archive items carry one h-entry instead of two.

= 1.9.0 =
* Added: RSVP archive at /kind/rsvp/ with an "Attending Remotely" status and two abilities; an event location prints only when the card and its stored privacy are both public.
* Added: presentations archive grouped by year, a record block on the single, and `wp post-kinds reclassify-presentations` (dry run by default).
* Added: trip and itinerary cards with per-stop privacy and date embargo, a map, and year-grouped archives at /kind/trip/ and /kind/itinerary/.
* Added: event calendar block and archive template. Requires the My Calendar plugin; two settings show the calendar and hide every event location.
* Added: comic lookup from Metron (add a token in Settings > APIs), with credits and a sideloaded cover.
* Added: watch posters retry at 1, 6 and 24 hours under Sideload; `wp postkind featured-artwork retry` runs one by hand.
* Added: the Stream card picks a photo for posts without one. Remove the `cr-stream-card-photo.php` mu-plugin if you run it.
* Added: block examples for the inserter and seven Query Loop variations for kind archives.
* Changed: RSVP Stream cards omit the location; the event card shows public My Calendar events only; watch posters follow Image Handling.
* Fixed: an unknown event ID no longer resolves to another event; classic sleep posts keep their paragraphs.
* Security: watch poster fetches pass an SSRF guard, and the API settings page masks every password field.

= 1.8.9 =
* Fixed: Trakt connects again. Its developer portal now rejects redirect URIs with a query string and issues PKCE-only apps with no client secret, so the Trakt redirect URI is the bare wp-admin/admin-post.php and Connect sends an S256 PKCE challenge with no secret.

= 1.8.8 =
* Fixed: saving Settings → API Connections keeps the BoardGameGeek API token and the RAWG API key. Every save had discarded both, so the game lookup answered "BoardGameGeek API is not configured". The Test button works for both providers too.

= 1.8.7 =
Added
* Archives for most kinds, each grouped to suit it: play (Staff Picks, video games, board games), read (shelves by status, A–Z by author), review (sections by category, Four Stars), reply and like (by cited site), bookmark (tag drawers), photo, article, repost, favorite, acquisition and chicken (by month), mood (year grid), weather (almanac month), exercise (weekly log, medal row), sleep (monthly sheet with moon phase), craft (by craft type), note (board), video (by year), audio and jam (month logs), listen (podcast view), wish, question, quote, comics, collection, issue, recipe, and eat and drink menus.
* Blocks for those archives and cards: Archive sections, Staff Picks, Four Stars, Read order, Reading time, Recent Specials, Kind menu entry, Recipe courses, Mood year grid, Almanac month, Medal row, Sleep night, Steps and Step, Listen types, Podcast episode, Comic Card and Comic series, Collection item, Quote entry, and the Exercise, Sleep and Craft cards.
* A check-in archive as a map and a list, through an archive mode on the Check-ins Feed block.
* A board game play card prints a tabletop with facts, links and a score pad. Micropub builds Play, Acquisition, Exercise, Sleep and Comic cards from their properties, and a play-of on a boardgamegeek.com page sets the BGG ID.
* Reply, like and bookmark cards copy their citation into post meta, Stream cards carry a hidden citation, and review, reply and bookmark singles carry microformats2.
* A photo carousel, a pre-publish warning for photos with empty alt text, an audio transcript notice, and the `wp postkind listen-media-type backfill` command.
* A post with a slide deck or talk recording gets the presentation kind on save. The read abilities work over MCP without WP Pinch, and operations such as the card meta backfill run as dry-run abilities.
Changed
* Location privacy has one rule for every post. An unset choice means approximate: venue name, city, region and country, with no street address, postal code, coordinates, map or pin. A hidden location prints the same markup as no location.
* A logged-in editor on the front end sees a check-in's location the way a visitor does, in cards, feeds, the dashboard, the map, archives and the weather line. Admin screens and the block editor still show the full location.
* An RSVP's event location and an acquisition card's cost are private until you turn on "Show event location publicly" or "Show cost publicly". Cost never prints on the Stream or an archive.
* Venue archives live at /venues/<slug>/ because Simple Location's venue post type owns /venue/. An old /venue/<slug>/ link that would 404 now redirects. Upgraded sites rebuild their rewrite rules once.
* The Coordinate Handling setting is gone because nothing read it. A drink saved without a type no longer defaults to Coffee. Every play card is the entry's u-play-of h-cite. The card meta backfill runs once more, at version 9.
Fixed (location privacy)
* Micropub q=source hides card values a visitor couldn't see, a Micropub check-in takes its privacy from the request, and a card's privacy holds wherever the card sits in the post.
* Visitor search no longer matches hidden locations or location-derived titles, and a hidden location's time zone offset stays out of the post's dates. A private check-in's slug, venue term and drink brand name no venue.
* Simple Location's REST fields, head tags, GeoRSS and JSON Feed geo follow the post's privacy, and its read-location capabilities are denied on the front end. Chicken, exercise, sleep, weather, question and photo posts hide its map and address unless the location is public.
* The Default Location Privacy choice saves, and check-ins at latitude or longitude 0 keep their coordinates.
* Changing a post's privacy after ATmosphere shared it queues the record update even when ATmosphere's auto-publish is off.
Fixed (other)
* Each Check-in Dashboard draws its own map, and its Grid, Map and Timeline buttons run because its map scripts ship in the plugin. The Venue Detail and Check-ins Feed venue pickers list your venues again, and the venue fields save, with a nonce and an edit_term check.
* A listen card prints a media library cover with core's srcset and sizes. A play card switched to a board game drops the old video game ID. A watch card prints the downloaded poster. A Micropub photo shows once.
* Foursquare imports keep the venue, date and postal code, Untappd check-in dates store as UTC, OwnTracks check-ins get an author and local date, and the Untappd sync no longer stops with a fatal error. Bookmark, check-in and note imports no longer duplicate on every scheduled run.
* The translation template uses the plugin's real text domain, and a mood card's intensity of 3 is no longer confused with no rating.
Security
* A password-protected post's kind data no longer reaches REST, abilities, the check-in routes, bindings, the map or the dashboard for someone who can't edit it, and a search can't confirm a hidden distance, bedtime or cost with an exact-phrase guess.
* Plex artwork downloads on the server, and the Plex token is never stored in a post or printed in a URL.

= 1.8.6 =
* Added: checkin-dashboard and checkins-feed gained a headingLevel attribute (default 2, clamped 2–4), matching the Stream card block.
* Changed: checkin-dashboard's and checkins-feed's headings default to h2 instead of the previous hardcoded h3, unless a site sets headingLevel.
* Changed: .pk-meta links are underlined, not color-only.
* Fixed: a stray block of prose outside any CSS comment in kind-tokens.css, which broke parsing from that point on.
* Fixed: dark-mode card colors now also follow a site theme's own dark-mode switch (data-theme="dark"), not only the OS-level prefers-color-scheme.
* Fixed: every front-end link that opens in a new tab now tells screen reader users so, including the media lookup block.
* Fixed: check-in photo alt text, poster/cover fallback alt text, sideloaded artwork alt text, and check-in dashboard grid photo alt text all describe what's shown instead of repeating a title or saying nothing.
* Fixed: the kind label badge is a span, not a paragraph, and the decorative pushpin emoji is gone from the check-in card pattern.
* Fixed: Stream cards rendered from a card block or a long-form watch post now honor the Stream's heading level, matching the generic card branch.
* Fixed: star-rating SVGs, the dashicons-lock icon on private check-ins, and the Stream card's hidden microformats fallback markup all use accessibility-checker-clean patterns instead of redundant ARIA, an unenqueued icon font, and empty aria-hidden elements.
* Fixed: check-in dashboard view buttons report their pressed state, inactive panels are properly hidden, the map is a landmark region, and map popup links name the venue and escape it and the permalink before inserting them.
* Fixed: checkins-feed thumbnail links no longer duplicate the adjacent title link in the tab order.
* Fixed: star-rating's accessible name no longer relies on an aria-label with no defined ARIA mapping.
* Fixed: the Stream card's generic-branch featured image gets real alt text instead of alt="".

= 1.8.5 =
* Added: the Stream card block's headingLevel attribute (default 2, clamped 2–4) lets the generic card's title tag match a theme's heading outline; per-kind cards still render an h2.
* Changed: the /webhook/generic route reads its secret from the X-Webhook-Token header only; it no longer accepts a ?token= query string. Query strings can show up in CDN and access logs.
* Changed: generic webhook callers that used the query string now get a 401 — send the same secret in an X-Webhook-Token header instead. GET /wp-json/post-kinds-indieweb/v1/settings/webhooks (requires manage_options) returns it. Plex's own webhook URL is unchanged.
* Changed: OwnTracks enabled with an empty username or password now refuses every check-in with a 403 instead of accepting them, and compares credentials in constant time.
* Changed: .pk-kindlabel and .pk-link font sizes increased to 0.75rem and 0.875rem for legibility.
* Fixed: Stream card captions keep their credit links instead of printing markup, and swapped thumbnail captions show plain text.
* Fixed: Quick Post and scrobble approval only publish a post or mark it private for users with the publish_posts capability; everyone else gets a pending post.
* Security: abilities write only registered, scalar Post Kinds meta, the get-post-meta ability returns only registered fields with the same location redaction as REST, and lookup results render as text with provider strings stripped on the server.

= 1.8.4 =
* Fixed: check-ins and posts with a venue show their location to visitors unless the author marks it private. Simple Location's "Protected" setting hides coordinates and the map but keeps the place name and address.
* Fixed: Simple Location and IndieBlocks location fields in the REST API follow the same visibility rule as Post Kinds' own fields, so a geotagged note without a venue no longer returns exact coordinates to visitors.
* Fixed: eat and drink cards save their venue to post meta on save, so their location follows the venue rule.
* Fixed: the Checkin Card and the Default Location Privacy setting no longer describe Approximate as city level for check-ins, and the editor preview shows the exact location unless private.

= 1.8.3 =
* Fixed: the Check-in Dashboard block and the `/checkins` and `/checkins/stats` REST routes follow location privacy. Approximate check-ins show only venue name, city, region and country, private check-ins show nothing to people who can't edit them, and search, totals and pagination no longer confirm hidden venue names.

= 1.8.2 =
* Added: a "Mood label spelling" setting (follow the site language, US English or UK English) for plugin-supplied mood suggestions, card names, accessible names and ATmosphere titles. Mood identity is stored separately, so changing the setting never rewrites a post (#207).
* Fixed: location privacy is applied the same way everywhere. Approximate locations show only the venue name, city, region and country; private locations show nothing to visitors, across cards, block bindings and REST.
* Fixed: block bindings, check-in helpers and card blocks used a taxonomy that doesn't exist, so bound listen ratings and links rendered empty and inserting a card didn't set the post's kind.
* Fixed: star ratings on listen, watch, read, play, eat and drink cards now parse as a microformats rating without adding the number to the card's text.
* Fixed: Plex and Jellyfin webhooks authorize with a per-service token instead of a signature neither service can send.
* Fixed: scheduled imports no longer create the same draft again on every run.

= 1.8.1 =
* Fixed: Stream card "Read more" links now carry the post title for screen readers; mood emoji are announced instead of hidden; watch/play/read artwork falls back to the post title for alt text.
* Fixed: every Stream card carries an entry-level permalink and publication date for microformats parsers, the eat/drink cards bind their food object, and a card inside an authored wrapper no longer produces a second entry root.
* Fixed: street address and coordinates of a check-in, meal or drink are no longer exposed through the REST API, block bindings or cards unless the location is public.
* Fixed: star ratings, the reading progress bar and thumbnail buttons without alt text now have accessible names.
* Fixed: a post whose kind or post format is assigned after the first save (REST, WP-CLI, Micropub, Quick Edit) now moves to the right surface immediately.
* Fixed: toggling "Promote to main archive" now moves the post immediately instead of after the next save.
* Fixed: the ListenBrainz, Trakt, Plex, Jellyfin and generic webhook endpoints returned a server error on every authenticated call.
* Fixed: private and protected Simple Location data no longer appears in REST API responses for readers who cannot edit the post.
* Added: Stream cards and singular entries name their author as a microformats h-card (filter `pkiw_entry_author`).
* Changed: the Kind and Promote editor panels load only on post types that support kinds, so they no longer appear on navigation menus and templates in the site editor.
* Changed: the Stream card block previews in the editor with the real card (server-rendered); Micropub-created posts wrap their card in a plain group instead of a second h-entry.

= 1.8.0 =
* Added: image captions on Stream cards. The featured image's caption now shows under it in the feed, and a post with more than one image gets a row of thumbnails so it no longer looks identical to a single-image post. Choosing a thumbnail swaps the image and its caption; the card is complete without JavaScript, and thumbnails work by keyboard as well as by tap.

= 1.7.1 =
* Fixed: long mood posts lost their emoji on the Stream. The compact card now shows the emoji from the post's mood card.
* Fixed: mood posts read as a stack of stray lines in feed readers. Feeds now show the emoji inline with the post text instead of the flattened card markup.
* Fixed: choosing the quote post format now maps to the quote kind instead of repost.
* Fixed: a search that found nothing kept saying "no results found" for a full day, even after the service recovered. Empty lookups now retry after five minutes.
* Fixed: the plugin download no longer includes the directory-listing artwork, making it noticeably smaller.

= 1.7.0 =
* Added: four more kinds — chicken, comics, collection, and presentation — bringing the total to 40.
* Added: the Play Card has six looks to choose from in the editor's Styles panel — board game, console game, computer game, card game, dice game, and tabletop RPG. It picks one for you based on what it knows about the game, and leaves your choice alone if you've made one.
* Added: sites can now give their own custom kinds an icon in the kind picker, instead of every custom kind showing the generic note icon. Developers: the new `postKindsIndieweb.kindIcons` JavaScript filter.
* Added: the Micropub bridge understands every registered kind, not just the original 16, and clients can name a kind outright with the new `pkiw-kind` property.
* Fixed: publishing a book post after a lookup failed with "meta._pkiw_read_isbn is not of type string". Lookup services return ISBNs, IDs, and years as numbers where the plugin stores text, which made WordPress reject the whole save. Values are now converted where they're stored, so every lookup field is covered rather than just the ones reported.

= 1.6.0 =
* Added: Standard.site publishing through the optional [ATmosphere](https://wordpress.org/plugins/atmosphere/) companion plugin (2.1.0 or later). Public content and public logs — notes, articles, photos, videos, reviews, recipes, events, listens, watches, reads, plays, eats, drinks, jams, replies, bookmarks, and RSVPs — publish as Standard.site documents by default once ATmosphere is connected; likes, reposts, favorites, follows, check-ins, moods, and the other privacy-sensitive kinds stay off until you turn them on, per kind or per post. Untitled posts get readable derived titles ("Listened to … by …", "Checked in at …" — or just "Checked in" for a private check-in), the kind rides along as a tag, and a post's own sharing toggle always has the final say. Posts that already published are never removed by a settings change, and nothing backfills automatically. Post Kinds works fully without ATmosphere.
* Added: a Standard.site column on the posts list showing each post's publish state, and per-kind publishing controls on the Integrations settings tab.
* Fixed: hidden microformats markers no longer appear in ATmosphere's record previews, so what you preview is exactly what publishes.


= 1.5.2 =
* Fixed: the Check-in Dashboard block crashed in the editor for everyone who inserted it, with any settings. It read its data feed in the wrong shape.
* Fixed: the Play Card crashed in the editor whenever its status differed from the default — pasted, imported, or pattern-inserted cards hit this every time.
* Fixed: Event and RSVP cards crashed in the editor on a date they couldn't parse, and a similar problem in several cards' saving code could block a post from saving at all. Every date now degrades gracefully instead.
* Fixed: the Check-in Dashboard's "Check-ins" and "Countries" stats always showed 0 regardless of your data.
* Fixed: the cover image's remove control was a button nested inside another button in all eleven card blocks — invalid HTML that confuses screen readers, and it was invisible to keyboard users. It's now a proper sibling control that appears on keyboard focus too.
* Changed: the plugin download shrinks from 4.8M to 0.6M — a folder of marketing imagery that the plugin never loads no longer ships.

= 1.5.1 =
* Fixed: the /firehose feed added in 1.5.0 returned 404 on any site that updated rather than installed fresh, and stayed that way. Rewrite rules were only rebuilt on activation, which WordPress doesn't run on update, so the feed's address was never registered. Updating now rebuilds them.
* Fixed: kinds added in a previous update now show up right away. They were only filled in on a wp-admin page load, so a freshly-updated site briefly showed 24 kinds instead of 36 and 404'd the newer kind archives for visitors.

= 1.5.0 =
* Fixed: a kind post's microformats2 property (u-like-of, u-listen-of, and the rest) now attaches to an h-entry at the post's own URL — the page webmention receivers actually fetch. Block themes whose single template never calls post_class() (Twenty Twenty-Five, Twenty Twenty-Four) left the card parsing as an orphan citation; the plugin now supplies its own h-entry wrapper there, with the permalink and publish date included, and steps aside on themes that already provide one.
* Fixed: the Stream Card registers with block API version 3 like every other block.
* Fixed: the plugin package includes its stylesheet and admin assets again — a packaging manifest had gone stale, so the downloaded zip was missing the card design tokens and the whole admin interface.
* Fixed: card links show a visible focus outline again for keyboard users — the style referenced a color token the plugin never defines, which silently removed the outline entirely.
* Fixed: favorite, eat, drink, play, jam, mood, acquisition, and wish posts now carry their microformats root at the post's own URL, like every other kind.
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
* Added: media artwork (album covers, posters, book covers) becomes the post's featured image when none is set, with a pkiw_set_featured_from_artwork filter to opt out and a wp postkind featured-artwork backfill command for existing posts.
* Added: kind artwork reaches Yoast SEO's schema graph as the primary image when Yoast finds none itself.
* Added: listen, watch, jam, and play card fields mirror into post meta on save, so themes and plugins can read them without parsing blocks.

= 1.0.0 =
* Initial WordPress.org release: 24 post kinds with card blocks, media lookup, imports and webhook scrobbling, microformats2 markup, syndication, and Micropub support. Development history for the pre-release builds lives in CHANGELOG.md in the GitHub repository.
* Security: syndication handlers require the per-post `edit_post` capability, closing an IDOR where a user with generic `edit_posts` could syndicate another user's post.
* Security: Letterboxd lookups use `wp_safe_remote_get` with `reject_unsafe_urls`, so a redirect target can't reach private or loopback hosts.
* Fixed: like, reply, repost, bookmark, favorite, listen, watch, and read posts expose the correct microformats2 markup, so webmention receivers and feed readers recognize them as their kind.

== Upgrade Notice ==

= 1.9.1 =
Keeps Trakt connected past 7 days and corrects the Check-in Card's description of Approximate privacy. Coming from 1.8.9, you also get 1.9.0's RSVP, presentation, trip, itinerary and event archives and the Metron comic lookup.

= 1.8.9 =
Trakt connects again. Copy the Redirect URI from API Connections into your Trakt app, paste its Client ID, and click Connect. No client secret needed.

= 1.8.8 =
Saving API Connections now keeps your BoardGameGeek token and RAWG key. Re-enter them once if an earlier save cleared them.

= 1.8.7 =
Tightens location privacy: an unset choice now means approximate, and editors see a check-in's location on the front end as a visitor does. Venue archives move from /venue/ to /venues/. Adds archives for many more kinds.

= 1.8.6 =
Accessibility pass on every card, the Stream and the dashboards. Check-in dashboard and feed headings default to h2.

= 1.8.5 =
Generic webhook callers must send the site secret in an X-Webhook-Token header (the query string is no longer accepted). OwnTracks needs both a username and a password set.

= 1.6.0 =
Adds optional Standard.site publishing via the ATmosphere companion plugin. Nothing publishes until you install ATmosphere and connect an account.

= 1.5.2 =
Fixes four editor crashes and shrinks the download from 4.8M to 0.6M. Recommended for everyone.

= 1.5.1 =
Fixes /firehose 404s and missing kind archives on updated sites — both self-heal on update.

== External services ==

This plugin integrates with external services for media metadata lookups, history imports, scrobbling, syndication, and maps. Your server contacts a service only after you enable it, save its credentials, use its lookup, or paste one of its links. Maps work differently: on pages that show a check-in map, each visitor's browser requests OpenStreetMap tiles or an embedded map with no setup (see Geocoding and maps). What follows lists each service, what is sent and when, and its terms and privacy policy.

= Media lookup services (used when you search from the editor, Quick Post, or the Media Lookup block) =

Each lookup sends your search text (a title, artist, venue name, or similar) plus your stored API key or credentials for that service, only at the moment you run a search or refresh metadata.

* **MusicBrainz** — music metadata (albums, artists, recordings). Also sends the app name/contact you configure, per their API etiquette. [Terms](https://metabrainz.org/social-contract), [Privacy](https://metabrainz.org/privacy).
* **Cover Art Archive** — album art for MusicBrainz results, requested by release ID. Same operator and policies as MusicBrainz.
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
* **OpenStreetMap map tiles (tile.openstreetmap.org)** — the check-in archive map and the Check-in Dashboard block's map are drawn from OpenStreetMap Foundation tiles. Each visitor's browser requests the tile images from tile.openstreetmap.org, sending the visitor's IP address, user agent, the referrer your site's referrer policy allows, and the tile coordinates of the map area on screen. The archive map loads tiles only when a check-in on the page has a pin: a visitor gets pins for check-ins whose location is public, and a signed-in editor sees the same pins on the front end. The Check-in Dashboard block loads tiles when its Map view is showing and one of the check-ins it lists has coordinates that viewer may see, by the same rule. With none, it draws no map and requests no tiles. The Reactions → Check-ins admin screen, which shows editors their own check-ins in full, loads them in the signed-in user's browser when they open its Map view. The `pkiw_map_tile_url` and `pkiw_map_tile_attribution` filters point all three maps at another tile provider or your own tile cache. [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/), [Privacy](https://osmfoundation.org/wiki/Privacy_Policy).

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

= Inbound webhooks =

Plex, Jellyfin, Trakt, ListenBrainz, and OwnTracks scrobbling works through webhooks: those services send data *to* your site at the secret URL you configure. The plugin makes no outbound request to them for this feature. One optional exception: if you set the `pkiw_plex_url` and `pkiw_plex_token` options, a Plex scrobble downloads its artwork from that Plex server, with the token in a request header.

= AI features =

The optional AI enhancements use the WordPress AI Client bundled with WordPress 7.0+, which routes requests to whatever AI provider the site administrator has configured in WordPress itself. This plugin does not contact any AI service directly and sends nothing unless you invoke an AI action.

== Privacy Policy ==

This plugin:

* Stores all post data locally in your WordPress database — no custom tables
* Makes API calls to external services only when you search for media, paste a media URL, run imports, or receive a webhook
* Does not track users or send analytics
* Stores API keys and OAuth tokens saved on its settings screens in the WordPress options table; they are removed when you uninstall the plugin
* Sends your activity to Last.fm, Trakt, or Foursquare only when you enable those syndication toggles

External services contacted (when you use the matching feature):

* MusicBrainz / Cover Art Archive / ListenBrainz — music metadata and album art (no API key required)
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
