# Playground preview

How to try Post Kinds for IndieWeb in WordPress Playground — a throwaway WordPress site that runs in your browser or terminal, no server needed.

## What the preview shows

The blueprint boots a WordPress site (latest WordPress, PHP 8.2 requested) named "Post Kinds Demo" and:

- logs you in as an admin and lands on the new-post screen, where the Post Kind panel appears in the editor sidebar,
- installs and activates the plugin plus [IndieBlocks](https://wordpress.org/plugins/indieblocks/), the recommended companion,
- seeds the listen, watch, read, checkin, and rsvp kind terms (the plugin creates the rest of its 24 kinds on init),
- sets pretty permalinks so kind archives like `/kind/listen/` resolve.

From the new-post screen you can insert any card block (Listen Card, Watch Card, Read Card, and so on) and watch the post kind get assigned automatically. Media lookups work because the blueprint enables networking; MusicBrainz and Open Library need no API key.

## Blueprint files and which one to use

- `blueprints/blueprint.json` — the standalone blueprint. It installs the plugin from the GitHub `main` branch (the `git:directory` resource), so it works anywhere the plugin isn't already present — for example the "Open in Playground" badge in `blueprints/README.md`.
- `.wordpress-org/blueprints/blueprint.json` — identical demo steps, minus the plugin-install step. Use it wherever something else already supplies the plugin: the WordPress.org Live Preview (the plugin directory installs and activates the plugin itself) and local runs that mount your working copy.

Why two files: a blueprint that installs the plugin from GitHub breaks when the plugin is already present — locally, the auto-mounted working copy plus the GitHub copy are both activated, and loading the same classes twice is a PHP fatal error (verified: the boot fails at the term-seeding step). On WordPress.org the install step would at best fetch a redundant second copy from GitHub instead of the reviewed directory version. So the self-install lives only in the standalone blueprint, and the no-install variant covers both "plugin already supplied" contexts.

## Run it locally

One command, from the repository root (uses the no-install variant because `--auto-mount` supplies your working copy as the plugin):

```bash
npx --yes @wp-playground/cli@latest server --auto-mount . --blueprint .wordpress-org/blueprints/blueprint.json --login --port 9413
```

Then open `http://127.0.0.1:9413/wp-admin/post-new.php`. Local changes to the plugin show up live.

## Enabling Live Preview on WordPress.org (maintainer actions)

PREFLIGHT: the plugin isn't listed on WordPress.org yet, so these steps apply only after the slug is claimed and the plugin is approved.

1. Copy `.wordpress-org/blueprints/blueprint.json` to `assets/blueprints/blueprint.json` in the plugin's SVN repository and commit. The directory only reads blueprints from SVN `assets/blueprints/` — the repo's `blueprints/` directory isn't picked up.
2. In the plugin's admin view on WordPress.org, enable the Live Preview button.
3. Test the Live Preview link from the listing: the plugin must activate (it requires WordPress 7.0 and PHP 8.2, which the blueprint's preferred versions satisfy) and the new-post screen should show the Post Kind panel.

---

[Documentation home](index.md)
