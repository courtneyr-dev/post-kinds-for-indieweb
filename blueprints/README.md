# WordPress Playground Blueprints

This directory contains WordPress Playground blueprints for testing and demonstrating Post Kinds for IndieWeb.

## Quick Start

Try the plugin instantly in your browser:

[![Open in WordPress Playground](https://img.shields.io/badge/Open%20in-WordPress%20Playground-blue?logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/courtneyr-dev/post-kinds-for-indieweb/main/blueprints/blueprint.json)

## Available Blueprints

### `blueprint.json` - Default Demo

The main demo blueprint that:
- Installs the latest WordPress with PHP 8.2 (the plugin requires WordPress 7.0+)
- Installs Post Kinds for IndieWeb from WordPress.org
- Installs IndieBlocks for complementary functionality
- Pre-configures permalink structure
- Imports the small IndieWeb test data export after PKIW registers the kind taxonomy
- Opens directly to the new post screen

Open **Posts → All Posts** to browse the imported posts.

### `.wordpress-org/blueprints/blueprint.json` - WordPress.org variant

The directory Blueprint installs and activates PKIW before it imports the same
sample posts. WordPress.org reads it from `assets/blueprints/blueprint.json` in SVN.

### `docs-screenshots.json` - Local checkout

This Blueprint activates the mounted checkout, creates the named screenshot
fixtures, then imports the shared sample posts. From the plugin root, run:

```sh
wp-playground-cli server --blueprint=blueprints/docs-screenshots.json \
  --mount="$PWD:/wordpress/wp-content/plugins/post-kinds-for-indieweb"
```

### Test data for disposable sites

Use [IndieWeb test data](https://github.com/courtneyr-dev/indieweb-test-data) for
content testing in Playground and disposable local, CI, or staging WordPress
installs. Activate PKIW before importing. In a local install, use **Tools →
Import → WordPress** to import the WXR file once.

- Use the small export for 89 sample posts across 40 kinds.
- Use the full export for all 400 post-format and kind combinations.
- Use the extended export when the test needs PKIW cite, check-in, or geo metadata.

Pin the fixture commit in automated previews. Keep test-specific fixtures for
individual assertions, and keep the shared exports off the live site.

## Using Blueprints

### Via URL

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/courtneyr-dev/post-kinds-for-indieweb/main/blueprints/blueprint.json
```

### Via Embed

```html
<iframe
  src="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/courtneyr-dev/post-kinds-for-indieweb/main/blueprints/blueprint.json"
  style="width: 100%; height: 600px; border: 1px solid #ccc;"
></iframe>
```

### Programmatically

```javascript
import { startPlaygroundWeb } from '@wp-playground/client';

const client = await startPlaygroundWeb({
  iframe: document.getElementById('wp'),
  remoteUrl: 'https://playground.wordpress.net/remote.html',
  blueprint: {
    // ... blueprint contents
  }
});
```

## Creating Custom Blueprints

See the [WordPress Playground Blueprint documentation](https://wordpress.github.io/wordpress-playground/blueprints-api/index) for full reference.

### Blueprint Schema

```json
{
  "$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "landingPage": "/wp-admin/",
  "preferredVersions": {
    "php": "8.2",
    "wp": "latest"
  },
  "steps": [
    // ... installation and configuration steps
  ]
}
```

## Automated Screenshots

For documentation, you can automate screenshot generation using Playwright with Playground:

```javascript
const { chromium } = require('@playwright/test');

async function captureScreenshots() {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  await page.goto('https://playground.wordpress.net/?blueprint-url=...');
  await page.waitForSelector('.wp-block-editor');
  await page.screenshot({ path: 'screenshots/editor.png' });

  await browser.close();
}
```

## Contributing

When adding new blueprints:

1. Test the blueprint works in WordPress Playground
2. Document what the blueprint does
3. Update this README with the new blueprint
4. Consider adding automated tests
