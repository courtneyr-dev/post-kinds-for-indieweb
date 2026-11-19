---
title: Playground preview
description: "Try Post Kinds for IndieWeb in Block Themes on a throwaway WordPress site in your browser with WordPress Playground — no server or installation needed."
---

How to try Post Kinds for IndieWeb in Block Themes in WordPress Playground — a throwaway WordPress site that runs in your browser or terminal, no server needed.

## What the preview shows

The blueprint boots a WordPress site (latest WordPress, PHP 8.2 requested) named "Post Kinds Demo" and:

- logs you in as an admin and lands on the new-post screen, where the Post Kind panel appears in the editor sidebar,
- installs and activates the plugin plus [IndieBlocks](https://wordpress.org/plugins/indieblocks/), the recommended companion,
- imports sample posts across all 40 kinds,
- sets pretty permalinks so kind archives like `/kind/listen/` resolve.

From the new-post screen you can insert any card block (Listen Card, Watch Card, Read Card, and so on) and watch the post kind get assigned automatically. Media lookups work because the blueprint enables networking; MusicBrainz and Open Library need no API key.

## Blueprint files and which one to use

- `blueprints/blueprint.json` powers the "Open in WordPress Playground" badge in the repository.
- `.wordpress-org/blueprints/blueprint.json` powers the plugin directory's Live Preview. Both previews activate the WordPress.org package before importing posts.
- `blueprints/docs-screenshots.json` activates your local checkout, adds the named screenshot fixtures, and then imports the sample posts.

## Run it locally

From the repository root:

```bash
npx --yes @wp-playground/cli@latest server --blueprint=blueprints/docs-screenshots.json \
  --mount="$PWD:/wordpress/wp-content/plugins/post-kinds-for-indieweb" --port=9413
```

Open `http://127.0.0.1:9413/wp-admin/edit.php` to browse the posts with your local copy of PKIW.

## Use the sample posts on a disposable site

Get the WXR exports from [IndieWeb test data](https://github.com/courtneyr-dev/indieweb-test-data). You can find the same link under **Reactions → Settings → General**.

- Pick the small export for 89 sample posts across 40 kinds.
- Pick the full export for the post-format and kind matrix.
- Pick the extended export for PKIW cite, check-in, and geo metadata.

On a disposable WordPress site, activate PKIW and import the WXR file once under **Tools → Import → WordPress**. Keep sample posts off production sites.

## Enabling Live Preview on WordPress.org (maintainer actions)

1. Copy `.wordpress-org/blueprints/blueprint.json` to `assets/blueprints/blueprint.json` in the plugin's SVN repository and commit. The directory only reads blueprints from SVN `assets/blueprints/` — the repo's `blueprints/` directory isn't picked up.
2. In the plugin's admin view on WordPress.org, enable the Live Preview button.
3. Test the Live Preview link from the listing: the plugin must activate (it requires WordPress 7.0 and PHP 8.2, which the blueprint's preferred versions satisfy) and the new-post screen should show the Post Kind panel.

## Standard.site publishing and Playground

The Playground demo covers everything except Standard.site publishing. Publishing needs the optional ATmosphere companion connected to an AT Protocol account, and that connection is an OAuth round-trip to a public HTTPS site — something a temporary in-browser Playground can't complete. To try publishing for real, use an ordinary WordPress install on a public HTTPS domain, add ATmosphere (2.1.0 or later), and connect it under Settings → ATmosphere.
