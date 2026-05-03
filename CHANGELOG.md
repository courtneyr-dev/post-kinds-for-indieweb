# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Micropub-to-block content bridge** (`includes/class-micropub-content-builder.php`). Hooks `after_micropub` priority 30 and rewrites Micropub-created `post_content` from plain text to the registered card blocks (Checkin Card, Eat Card, Drink Card, Listen Card, Watch Card, Read Card, Play Card, RSVP Card, Mood Card) when the incoming h-entry shape matches a recognized post kind. Bridges any Micropub client (Outpost, Quill, Indigenous, etc.) to this plugin's block-editor cards without requiring the client to know about Gutenberg block markup.
  - **Idempotent.** Sets a `_pkiw_block_content_generated` post meta marker on first generation; subsequent Micropub updates leave (potentially user-edited) content alone.
  - **h-entry envelope.** Wraps each card in a `wp:group` with `class="h-entry"` and an inner `e-content` group for the user's typed body text, so microformats2 readers see one h-entry root and the body in `e-content`.
  - **Geo extraction.** Parses `location: geo:lat,lon` (RFC 5870) into `latitude`/`longitude` (or `geoLatitude`/`geoLongitude` for Eat/Drink) attributes on the card block.
  - **Outpost-friendly.** Recognizes Outpost's `mp-place-name` extension property as the venue name attribute. Outpost-made checkin/eat/drink/listen posts now render with proper card UI on the front-end.
  - 17 PHPUnit unit tests covering kind detection, geo parsing, per-kind builders, idempotency, and the plain-note skip path.
- Jam Card, Eat Card, Drink Card, Favorite Card, Wish Card, Mood Card, Acquisition Card blocks
- Play Card block with RAWG integration
- Checkin Dashboard block for location overview
- Block Bindings source for post kind meta fields
- Block Bindings format helpers for dynamic content display
- Format Badge block for displaying post kind labels
- Media Stats block for collection summaries
- Now Playing block for current listening activity
- Recent Kinds block for latest post kind entries
- AI Enhancements class for optional WP AI Client integration
- oEmbed support for Jam Card block
- Code of Conduct (CODE_OF_CONDUCT.md)
- Project documentation (CONTRIBUTING.md, SECURITY.md, SUPPORT.md)
- GitHub issue templates (bug report, feature request, question)
- Pull request template with comprehensive checklists
- QA checklist for pre-release testing

### Changed

- Converted Jam Card, Listen Card, and Watch Card to dynamic server-side rendering
- Rewrote README.md with improved structure, badges, and feature documentation
- Rewrote readme.txt (WordPress.org) with expanded description and FAQ
- Rewrote CONTRIBUTING.md with streamlined development workflow
- Rewrote SUPPORT.md with clearer troubleshooting guidance
- Upgraded pull request template with security, accessibility, and i18n checklists
- Prefixed render.php variables with `pkiw_` for wp.org plugin checker compliance
- Converted CSS indentation from spaces to tabs for WordPress coding standards
- Updated copyright year to 2026
- Synchronized platform requirements (WP 6.9+, PHP 8.2+) across all documentation

### Fixed

- CSS specificity ordering issues in checkin-dashboard.css
- ESLint exhaustive-deps warnings in Jam Card useEffect hooks
- CSS selector formatting in shared card-editor.css

## [1.0.0] - 2024-12-23

### Added

#### Core Features

- Reaction Kind taxonomy for categorizing posts (listen, watch, read, checkin, rsvp)
- Custom meta fields for reaction metadata
- Block bindings for dynamic content display
- Microformats2 markup enhancement for IndieWeb compatibility

#### Custom Blocks

- **Listen Card**: Music/podcast scrobbling with album art, artist, rating, MusicBrainz integration
- **Watch Card**: Movies/TV shows with poster, episode tracking, TMDB/IMDb links, rewatch indicator
- **Read Card**: Books with cover, author, reading progress bar, Open Library integration
- **Checkin Card**: Location checkins with venue details, OpenStreetMap embed, geo coordinates
- **RSVP Card**: Event responses (yes/no/maybe/interested/remote) with h-event microformats
- **Star Rating**: Standalone rating component with stars/hearts/circles styles, half-star support
- **Media Lookup**: Universal media search across all integrated APIs

#### Block Patterns

- Listen Log pattern for music posts
- Watch Log pattern for movie/TV posts
- Read Progress pattern for book posts
- Checkin Card pattern for location posts
- RSVP Response pattern for event responses

#### External API Integrations

- **Music**: MusicBrainz, ListenBrainz, Last.fm
- **Movies/TV**: TMDB, Trakt, Simkl, TVMaze
- **Books**: Open Library, Google Books, Hardcover
- **Podcasts**: Podcast Index
- **Locations**: Foursquare, OpenStreetMap Nominatim

#### REST API

- Custom endpoints for media search
- Import endpoints for external services
- Webhook handlers for real-time sync

#### Admin Features

- Settings page with tabbed interface
- API key management with secure storage
- Import tools for bulk data migration
- Webhook configuration for scrobbling services
- Meta boxes for post editing
- Quick Post interface for rapid posting

#### Shared Components

- StarRating component with interactive editing
- CoverImage component with fallback handling
- MediaSearch component with API integration
- ProgressBar component for reading progress
- BlockPlaceholder for empty states
- DateDisplay with relative times
- LocationDisplay with address formatting

### Technical

- WordPress Block API v3 compatibility
- PHP 8.2+ with strict types
- Full internationalization support
- WordPress Coding Standards compliance
- Comprehensive PHPDoc documentation

### Dependencies

- Requires WordPress 6.9+
- Requires PHP 8.2+
- Recommends IndieBlocks plugin

---

## Version History Notes

### Versioning

This project uses Semantic Versioning:

- **Major** (X.0.0): Breaking changes
- **Minor** (0.X.0): New features, backward compatible
- **Patch** (0.0.X): Bug fixes, backward compatible

### Links

- [Repository](https://github.com/courtneyr-dev/post-kinds-for-indieweb)
- [Issues](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues)
- [IndieWeb Wiki](https://indieweb.org/)

[Unreleased]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/courtneyr-dev/post-kinds-for-indieweb/releases/tag/v1.0.0
