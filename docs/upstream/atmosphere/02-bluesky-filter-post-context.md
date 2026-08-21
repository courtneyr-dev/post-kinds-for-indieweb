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
