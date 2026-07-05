# Post surfaces — ephemeral/stream vs main, across PKIW + site + Outpost

**Date:** 2026-07-05
**Surfaces owner split:** Post Kinds for IndieWeb owns the reusable *signal*; each site owns its *pages*; Outpost PWA is one *writer* of the signal.
**Hard constraint:** on courtneyr.dev, RSS and every surface except the Blog page and the new Stream page stay exactly as today.

## Problem

One post type (`post`) classified by the `kind` taxonomy. The site wants a curated **Blog** (everything except ephemeral lifelog) and a **Stream** (the ephemeral lifelog), with **RSS unchanged**, and the ability to hand-promote an individual lifelog item into the Blog. This should be a reusable Post Kinds capability so other installs can make the same distinction, and settable from the Outpost PWA composer, not just the WP editor.

## Design principle

Post Kinds must not assume a site has a "Blog" and a "Stream." It exposes a **classification signal** (`stream` vs `main`) plus an override; each site wires that signal into whatever surfaces it has. One meta, three writers (editor, Micropub/Outpost, WP-CLI).

---

## Layer 1 — Post Kinds for IndieWeb (reusable primitive) — *plugin release, gated*

New "post surface" capability. **Does not filter any queries itself.**

- **Filter `pk_stream_kinds`** — `apply_filters( 'pk_stream_kinds', [] )` → array of kind slugs treated as ephemeral. **Default empty** (no behavior change for existing installs). readme documents a recommended set. This is the "easy for others to decide similarly" seam.
- **Override meta `pk_promote`** — boolean, `register_post_meta` on kind-bearing post types, `show_in_rest => true`, `single => true`, edit-gated `auth_callback`. Non-underscore key (REST + block-binding safe). Meaning: "treat this post as main regardless of kind."
- **Derived meta `_pk_surface`** — `'stream' | 'main'`, read-only/protected, recomputed on `save_post`: `stream` if the post has a kind in `pk_stream_kinds` **and** `pk_promote` is not set; else `main`. Stored so sites can query it with a single meta clause.
- **Helper + filter** — `\PostKindsForIndieWeb\get_post_surface( $post ): string` and a `pk_post_surface` filter for full programmatic override.
- **Editor control** — a `PluginDocumentSettingPanel` toggle ("Promote to main archive", label filterable) bound to `pk_promote`, shown only for kind-bearing types. (JS → rebuild `build/`.)
- **Micropub mapping** — the Micropub intake that creates the post maps an incoming property `pk-promote` → `pk_promote` meta, so Micropub clients (Outpost) can set the override at creation. *Integration point: confirm which endpoint actually processes Outpost posts on the site (PKIW content builder vs IndieBlocks vs Outpost endpoint) and add the mapping there.*
- **WP-CLI** — `wp pk surfaces backfill` recomputes `_pk_surface` for all posts.
- **Tests** — unit: `get_post_surface` (kind in/out of set, promote override, filter override); save-hook recompute on kind change and promote toggle; Micropub property → meta. CI green is the gate.

## Layer 2 — courtneyr.dev site (mu-plugin + child theme) — *site-only, staging first*

- mu-plugin sets `add_filter( 'pk_stream_kinds', fn() => [ 'checkin','eat','drink','listen','jam','mood','play','acquisition' ] )`.
- `pre_get_posts` gated to the Blog main query only — `! is_admin() && $q->is_main_query() && $q->is_home() && ! $q->is_feed()` — excludes `_pk_surface = stream` (`relation OR`: `_pk_surface NOT EXISTS` or `!= stream`, so missing = main).
- Child theme: **Stream** page via a filtered Query Loop (`_pk_surface = stream`), ~20/page, secondary query only; add **Stream** to primary nav.
- RSS `/feed/`, homepage, author, `/kind/*/` archives, tags, search: untouched.

## Layer 3 — Outpost PWA — *plugin release, gated (two deploy repos)*

- Composer gets a **"Promote to main archive"** toggle.
- On submit it includes Micropub property `pk-promote: true` when on.
- Depends on Layer 1's Micropub mapping being live on the target site.
- Test: post from the PWA with the toggle on/off → lands on Blog vs Stream correctly → `/feed/` unchanged.

---

## Build order (even doing all three)

1. **PKIW** (meta, `_pk_surface`, filter, editor toggle, Micropub mapping, CLI, tests) — everything else depends on it.
2. **Site wiring** and **Outpost composer** — parallel, both consume Layer 1.
3. **Integrate + verify on staging** end-to-end; release PKIW then Outpost on explicit go.

## Naming

`pk_stream_kinds` (filter) · `pk_promote` (public override meta) · `_pk_surface` (derived, protected) · `pk_post_surface` (filter) · `pk-promote` (Micropub property).

## The RSS guarantee (site)

The only main-query filter fires solely on `is_home() && ! is_feed()`. Feeds, the static homepage (a page), author archives, and `kind` term archives never hit that branch. Part of the test plan: assert `/feed/` is byte-identical before/after.

## Edge cases

- Posts created outside `save_post` won't have `_pk_surface` → default to `main`; `wp pk surfaces backfill` is the catch-all. Micropub creates fire `save_post`, so Outpost posts are fine.
- Kind changed post-publish → recompute on save.
- Existing PKIW installs: empty default `pk_stream_kinds` → nothing changes until a site opts in.

## Releases / gating

- PKIW: standard release, staging-tested, on explicit go.
- Outpost: release across its two deploy repos; "green deploy ≠ deployed" — verify with `wp plugin list` on target.
- Site: deploy to staging first; live on explicit go.

## Not in scope

- No RSS/feed changes. No new feeds. No changes to homepage, author, or per-kind archives. PKIW ships the signal only — it never filters a site's queries.
