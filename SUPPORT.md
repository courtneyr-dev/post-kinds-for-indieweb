# Getting Support

Thank you for using Post Kinds for IndieWeb! This document explains how to get help.

## Before Requesting Support

Please try these steps first:

### 1. Check the Basics
- [ ] WordPress 6.5 or higher installed
- [ ] PHP 8.0 or higher
- [ ] Plugin is activated
- [ ] IndieBlocks installed (recommended but not required)

### 2. Clear Caches
- [ ] Clear your browser cache
- [ ] Clear any caching plugin caches
- [ ] Clear server-side caches if applicable

### 3. Test for Conflicts
- [ ] Switch to a default theme (Twenty Twenty-Five)
- [ ] Deactivate other plugins temporarily
- [ ] Check if the issue persists

### 4. Check Error Logs
- [ ] Enable `WP_DEBUG` in wp-config.php
- [ ] Check `debug.log` for errors
- [ ] Check browser console for JavaScript errors

## Support Channels

### GitHub Issues (Preferred)
**Best for**: Bug reports, feature requests, documentation issues

[Create an Issue](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues/new/choose)

### GitHub Discussions
**Best for**: Questions, ideas, general discussion

[Start a Discussion](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)

### IndieWeb Chat
**Best for**: Real-time help, IndieWeb community

[Join IndieWeb Chat](https://chat.indieweb.org/)

## How to Write a Good Support Request

Help us help you by including:

### For Bug Reports

```
**WordPress version**: 6.7
**PHP version**: 8.2
**Plugin version**: 1.0.0
**IndieBlocks version**: 1.2.0 (or "not installed")
**Theme**: Twenty Twenty-Five

**What happened**:
[Describe the problem]

**What I expected**:
[Describe expected behavior]

**Steps to reproduce**:
1. Go to...
2. Click on...
3. See error...

**Error messages**:
[Paste any error messages]

**Screenshots**:
[If applicable]
```

### For Feature Requests

```
**Feature description**:
[What do you want to add?]

**Problem it solves**:
[Why is this needed?]

**How IndieWeb users benefit**:
[Explain the use case]

**Examples**:
[Links to similar features elsewhere]
```

## Frequently Asked Questions

<details>
<summary><strong>Do I need IndieBlocks installed?</strong></summary>

No, but it's recommended. IndieBlocks provides the core blocks for bookmarks, likes, replies, and reposts. Post Kinds for IndieWeb extends these with additional post kinds (listen, watch, read, etc.) and enhanced features.

Without IndieBlocks, you can still use the reaction post kinds and custom blocks.
</details>

<details>
<summary><strong>How do I set up API keys?</strong></summary>

1. Go to Settings → Post Kinds for IndieWeb → API Settings
2. Enter your API keys for the services you want to use:
   - TMDB (for movies/TV): [Get key](https://www.themoviedb.org/settings/api)
   - Last.fm (for music): [Get key](https://www.last.fm/api/account/create)
3. Save settings

Some APIs (MusicBrainz, Open Library) don't require keys.
</details>

<details>
<summary><strong>Why aren't my reactions showing up?</strong></summary>

Check these common causes:

1. **Post Kind not set**: Ensure the post has a reaction type selected in the editor
2. **Template issue**: Your theme needs to support the post content or use our patterns
3. **Caching**: Clear all caches after making changes
4. **JavaScript error**: Check browser console for errors
</details>

<details>
<summary><strong>How do I import from external services?</strong></summary>

1. Go to Tools → Reactions Import
2. Select the service (Trakt, Last.fm, etc.)
3. Connect your account or upload an export file
4. Configure import options
5. Click Import

Note: Initial imports may take time for large libraries.
</details>

<details>
<summary><strong>Why isn't the media search finding anything?</strong></summary>

1. Check API key is entered (for TMDB, Last.fm)
2. Verify the service isn't rate-limited
3. Try different search terms
4. Check Settings → Post Kinds for IndieWeb → Debug for API errors
</details>

<details>
<summary><strong>How do I customize the block appearance?</strong></summary>

Options in order of complexity:

1. **Block settings**: Use the sidebar controls in the editor
2. **Global styles**: Customize via Appearance → Editor → Styles
3. **Additional CSS**: Add custom CSS via Customizer or theme
4. **Child theme**: Create template overrides
</details>

<details>
<summary><strong>Why aren't microformats showing correctly?</strong></summary>

1. Use [pin13.net/mf2](https://pin13.net/mf2/) to test your pages
2. Ensure your theme doesn't strip HTML classes
3. Check that no caching plugin is modifying output
4. Verify the post has proper metadata filled in
</details>

<details>
<summary><strong>Can I use this without the block editor?</strong></summary>

The custom blocks require the block editor (Gutenberg). However:
- Post kinds work with Classic Editor (limited UI)
- Meta fields can be set programmatically
- Shortcodes may be added in future versions
</details>

<details>
<summary><strong>How do I contribute translations?</strong></summary>

1. Generate .pot file: `wp i18n make-pot . languages/post-kinds-indieweb.pot`
2. Create translation using [Poedit](https://poedit.net/) or similar
3. Submit via pull request or [translate.wordpress.org](https://translate.wordpress.org/)
</details>

<details>
<summary><strong>Is this compatible with [other plugin]?</strong></summary>

Known compatible:
- IndieBlocks (recommended)
- IndieAuth
- Webmention
- Syndication Links
- Most SEO plugins
- Most caching plugins

Known issues:
- None reported yet

If you find an incompatibility, please report it!
</details>

## Common Issues & Solutions

### Block not appearing in inserter
```
Solution: Clear browser cache and reload editor
Also try: Deactivate/reactivate the plugin
```

### "Failed to fetch" API errors
```
Solution: Check API keys are correct
Also try: Verify server can make outbound HTTPS requests
Check: Settings → Post Kinds for IndieWeb → Debug
```

### Styles not loading
```
Solution: npm run build (if developing)
Also try: Clear all caches
Check: Browser network tab for 404s
```

### Import stuck or timing out
```
Solution: Increase PHP max_execution_time
Also try: Import in smaller batches
Check: PHP error logs for memory issues
```

### Microformats not parsed correctly
```
Solution: Verify theme isn't stripping classes
Also try: Test with default theme
Check: View source for correct HTML structure
```

## Debug Information

When reporting issues, you can gather debug info:

1. Go to Settings → Post Kinds for IndieWeb → Debug
2. Click "Copy Debug Info"
3. Include in your support request

Or manually collect:
- WordPress: Tools → Site Health → Info
- Browser: F12 → Console tab
- PHP: Check error_log or debug.log

## Response Times

This is a community project maintained by volunteers:
- **Issues**: Reviewed within a few days typically
- **Discussions**: Community may respond faster
- **PRs**: Reviewed when maintainers are available

For urgent issues, consider the [IndieWeb Chat](https://chat.indieweb.org/) for real-time community help.

## Contributing

If you've solved a problem, consider contributing:
- Add to FAQ via pull request
- Answer questions in Discussions
- Improve documentation
- Submit bug fixes

See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

Thank you for being part of the IndieWeb community!
