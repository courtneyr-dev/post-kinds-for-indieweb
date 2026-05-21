# Post Kinds for IndieWeb

[![WordPress 6.9+](https://img.shields.io/badge/WordPress-6.9%2B-21759b.svg?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg?logo=php&logoColor=white)](https://php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![IndieWeb](https://img.shields.io/badge/IndieWeb-compatible-FF5C00.svg)](https://indieweb.org/)
[![CI](https://github.com/courtneyr-dev/post-kinds-for-indieweb/actions/workflows/ci.yml/badge.svg)](https://github.com/courtneyr-dev/post-kinds-for-indieweb/actions)

**Own your media life.** Track what you listen to, watch, read, play, and experience — all from your WordPress site, with full block editor support and IndieWeb microformats.

A modern, block-editor successor to David Shanske's [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin.

---

## Why Post Kinds?

The original Post Kinds plugin brought IndieWeb interaction types to WordPress, but it was built for the Classic Editor. WordPress has moved on — block themes, the Block Bindings API, and the Interactivity API open new possibilities.

This plugin bridges that gap:

- **Block-native** — 16 custom Gutenberg blocks, not shortcodes or meta boxes
- **API-powered** — search MusicBrainz, TMDB, Open Library, and more directly from the editor
- **Import everything** — bulk import from Last.fm, Trakt, Hardcover, and other services
- **IndieWeb-first** — proper microformats2 markup on every post

## Post Kinds

| Kind                                  | What it tracks           | API integrations                      |
| ------------------------------------- | ------------------------ | ------------------------------------- |
| **Listen**                            | Music, podcasts, audio   | MusicBrainz, ListenBrainz, Last.fm    |
| **Watch**                             | Movies, TV shows, videos | TMDB, Trakt, Simkl, TVMaze            |
| **Read**                              | Books, articles          | Open Library, Google Books, Hardcover |
| **Checkin**                           | Places, venues           | Foursquare, Nominatim (OpenStreetMap) |
| **Play**                              | Video games, board games | RAWG, BoardGameGeek                   |
| **Eat / Drink**                       | Food, beverages          | Manual entry                          |
| **Jam**                               | Music you love           | MusicBrainz, oEmbed                   |
| **RSVP**                              | Event responses          | Manual entry                          |
| **Like, Reply, Repost, Bookmark**     | IndieWeb interactions    | Works with IndieBlocks                |
| **Favorite, Wish, Mood, Acquisition** | Personal tracking        | Manual entry                          |

## Custom Blocks

| Block                 | Description                                                      |
| --------------------- | ---------------------------------------------------------------- |
| **Listen Card**       | Album art, artist, track, rating, MusicBrainz lookup             |
| **Watch Card**        | Poster, episode tracking, rewatch indicator, TMDB search         |
| **Read Card**         | Cover image, reading progress bar, Open Library search           |
| **Checkin Card**      | Venue details, OpenStreetMap embed, geo coordinates              |
| **RSVP Card**         | Event response with yes/no/maybe/interested/remote states        |
| **Play Card**         | Game cover art, platform info, RAWG integration                  |
| **Eat Card**          | Restaurant, cuisine details                                      |
| **Drink Card**        | Venue, beverage type                                             |
| **Jam Card**          | Music you're into, with oEmbed previews                          |
| **Favorite Card**     | Favorited content                                                |
| **Wish Card**         | Wishlist items                                                   |
| **Mood Card**         | Current mood or feeling                                          |
| **Acquisition Card**  | Items acquired                                                   |
| **Star Rating**       | Standalone component — stars, hearts, circles, half-star support |
| **Media Lookup**      | Universal search across all integrated APIs                      |
| **Checkin Dashboard** | Overview of recent check-ins                                     |

## Import and Sync

Pull in your existing data:

- **Bulk import** from ListenBrainz, Last.fm, Trakt, Simkl, Hardcover
- **Webhooks** for Plex, Jellyfin, Trakt, ListenBrainz (real-time scrobbling)
- **Background processing** via WP-Cron for large imports
- **Duplicate prevention** on re-imports

## Microformats

Every block outputs proper [microformats2](http://microformats.org/wiki/microformats2) markup:

- `h-entry` for posts
- `h-cite` for cited media (listen-of, watch-of, read-of)
- `h-card` for people and artists
- `h-adr` / `h-geo` for locations
- `h-event` for RSVP responses
- `p-rating` for star ratings

Validate your output at [pin13.net/mf2](https://pin13.net/mf2/).

## Requirements

- WordPress 6.9 or higher
- PHP 8.2 or higher

## Quick Start

### Install from WordPress.org

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for **Post Kinds for IndieWeb**
3. Click **Install Now**, then **Activate**
4. Configure API keys at **Settings > Post Kinds for IndieWeb**

### Install from GitHub

```bash
git clone https://github.com/courtneyr-dev/post-kinds-for-indieweb.git
cd post-kinds-for-indieweb
composer install
npm install && npm run build
```

Then activate the plugin in WordPress admin.

## Configuration

1. **Settings > Post Kinds for IndieWeb** — general plugin settings
2. **API Connections** — enter API keys for TMDB, Last.fm, etc. (MusicBrainz and Open Library don't need keys)
3. **Webhooks** — set up endpoints for Plex, Jellyfin, or ListenBrainz scrobbling
4. **Quick Post** — use the rapid posting interface for fast content creation

## Related Plugins

**Recommended** (works great together):

- [IndieBlocks](https://wordpress.org/plugins/indieblocks/) — core IndieWeb theme blocks (Facepile, Location, Syndication, Link Preview)
- [IndieWeb](https://wordpress.org/plugins/indieweb/) — the IndieWeb plugin suite foundation
- [Webmention](https://wordpress.org/plugins/webmention/) — cross-site conversations
- [Syndication Links](https://wordpress.org/plugins/syndication-links/) — POSSE workflow support
- [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/) — post format support in block themes
- [Link Extension for XFN](https://wordpress.org/plugins/link-extension-for-xfn/) — XFN relationship options

**Optional:**

- [ActivityPub](https://wordpress.org/plugins/activitypub/) — federate with Mastodon and the Fediverse

**Conflicts:**

- [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) — this plugin replaces it. Use one or the other, not both.

## Extending the Plugin

### Filters

```php
// Add a custom post kind
add_filter( 'post_kinds_indieweb_post_kinds', function ( $kinds ) {
    $kinds['custom'] = [
        'label' => 'Custom',
        'icon'  => 'dashicons-star-filled',
    ];
    return $kinds;
} );

// Change API response cache duration
add_filter( 'post_kinds_indieweb_cache_duration', function ( $duration, $api ) {
    return 3600; // 1 hour
}, 10, 2 );
```

### Actions

```php
// After a post kind is created
add_action( 'post_kinds_indieweb_post_created', function ( $post_id, $kind, $data ) {
    // Your custom logic
}, 10, 3 );

// After an import completes
add_action( 'post_kinds_indieweb_import_complete', function ( $import_id, $stats ) {
    // Send notification, log results, etc.
}, 10, 2 );
```

## Development

### Setup

```bash
composer install && npm install
npm run start       # Watch mode (development)
npm run build       # Production build
```

### Code Quality

```bash
composer lint       # PHPCS (WordPress-Extra)
composer lint:fix   # Auto-fix PHP
composer analyze    # PHPStan level 6
composer test       # PHPUnit

npm run lint:js     # ESLint
npm run lint:css    # Stylelint
npm run test:e2e    # Playwright
```

### Branch Naming

- `feature/description` — new features
- `fix/description` — bug fixes
- `docs/description` — documentation
- `refactor/description` — code refactoring

See [CONTRIBUTING.md](CONTRIBUTING.md) for full development guidelines.

## Support

- **Bug reports & features** — [GitHub Issues](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues)
- **Questions & ideas** — [GitHub Discussions](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)
- **Real-time help** — [IndieWeb Chat](https://chat.indieweb.org/)
- **FAQ & troubleshooting** — [SUPPORT.md](SUPPORT.md)

## Security

Report vulnerabilities privately via [GitHub Security Advisories](https://github.com/courtneyr-dev/post-kinds-for-indieweb/security/advisories/new). Do not open public issues for security problems. See [SECURITY.md](SECURITY.md) for details and response timelines.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Credits

Created by [Courtney Robertson](https://courtneyr.dev). Built as a modern successor to [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) by David Shanske.

Uses open data from MusicBrainz, TMDB, Open Library, RAWG, OpenStreetMap, and other services.

Made for the [IndieWeb](https://indieweb.org/) community.
