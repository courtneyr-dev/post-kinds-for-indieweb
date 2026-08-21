# Upstream proposal: adopt an existing site.standard.publication record

**Target:** Automattic/wordpress-atmosphere (verified @ `bf8e267`, 2.1.0 + 12)
**Status:** proposal only — not shipped in Post Kinds for IndieWeb; a
documented manual migration covers the gap meanwhile.

## The gap, proven from source

ATmosphere mints its publication rkey blind and never inspects the
account's existing records:

- `atmosphere.php` `activate()` — generates `atmosphere_publication_tid`
  via `TID::generate()` on activation, before any connection exists.
- `includes/transformer/class-publication.php` `get_rkey()` — lazily
  generates when the option is empty; no filter runs on the value.
- `includes/class-publisher.php` `sync_publication()` — unconditional
  `com.atproto.repo.putRecord` at the stored TID; the docblock states the
  single-upsert design explicitly.
- Repo-wide, the only `listRecords` callers are reaction sync and the
  generic API wrapper — nothing in the publication path.

Consequence: connecting an account that **already** has a
`site.standard.publication` record (written by Sequoia, Leaflet, a static
tool, or by hand) creates a second, competing publication record. The
`/.well-known/site.standard.publication` endpoint and every document's
publication reference then point at ATmosphere's copy while the original
keeps existing — indexers see two publications claiming one URL.

The gap is additionally proven executable in Post Kinds' test suite
(`test_publication_rkey_is_minted_blind_never_adopted`): with an identity
present and no stored TID, `get_rkey()` mints with zero network requests.
The deployment that first raised the concern turned out to be a
non-case — read-only inspection found that site's publication record was
ATmosphere's own (stored TID matches the live record) — but any site
moving to ATmosphere from Sequoia, Leaflet, a static-site tool, or a
manual record still hits the limitation.

## Smallest general-purpose API

On connect (or on the first publication sync after connect), when
`atmosphere_publication_tid` is still unset:

1. `com.atproto.repo.listRecords` for `site.standard.publication` in the
   connected repo (one page is plenty; the collection is near-singleton in
   practice).
2. If a record's `url` matches the site's home URL (normalized: scheme-
   and trailing-slash-insensitive), store its rkey as
   `atmosphere_publication_tid` instead of generating one. The next
   `sync_publication()` putRecord then updates the existing record in
   place — no other code changes.
3. Otherwise generate as today.

Optional companion: `wp atmosphere publication adopt <rkey>` for explicit
operator control, and a one-line filter
`atmosphere_publication_rkey( string $rkey ): string` for hosts.

Backward compatibility: sites with an already-stored TID are untouched;
the change only affects the first-run path where today's behavior is a
blind mint.

## Interim workaround (what Post Kinds documents)

```bash
wp option update atmosphere_publication_tid <existing-rkey>
```

before connecting. Works because `get_rkey()` respects a pre-seeded
option; it is an operator action on ATmosphere's own option, not
something a companion plugin should automate.
