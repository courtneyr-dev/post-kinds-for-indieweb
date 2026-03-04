# Contributing to Post Kinds for IndieWeb

Thanks for your interest in contributing! This guide covers everything you need to get started.

This project follows the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/).

## Ways to Contribute

**Report bugs** — Check [existing issues](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues) first, then open a new one with your WordPress version, PHP version, steps to reproduce, and any error messages.

**Suggest features** — Describe the problem you're solving, how IndieWeb users benefit, and whether it fits the plugin's scope.

**Submit code** — Fork, branch, make changes, and open a pull request.

**Improve docs** — Fix typos, add examples, clarify instructions, or help with translations.

## Prerequisites

- PHP 8.2+
- Node.js 18+
- Composer 2.x
- WordPress 6.9+ (local dev environment)
- Git

## Setup

```bash
# Fork and clone
git clone https://github.com/YOUR-USERNAME/post-kinds-for-indieweb.git
cd post-kinds-for-indieweb

# Install dependencies
composer install
npm install

# Build assets
npm run build

# Start development (watch mode)
npm run start
```

### Local Environment

We recommend [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for local development:

```bash
npm run env:start
# http://localhost:8888 — admin / password
```

Other options: [Local](https://localwp.com/), [DDEV](https://ddev.com/), [Lando](https://lando.dev/).

## Development Workflow

### Branch Naming

| Prefix      | Use              |
| ----------- | ---------------- |
| `feature/`  | New features     |
| `fix/`      | Bug fixes        |
| `docs/`     | Documentation    |
| `refactor/` | Code refactoring |
| `test/`     | Tests            |

### Making Changes

```bash
# Create a branch from main
git checkout -b feature/my-new-feature

# Make your changes, then lint and test
composer lint
npm run lint
composer test

# Commit and push
git push origin feature/my-new-feature
```

## Coding Standards

### PHP

Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/):

```bash
composer lint        # Check
composer lint:fix    # Auto-fix
composer analyze     # PHPStan level 6
```

Key rules:

- `declare(strict_types=1)` in every file
- Escape all output (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`)
- Sanitize all input, verify nonces, check capabilities
- Comprehensive PHPDoc blocks

### JavaScript

Follow [WordPress JavaScript Standards](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-eslint-plugin/):

```bash
npm run lint:js        # Check
npm run lint:js:fix    # Auto-fix
```

Key rules:

- ES6+ features, prefer `@wordpress` packages
- `__()` for translatable strings with text domain `post-kinds-for-indieweb`

### CSS

Follow [WordPress CSS Standards](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-stylelint-config/):

```bash
npm run lint:css        # Check
npm run lint:css:fix    # Auto-fix
```

Key rules:

- CSS custom properties for theming
- Mobile-first responsive design
- Support dark mode where applicable

### Internationalization

All user-facing strings must be translatable:

```php
// PHP
__( 'Text', 'post-kinds-for-indieweb' )
esc_html__( 'Text', 'post-kinds-for-indieweb' )
```

```javascript
// JavaScript
import { __ } from "@wordpress/i18n";
__("Text", "post-kinds-for-indieweb");
```

## Testing

```bash
# PHP
composer test                          # All tests
composer test -- --testsuite=unit      # Unit only
composer test -- --testsuite=integration  # Integration only
composer test:coverage                 # Coverage report

# JavaScript
npm run test:unit          # Jest
npm run test:unit:watch    # Watch mode

# End-to-end
npm run test:e2e           # Playwright
```

### Manual Testing Checklist

Before submitting a PR:

- [ ] Block renders correctly in editor and frontend
- [ ] Settings save and persist
- [ ] Microformats markup is correct (check with [pin13.net/mf2](https://pin13.net/mf2/))
- [ ] Works with and without IndieBlocks installed
- [ ] No console errors or warnings
- [ ] Keyboard accessible, screen reader friendly

## Commit Guidelines

We use [Conventional Commits](https://www.conventionalcommits.org/):

```
type(scope): description
```

| Type       | Use                                |
| ---------- | ---------------------------------- |
| `feat`     | New feature                        |
| `fix`      | Bug fix                            |
| `docs`     | Documentation only                 |
| `style`    | Formatting (no functional changes) |
| `refactor` | Code refactoring                   |
| `test`     | Adding or updating tests           |
| `chore`    | Build process, dependencies        |

Examples:

```
feat(blocks): add Watch Card block for movie logging
fix(api): handle rate limiting from TMDB API
docs: update installation instructions
refactor(listen-card): simplify state management
```

Keep commits atomic — one logical change per commit. Reference issues when applicable: `Fixes #123`.

## Pull Requests

### Before Submitting

1. All tests pass (`composer test`, `npm run test:unit`)
2. Linting passes (`composer lint`, `npm run lint`)
3. Documentation updated if needed
4. Tests added for new functionality
5. Rebased on latest `main`

### PR Description

Use the [pull request template](.github/PULL_REQUEST_TEMPLATE.md). Include a summary, related issue, test environment, and screenshots for UI changes.

### Review Process

1. Maintainer reviews code and automated checks run
2. Changes may be requested and discussed
3. Once approved, the PR is merged
4. Delete your feature branch after merge

## Release Process

Maintainers follow [Semantic Versioning](https://semver.org/):

- **Major** (X.0.0) — Breaking changes
- **Minor** (0.X.0) — New features, backward compatible
- **Patch** (0.0.X) — Bug fixes

Release checklist:

1. Update version numbers in plugin header, `readme.txt`, and `package.json`
2. Update CHANGELOG.md
3. Create release tag
4. Deploy to WordPress.org

## Questions?

- [GitHub Discussions](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)
- [IndieWeb Chat](https://chat.indieweb.org/)
- [IndieWeb Wiki](https://indieweb.org/)

---

Thank you for contributing to the IndieWeb!
