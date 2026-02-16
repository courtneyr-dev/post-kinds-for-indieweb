# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Project documentation (CONTRIBUTING.md, SECURITY.md, SUPPORT.md)
- GitHub issue templates (bug report, feature request, question)
- Pull request template

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
- PHP 8.0+ with strict types
- Full internationalization support
- WordPress Coding Standards compliance
- Comprehensive PHPDoc documentation

### Dependencies

- Requires WordPress 6.5+
- Requires PHP 8.0+
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
