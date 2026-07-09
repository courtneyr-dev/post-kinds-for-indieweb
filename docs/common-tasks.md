# Common tasks

Step-by-step instructions for the things you'll do most often. All of these are supported by the plugin as shipped; pages assume the block editor.

## Add a listen, watch, read, or other kind post

1. Go to **Posts → Add New Post**.
2. Insert the matching card block from the **Post Kinds for IndieWeb** inserter category (Listen Card, Watch Card, Read Card, Checkin Card, Play Card, and so on).
3. Search for the media in the card (or use the **Media Lookup** block for a universal search across services), or fill fields manually.
4. Publish. The post's kind is set automatically from the first card block in the post; a manual kind choice in the Post Kind panel is never overridden.

## Assign a kind without a card block

For kinds that have no dedicated card (note, article, event, photo, video, review, recipe) or any post you want to label:

1. In the post editor sidebar, open the **Post Kind** panel.
2. Pick the kind from the grid.
3. Publish. The post gets microformats2 markup for that kind and appears on its `/kind/<slug>/` archive.

You can also assign kinds from the Kinds column in quick edit on the Posts list.

## Add API keys so media search works

1. Go to **Reactions → API Connections**.
2. Pick the service (TMDB for movies/TV, RAWG or BoardGameGeek for games, Hardcover or Google Books for books, and so on). Each card links to the provider's sign-up page.
3. Paste the credential(s) and save, then use the connection test to confirm.

MusicBrainz (music) and Open Library (books) work without keys.

## Add a check-in with controlled location privacy

1. Insert a **Checkin Card** in a new post.
2. Search for the venue (OpenStreetMap works without a key; Foursquare needs an API key) or enter it manually.
3. Set the card's location privacy — public, approximate, or private — or rely on the default from **Reactions → Settings → Checkin**.
4. Publish. The front-end markup honors the privacy level: private hides venue, address, and coordinates; approximate shows only city/region/country.

![Checkin Card with location and venue details](assets/screenshots/editor-checkin-card.png)

## Display your check-ins on a page

Add one of these blocks to any post or page:

- **Check-in Dashboard** — grid, map, or timeline of check-ins with optional stats and filters.
- **Check-ins Feed** — a recent-check-ins list with optional map and column settings.
- **Venue Detail** — one venue's info, map, and recent check-ins there.

## Import your history from another service

1. Connect the service under **Reactions → API Connections** (for example Last.fm, Trakt, Hardcover, Readwise, Foursquare).
2. Go to **Reactions → Import**.
3. Use **Preview** to check what would be imported, then **Start Import**.
4. Large imports run in the background; watch progress under **Active Imports** or via the admin notice. **Re-sync** fetches new items later.

## Set up automatic scrobbling via webhooks

1. Go to **Reactions → Webhooks**.
2. Copy the webhook URL for your service (Plex, Jellyfin, Trakt, ListenBrainz, or generic) into that service's webhook settings.
3. Generate and configure the secret key so deliveries are signature-verified.
4. Confirm incoming events in the **Webhook Log**; posts are created automatically as you play or watch things.

## Turn on POSSE syndication to Last.fm, Trakt, or Foursquare

POSSE = Publish on your Own Site, Syndicate Elsewhere. Nothing is sent until you enable it.

1. Connect the target service under **Reactions → API Connections** (Last.fm authorization, Trakt OAuth, or Foursquare OAuth).
2. Enable the matching toggle: **Scrobble to Last.fm** (Listen tab), **Sync to Trakt** (Watch tab), or **Sync to Foursquare** (Checkin tab).
3. Publish a post of that kind; check **Reactions → Syndication** for its status, or use "Syndicate Now" on skipped posts.

## Post from a mobile app via Micropub

1. Install and configure the separate [Micropub plugin](https://wordpress.org/plugins/micropub/) and an IndieAuth setup (for example the IndieAuth plugin).
2. Sign in to a Micropub app with your site URL and publish.
3. Post Kinds for IndieWeb converts the incoming post into the right card block and assigns the kind (it detects listens, watches, reads, check-ins, RSVPs, likes, reposts, bookmarks, replies, and more, and handles photos and `geo:` locations).

## Change which category kind posts get

1. Go to **Reactions → Settings → General**.
2. Set **Default category** (or "— None —" to disable). It's applied once at first save to stream-shaped kind posts, and you can still remove it per post.

## Keep post formats and kinds in sync

1. Go to **Reactions → Settings → General**.
2. Leave **Sync Post Formats to Kinds** on (the default) and adjust the mapping table if you want different pairings (for example Audio → listen, Link → bookmark).

## Confirm your microformats are valid

1. Publish a kind post.
2. Visit [pin13.net/mf2](https://pin13.net/mf2/) and enter the post URL.
3. The parser shows the detected microformats (for example `h-entry` with `u-listen-of`, or `p-rsvp` for RSVPs).

## Clear cached API data

1. Go to **Reactions → Settings → Tools**.
2. Use **Clear API Cache**, **Clear Metadata Cache**, or **Clear All Caches** — useful when a lookup returned stale or wrong metadata.

## Route activity kinds into a stream instead of the main archive

For theme-savvy users: the plugin can classify kinds as "stream" (short activity posts) vs "main" (long-form) without ever filtering your queries — your theme decides what to do with the signal.

1. Add the `pkiw_stream_kinds` filter in your theme or a small plugin, per the readme example:

   ```php
   add_filter( 'pkiw_stream_kinds', fn() => [ 'checkin', 'eat', 'drink', 'listen', 'jam' ] );
   ```

2. To promote an individual post back to the main archive, use the **Promote to main archive** toggle in the editor's Post surface panel (or the `pkiw-promote` Micropub property).
3. After a bulk import, run `wp postkind surfaces backfill` once (WP-CLI).

---

[Documentation home](index.md) · Previous: [Settings](settings.md) · Next: [Screenshots](screenshots.md)
