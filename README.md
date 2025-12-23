# Reactions for IndieWeb

A comprehensive WordPress plugin that extends IndieBlocks with rich support for IndieWeb Post Kinds, external API integrations, and media tracking.

## Features

### Post Kinds Support
- **Listen** - Track music you've listened to with MusicBrainz/ListenBrainz/Last.fm integration
- **Watch** - Log movies and TV shows with TMDB/Trakt/Simkl support
- **Read** - Track books with Open Library/Hardcover/Google Books integration
- **Checkin** - Location check-ins with Foursquare/Nominatim geocoding
- **Like, Reply, Repost, Bookmark, RSVP** - Full IndieWeb interaction support

### External API Integrations
- **Music**: MusicBrainz, ListenBrainz, Last.fm
- **Movies/TV**: TMDB, Trakt, Simkl, TVmaze
- **Books**: Open Library, Hardcover, Google Books
- **Podcasts**: Podcast Index
- **Location**: Foursquare, Nominatim (OpenStreetMap)

### Import & Sync
- Bulk import from ListenBrainz, Last.fm, Trakt, Simkl, Hardcover
- Webhook support for Plex, Jellyfin, Trakt, ListenBrainz
- Background processing with WP-Cron

### Block Editor Integration
- Custom blocks for each post kind
- Media search/lookup in editor
- Star rating component
- Microformats2 output for IndieWeb compatibility

## Requirements

- WordPress 6.5+
- PHP 8.0+
- [IndieBlocks](https://developer.wordpress.org/plugins/indieblocks/) plugin (recommended)

## Installation

1. Download or clone this repository to your `wp-content/plugins/` directory
2. Run `composer install` to install PHP dependencies
3. Run `npm install && npm run build` to build JavaScript assets
4. Activate the plugin in WordPress admin

## Configuration

1. Navigate to **Reactions** in the WordPress admin menu
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
add_filter('reactions_indieweb_post_kinds', function($kinds) {
    // Add custom kind
    $kinds['custom'] = [
        'label' => 'Custom',
        'icon' => 'dashicons-star-filled',
    ];
    return $kinds;
});

// Modify API response caching
add_filter('reactions_indieweb_cache_duration', function($duration, $api) {
    return 3600; // 1 hour
}, 10, 2);
```

### Actions

```php
// After a reaction post is created
add_action('reactions_indieweb_post_created', function($post_id, $kind, $data) {
    // Custom logic
}, 10, 3);

// After import completes
add_action('reactions_indieweb_import_complete', function($import_id, $stats) {
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

## License

GPL v2 or later

## Credits

- Built to extend [IndieBlocks](https://developer.wordpress.org/plugins/indieblocks/)
- Inspired by [Post Kinds](https://developer.wordpress.org/plugins/indieweb-post-kinds/)
- Uses data from MusicBrainz, TMDB, Open Library, and other open APIs
