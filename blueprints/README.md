# WordPress Playground Blueprints

This directory contains WordPress Playground blueprints for testing and demonstrating Post Kinds for IndieWeb.

## Quick Start

Try the plugin instantly in your browser:

[![Open in WordPress Playground](https://img.shields.io/badge/Open%20in-WordPress%20Playground-blue?logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/courtneyr-dev/post-kinds-for-indieweb/main/blueprints/blueprint.json)

## Available Blueprints

### `blueprint.json` - Default Demo

The main demo blueprint that:
- Installs the latest WordPress with PHP 8.2 (the plugin requires WordPress 7.0+)
- Installs Post Kinds for IndieWeb from the main branch
- Installs IndieBlocks for complementary functionality
- Pre-configures permalink structure
- Seeds the listen, watch, read, checkin, and rsvp kind terms (the plugin creates the rest on init)
- Opens directly to the new post screen

### `.wordpress-org/blueprints/blueprint.json` - WordPress.org variant

Same demo, minus the plugin-install step: in a WordPress.org Live Preview the
plugin directory installs and activates the plugin itself. Once the plugin is
listed, copy this file to `assets/blueprints/blueprint.json` in SVN to enable
Live Preview.

This is also the blueprint to use for local runs with `--auto-mount`, since the
mount already supplies the plugin — see [docs/playground.md](../docs/playground.md)
for the one-line command and the reasoning.

**Use this for:**
- Quick demos
- Testing new features
- Bug reproduction
- Screenshot generation

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
