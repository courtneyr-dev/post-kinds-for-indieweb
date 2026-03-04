# Contributing to Post Kinds for IndieWeb

Thank you for your interest in contributing to Post Kinds for IndieWeb! This document provides guidelines and instructions for contributing.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How to Contribute](#how-to-contribute)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Commit Guidelines](#commit-guidelines)
- [Pull Request Process](#pull-request-process)
- [Release Process](#release-process)

## Code of Conduct

This project adheres to the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/). By participating, you are expected to uphold this code.

## How to Contribute

### Reporting Bugs

Before creating a bug report:

1. Check the [existing issues](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues) to avoid duplicates
2. Ensure you're using the latest version
3. Verify the issue isn't caused by a plugin conflict

When creating a bug report, include:

- WordPress version
- PHP version
- IndieBlocks version (if installed)
- Steps to reproduce
- Expected vs actual behavior
- Error messages or screenshots

### Suggesting Features

Feature requests are welcome! Please:

1. Check existing issues for similar suggestions
2. Describe the problem you're trying to solve
3. Explain how IndieWeb users would benefit
4. Consider if it fits the plugin's scope

### Contributing Code

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

### Contributing Documentation

Documentation improvements are highly valued:

- Fix typos or unclear explanations
- Add examples or use cases
- Translate to other languages
- Improve inline code comments

## Getting Started

### Prerequisites

- PHP 8.2 or higher
- Node.js 18 or higher
- Composer 2.x
- WordPress 6.9 or higher (local development environment)
- Git

### Setup

1. **Fork and clone the repository**

   ```bash
   git clone https://github.com/YOUR-USERNAME/post-kinds-for-indieweb.git
   cd post-kinds-for-indieweb
   ```

2. **Install PHP dependencies**

   ```bash
   composer install
   ```

3. **Install JavaScript dependencies**

   ```bash
   npm install
   ```

4. **Build assets**

   ```bash
   npm run build
   ```

5. **Start development build**
   ```bash
   npm run start
   ```

### Local Development Environment

We recommend using one of these local development tools:

- [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (recommended)
- [Local](https://localwp.com/)
- [DDEV](https://ddev.com/)
- [Lando](https://lando.dev/)

With wp-env:

```bash
npm run env:start
# Access at http://localhost:8888
# Admin: admin / password
```

## Development Workflow

### Branch Naming

- `feature/description` - New features
- `fix/description` - Bug fixes
- `docs/description` - Documentation updates
- `refactor/description` - Code refactoring
- `test/description` - Test additions/changes

### Making Changes

1. Create a branch from `main`

   ```bash
   git checkout -b feature/my-new-feature
   ```

2. Make your changes

3. Run linting and tests

   ```bash
   composer lint
   npm run lint
   composer test
   ```

4. Commit your changes (see [Commit Guidelines](#commit-guidelines))

5. Push and create a pull request

## Coding Standards

### PHP

We follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/):

```bash
# Check coding standards
composer lint

# Auto-fix where possible
composer lint:fix

# Run static analysis
composer analyze
```

Key requirements:

- Use strict types: `declare(strict_types=1);`
- Add comprehensive PHPDoc blocks
- Follow WordPress naming conventions
- Escape all output, sanitize all input
- Verify nonces and capabilities

### JavaScript

We use [@wordpress/eslint-plugin](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-eslint-plugin/):

```bash
# Check JavaScript
npm run lint:js

# Auto-fix
npm run lint:js:fix
```

Key requirements:

- Use ES6+ features
- Follow WordPress JavaScript Standards
- Use `__()` for translatable strings
- Prefer @wordpress packages

### CSS

We use [@wordpress/stylelint-config](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-stylelint-config/):

```bash
# Check styles
npm run lint:css

# Auto-fix
npm run lint:css:fix
```

Key requirements:

- Use CSS custom properties for theming
- Follow WordPress CSS standards
- Support dark mode where applicable
- Mobile-first responsive design

### Internationalization

All user-facing strings must be translatable:

```php
// PHP
__( 'Text', 'post-kinds-for-indieweb' )
_e( 'Text', 'post-kinds-for-indieweb' )
esc_html__( 'Text', 'post-kinds-for-indieweb' )
```

```javascript
// JavaScript
import { __ } from "@wordpress/i18n";
__("Text", "post-kinds-for-indieweb");
```

## Testing

### PHP Tests

```bash
# Run all tests
composer test

# Run specific test suite
composer test -- --testsuite=unit
composer test -- --testsuite=integration

# Generate coverage report
composer test:coverage
```

### JavaScript Tests

```bash
# Run Jest tests
npm run test:unit

# Watch mode
npm run test:unit:watch
```

### Manual Testing Checklist

Before submitting a PR, test:

- [ ] Block renders correctly in editor
- [ ] Block renders correctly on frontend
- [ ] Settings save and persist
- [ ] Microformats markup is correct
- [ ] Works without IndieBlocks installed
- [ ] Works with IndieBlocks installed
- [ ] No console errors or warnings
- [ ] Accessibility (keyboard navigation, screen readers)

## Commit Guidelines

We use [Conventional Commits](https://www.conventionalcommits.org/):

```
type(scope): description

[optional body]

[optional footer]
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Code style (formatting, semicolons, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Build process, dependencies, etc.

### Examples

```
feat(blocks): add Watch Card block for movie logging

fix(api): handle rate limiting from TMDB API

docs: update installation instructions

refactor(listen-card): simplify state management
```

### Commit Best Practices

- Keep commits atomic (one logical change per commit)
- Write clear, descriptive messages
- Reference issues when applicable: `Fixes #123`

## Pull Request Process

### Before Submitting

1. Ensure all tests pass
2. Ensure linting passes
3. Update documentation if needed
4. Add tests for new functionality
5. Rebase on latest `main`

### PR Description

Use the pull request template, including:

- Summary of changes
- Related issue(s)
- Testing performed
- Screenshots (for UI changes)
- Checklist completion

### Review Process

1. Maintainer reviews code
2. Automated checks must pass
3. Changes requested will be discussed
4. Once approved, PR is merged

### After Merge

- Delete your feature branch
- Check the deployed changes work correctly

## Release Process

Releases are managed by maintainers following semantic versioning:

- **Major** (1.0.0): Breaking changes
- **Minor** (0.1.0): New features, backward compatible
- **Patch** (0.0.1): Bug fixes, backward compatible

### Release Checklist

1. Update version numbers
2. Update CHANGELOG.md
3. Update readme.txt
4. Create release tag
5. Deploy to WordPress.org

## Questions?

- Open a [GitHub Discussion](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)
- Check the [IndieWeb Wiki](https://indieweb.org/)
- Join the [IndieWeb Chat](https://chat.indieweb.org/)

---

Thank you for contributing to the IndieWeb!
