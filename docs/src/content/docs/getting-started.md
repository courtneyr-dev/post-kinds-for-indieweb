---
title: Getting started
description: "Create your first post kind in the block editor: pick a kind, look up media details, insert the kind card, and publish."
---

Create your first post kind and learn how the pieces fit together. This assumes the plugin is [installed and activated](/post-kinds-for-indieweb/installation/).

## The 24 kinds at a glance

Every kind is a term in the `kind` taxonomy, and every kind gets an archive at `/kind/<slug>/`. They fall into four rough groups:

**Media and activity tracking** — listen, watch, read, play, jam, eat, drink, checkin
**IndieWeb responses** — reply, like, repost, bookmark, rsvp
**Content types** — note, article, event, photo, video, review, recipe
**Personal tracking** — favorite, wish, mood, acquisition

Most kinds have a matching **card block** (Listen Card, Watch Card, Read Card, Checkin Card, RSVP Card, Play Card, Eat Card, Drink Card, Jam Card, Favorite Card, Wish Card, Mood Card, Acquisition Card, Bookmark Card, Like Card, Reply Card, Repost Card). Seven kinds — **note, article, event, photo, video, review, and recipe** — have no dedicated card block: they work as taxonomy terms with microformats2 markup, and you build their content with regular blocks (recipe pairs with the WP Recipe Maker plugin; review data such as star ratings lives inside the other cards).

## Your first post: log something you listened to

1. Go to **Posts → Add New Post**.
2. Open the block inserter and find the **Post Kinds for IndieWeb in Block Themes** category, or type `/listen`. Insert a **Listen Card**. (See the block inserter in [Screenshots](/post-kinds-for-indieweb/screenshots/).)
3. Use the card's search field to look up a track (MusicBrainz works without any API key), or fill in track, artist, and album manually. Add a rating if you like.

   ![Listen Card block in the editor showing a song with album art and rating](../../assets/screenshots/editor-listen-card.png)

4. Publish the post.

**Expected results at each stage:**

- After inserting the card, the editor sidebar's **Post Kind** panel shows a notice that the kind was auto-detected from the block. On save, the post's kind is set to **listen** automatically — you don't have to pick it by hand. If you do pick a kind manually, your choice is never overridden.
- After publishing, the post appears at `/kind/listen/` and its HTML carries microformats2 markup (an `h-entry` with `u-listen-of`), which IndieWeb readers and parsers can understand.

The same flow works for movies (Watch Card), books (Read Card), places (Checkin Card), games (Play Card), and the rest.

![Watch Card block showing a movie with poster and review](../../assets/screenshots/editor-watch-card.png)

![Read Card block with book cover and reading progress](../../assets/screenshots/editor-read-card.png)

## Next steps

1. **Add API keys for richer search.** MusicBrainz and Open Library work without keys, but movie/TV search (TMDB), games (RAWG, BoardGameGeek), and others need keys. Go to **Reactions → API Connections**, where each service links to its sign-up page and has a "Test connection" style check. See [Settings](/post-kinds-for-indieweb/settings/#api-connections-page-reactions--api-connections).
2. **Review the General tab.** **Reactions → Settings** controls the default category for kind posts, default post status, microformats, syndication, and post-format syncing. See [Settings](/post-kinds-for-indieweb/settings/).
3. **Set your check-in privacy default.** If you plan to post check-ins, decide up front how much location detail to publish. The Checkin tab offers public / approximate / private defaults and coordinate handling. See [Privacy and data](/post-kinds-for-indieweb/privacy-and-data/).
4. **Import your history.** **Reactions → Import** pulls in listening, watching, and reading history from connected services; large imports run in the background. See [Common tasks](/post-kinds-for-indieweb/common-tasks/).

## Common first-run confusion points

- **"The blocks aren't in the inserter."** The card blocks require the block editor. If you use the Classic Editor plugin, you only get basic kind assignment with limited UI (stated in the plugin's readme FAQ).
- **"Media search finds nothing."** Usually missing API keys or a server that can't make outbound HTTPS requests. See [Troubleshooting](/post-kinds-for-indieweb/troubleshooting/#media-search-returns-no-results).
- **"There's a warning about IndieBlocks."** That's a recommendation, not an error — the plugin works without IndieBlocks, but bookmarks, likes, replies, and reposts pair with IndieBlocks' own blocks. Install IndieBlocks to dismiss the concern, or ignore the notice.
- **"I posted from my phone app and nothing happened."** Micropub posting requires the separate Micropub plugin (plus IndieAuth). See [Troubleshooting](/post-kinds-for-indieweb/troubleshooting/#posts-from-a-micropub-app-dont-arrive).
- **"My cards looked wrong after an update."** Pre-release builds had a run of card layout regressions (scattered editor layout, stripped padding and shadow on the front end), all fixed before 1.0.0. If cards look off, update to the latest version first.
