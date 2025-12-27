# QA Testing Guide for Reactions for IndieWeb

This document provides comprehensive testing directions for the Reactions for IndieWeb plugin.

## Table of Contents

- [Test Environment Setup](#test-environment-setup)
- [Pre-Testing Checklist](#pre-testing-checklist)
- [Test Categories](#test-categories)
  - [1. Installation & Activation](#1-installation--activation)
  - [2. Post Kinds Taxonomy](#2-post-kinds-taxonomy)
  - [3. Custom Blocks](#3-custom-blocks)
  - [4. External API Integrations](#4-external-api-integrations)
  - [5. Admin Settings](#5-admin-settings)
  - [6. Microformats Output](#6-microformats-output)
  - [7. Import Functionality](#7-import-functionality)
  - [8. Compatibility Testing](#8-compatibility-testing)
  - [9. Performance Testing](#9-performance-testing)
  - [10. Accessibility Testing](#10-accessibility-testing)
- [Regression Testing](#regression-testing)
- [Bug Reporting](#bug-reporting)

---

## Test Environment Setup

### Required Environment

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| WordPress | 6.5 | 6.7+ |
| PHP | 8.0 | 8.2+ |
| MySQL | 5.7 | 8.0+ |
| Node.js | 18 | 20+ |

### Local WP Site

Your test site is configured at: **post-formats-test**

Access: `http://post-formats-test.local` (or check Local WP for exact URL)

### Installed Plugins for Testing

- [x] Reactions for IndieWeb (symlinked)
- [x] Query Monitor
- [x] Debug Bar
- [x] IndieWeb
- [x] IndieBlocks
- [x] Link Extension for XFN
- [x] Post Formats for Block Themes

### Build the Plugin

Before testing, ensure assets are compiled:

```bash
cd "/Users/crobertson/Documents/Reactions for IndieWeb"
npm install
npm run build
```

For development with hot reload:
```bash
npm run start
```

---

## Pre-Testing Checklist

Before each test session:

- [ ] WordPress is updated to latest version
- [ ] Plugin assets are built (`npm run build`)
- [ ] WP_DEBUG is enabled in wp-config.php
- [ ] Query Monitor is active
- [ ] Browser console is open (F12)
- [ ] Clear all caches

### Enable Debug Mode

Add to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'SCRIPT_DEBUG', true );
```

---

## Test Categories

### 1. Installation & Activation

#### Test 1.1: Fresh Installation
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Deactivate and delete plugin | Plugin removed |
| 2 | Install plugin fresh | No errors during installation |
| 3 | Activate plugin | Activation successful, no PHP errors |
| 4 | Check error log | No errors in debug.log |

#### Test 1.2: Activation Without IndieBlocks
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Deactivate IndieBlocks | IndieBlocks inactive |
| 2 | Activate Reactions for IndieWeb | Plugin activates with info notice |
| 3 | Check admin notice | Shows "works best with IndieBlocks" notice |
| 4 | Verify functionality | Core features work without IndieBlocks |

#### Test 1.3: Activation With IndieBlocks
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Activate IndieBlocks first | IndieBlocks active |
| 2 | Activate Reactions for IndieWeb | No warning notice shown |
| 3 | Check integration | Enhanced features available |

#### Test 1.4: Deactivation
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Deactivate plugin | Deactivation successful |
| 2 | Check database | Options preserved (not deleted) |
| 3 | Check posts | Post meta and taxonomy preserved |

---

### 2. Post Kinds Taxonomy

#### Test 2.1: Taxonomy Registration
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Go to Posts → Add New | Editor loads |
| 2 | Check sidebar | "Reaction Kind" panel visible |
| 3 | Expand panel | All kinds listed (listen, watch, read, checkin, rsvp) |

#### Test 2.2: Assign Reaction Kind
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Create new post | Editor opens |
| 2 | Select "Listen" kind | Kind selected, UI updates |
| 3 | Save post | Post saved with kind |
| 4 | View post list | Kind column shows "Listen" |
| 5 | Filter by kind | Only listen posts shown |

#### Test 2.3: Taxonomy Archive
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Create posts with different kinds | Multiple posts exist |
| 2 | Visit `/reaction-kind/listen/` | Archive page loads |
| 3 | Check posts displayed | Only listen posts shown |
| 4 | Check pagination | Works if >10 posts |

---

### 3. Custom Blocks

#### Test 3.1: Block Registration
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open block inserter (+ button) | Inserter opens |
| 2 | Search "Reactions" | Category "Reactions for IndieWeb" visible |
| 3 | View blocks | All 7 blocks listed |

**Blocks to verify:**
- [ ] Listen Card
- [ ] Watch Card
- [ ] Read Card
- [ ] Checkin Card
- [ ] RSVP Card
- [ ] Star Rating
- [ ] Media Lookup

#### Test 3.2: Listen Card Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Listen Card block | Placeholder shown |
| 2 | Click "Add Listen" | Edit mode active |
| 3 | Enter track title | Title field updates |
| 4 | Enter artist name | Artist field updates |
| 5 | Upload cover image | Image displays |
| 6 | Set rating (1-5 stars) | Stars highlight correctly |
| 7 | Save post | No errors |
| 8 | View frontend | Block renders with all data |
| 9 | Inspect HTML | Microformats classes present (h-cite, p-name, etc.) |

**Sidebar Settings to Test:**
- [ ] Layout options (horizontal, vertical, cover, compact)
- [ ] Album title field
- [ ] Release date field
- [ ] MusicBrainz ID field
- [ ] Listened date picker

#### Test 3.3: Watch Card Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Watch Card block | Placeholder shown |
| 2 | Enter media title | Title updates |
| 3 | Select media type (movie/tv/episode) | Badge updates |
| 4 | For TV: enter season/episode | Episode info displays |
| 5 | Upload poster | Image displays |
| 6 | Toggle "Rewatch" | Rewatch badge appears |
| 7 | Set rating | Stars display |
| 8 | Save and view frontend | All data renders correctly |

**Sidebar Settings to Test:**
- [ ] Media type selector
- [ ] Season number (for TV)
- [ ] Episode number (for TV)
- [ ] Director field
- [ ] TMDB ID field
- [ ] IMDb ID field
- [ ] Watch date picker
- [ ] Rewatch toggle

#### Test 3.4: Read Card Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Read Card block | Placeholder shown |
| 2 | Enter book title | Title updates |
| 3 | Enter author | Author displays |
| 4 | Set status to "Reading" | Status badge shows "Currently Reading" |
| 5 | Enter page count (e.g., 300) | Total pages set |
| 6 | Enter current page (e.g., 150) | Progress bar shows 50% |
| 7 | Change status to "Finished" | Badge updates, progress hidden |
| 8 | Add review text | Review displays |

**Status Options to Test:**
- [ ] To Read
- [ ] Reading (shows progress bar)
- [ ] Finished
- [ ] Abandoned

**Sidebar Settings to Test:**
- [ ] ISBN field
- [ ] Publisher field
- [ ] Publish date field
- [ ] Open Library ID field
- [ ] Started date picker
- [ ] Finished date picker

#### Test 3.5: Checkin Card Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Checkin Card block | Placeholder shown |
| 2 | Enter venue name | Name displays |
| 3 | Select venue type | Icon/badge updates |
| 4 | Enter address fields | Location displays |
| 5 | Enter lat/long coordinates | Map embed appears |
| 6 | Toggle "Show Map" off | Map hidden |
| 7 | Upload photo | Photo displays |
| 8 | Add note | Note displays |

**Venue Types to Test:**
- [ ] Place, Restaurant, Cafe, Bar
- [ ] Hotel, Airport, Park, Museum
- [ ] Theater, Store, Office, Home

**Sidebar Settings to Test:**
- [ ] Street address
- [ ] City/Locality
- [ ] State/Region
- [ ] Country
- [ ] Postal code
- [ ] Latitude/Longitude
- [ ] Foursquare ID

#### Test 3.6: RSVP Card Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert RSVP Card block | Placeholder shown |
| 2 | Enter event name | Name displays |
| 3 | Enter event URL | Link active |
| 4 | Set start date/time | Date displays |
| 5 | Select RSVP status "Yes" | Green "Going" badge |
| 6 | Change to "No" | Red "Not Going" badge |
| 7 | Change to "Maybe" | Orange "Maybe" badge |
| 8 | Add note | Note displays |

**RSVP Statuses to Test:**
- [ ] Yes → "Going" (green)
- [ ] No → "Not Going" (red)
- [ ] Maybe → "Maybe" (orange)
- [ ] Interested → "Interested" (blue)
- [ ] Remote → "Attending Remotely" (purple)

#### Test 3.7: Star Rating Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Star Rating block | Default 5 stars shown |
| 2 | Click 3rd star | 3 stars filled |
| 3 | Click 3rd star again | Rating clears (toggle) |
| 4 | Change style to "Hearts" | Heart icons display |
| 5 | Change style to "Circles" | Circle icons display |
| 6 | Change max rating to 10 | 10 icons shown |
| 7 | Enable half stars | Half increments work |
| 8 | Change size (S/M/L) | Icon size changes |

**Sidebar Settings to Test:**
- [ ] Current rating slider
- [ ] Maximum rating (3-10)
- [ ] Allow half stars toggle
- [ ] Style (stars/hearts/circles/numeric)
- [ ] Size (small/medium/large)
- [ ] Show label toggle
- [ ] Show value toggle

#### Test 3.8: Media Lookup Block
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Media Lookup block | Placeholder with type selector |
| 2 | Select "Book" type | Book search enabled |
| 3 | Search for a book | Results display |
| 4 | Select a result | Book info populates |
| 5 | Change type to "Movie" | Clears and shows movie search |
| 6 | Search and select movie | Movie info displays |
| 7 | Toggle display options | Appearance updates |

**Display Options to Test:**
- [ ] Display style (card/inline/compact)
- [ ] Show image toggle
- [ ] Show description toggle
- [ ] Link to source toggle

---

### 4. External API Integrations

#### Test 4.1: API Settings Page
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Go to Settings → Reactions for IndieWeb | Settings page loads |
| 2 | Navigate to "API Settings" tab | API key fields visible |
| 3 | Enter invalid API key | Error on save or API call |
| 4 | Enter valid API key | Success message |

#### Test 4.2: Music APIs (MusicBrainz)
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Listen Card | Block loads |
| 2 | Click search icon | Search modal opens |
| 3 | Search "Bohemian Rhapsody" | Results from MusicBrainz |
| 4 | Select result | Fields auto-populate |
| 5 | Check populated data | Title, artist, album, cover image |

#### Test 4.3: Movie/TV APIs (TMDB)
*Requires TMDB API key*

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Configure TMDB API key | Key saved |
| 2 | Insert Watch Card | Block loads |
| 3 | Search "The Matrix" | TMDB results appear |
| 4 | Select result | Poster, title, year, director populate |

#### Test 4.4: Book APIs (Open Library)
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Insert Read Card | Block loads |
| 2 | Search "1984 Orwell" | Open Library results |
| 3 | Select result | Cover, title, author, ISBN populate |

#### Test 4.5: API Rate Limiting
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Perform 20+ rapid searches | Requests sent |
| 2 | Check for rate limit errors | Graceful handling, user notified |
| 3 | Wait and retry | Requests succeed again |

#### Test 4.6: API Error Handling
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Disconnect internet | No connection |
| 2 | Attempt API search | Error message displayed |
| 3 | Reconnect | Search works again |

---

### 5. Admin Settings

#### Test 5.1: Settings Page Navigation
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Go to Settings → Reactions for IndieWeb | Page loads |
| 2 | Check all tabs exist | General, API Settings, Import, Webhooks, Debug |
| 3 | Click each tab | Tab content loads without page refresh |

#### Test 5.2: General Settings
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Toggle each setting | Setting updates |
| 2 | Save settings | Success message |
| 3 | Refresh page | Settings persisted |

#### Test 5.3: API Key Storage
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Enter API key | Key displayed (masked) |
| 2 | Save | Key saved |
| 3 | Check database | Key stored securely |
| 4 | View page source | Key not exposed in HTML |

#### Test 5.4: Debug Tab
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Debug tab | Debug info displays |
| 2 | Click "Copy Debug Info" | Info copied to clipboard |
| 3 | Check API status | Shows connection status for each API |

---

### 6. Microformats Output

#### Test 6.1: Validate with Parser
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Create post with Listen Card | Post published |
| 2 | Get public URL | URL copied |
| 3 | Go to [pin13.net/mf2](https://pin13.net/mf2/) | Parser loads |
| 4 | Enter URL | Parse results |
| 5 | Check h-entry | Present with correct properties |
| 6 | Check h-cite | Present for cited content |

#### Test 6.2: Listen Card Microformats
Expected output structure:
```html
<div class="h-cite">
  <img class="u-photo" src="..." />
  <span class="p-name">Track Title</span>
  <span class="p-author h-card">
    <span class="p-name">Artist Name</span>
  </span>
  <data class="u-listen-of" value="..." />
  <data class="p-rating" value="4" />
</div>
```

Verify presence of:
- [ ] `h-cite` on container
- [ ] `u-photo` on cover image
- [ ] `p-name` on track title
- [ ] `p-author` with nested `h-card`
- [ ] `u-listen-of` with URL value
- [ ] `p-rating` if rating set

#### Test 6.3: Watch Card Microformats
Verify presence of:
- [ ] `h-cite` container
- [ ] `p-name` on title
- [ ] `u-photo` on poster
- [ ] `u-watch-of` with URL
- [ ] `dt-published` on watch date

#### Test 6.4: Read Card Microformats
Verify presence of:
- [ ] `h-cite` container
- [ ] `p-name` on book title
- [ ] `p-author` on author
- [ ] `p-isbn` (hidden data element)
- [ ] `u-read-of` with URL
- [ ] `p-rating` if rated

#### Test 6.5: Checkin Card Microformats
Verify presence of:
- [ ] `h-entry` container
- [ ] `p-location` with nested `h-card`
- [ ] `p-geo` with `h-geo`
- [ ] `p-latitude` and `p-longitude`
- [ ] `u-checkin` with venue URL
- [ ] `dt-published` on checkin time

#### Test 6.6: RSVP Card Microformats
Verify presence of:
- [ ] `h-entry` container
- [ ] `p-rsvp` with value (yes/no/maybe/interested/remote)
- [ ] `p-in-reply-to` with `h-event`
- [ ] `dt-start` on event start
- [ ] `p-location` on event location

---

### 7. Import Functionality

#### Test 7.1: Import Page Access
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Go to Tools → Reactions Import | Import page loads |
| 2 | Check available importers | List of services shown |

#### Test 7.2: Last.fm Import
*Requires Last.fm data export*

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Select Last.fm importer | Options displayed |
| 2 | Upload export file | File accepted |
| 3 | Configure options | Settings available |
| 4 | Start import | Progress displayed |
| 5 | Check results | Posts created |

#### Test 7.3: Import Duplicate Prevention
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Import same data twice | Second import runs |
| 2 | Check post count | No duplicates created |

---

### 8. Compatibility Testing

#### Test 8.1: Theme Compatibility
Test with these themes:
- [ ] Twenty Twenty-Five (block theme)
- [ ] Twenty Twenty-Four (block theme)
- [ ] Twenty Twenty-Three (block theme)
- [ ] Flavor theme (FSE)
- [ ] Classic theme (Twenty Twenty-One)

For each theme:
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Activate theme | Theme active |
| 2 | View existing reaction post | Content displays correctly |
| 3 | Create new post with block | Block works in editor |
| 4 | View frontend | Styling appropriate |

#### Test 8.2: Plugin Compatibility
Test with each plugin active:

**IndieBlocks Integration:**
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Activate IndieBlocks | Both plugins active |
| 2 | Create post with IndieBlocks block | Works correctly |
| 3 | Add Reactions block same post | Both work together |

**IndieWeb Plugin Integration:**
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Activate IndieWeb plugin | Plugin active |
| 2 | Check Webmention compatibility | Webmentions work |
| 3 | Verify IndieAuth | Authentication works |

**Query Monitor:**
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Load page with blocks | Page loads |
| 2 | Check QM panel | No PHP errors |
| 3 | Check database queries | Reasonable query count |

#### Test 8.3: Multisite Compatibility
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Network activate plugin | Activated network-wide |
| 2 | Check subsite A | Plugin works |
| 3 | Check subsite B | Plugin works independently |
| 4 | Verify settings per-site | Each site has own settings |

---

### 9. Performance Testing

#### Test 9.1: Editor Performance
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open new post | Editor loads in <3 seconds |
| 2 | Insert 10 reaction blocks | No significant lag |
| 3 | Save post | Saves in <5 seconds |
| 4 | Check memory usage (QM) | No memory spikes |

#### Test 9.2: Frontend Performance
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Load page with 5 blocks | Page loads in <2 seconds |
| 2 | Check network requests | Minimal HTTP requests |
| 3 | Check asset sizes | JS/CSS reasonably sized |

#### Test 9.3: API Response Caching
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Search for same term twice | First: API call, Second: cached |
| 2 | Check QM HTTP panel | Second request uses cache |
| 3 | Wait for cache expiry | Fresh API call made |

---

### 10. Accessibility Testing

#### Test 10.1: Keyboard Navigation
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Tab through block controls | All controls focusable |
| 2 | Use Enter/Space on buttons | Buttons activate |
| 3 | Use arrow keys on star rating | Rating changes |
| 4 | Escape key on modals | Modal closes |

#### Test 10.2: Screen Reader Testing
Test with VoiceOver (Mac) or NVDA (Windows):

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to block | Block announced |
| 2 | Read star rating | "Rating: 4 out of 5 stars" |
| 3 | Read progress bar | "50% complete, 150 of 300 pages" |
| 4 | Activate search | Search announced |

#### Test 10.3: Color Contrast
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Use browser contrast checker | Check all text |
| 2 | Verify status badges | Meet WCAG AA (4.5:1) |
| 3 | Check star rating colors | Visible in all modes |

#### Test 10.4: Focus Indicators
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Tab through interface | Focus ring visible on each element |
| 2 | Check buttons | Clear focus state |
| 3 | Check form fields | Clear focus state |

---

## Regression Testing

After any code changes, run these critical tests:

### Quick Smoke Test (5 minutes)
- [ ] Plugin activates without errors
- [ ] Settings page loads
- [ ] Can insert Listen Card block
- [ ] Block saves and displays on frontend
- [ ] No console errors

### Full Regression (30 minutes)
- [ ] All blocks insert correctly
- [ ] All blocks save correctly
- [ ] All blocks render on frontend
- [ ] API search works for each type
- [ ] Settings persist after save
- [ ] No PHP errors in debug.log
- [ ] No JavaScript console errors

---

## Bug Reporting

When reporting bugs, include:

### Required Information
```
**WordPress version**:
**PHP version**:
**Plugin version**:
**Browser**:
**Theme**:

**Steps to reproduce**:
1.
2.
3.

**Expected behavior**:

**Actual behavior**:

**Console errors**:

**PHP errors (from debug.log)**:

**Screenshots**:
```

### Getting Debug Info
1. Go to Settings → Reactions for IndieWeb → Debug
2. Click "Copy Debug Info"
3. Paste into bug report

### Console Errors
1. Open browser DevTools (F12)
2. Go to Console tab
3. Reproduce the issue
4. Copy any red error messages

### PHP Errors
1. Check `wp-content/debug.log`
2. Look for entries with `reactions-indieweb`
3. Include relevant lines in report

---

## Test Coverage Summary

| Category | Tests | Priority |
|----------|-------|----------|
| Installation | 4 | Critical |
| Taxonomy | 3 | High |
| Blocks (7 types) | 28+ | Critical |
| API Integration | 6 | High |
| Admin Settings | 4 | Medium |
| Microformats | 6 | High |
| Import | 3 | Medium |
| Compatibility | 6 | High |
| Performance | 3 | Medium |
| Accessibility | 4 | High |

**Total: 65+ individual test cases**

---

## Appendix: Test Data

### Sample Search Terms

**Music:**
- "Bohemian Rhapsody Queen"
- "Billie Jean Michael Jackson"
- "Smells Like Teen Spirit"

**Movies:**
- "The Matrix 1999"
- "Inception"
- "Pulp Fiction"

**TV Shows:**
- "Breaking Bad"
- "The Office"
- "Game of Thrones"

**Books:**
- "1984 George Orwell"
- "The Great Gatsby"
- "To Kill a Mockingbird"

### Sample Coordinates

| Location | Latitude | Longitude |
|----------|----------|-----------|
| New York, NY | 40.7128 | -74.0060 |
| London, UK | 51.5074 | -0.1278 |
| Tokyo, Japan | 35.6762 | 139.6503 |
| Sydney, Australia | -33.8688 | 151.2093 |
