# Full test coverage design

**Date:** 2026-02-22
**Approach:** Layer-by-layer, bottom-up
**API mocking strategy:** Record and replay (JSON fixtures)

## Current state

- 7 PHPUnit unit tests, 0 integration tests
- 1 Jest test (StarRating), 3 Playwright E2E tests
- CI pipeline runs 13 jobs including PHPUnit, Jest, Playwright, PHPCS, PHPStan, Lighthouse

## Design

### Layer 1: PHPUnit unit tests for core classes

New files in `tests/phpunit/unit/`:

| File | Class under test | Focus |
|---|---|---|
| TaxonomyTest.php | Taxonomy | TAXONOMY constant, 24 default terms, taxonomy args, post type attachment |
| MicroformatsTest.php | Microformats | Kind-to-mf2 mapping for all 24 kinds, IndieBlocks skip list, correct h-entry/u-like-of/u-bookmark-of/p-rsvp/h-checkin output |
| BlockBindingsTest.php | Block_Bindings | Binding source registration, callback returns correct meta values |
| WebhookHandlerTest.php | Webhook_Handler | 5 endpoints, token auth verification, content-type validation, malformed payload rejection |
| QueryFilterTest.php | Query_Filter | Hidden kind query modification, no-op when not applicable |
| VenueTaxonomyTest.php | Venue_Taxonomy | Registration args, term CRUD |
| PostTypeTest.php | Post_Type | Reaction CPT registration args in CPT mode |
| ImportManagerTest.php | Import_Manager | Import coordination, batch processing, error handling |
| ScheduledSyncTest.php | Scheduled_Sync | Cron event scheduling/unscheduling, interval registration |

Pattern: Extend WP_UnitTestCase, namespace PostKindsForIndieWeb\Tests\Unit, data providers for multi-value scenarios, XSS/injection edge cases.

### Layer 2: API client tests with record-and-replay fixtures

**Infrastructure:**

`tests/phpunit/fixtures/` directory with JSON response snapshots per service (e.g. `musicbrainz/search-artist.json`).

`tests/phpunit/ApiTestCase.php` base class extending WP_UnitTestCase:
- `load_fixture( $path )` - reads JSON fixture files
- `mock_http_response( $url_pattern, $fixture )` - hooks `pre_http_request` to intercept WP HTTP calls
- `assert_api_request_made( $url_pattern )` - verifies request attempted
- Automatic mock cleanup in tear_down()

**Fixture capture workflow:** Run each API client once with real credentials, save response JSON, strip sensitive data before committing.

New files in `tests/phpunit/unit/`:

| File | Class under test |
|---|---|
| ApiBaseTest.php | API_Base (cache keys, rate limits, retries, timeout, error handling) |
| MusicBrainzApiTest.php | MusicBrainz |
| ListenBrainzApiTest.php | ListenBrainz |
| LastfmApiTest.php | Lastfm |
| TmdbApiTest.php | TMDB |
| TraktApiTest.php | Trakt |
| SimklApiTest.php | Simkl |
| TvmazeApiTest.php | TVmaze |
| OpenLibraryApiTest.php | OpenLibrary |
| GoogleBooksApiTest.php | GoogleBooks |
| HardcoverApiTest.php | Hardcover |
| PodcastIndexApiTest.php | PodcastIndex |
| FoursquareApiTest.php | Foursquare |
| NominatimApiTest.php | Nominatim |
| BoardGameGeekApiTest.php | BoardGameGeek |
| RawgApiTest.php | RAWG |
| ReadwiseApiTest.php | Readwise |

Each tests: URL construction, header/auth setup, response parsing, error/empty/malformed handling.

### Layer 3: Sync service tests

New files in `tests/phpunit/unit/`:

| File | Class under test |
|---|---|
| CheckinSyncBaseTest.php | Checkin_Sync_Base (interface contract, common mapping, duplicate detection) |
| ListenSyncBaseTest.php | Listen_Sync_Base |
| WatchSyncBaseTest.php | Watch_Sync_Base |
| FoursquareCheckinSyncTest.php | Foursquare_Checkin_Sync |
| UntappdCheckinSyncTest.php | Untappd_Checkin_Sync |
| OwnTracksCheckinSyncTest.php | OwnTracks_Checkin_Sync |
| LastfmListenSyncTest.php | Lastfm_Listen_Sync |
| TraktWatchSyncTest.php | Trakt_Watch_Sync |

Reuses ApiTestCase from Layer 2. Tests: post creation, meta population, duplicate prevention, error handling.

### Layer 4: Admin class tests

New files in `tests/phpunit/unit/`:

| File | Class under test |
|---|---|
| AdminTest.php | Admin (hooks, menus, enqueues, conflict notice) |
| SettingsPageTest.php | Settings_Page (register_setting, sections/fields, sanitization, nonces) |
| ApiSettingsTest.php | API_Settings (key storage, connection test, card rendering) |
| ImportPageTest.php | Import_Page (form rendering, service selection, batch validation, nonces) |
| QuickPostTest.php | Quick_Post (form fields per kind, post creation, redirect, validation) |
| MetaBoxesTest.php | Meta_Boxes (registration per kind, save_post, nonces, autosave skip) |
| CheckinDashboardTest.php | Checkin_Dashboard (widget registration, recent checkins query) |
| SyndicationPageTest.php | Syndication_Page (target config, list rendering) |
| WebhooksPageTest.php | Webhooks_Page (URL generation, token management, enable/disable) |

Pattern: Admin user factory, set_current_screen(), output capture with ob_start/ob_get_clean, $_POST simulation for saves.

### Layer 5: Jest block tests + editor data store

New files in `tests/js/blocks/`:

| File | Covers |
|---|---|
| listen-card.test.js | Edit fields (artist, track, album, cover), save markup |
| watch-card.test.js | Edit fields (title, season, episode, status), save markup |
| read-card.test.js | Edit fields (title, author, ISBN, status, progress), save markup |
| checkin-card.test.js | Edit fields (venue, address, geo, photo), save markup |
| rsvp-card.test.js | Edit fields (event URL, status radios), save markup |
| play-card.test.js | Edit fields (game, platform, hours, status), save markup |
| eat-card.test.js | Edit fields (meal, restaurant, photo), save markup |
| drink-card.test.js | Edit fields (drink, brewery, type, rating), save markup |
| favorite-card.test.js | Edit + save |
| jam-card.test.js | Edit + save |
| wish-card.test.js | Edit + save |
| mood-card.test.js | Edit fields (mood label, rating slider), save markup |
| acquisition-card.test.js | Edit + save |
| media-lookup.test.js | Search, API fetch mock, result selection, loading/error states |
| checkin-dashboard.test.js | Container render, SSR attributes |
| checkins-feed.test.js | Container render, attributes |
| venue-detail.test.js | Container render, attributes |

New files in `tests/js/editor/`:

| File | Covers |
|---|---|
| post-kinds-store.test.js | Store registration, getKind/setKind, default state, persistence |
| KindGrid.test.js | 24 kind options, click selection, highlight, keyboard nav |
| KindFields.test.js | Correct field set per kind |
| AutoDetectionNotice.test.js | Show/hide on URL, kind suggestion |
| SyndicationControls.test.js | Target checkboxes, toggle state, disabled state |

Pattern: @testing-library/react, mock @wordpress/api-fetch and @wordpress/data. 60% coverage threshold.

### Layer 6: Playwright E2E expansion

New files in `tests/e2e/`:

| File | Covers |
|---|---|
| kind-selection.spec.js | Kind selector grid, click kind, sidebar fields, save, verify term on reload |
| block-insertion.spec.js | Insert each card block, fill fields, save, verify front-end |
| media-lookup.spec.js | Search, results (network mocked), field population |
| import-flow.spec.js | Import page, service select, configure, trigger (mocked), verify posts |
| webhook-receipt.spec.js | POST to webhook endpoint, verify post created with correct kind/meta |
| settings-page.spec.js | Change options, save, reload, verify persisted |
| api-settings.spec.js | Enter API key, save, masked display, test connection |
| quick-post.spec.js | Kind select, fill, submit, verify post |
| microformats-output.spec.js | Create posts of different kinds, verify mf2 classes in front-end HTML |

Uses wp-env, Playwright page.route() for external API mocking, admin auth via test cookie.

### Layer 7: PHPUnit integration tests

New files in `tests/phpunit/integration/`:

| File | Covers |
|---|---|
| KindPostLifecycleTest.php | Create post + kind + meta, retrieve via REST, verify round-trip |
| WebhookToPostTest.php | Full webhook -> API client -> post creation pipeline |
| ImportPipelineTest.php | Import_Manager + Scheduled_Sync, cron trigger, duplicate prevention |
| SyncToPostTest.php | Each sync service end-to-end with fixtures |
| RestApiEndpointsTest.php | Full-stack REST lookups with mocked external APIs |
| BlockRenderingTest.php | Register blocks, create posts with meta, render_block(), verify HTML |
| FeatureFlagIntegrationTest.php | Toggle flags, verify abilities register/deregister, MCP hooks |
| PluginConflictTest.php | Original Post Kinds active, verify error notice, no registration |

Integration tests exercise multiple classes through WordPress hooks with real database access. External API calls still mocked via fixtures.

## Totals

| Layer | New files | Existing |
|---|---|---|
| PHPUnit unit | 43 | 7 |
| PHPUnit integration | 8 | 0 |
| Jest | 22 | 1 |
| Playwright E2E | 9 | 3 |
| Infrastructure | 1 (ApiTestCase) | 0 |
| **Total** | **83** | **11** |
