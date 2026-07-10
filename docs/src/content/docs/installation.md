---
title: Installation
description: "Install and activate Post Kinds for IndieWeb, check the WordPress 7.0 and PHP 8.2 requirements, and confirm the Reactions menu appears."
---

How to install and activate Post Kinds for IndieWeb, what the plugin requires, and how to confirm it's working.

## Requirements

- **WordPress 7.0 or later.** This minimum is unusually high — sites on WordPress 6.x can't run this plugin. If your WordPress version is too old, activation is blocked and an admin notice tells you which version you're running and what's required.
- **PHP 8.2 or later**, enforced the same way with an admin notice.
- The custom blocks require the **block editor**. Classic Editor users get only basic kind assignment through the taxonomy box.

## Before you install: check for the classic Post Kinds plugin

Post Kinds for IndieWeb is a successor to David Shanske's classic [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) plugin (`indieweb-post-kinds`). Both use the same `kind` taxonomy, so they conflict:

- If the classic Post Kinds plugin is active (including network-activated on multisite), Post Kinds for IndieWeb **refuses to initialize** and shows an error notice: it cannot run while Post Kinds is active.
- Deactivate the classic Post Kinds plugin before (or after) activating this one. Use one or the other, never both.

## Install from a ZIP file

1. Download a ZIP of the plugin — from the repository's [Releases page](https://github.com/courtneyr-dev/post-kinds-for-indieweb/releases) if one is published, or via **Code → Download ZIP** on the [GitHub repository](https://github.com/courtneyr-dev/post-kinds-for-indieweb).
2. In wp-admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate**.

The repository commits its built block assets in the `build/` directory, so a ZIP made from the repository works without a build step.

The plugin's readme.txt also describes installing from the WordPress.org plugin directory (**Plugins → Add New**, search for "Post Kinds for IndieWeb"). If the plugin appears there for your site, that's the simplest route.

## Install from GitHub (clone)

1. Clone the repository into your plugins directory:

   ```bash
   cd wp-content/plugins
   git clone https://github.com/courtneyr-dev/post-kinds-for-indieweb.git
   ```

2. Activate **Post Kinds for IndieWeb** on the Plugins screen.

The built block assets are committed in `build/`, and the plugin uses its own PHP autoloader, so a plain clone activates and runs without running Composer or npm. The readme's GitHub instructions mention `composer install` and `npm run build` — those are needed for development or if you change the source in `src/`, not for running the plugin.

## Activate the plugin

On the **Plugins** screen, click **Activate** under Post Kinds for IndieWeb. On activation the plugin:

- Registers the `kind` taxonomy and creates its 24 default kind terms.
- Adds a top-level **Reactions** menu (heart icon) to wp-admin.
- Adds a **Settings** link to its row on the Plugins screen.

## Companion plugins

None of these are required, but the plugin is built to work alongside them:

- **[IndieBlocks](https://wordpress.org/plugins/indieblocks/)** — recommended. The plugin shows an admin notice until IndieBlocks is active, because IndieBlocks provides companion blocks for bookmarks, likes, replies, and reposts. Post Kinds for IndieWeb detects IndieBlocks; it doesn't replace it.
- **[Micropub](https://wordpress.org/plugins/micropub/)** — required only if you want to publish from Micropub apps (mobile composers and similar). Without it, Micropub posting doesn't work at all; with it, this plugin converts incoming Micropub posts into card blocks and assigns the right kind. Micropub apps also need IndieAuth for sign-in (for example the [IndieAuth plugin](https://wordpress.org/plugins/indieauth/)).
- **[Webmention](https://wordpress.org/plugins/webmention/)** — detected and shown on the Integrations settings tab for cross-site conversations. This plugin does not send or receive webmentions itself.
- **[Syndication Links](https://wordpress.org/plugins/syndication-links/)** — the "Enable Syndication" setting notes it requires this plugin.
- **[Bookmark Card](https://wordpress.org/plugins/bookmark-card/)** — detected; enhances the bookmark kind.
- **[WP Recipe Maker](https://wordpress.org/plugins/wp-recipe-maker/)** — the recipe kind integrates with it (there is no dedicated recipe card block).

## Confirm the plugin is working

Any one of these confirms a successful install:

1. A **Reactions** menu (heart icon) appears in the wp-admin sidebar, with Settings, API Connections, Import, Webhooks, Quick Post, Syndication, and Check-ins submenus.
2. Open a new post in the block editor and open the block inserter — a **Post Kinds for IndieWeb** category lists the card blocks. (See the block inserter in [Screenshots](/post-kinds-for-indieweb/screenshots/).)
3. The post editor sidebar shows a **Post Kind** panel with a grid of kinds.
4. **Posts → Kinds** lists the 24 kind terms (Note, Article, Listen, Watch, and so on).

If instead you see an error notice about the Post Kinds plugin, deactivate the classic `indieweb-post-kinds` plugin. If you see a notice about PHP or WordPress versions, update your environment first.
