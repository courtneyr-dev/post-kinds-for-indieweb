---
title: Schema and featured images
description: "How kind artwork becomes the featured image and the schema.org image: precedence rules, the Yoast SEO integration, the off switch, and the backfill command."
---

A listen post is often two words and a card. It has no featured image, and its artwork — album cover, movie poster, book cover, game art, checkin photo — lives inside the card, where theme templates and search engines can't see it. The plugin closes both gaps from the same data, following one rule: **only real media, never invented media**.

## What happens on save

When you save a kind post that has representative artwork but no featured image:

1. The artwork is imported into your media library (once per URL) and set as the post's **featured image**, so it appears everywhere featured images do — archive grids, related-post cards, feeds. Artwork that's already in your media library is reused without downloading anything. The image gets the post title as alt text if it has none.
2. With Yoast SEO active, the featured image then flows into the **schema.org graph** (`Article` image, `primaryImageOfPage`) through Yoast's own resolution — the same as any hand-picked featured image.

Where does "representative artwork" come from? The card's image field: the Listen Card's cover image, Watch Card's poster, Read Card's book cover, Jam and Play Card art, and the Checkin Card photo. Editor-created and Micropub-created posts behave identically.

## What never happens

- **A featured image you chose is never replaced.** The plugin only ever fills silence.
- **Removing the auto-set image sticks.** Delete the featured image and it stays gone — the same artwork URL is not re-applied on the next save.
- **No stand-in images.** A post with no real artwork gets no featured image and no schema image. The plugin never assigns a site logo or placeholder to quiet an SEO warning, and it never pads content or word counts.
- **No repeat fetches.** A failed download is recorded and not retried until the artwork URL actually changes.
- **Only safe URLs.** Artwork must be a well-formed absolute `http(s)` URL; anything else is ignored.

## Schema without a featured image

If a kind post still has no featured image (for example, you removed the auto-set one) but valid card artwork exists, the Yoast integration adds the image to the schema graph directly — an `ImageObject` wired exactly the way Yoast wires a native featured image — without touching the post. If Yoast already found any image (featured image or an image in the post content), the integration stays out of the way entirely.

Without Yoast SEO installed, the integration is inert: no hooks, no output, no second schema graph.

## Backfill existing posts

Posts created before this feature gain the schema behavior automatically. To also give them featured images, run:

```bash
wp postkind featured-artwork backfill --dry-run
```

Review the counts, then run it without `--dry-run`. Posts that already have a featured image are skipped, and previously attempted artwork URLs are not retried — deliberate removals stay removed.

## Turning it off

To keep artwork out of featured images entirely (schema output is unaffected):

```php
add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
```

The filter also receives the post ID and resolved artwork URL, so you can disable it selectively:

```php
add_filter( 'pkiw_set_featured_from_artwork', function ( $enabled, $post_id, $url ) {
	return ! has_term( 'checkin', 'kind', $post_id ); // e.g. keep checkin photos out
}, 10, 3 );
```

## Permissions

Sideloading creates media library attachments, so it requires the `upload_files` capability. A user who can edit posts but not upload media (a Contributor, or a Micropub token mapped to such a user) never triggers an import. WP-CLI and cron contexts are treated as deliberate operator actions.
