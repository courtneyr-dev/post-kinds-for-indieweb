# Upstream proposal: pass the post to atmosphere_should_publish_bluesky_post

**Target:** Automattic/wordpress-atmosphere trunk (the filter is
`@since unreleased`; it does not exist in the 2.1.0 release)
**Status:** FILED 2026-08-21 as https://github.com/Automattic/wordpress-atmosphere/issues/239 (text below is what was filed).

## The gap, proven from source

`includes/functions.php` (trunk):

```php
function is_bluesky_post_enabled(): bool {
	return (bool) \apply_filters( 'atmosphere_should_publish_bluesky_post', true );
}
```

The filter receives no arguments beyond the boolean, and
`is_bluesky_post_enabled()` takes none — yet its two call sites
(`Publisher::publish_post()` and `Publisher::update_post()`) always
operate on a specific `\WP_Post`. A callback cannot make a per-post or
per-category decision through public API; the only supported choice is
site-wide.

## Why a companion needs it

Post Kinds for IndieWeb models many short-form kinds (listens, likes,
check-ins). The natural policy "publish these as Standard.site documents
but do not fan them into Bluesky feed posts" is per-kind — impossible to
express today without capturing publisher state through non-public means,
which the Post Kinds integration refuses to do.

## Smallest change

Thread the post through:

```php
function is_bluesky_post_enabled( ?\WP_Post $post = null ): bool {
	/**
	 * @param bool          $enabled Whether to publish the Bluesky companion.
	 * @param \WP_Post|null $post    The post being published, when known.
	 */
	return (bool) \apply_filters( 'atmosphere_should_publish_bluesky_post', true, $post );
}
```

and pass `$post` at the two Publisher call sites. Existing callbacks
registered with one accepted arg keep working unchanged. The filter is
still unreleased, so the signature can gain the argument before it ever
ships — after release this would be additive anyway.

---

## Issue as filed (2026-08-21, https://github.com/Automattic/wordpress-atmosphere/issues/239; no equivalent existed upstream, verified via GitHub search)

**Title:** Pass the post to `atmosphere_should_publish_bluesky_post` so integrations can decide per post

**Body:**

`atmosphere_should_publish_bluesky_post` (trunk, `@since unreleased`) makes document-only publishing possible, but the filter receives only the boolean — `is_bluesky_post_enabled()` takes no arguments while both call sites (`Publisher::publish_post()`, `Publisher::update_post()`) operate on a specific `WP_Post`. A callback can only answer for the whole site, never for the post in front of it.

**Who needs per-post answers**

Standard.site documents and Bluesky feed posts serve different audiences: the document is an archival, discoverable record — low-noise by design — while a feed post broadcasts to followers, where volume has a real social cost. Whether a given post deserves the broadcast is a property of the *post*, and a lot of WordPress content is exactly the kind of high-volume activity where that distinction bites:

- **Activity logs and scrobbles** — music, film, book, and game trackers, fitness loggers, check-in tools. Five listens a day belong in the Standard.site archive; five `app.bsky.feed.post` records a day get an account muted. Today those sites must choose all-or-nothing.
- **Automation-created posts** — webhook- and import-driven content (feed importers, syndication bridges, IFTTT-style tooling) usually shouldn't broadcast, while hand-written posts on the same site should.
- **Mixed post types** — essays plus status posts, a podcast CPT plus an episode-notes CPT, link blogs beside long-form. The long-form type wants the companion; the log type wants document-only.
- **Per-post author choice** — "publish this one quietly." With post context, any integration can build that toggle; without it, none can.

One concrete implementer: Post Kinds for IndieWeb maps 36 IndieWeb Post Kinds onto Standard.site documents and wants short-form kinds (listens, likes, check-ins) document-only while articles publish both. But the same signature serves every case above — including ATmosphere itself, if it ever wants a per-post-type setting or a per-post checkbox: post context in this filter is the primitive all of those build on.

**Proposed signature:**

```php
function is_bluesky_post_enabled( ?\WP_Post $post = null ): bool {
	/**
	 * @param bool          $enabled Whether to publish the Bluesky companion. Default true.
	 * @param \WP_Post|null $post    The post being published, when known.
	 */
	return (bool) \apply_filters( 'atmosphere_should_publish_bluesky_post', true, $post );
}
```

with `$post` passed at the two Publisher call sites.

**Backward compatibility:** existing callbacks registered with one accepted argument keep working unchanged (WordPress passes only the accepted count). The filter is still unreleased, so the signature can grow freely; the optional parameter keeps it painless even after release.

**Behavior expectations:** the filter already runs per publish and per update, so per-post answers compose naturally with auto-publish, backfill (each post's `publish_post()` call evaluates it), and update-reconciliation. The documented forward-only semantics stay intact: a post whose answer later flips must not lose its existing Bluesky record (matching today's site-wide behavior), and gaining a companion later remains the already-documented `atmosphere_update_skipped_unsynced_post` territory.

**Tests worth adding:** a one-arg callback still works (compat); per-post true/false includes/omits the `app.bsky.feed.post` write in the `applyWrites` batch; a per-post-type callback routes two CPTs differently in one backfill run; an update after the answer flips false leaves the existing Bluesky record in place.

**Docs:** extend the filter's entry in `docs/developer-docs.md` (Document-only publishing section) with the second parameter and two short examples — one per post type, one per post meta.
