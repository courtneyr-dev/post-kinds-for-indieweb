# Getting Support

Thanks for using Post Kinds for IndieWeb! Here's how to get help.

## Before You Ask

Try these steps first — they resolve most issues:

1. **Check requirements** — WordPress 6.9+, PHP 8.2+, plugin activated
2. **Clear caches** — browser cache, caching plugins, server-side caches
3. **Test for conflicts** — switch to Twenty Twenty-Five, deactivate other plugins, check if the issue persists
4. **Check error logs** — enable `WP_DEBUG` in wp-config.php, check `debug.log` and browser console

## Where to Get Help

| Channel                                                                                     | Best for                             |
| ------------------------------------------------------------------------------------------- | ------------------------------------ |
| [GitHub Issues](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues/new/choose) | Bug reports, feature requests        |
| [GitHub Discussions](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)  | Questions, ideas, general discussion |
| [IndieWeb Chat](https://chat.indieweb.org/)                                                 | Real-time community help             |

## Writing a Good Bug Report

Include this information so we can help quickly:

```
WordPress version:
PHP version:
Plugin version:
IndieBlocks version: (or "not installed")
Theme:

What happened:
What I expected:
Steps to reproduce:
Error messages:
```

For feature requests, describe the problem you're solving and how IndieWeb users benefit.

## FAQ

<details>
<summary><strong>Do I need IndieBlocks?</strong></summary>

No, but it's recommended. IndieBlocks provides core blocks for bookmarks, likes, replies, and reposts. Post Kinds for IndieWeb adds complementary post kinds (listen, watch, read, checkin, play, etc.) and enhanced media features. Both plugins work independently.

</details>

<details>
<summary><strong>How do I set up API keys?</strong></summary>

Go to **Settings > Post Kinds for IndieWeb > API Settings**. Each service has a link to register for a key. Some services (MusicBrainz, Open Library) don't need keys at all.

</details>

<details>
<summary><strong>Why aren't my post kinds showing up?</strong></summary>

Check these common causes:

1. **Post kind not set** — make sure the post has a kind selected in the editor
2. **Template issue** — your theme needs to support post content or use the plugin's patterns
3. **Caching** — clear all caches after making changes
4. **JavaScript error** — check browser console (F12) for errors

</details>

<details>
<summary><strong>How do I import from external services?</strong></summary>

Go to **Tools > Post Kinds Import**, select your service (Trakt, Last.fm, etc.), connect your account or upload an export file, configure options, and click Import. Large imports run in the background via WP-Cron.

</details>

<details>
<summary><strong>Why isn't the media search finding anything?</strong></summary>

1. Check that your API key is entered correctly (for TMDB, Last.fm)
2. Verify your server can make outbound HTTPS requests
3. Try different search terms
4. Check **Settings > Post Kinds for IndieWeb > Debug** for API errors

</details>

<details>
<summary><strong>How do I customize block appearance?</strong></summary>

In order of complexity:

1. **Block settings** — sidebar controls in the editor
2. **Global Styles** — Appearance > Editor > Styles
3. **Custom CSS** — add CSS via Customizer or theme
4. **Child theme** — create template overrides

</details>

<details>
<summary><strong>Why aren't microformats showing correctly?</strong></summary>

1. Test at [pin13.net/mf2](https://pin13.net/mf2/)
2. Make sure your theme isn't stripping HTML classes
3. Check that no caching plugin is modifying output
4. Verify the post has proper metadata filled in

</details>

<details>
<summary><strong>Can I use this without the block editor?</strong></summary>

The custom blocks require Gutenberg. The post kind taxonomy works with the Classic Editor but with limited UI. Meta fields can be set programmatically via the REST API.

</details>

<details>
<summary><strong>Can I use this with the original Post Kinds plugin?</strong></summary>

No. This plugin replaces the original. Using both will cause conflicts. Deactivate the original Post Kinds plugin before activating this one.

</details>

## Common Issues

### Block not appearing in the inserter

Clear your browser cache and reload the editor. If that doesn't work, deactivate and reactivate the plugin.

### "Failed to fetch" API errors

Check that API keys are correct in Settings. Verify your server can make outbound HTTPS requests. Check **Settings > Post Kinds for IndieWeb > Debug** for details.

### Styles not loading

Run `npm run build` if developing locally. Clear all caches. Check the browser network tab for 404 errors on CSS files.

### Import stuck or timing out

Increase `max_execution_time` in your PHP configuration. Try importing in smaller batches. Check PHP error logs for memory issues.

### Microformats not parsed correctly

Verify your theme isn't stripping HTML classes. Test with a default theme (Twenty Twenty-Five). View page source to confirm the correct HTML structure is present.

## Debug Information

When reporting issues, gather debug info from one of these sources:

- **Plugin debug** — Settings > Post Kinds for IndieWeb > Debug > Copy Debug Info
- **Site Health** — Tools > Site Health > Info
- **Browser console** — F12 > Console tab
- **PHP logs** — check `error_log` or `debug.log`

## Response Times

This is a community project maintained by volunteers:

- **Issues** — reviewed within a few days
- **Discussions** — community may respond faster
- **Pull requests** — reviewed when maintainers are available

For urgent help, try [IndieWeb Chat](https://chat.indieweb.org/) for real-time community support.

## Contributing

Solved a problem? Consider helping others:

- Answer questions in [Discussions](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)
- Submit fixes via pull request
- Improve documentation

See [CONTRIBUTING.md](CONTRIBUTING.md) for details.
