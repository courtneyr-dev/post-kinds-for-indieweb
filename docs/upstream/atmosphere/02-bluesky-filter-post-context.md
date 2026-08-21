# Upstream proposal: pass the post to atmosphere_should_publish_bluesky_post

**Target:** Automattic/wordpress-atmosphere trunk (the filter is
`@since unreleased`; it does not exist in the 2.1.0 release)
**Status:** proposal only.

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

## Review-ready issue draft (2026-08-21; not filed — no equivalent issue or PR exists upstream, verified via GitHub search)

**Title:** Pass the post to `atmosphere_should_publish_bluesky_post` so companions can decide per post

**Body:**

`atmosphere_should_publish_bluesky_post` (trunk, `@since unreleased`) makes document-only publishing possible, but the filter receives only the boolean — `is_bluesky_post_enabled()` takes no arguments while both call sites (`Publisher::publish_post()`, `Publisher::update_post()`) operate on a specific `WP_Post`. A callback cannot make a per-post decision through public API.

**Use case:** Post Kinds for IndieWeb maps 36 IndieWeb Post Kinds onto Standard.site documents. Short-form kinds — listens, likes, check-ins — belong on Standard.site as documents but make noisy `app.bsky.feed.post` companions; long-form kinds want both. That's inherently a per-post (per-kind) choice, and today the only supported option is site-wide.

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

**Backward compatibility:** existing callbacks registered with one accepted argument keep working unchanged (WordPress only passes the accepted count). The filter is still unreleased, so even a required argument would be safe — the optional form keeps it painless either way.

**Behavior expectations:** the filter already runs per publish and per update, so per-post answers compose naturally with auto-publish, backfill (each post's `publish_post()` call evaluates it), and update-reconciliation. The documented forward-only semantics stay intact: flipping a post's answer later must not delete an existing Bluesky record (matching today's site-wide behavior), and gaining a companion later remains the already-documented `atmosphere_update_skipped_unsynced_post` territory.

**Tests worth adding:** callback with one arg (compat); per-post true/false routing writes/skips the `app.bsky.feed.post` write in the `applyWrites` batch; backfill honors a per-post false; an update after the answer flips false leaves the existing Bluesky record in place.

**Docs:** extend the filter's entry in `docs/developer-docs.md` (Document-only publishing section) with the second parameter and a per-post example.
