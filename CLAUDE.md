# Post Kinds for IndieWeb

## Project

- **Slug:** post-kinds-for-indieweb
- **Text Domain:** post-kinds-for-indieweb
- **Prefix:** pk\_
- **Min WP:** 7.0 | **Min PHP:** 8.2
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
- Secrets (API keys, tokens, credentials) live in env/config only — never in code or commits
- Public-facing features get a security review pass before shipping

### Testing to capacity

- Every feature and bugfix lands with tests; write the failing test first
- Cover edge cases and failure paths, not just the happy path — unit + integration, plus e2e (Playwright) where there's a UI
- No OR-assertions, no self-grading tests
- CI green is the source of truth over local runs

### Accessibility floor: WCAG 2.2 AA

- Semantic HTML first; ARIA only where semantics can't cover it
- Keyboard-only pass (everything reachable, no traps, visible focus) + a screen reader spot-check before shipping UI

### Release gate: prepare ≠ ship

Release machinery (version bump, changelog, tag) can be prepared at any time, but never cut a release, tag, or deploy without Courtney's explicit go.

## Commit convention

Commits use [Emoji-Log](https://github.com/ahmadawais/Emoji-Log) going forward — this repo previously used Conventional Commits (`fix:`, `test:`, `docs:`, etc.); the switch is deliberate, starting with this commit. Imperative mood, exactly one prefix:

| Prefix | Use for |
|---|---|
| `📦 NEW:` | Something entirely new |
| `👌 IMPROVE:` | Enhancement / refactor |
| `🐛 FIX:` | Bug fix |
| `📖 DOC:` | Documentation |
| `🚀 RELEASE:` | New version |
| `🤖 TEST:` | Testing |
| `‼️ BREAKING:` | Breaks previous versions |

## Build Commands

```bash
composer install && npm install
npm run start           # Watch
npm run build           # Production
composer test           # PHPUnit
composer lint           # PHPCS (WordPress-Extra)
composer analyze        # PHPStan level 5 (phpstan.neon)
npm run lint:js         # ESLint
npm run test:e2e        # Playwright
```

## File Layout

```
includes/                      # PHP classes
includes/abilities/            # Abilities API (existing)
includes/class-abilities-manager.php  # Abilities registration (existing)
src/blocks/                    # Block source (JS) — registered directly, not build/
src/interactivity/             # Interactivity API view scripts (new)
build/                         # Compiled (tracked, not gitignored — shipped for distribution)
tests/phpunit/unit/            # PHPUnit unit tests
tests/phpunit/integration/     # PHPUnit integration tests (WP_UnitTestCase)
tests/phpunit/fixtures/        # Fixture data (per external API)
tests/e2e/                     # Playwright e2e, a11y, visual-regression specs
tests/js/                      # Jest unit tests
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
if ( function_exists( 'wp_ai_client_prompt' ) && get_option( 'pkiw_enable_ai' ) ) { /* AI code */ }
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
