# Post Kinds for IndieWeb and Block Themes

[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)
[![IndieWeb](https://img.shields.io/badge/IndieWeb-compatible-orange.svg)](https://indieweb.org/)

**Modern block editor support for IndieWeb post kinds and microformats.** A successor to the classic [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin by David Shanske.

Share what you're listening to, watching, reading, and experiencing—all on your own website with full Gutenberg support.

## About This Plugin

This plugin is a modern, block-editor compatible successor to David Shanske's [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin. It maintains compatibility with the same post kind concepts and IndieWeb microformats while adding full Gutenberg support.

**Why a new plugin?**
- The original Post Kinds plugin is incompatible with the Block Editor
- WordPress has evolved—block themes and the Block Bindings API offer new possibilities
- Modern APIs and import capabilities enhance the experience

## Features

### Post Kinds Support
- **Listen** - Track music you've listened to with MusicBrainz/ListenBrainz/Last.fm integration
- **Watch** - Log movies and TV shows with TMDB/Trakt/Simkl support
- **Read** - Track books with Open Library/Hardcover/Google Books integration
- **Checkin** - Location check-ins with Foursquare/Nominatim geocoding
- **Play** - Game tracking with RAWG and BoardGameGeek integration
- **Eat/Drink** - Food and beverage logging
- **Like, Reply, Repost, Bookmark, RSVP** - Full IndieWeb interaction support

### External API Integrations
- **Music**: MusicBrainz, ListenBrainz, Last.fm
- **Movies/TV**: TMDB, Trakt, Simkl, TVmaze
- **Books**: Open Library, Hardcover, Google Books
- **Games**: RAWG, BoardGameGeek
- **Podcasts**: Podcast Index
- **Location**: Foursquare, Nominatim (OpenStreetMap)

### Import & Sync
- Bulk import from ListenBrainz, Last.fm, Trakt, Simkl, Hardcover
- Webhook support for Plex, Jellyfin, Trakt, ListenBrainz
- Background processing with WP-Cron

### Block Editor Integration
- Custom card blocks for each post kind
- Media search/lookup in editor
- Star rating component
- Microformats2 output for IndieWeb compatibility
- Block Bindings API for dynamic content

## Requirements

- WordPress 6.5+
- PHP 8.0+

## Related Plugins

**Recommended:**
- [IndieWeb](https://wordpress.org/plugins/indieweb/) - people-focused alternative to the ‘corporate web’ that allows you to be the hub of your own web presence.
- [IndieBlocks](https://wordpress.org/plugins/indieblocks/) - Core IndieWeb blocks register several “theme” blocks (Facepile, Location, Syndication, and Link Preview), to be used in “block theme” templates.
- [Syndication Links](https://wordpress.org/plugins/syndication-links/) - Stores and displays syndication URLs for POSSE workflows
- [Webmention](https://wordpress.org/plugins/webmention/) - Cross-site conversations and notifications
- [Link Extension for XFN](https://wordpress.org/plugins/link-extension-for-xfn/) - Integrates XFN (XHTML Friends Network) relationship options into WordPress’s native link interface.
- [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/) - Brings the beloved post format functionality from classic WordPress themes to modern block themes, with intelligent pattern insertion, automatic format detection, and a streamlined editing experience that makes creating formatted content effortless.

**Optional:**
- [ActivityPub](https://wordpress.org/plugins/activitypub/) - Fediverse federation
- [Bookmark Card](https://wordpress.org/plugins/developer/mamaduka/bookmark-card/) - Enhanced bookmark previews

**Conflicts:**
- [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) - Use one or the other (this plugin is the block editor successor)

## Installation

1. Download or clone this repository to your `wp-content/plugins/` directory
2. Run `composer install` to install PHP dependencies
3. Run `npm install && npm run build` to build JavaScript assets
4. Activate the plugin in WordPress admin

## Configuration

1. Navigate to **Post Kinds** in the WordPress admin menu
2. Configure API connections under **API Connections**
3. Set up webhooks under **Webhooks** for automatic scrobbling
4. Use **Quick Post** for rapid content creation

## Development

```bash
# Install dependencies
npm install
composer install

# Build for development
npm run start

# Build for production
npm run build

# Lint code
npm run lint
composer run phpcs
```

## Block Patterns

The plugin includes several block patterns:
- Listen Log
- Watch Log
- Read Progress
- Checkin Card
- RSVP Response

## Hooks & Filters

### Filters

```php
// Modify post kinds
add_filter('post_kinds_indieweb_post_kinds', function($kinds) {
    // Add custom kind
    $kinds['custom'] = [
        'label' => 'Custom',
        'icon' => 'dashicons-star-filled',
    ];
    return $kinds;
});

// Modify API response caching
add_filter('post_kinds_indieweb_cache_duration', function($duration, $api) {
    return 3600; // 1 hour
}, 10, 2);
```

### Actions

```php
// After a post kind is created
add_action('post_kinds_indieweb_post_created', function($post_id, $kind, $data) {
    // Custom logic
}, 10, 3);

// After import completes
add_action('post_kinds_indieweb_import_complete', function($import_id, $stats) {
    // Send notification, etc.
}, 10, 2);
```

## Microformats

All output includes proper microformats2 markup for IndieWeb compatibility:

- `h-entry` for posts
- `h-cite` for citations
- `h-card` for people/artists
- `h-adr` for locations
- `h-event` for events (RSVP)

## Custom Blocks

The plugin includes 16 custom Gutenberg blocks:

| Block | Description |
|-------|-------------|
| **Listen Card** | Music/podcast with album art, artist, rating |
| **Watch Card** | Movie/TV with poster, episode info, rewatch tracking |
| **Read Card** | Book with cover, author, reading progress |
| **Checkin Card** | Location with venue details and map embed |
| **RSVP Card** | Event response (yes/no/maybe/interested/remote) |
| **Play Card** | Games with cover art and platform info |
| **Eat Card** | Food with restaurant and cuisine details |
| **Drink Card** | Beverages with venue and type info |
| **Jam Card** | Personally meaningful music |
| **Favorite Card** | Favorited content |
| **Wish Card** | Wishlist items |
| **Mood Card** | Current mood/feeling |
| **Acquisition Card** | Items acquired |
| **Star Rating** | Standalone rating component |
| **Media Lookup** | Universal media search and embed |
| **Checkin Dashboard** | Overview of recent checkins |

## Contributing

Contributions are welcome! Please read our [Contributing Guidelines](CONTRIBUTING.md) before submitting a pull request.

### Quick Start

```bash
git clone https://github.com/developer-advocacy/post-kinds-for-indieweb.git
cd post-kinds-for-indieweb
composer install
npm install
npm run build
```

## Support

- [GitHub Issues](https://github.com/developer-advocacy/post-kinds-for-indieweb/issues) - Bug reports and feature requests
- [GitHub Discussions](https://github.com/developer-advocacy/post-kinds-for-indieweb/discussions) - Questions and ideas
- [IndieWeb Chat](https://chat.indieweb.org/) - Real-time community help
- [SUPPORT.md](SUPPORT.md) - FAQ and troubleshooting

## Security

Please report security vulnerabilities privately. See [SECURITY.md](SECURITY.md) for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes.

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.

## Credits

- **Author**: [Courtney Robertson](https://courtneyr.dev)
- **Original Plugin**: [IndieWeb Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) by David Shanske
- Built to extend [IndieBlocks](https://wordpress.org/plugins/indieblocks/)
- Uses data from MusicBrainz, TMDB, Open Library, RAWG, and other open APIs
- Made with love for the IndieWeb community
