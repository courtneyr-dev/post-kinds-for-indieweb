---
title: Accessibility
description: "What Post Kinds for IndieWeb in Block Themes does for accessibility, what testing exists in the repository, and what still needs verification. Evidence, not claims."
---

What the plugin does for accessibility, what testing exists in the repository, and what still needs verification. This page describes evidence from the repository — it does not claim WCAG conformance.

## User-facing UI surfaces

Accessibility matters in three places:

- **Admin screens** — the Reactions settings tabs, API Connections, Import, Webhooks, Quick Post, Syndication, and Check-ins pages, built on standard WordPress admin markup (Settings API tables, nav tabs, buttons).
- **Editor UI** — the card blocks, the Post Kind sidebar panel with its kind grid, and block toolbars/inspector controls built on the block editor's standard components.
- **Front end** — rendered cards, star ratings, check-in dashboards/feeds, and maps on your public pages.

## Accessibility work evidenced in the repository

- **Heading order in cards:** card titles render as `h2` rather than `h3` so published pages keep a logical heading order (changelog 1.3.0, referencing WCAG 1.3.1 Info and Relationships).
- **Editor icon contrast:** block icons render with `currentColor` so they stay legible against the List View selection highlight instead of disappearing on dark backgrounds (changelog 1.3.0).
- **Able Player for watch cards:** the plugin integrates the Able Player accessible media player for video in watch cards, and the Watch Card block has a `captionsUrl` attribute for providing a captions file.
- **Star Rating options:** the Star Rating block exposes label and value display options (`label`, `showLabel`, `showValue`), so ratings can carry visible text rather than relying on icons alone.

## Automated accessibility testing in the repo

The repository gates changes on automated accessibility checks:

- A dedicated **axe-core + Playwright** test suite (`tests/e2e/accessibility.spec.js`, run with `npm run test:a11y`), with `@axe-core/playwright` as a dev dependency and an "Accessibility Tests" job in CI.
- A **Lighthouse CI accessibility gate**: the accessibility category is set to error below a **0.9 minimum score**, run against the home page and wp-admin.

Automated checks like axe and Lighthouse catch a useful subset of issues (contrast, names/roles, structure) but can't verify everything.

## What still needs testing

- **Per-block ARIA and keyboard behavior.** The audit did not exhaustively verify ARIA attributes or keyboard operability in every block's rendered output. Manual keyboard-only passes through card editing and front-end widgets (Check-in Dashboard filters, maps, embedded players) haven't been documented.
- **Screen reader walkthroughs.** No screen reader test results (NVDA, JAWS, VoiceOver) are recorded in the repository.
- **Map interactions.** Check-in maps and venue maps are inherently visual; alternatives for non-visual users haven't been documented.

## No conformance claim

This plugin has automated accessibility gates and documented fixes, but the repository contains no WCAG conformance audit, and this documentation makes no compliance claim. If you find an accessibility problem, report it on the [issues page](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues) — heading structure, contrast, and keyboard traps are exactly the kind of report the existing test suite can then guard against.
