# Pre-Release QA Checklist

Use this checklist before each release of Post Kinds for IndieWeb.

## Release Information

- **Version**: ******\_\_\_******
- **Release Type**: [ ] Patch | [ ] Minor | [ ] Major
- **Release Date**: ******\_\_\_******
- **Tested By**: ******\_\_\_******

---

## 1. Pre-Testing Setup

- [ ] WordPress updated to latest stable version
- [ ] Plugin built with `npm run build:prod`
- [ ] WP_DEBUG enabled
- [ ] Browser dev tools open (Console tab)
- [ ] All caches cleared

---

## 2. Critical Path Tests

### Installation & Activation

- [ ] Fresh install activates without errors
- [ ] Upgrade from previous version works
- [ ] Deactivation preserves data
- [ ] No PHP errors in debug.log

### Core Functionality

- [ ] Kind taxonomy appears in editor sidebar
- [ ] Can assign kinds to posts
- [ ] Kind archives work (/kind/listen/, etc.)

### Block Tests (spot check at minimum)

- [ ] Listen Card - insert, edit, save, view frontend
- [ ] Watch Card - insert, edit, save, view frontend
- [ ] Read Card - insert, edit, save, view frontend
- [ ] Checkin Card - insert, edit, save, view frontend
- [ ] RSVP Card - insert, edit, save, view frontend
- [ ] Star Rating - insert, edit, save, view frontend
- [ ] Media Lookup - search returns results

### API Integrations

- [ ] MusicBrainz search works (Listen)
- [ ] Open Library search works (Read)
- [ ] TMDB search works (Watch) - if API key configured
- [ ] Nominatim location search works (Checkin)

### Settings

- [ ] Settings page loads without errors
- [ ] Settings save and persist
- [ ] API keys can be saved and used

---

## 3. Compatibility Tests

### WordPress Versions

- [ ] WordPress 6.9 (minimum supported)

### PHP Versions

- [ ] PHP 8.2 (minimum supported)
- [ ] PHP 8.3
- [ ] PHP 8.4

### Themes

- [ ] Twenty Twenty-Five (block theme)
- [ ] Twenty Twenty-Four (block theme)
- [ ] At least one classic theme

### Browser Testing

- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)

---

## 4. Code Quality

### Automated Checks

- [ ] `composer lint` passes (or documented exceptions)
- [ ] `composer analyze` passes (or baseline issues)
- [ ] `npm run lint` passes (or documented exceptions)
- [ ] `npm run build:prod` succeeds

### Security Review

- [ ] No hardcoded credentials
- [ ] All user input sanitized
- [ ] All output escaped
- [ ] Nonces verified on forms
- [ ] Capability checks in place

---

## 5. Documentation

- [ ] README.md is up to date
- [ ] readme.txt reflects new features/changes
- [ ] CHANGELOG.md updated
- [ ] Version number bumped in:
  - [ ] post-kinds-for-indieweb.php (header)
  - [ ] post-kinds-for-indieweb.php (constant)
  - [ ] package.json
  - [ ] readme.txt

---

## 6. Final Checks

- [ ] No console JavaScript errors
- [ ] No PHP notices/warnings in debug.log
- [ ] All new strings are translatable
- [ ] Text domain is `post-kinds-for-indieweb`
- [ ] Git status clean (all changes committed)
- [ ] Version tag created

---

## Sign-Off

| Role            | Name | Date | Approved |
| --------------- | ---- | ---- | -------- |
| Developer       |      |      | [ ]      |
| QA Tester       |      |      | [ ]      |
| Release Manager |      |      | [ ]      |

---

## Notes

_Add any notes, known issues, or follow-up items here:_

```




```

---

## Issue Tracking

| Issue | Severity | Status | Notes |
| ----- | -------- | ------ | ----- |
|       |          |        |       |
|       |          |        |       |
|       |          |        |       |
