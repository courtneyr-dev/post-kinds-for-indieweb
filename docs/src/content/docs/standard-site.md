---
title: Standard.site records
description: "Read a bookmarked page's own metadata from AT Protocol when the site publishes a standard.site document record."
---

When you bookmark, like, reply to, or note that you read someone else's page, Post Kinds stores the URL and whatever it can work out from the page. If that page publishes a [standard.site](https://standard.site) record, there is something better available: the author's own metadata, written by them, read straight from their AT Protocol repository.

You get the title they gave it, their description, their tags, the publication it belongs to, and the date they published it. Not a scrape, and not a guess.

Nothing to set up. There is no account to connect, no key to paste, and no setting to switch on. Only sites that publish these records are affected, and everything else behaves exactly as before.

## Using it

1. Add a Bookmark, Like, Reply, Repost, Favorite, Jam, or Wish card to a post
2. Enter the URL you are citing
3. Open the block settings sidebar and find **Standard.site record**
4. Choose **Check this URL**

![The Standard.site record panel in a Bookmark Card's settings, showing the resolved title "Blogging: It's more social than ever!", the line "From pckt - notes", the author's description and tags, and a link reading View the record](../../assets/screenshots/standard-site-panel.png)

If the page publishes a record, the panel shows what the author wrote and a link to the record itself. If it does not, the panel says so. Most of the web does not publish these records yet, so that is the ordinary result rather than a sign that anything went wrong.

The check only runs when you ask for it. Nothing is fetched while you type, and the panel stays hidden until the block has a URL in it.

Posts you publish are also checked in the background, a few seconds after saving, so a record can be found without opening the sidebar at all.

## What "verified" means

A page claims a record by putting a tag in its HTML that names the record's address. On its own that is only a claim: any page can name any record, including one written by somebody else.

So the claim gets checked in both directions. Post Kinds reads the record, follows it back to the publication it belongs to, rebuilds the address that record says it lives at, and confirms that address is the page you started from. Only then does it count as verified.

An unverified record still appears in the sidebar, with a warning above it, because seeing it is more useful than hiding it. It is never saved to your post — an attribution that might be wrong is worse than none at all.

## Where the data goes

Nowhere. This reads; it does not write. Your posts are not published to AT Protocol, no account of yours is involved, and nothing about you is sent to the sites being checked beyond an ordinary request for a page you already linked to.

Publishing your own posts as standard.site records is a different job that needs an authenticated connection. Post Kinds does not do it.

See [Privacy and data](/post-kinds-for-indieweb/privacy-and-data/) for exactly which servers are contacted and when.

## For developers

Resolution is available directly:

```php
$result = \PKIW\Standard_Site::resolve_url( 'https://example.com/a-post' );

if ( $result && $result['verified'] ) {
    echo $result['record']['title'];
    echo $result['publication']['record']['name'] ?? '';
}
```

`resolve_url()` returns `null` when the page publishes no record. When it finds one, the array holds:

### uri

The record's AT-URI, such as `at://did:plc:abc123/site.standard.document/3mp3nrm5leyim`.

### did

The identifier of whoever wrote it.

### record

The record itself, as the author wrote it. Follows the [document lexicon](https://standard.site/docs/lexicons/document/).

### publication

The publication the document belongs to, or `null` for a document that names no publication.

### verified

Whether the record points back at the URL it was found on, as described above.

Look a site up from its domain with `resolve_publication()`:

```php
$publication = \PKIW\Standard_Site::resolve_publication( 'https://notes.example.com' );
```

Results are cached for a day, including misses.

A resolved AT-URI is stored on the post in the `_pkiw_standard_site_uri` meta key, and only when verified. `Standard_Site::get_post_document_uri( $post_id )` reads it back.

The REST route behind the sidebar panel is `GET /post-kinds-indieweb/v1/resolve/standard-site?url=…`. It needs the `edit_posts` capability and allows 30 lookups per five minutes per person.

## Reading further

- [standard.site documentation](https://standard.site/docs/introduction/) — what the records are for
- [How verification works](https://standard.site/docs/verification/) — the specification behind the two-way check
- [Privacy and data](/post-kinds-for-indieweb/privacy-and-data/) — which servers are contacted
