# Post Kinds for IndieWeb

## Project

- **Slug:** post-kinds-for-indieweb
- **Text Domain:** post-kinds-for-indieweb
- **Prefix:** pk\_
- **Min WP:** 6.9 | **Min PHP:** 8.2
- **Repo:** https://github.com/courtneyr-dev/post-kinds-for-indieweb

## What It Does

Block editor support for IndieWeb post kinds (listen, watch, read, checkin, play, eat, drink, like, reply, repost, bookmark, RSVP). 16 custom blocks, external API integrations (MusicBrainz, TMDB, Open Library, RAWG), webhook support, bulk import, microformats2 output.

## Standards

- WordPress PHP + JS Coding Standards (tabs, Yoda conditions)
- Security Trinity: sanitize input → validate data → escape output
- All strings wrapped in `__()` / `_e()` with text domain `post-kinds-for-indieweb`
- All blocks use `apiVersion: 3` (WP 7.0 iframes the editor)
- Escape at render: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- DB queries via `$wpdb->prepare()` only
- Nonces + `current_user_can()` on every action

## Build Commands

```bash
composer install && npm install
npm run start           # Watch
npm run build           # Production
composer test           # PHPUnit
composer lint           # PHPCS (WordPress-Extra)
composer analyze        # PHPStan level 6
npm run lint:js         # ESLint
npm run test:e2e        # Playwright
```

## File Layout

```
includes/                      # PHP classes
includes/abilities/            # Abilities API (existing)
includes/class-abilities-manager.php  # Abilities registration (existing)
src/blocks/                    # Block source (JS)
src/interactivity/             # Interactivity API view scripts (new)
build/                         # Compiled (gitignored)
tests/Unit/                    # WP_Mock unit tests
tests/Integration/             # WP_UnitTestCase integration tests
```

## WP 7.0 Upgrade (In Progress)

Branch: `feature/wp70-api-integration`

All 7.0 features gated behind version checks. New files only — no destructive rewrites.

```php
// Version gate pattern
if ( version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ) { /* 7.0 code */ }

// Abilities gate pattern (function exists since 6.9)
if ( function_exists( 'wp_register_ability' ) ) { /* abilities code */ }

// AI gate pattern (doubly gated)
if ( function_exists( 'wp_ai_client_prompt' ) && get_option( 'pk_enable_ai' ) ) { /* AI code */ }
```

## Priority Order

1. Abilities API (existing code — audit + expand)
2. Block Bindings source
3. Interactivity API conversions
4. PHP-only blocks
5. WP AI Client (optional, last)

## Key API Notes

- **Abilities API:** use `execute_callback` (not `callback`), categories on `wp_abilities_api_categories_init`, abilities on `wp_abilities_api_init`
- **Interactivity API:** `wp_interactivity_state()` for server state, `viewScriptModule` in block.json, never raw `<script>` tags
- **Block Bindings:** `register_block_bindings_source()` on `init`, post meta needs `show_in_rest => true`, keys cannot start with `_`
- **WP AI Client:** `wp_ai_client_prompt()->generate_text()` returns string or `WP_Error`
