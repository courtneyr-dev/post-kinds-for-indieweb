# ATmosphere integration — Standard.site publishing

Post Kinds for IndieWeb publishes posts to the Standard.site ecosystem
(AT Protocol) **through the [ATmosphere plugin](https://wordpress.org/plugins/atmosphere/)**,
never beside it. ATmosphere is an **optional companion**: Post Kinds
installs, activates, and works fully without it, and every ATmosphere
state short of connected degrades to a contextual status line on the
Integrations settings tab. ATmosphere owns the protocol; Post Kinds owns
the Post Kind semantics. This document is the developer contract: what
each side owns, which public APIs the integration uses, what it adds,
and what is deliberately not implemented.

Verified against ATmosphere 2.1.0 (wp.org release, 2026-07-22) and
repository `Automattic/wordpress-atmosphere` @ `bf8e267` (trunk,
2.1.0 + 12). Minimum supported version: **2.1.0**, enforced at runtime
by `PKIW\Integrations\Atmosphere::MIN_VERSION`.

## Optional-integration behavior

| ATmosphere state | Post Kinds behavior |
|---|---|
| Not installed | Everything works; Integrations tab shows a recommendation with an install link |
| Installed, inactive | Everything works; capability-gated activate link |
| Active, below 2.1.0 | Everything works; integration stays unwired; the minimum version is shown |
| Active, compatible, disconnected | Policy wired but nothing publishes (ATmosphere's `is_connected()` gate); connect link shown |
| Active, compatible, connected | Standard.site publishing enabled |
| Deactivated during runtime | Next request degrades to the not-active state; verified non-fatal |
| Deleted after configuration | As not-installed; the `pkiw_atmosphere` setting persists until uninstall |
| Reauthorized / reconnected | ATmosphere's own lifecycle resumes; its per-record DID provenance prevents duplicate records across account changes |

## Compatibility matrix (verified against the 2.1.0 tag and trunk)

| API or behavior | First available | Used by Post Kinds? | Required / optional | Fallback |
|---|---|---|---|---|
| `ATMOSPHERE_VERSION` constant | ≤2.1.0 | yes — detection | required | integration unwired without it |
| `atmosphere_transform_document` filter | 1.0.0 | yes — title + kind tag | required | — |
| `atmosphere_disabled` registered meta | ≤2.1.0 | yes — eligibility default | required | — |
| `Document::META_URI/META_TID`, `Post::META_TID` | ≤2.1.0 | yes — guards, status column | required | — |
| `is_connected()` / `needs_reauth()` / `settings_url()` | ≤2.1.0 | yes — status surface | required | `function_exists`-guarded |
| `add_post_type_support( …, 'atmosphere' )` | ≤2.1.0 | yes — reaction CPT mode | optional | skipped |
| `atmosphere_pre_apply_writes` | ≤2.1.0 | tests only | optional | — |
| `atmosphere_document_links/labels/contributors` | 2.0.0 | no — documented decision | optional | n/a |
| `atmosphere_should_auto_publish` / `_should_sync_publication` / `_connection_only_mode` | ≤2.1.0 | no | n/a | n/a |
| `atmosphere_publish_post_result` | ≤2.1.0 | no — ATmosphere's own panel surfaces results | optional | n/a |
| Content-parser registry / preview transformers | 1.2.0 / 2.0.0 | no — built-in HTML parser chosen | optional | n/a |
| `atmosphere_should_publish_bluesky_post` (document-only lane) | **trunk only** (`@since unreleased`; absent from the 2.1.0 tag — verified) | documented only | optional | blocked until released |
| AT Tags output + `atmosphere_at_tags` | **trunk only** | no | n/a | docs note the difference |

One behavioral difference found while testing both (pinned by the live
suite's dual-record seeding): **released 2.1.0 emits the
`<link rel="site.standard.document">` tag only when the post also has a
Bluesky record** (`output_document_link()` bails on an empty
`Post::META_URI`, then rebuilds the URI from the stored doc TID); trunk
emits from the stored document URI itself. Immaterial for 2.1.0 users —
every 2.1.0 publish is a dual publish — but worth knowing when reasoning
about document-only futures.

A non-gating CI job runs the integration group against ATmosphere trunk
to catch upcoming-release breakage early.

## Ownership boundary

| Behavior | Owner |
|---|---|
| AT Protocol OAuth (PKCE, DPoP, PAR), token encryption/refresh | ATmosphere |
| Handle/DID/PDS resolution, XRPC requests, SSRF posture | ATmosphere |
| `site.standard.publication` record (creation, refresh, theme, icon blob) | ATmosphere |
| `site.standard.document` records: creation, update, deletion via `applyWrites`; rkeys, CIDs | ATmosphere |
| Blob uploads (cover images, icons) | ATmosphere |
| `/.well-known/site.standard.publication`, `/.well-known/atproto-did` | ATmosphere |
| `<link rel="site.standard.document">`, `<link rel="site.standard.publication">` head tags | ATmosphere |
| Publish/update/unpublish/trash/restore/delete propagation, retries, reconcile | ATmosphere |
| `wp atmosphere backfill`, `?atproto` record previews, Site Health, editor share toggle | ATmosphere |
| **Post Kind eligibility policy** (which kinds publish by default) | **Post Kinds** |
| **Derived document titles** for untitled kinds | **Post Kinds** |
| **Kind slug as a document tag** | **Post Kinds** |
| **Check-in privacy tiers in derived fields** | **Post Kinds** |
| **Preview parity** (keeping hidden mf2 markers out of `?atproto` projections) | **Post Kinds** |
| **Integration status surfaces** (settings field, posts-list column, dependency notice) | **Post Kinds** |
| Reading *other* sites' Standard.site records (`PKIW\Standard_Site`) | **Post Kinds** (consumer, pre-existing — unrelated to ATmosphere's publisher role) |

Post Kinds performs **no** PDS writes, stores **no** connection state, and
registers **no** competing `.well-known` route, head tag, queue, or
lifecycle engine. The final diff is grep-clean for OAuth/DPoP/applyWrites/
createRecord/putRecord/deleteRecord/uploadBlob/well-known implementations.

## ATmosphere public APIs the integration uses

| API | Kind | Used for |
|---|---|---|
| `ATMOSPHERE_VERSION` constant | detection | availability + minimum-version gate |
| `atmosphere_transform_document` filter | enrichment | derived title + kind tag (`Atmosphere_Document_Map::enrich()`); shared by publish and preview paths, so both always agree |
| `atmosphere_disabled` registered post meta (`ATMOSPHERE_META_DISABLED`) | per-post control | the eligibility default rides WordPress's `default_post_metadata` filter on this key — no state written, the author's toggle always wins |
| `\Atmosphere\Transformer\Document::META_URI` / `META_TID`, `\Atmosphere\Transformer\Post::META_TID` | published-state detection | never default-disabling an already-published post; the posts-list column |
| `\Atmosphere\is_connected()`, `\Atmosphere\needs_reauth()`, `\Atmosphere\settings_url()` | status surfaces | settings field + deep link into ATmosphere's own UI |
| `atmosphere_pre_apply_writes` filter | tests only | short-circuiting the PDS in the both-plugins suite |
| `add_post_type_support( $type, 'atmosphere' )` | CPT support | opt-in for the `pkiw_reaction` CPT when import storage uses it |

The integration does not use `atmosphere_connection_only_mode` — ATmosphere
publishes normally; Post Kinds only steers policy through public hooks.
It also does not use the deprecated `atmosphere_content_parser` filter.

## Content format decision

The built-in `org.wordpress.html` parser (Registry priority 10) renders
posts through `the_content`, which executes the kind card blocks'
`render.php` — so listen/review/check-in cards arrive in `content.html`
and `textContent` fully rendered, with the plugin's privacy tiers already
applied by the cards themselves. No Post Kinds-specific
`Content_Parser` is registered: tests against the real transformer showed
no material information loss, and a custom format would fragment the
ecosystem for no gain. Revisit only if a typed kind lexicon emerges.

## Hooks Post Kinds adds

All new filters live in `includes/integrations/` with PHPDoc and tests:

| Hook | Signature | Purpose |
|---|---|---|
| `pkiw_atmosphere_default_eligible_kinds` | `( string[] $kinds )` | Which kinds publish by default (the settings checkboxes feed this) |
| `pkiw_atmosphere_post_default_disabled` | `( bool $disabled, WP_Post $post, string $kind )` | Final say on a post's *default*; a stored per-post value never reaches it |
| `pkiw_atmosphere_document_title` | `( string $title, WP_Post $post, ?string $kind )` | Override the derived document title ('' = leave ATmosphere's mapping) |
| `pkiw_atmosphere_document_kind_tag` | `( ?string $tag, WP_Post $post, string $kind )` | Change or (null) drop the kind tag |
| `pkiw_feature_flag_atmosphere_integration` | `( ?bool $enabled )` | Kill switch for the whole integration |

Example — publish check-ins by default on one site:

```php
/**
 * Make check-ins Standard.site-eligible by default.
 *
 * @param string[] $kinds Eligible kind slugs.
 * @return string[]
 */
add_filter(
	'pkiw_atmosphere_default_eligible_kinds',
	static function ( array $kinds ): array {
		$kinds[] = 'checkin';
		return $kinds;
	}
);
```

## Eligibility model

- 22 kinds default-eligible (public content, public consumption logs,
  and substantive responses), 14 opt-in (thin signals and
  privacy-sensitive kinds). The complete verified inventory and per-kind
  reasoning: [standard-site-kind-eligibility.md](standard-site-kind-eligibility.md).
- Unknown kinds (added via the `pkiw_default_kinds` registry filter)
  default to opt-in by construction.
- Mechanism: a `default_post_metadata` filter (priority 20) answers `'1'`
  (disabled) for non-eligible kinds **only when the post has no stored
  `atmosphere_disabled` value and no published record meta**. Zero writes;
  fully reversible; `\Atmosphere\is_post_publishable()` and everything
  built on it (auto-publish, backfill, editor panel) see one consistent
  answer.
- First activation seeds the site setting once: if any default-off kind
  post already carries `_atmosphere_doc_uri` (the site published kinds
  through ATmosphere before this integration), every kind is seeded
  eligible so upgrade changes nothing; otherwise the recommended defaults
  are seeded.

## Standard.site-only publishing (no Bluesky companion)

ATmosphere trunk (post-2.1.0) adds `atmosphere_should_publish_bluesky_post`;
returning `false` publishes documents without `app.bsky.feed.post`
companions, forward-only. Post Kinds documents it but adds no UI and no
per-kind routing: the filter currently receives **no post context**, so a
per-kind choice cannot be built on public API — see
`docs/upstream/atmosphere/02-bluesky-filter-post-context.md`. Until that
lands, the choice is site-wide and code-level:

```php
// Publish Standard.site documents only — no Bluesky companion posts.
add_filter( 'atmosphere_should_publish_bluesky_post', '__return_false' );
```

Post Kinds never flips this itself: Bluesky behavior follows ATmosphere's
configuration unchanged, in both directions.

## Existing publication records (adoption)

ATmosphere 2.1.0 **always mints its own publication rkey**
(`atmosphere_publication_tid`, generated blind on activation) and never
lists or adopts an existing `site.standard.publication` record —
verified in source (`includes/class-publisher.php::sync_publication()` is
an unconditional `putRecord` at the locally stored TID) and proven
executable in the test suite
(`test_publication_rkey_is_minted_blind_never_adopted`: with an identity
present and no stored TID, `get_rkey()` mints with zero network requests
— no `listRecords`, no adoption). A site whose account already holds a
publication record written by another tool would get a **second,
competing record**.

The general limitation stands and a first-class adoption flow belongs
upstream (`docs/upstream/atmosphere/01-publication-adoption.md`). The
site that originally motivated the concern turned out not to need it:
read-only inspection of courtneyr.dev (2026-08-21, authorized WP-CLI)
found ATmosphere 2.1.0 **active** with `atmosphere_publication_tid`
exactly matching the live publication record's rkey — the record was
ATmosphere's own all along, local and remote state agree, and no
migration is necessary there.

**Unsupported developer recovery** — not setup guidance and not a
supported migration: the proof test also pins that a pre-seeded
`atmosphere_publication_tid` is respected, so a developer can, at their
own risk, seed the existing record's rkey *before* connecting. If you go
there anyway: take a database backup first; verify the record's DID
matches the account you will connect and fetch the record by rkey via
`com.atproto.repo.getRecord` to confirm ownership; know the option is
coupled to `atmosphere_publication_cid` (captured on the next sync) and
to every document's publication reference. Wrong values make ATmosphere
upsert over a record it does not own, or split documents across two
publications.

## Failure and compatibility behavior

- **ATmosphere missing or below 2.1.0:** the integration wires nothing
  but the settings-tab status line (see the behavior matrix above). No
  site-wide notices, no fatals; all other Post Kinds features work.
- **Deactivated after load / deactivation order:** hooks reference
  ATmosphere symbols only inside availability-guarded paths; the next
  request degrades cleanly. Deactivating Post Kinds leaves ATmosphere
  publishing exactly as its own settings dictate.
- **Disconnected / reauth-required:** publishing is ATmosphere's to gate
  (`is_connected()`), and its notices/Site Health lead the user;
  Post Kinds' settings field mirrors the state and links there.
- **Posts published before this integration:** never touched — the
  published-record guard exempts them from kind defaults, and the seeding
  heuristic keeps previously-publishing sites publishing.
- **Staging clones:** connection state and record identity live in
  ATmosphere's options; its token encryption is salt-derived, so clones
  disconnect rather than double-publish. Post Kinds adds no second copy
  of any remote identifier.

## Testing

- Unit + integration suites run without ATmosphere (`composer test`);
  everything ATmosphere-dependent skips cleanly.
- The both-plugins suite runs when the bootstrap can load ATmosphere:

```bash
WP_TESTS_DIR=~/.wp-tests/wordpress-tests-lib \
PKIW_TESTS_ATMOSPHERE_FILE=~/projects/wordpress-atmosphere/atmosphere.php \
vendor/bin/phpunit --group atmosphere
```

- CI checks out `Automattic/wordpress-atmosphere@2.1.0` and sets the env
  var, so the live suite runs on every PR.
- Network writes are short-circuited through ATmosphere's own
  `atmosphere_pre_apply_writes` seam; no test needs credentials or a PDS.
- Real-PDS contract tests (OAuth, verification round-trip, indexer
  visibility) require a disposable AT Protocol account and are documented
  in `docs/audit/standard-site-publisher-implementation.md` — they are
  not run in CI and not claimed as passed.

## Known limitations

- Per-kind Bluesky routing: blocked upstream (no post context in the
  filter), documented above.
- The e2e/visual-regression matrix now runs with ATmosphere active but
  disconnected; specs asserting editor-sidebar composition may need
  baseline updates via the Visual Baselines workflow.
- Trash → restore re-publishes with fresh record identities (ATmosphere
  semantics: cleanup deletes local record meta; restore is a new publish).
- Multisite: ATmosphere models one publication per site and no non-root
  `.well-known` path; subdirectory-multisite verification is an upstream
  limitation Post Kinds inherits.
