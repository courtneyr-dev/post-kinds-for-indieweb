# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

Only the latest version receives security updates. We recommend always using the most recent release.

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

### How to Report

**Do NOT create a public GitHub issue for security vulnerabilities.**

Instead, please report vulnerabilities by:

1. **Email**: Send details to the plugin maintainer (available via WordPress.org profile)
2. **GitHub Security Advisories**: Use [GitHub's private vulnerability reporting](https://github.com/courtneyr-dev/post-kinds-for-indieweb/security/advisories/new)

### What to Include

Please provide as much information as possible:

- **Type of vulnerability**: (e.g., XSS, SQL injection, CSRF, authentication bypass)
- **Location**: File path and line number(s) if known
- **Steps to reproduce**: Detailed steps to trigger the vulnerability
- **Proof of concept**: Code or screenshots demonstrating the issue
- **Impact assessment**: What could an attacker do with this vulnerability?
- **Suggested fix**: If you have recommendations

### Example Report

```
Type: Cross-Site Scripting (XSS)
Location: includes/class-microformats.php, line 142
Impact: Stored XSS via reaction note field

Steps to reproduce:
1. Create a new Listen post kind
2. In the notes field, enter: <script>alert('xss')</script>
3. Save and view the post
4. JavaScript executes

Suggested fix: Use esc_html() on line 142 when outputting the note
```

## Response Timeline

We aim to respond based on severity:

| Severity | Initial Response | Resolution Target |
|----------|------------------|-------------------|
| Critical | 24-48 hours | 7 days |
| High | 7 days | 14 days |
| Medium | 14 days | 30 days |
| Low | 30 days | Next release |

### Severity Definitions

- **Critical**: Remote code execution, authentication bypass, SQL injection allowing data theft
- **High**: Stored XSS, CSRF on privileged actions, privilege escalation
- **Medium**: Reflected XSS, information disclosure, denial of service
- **Low**: Non-sensitive information disclosure, minor issues with limited impact

## Security Measures

### What We Do

This plugin implements WordPress security best practices:

#### Input Validation & Sanitization
- All user input is sanitized using WordPress functions
- `sanitize_text_field()`, `sanitize_url()`, `wp_kses_post()`, etc.

#### Output Escaping
- All output is escaped appropriately
- `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`, etc.

#### Authorization
- Nonce verification on all forms and AJAX requests
- Capability checks before privileged operations
- `current_user_can()` before modifying data

#### Database Security
- Prepared statements via `$wpdb->prepare()`
- No raw SQL queries with user input

#### External API Security
- HTTPS for all external API calls
- API keys stored securely in options table
- Rate limiting to prevent abuse

### Regular Security Practices

- Dependencies updated regularly
- Automated security scanning via GitHub Actions
- PHPCS security sniffs enabled
- Static analysis with PHPStan

## Third-Party Dependencies

This plugin uses external APIs and services:

| Service | Purpose | Security Notes |
|---------|---------|----------------|
| MusicBrainz | Music metadata | Public API, no auth required |
| TMDB | Movie/TV data | API key required, HTTPS only |
| Open Library | Book metadata | Public API, no auth required |
| OpenStreetMap | Map embeds | Public embeds, no user data |

API keys are:
- Never exposed in frontend code
- Stored encrypted in WordPress options
- Transmitted only server-to-server

## Security Best Practices for Users

### Installation

1. Download only from official sources (WordPress.org or GitHub releases)
2. Verify file integrity after download
3. Keep WordPress, PHP, and all plugins updated

### Configuration

1. Use strong WordPress admin passwords
2. Enable two-factor authentication
3. Limit admin access to trusted users
4. Use HTTPS on your site

### API Keys

1. Use separate API keys for this plugin
2. Restrict API key permissions where possible
3. Monitor API usage for anomalies
4. Rotate keys if you suspect compromise

## Disclosure Policy

We follow responsible disclosure:

1. **Report received**: We acknowledge within the target response time
2. **Investigation**: We verify and assess the vulnerability
3. **Fix development**: We develop and test a patch
4. **Release**: We release the fix
5. **Disclosure**: We publicly disclose after users have time to update (typically 30 days)

### Credit

Security researchers who responsibly disclose vulnerabilities will be credited in:
- Release notes
- CHANGELOG.md
- Our security acknowledgments (if desired)

If you prefer to remain anonymous, please let us know.

## Security Hall of Fame

We thank the following researchers for responsibly reporting security issues:

*No reports yet - be the first to help secure this plugin!*

## Questions?

For security-related questions that aren't vulnerabilities, you can:
- Open a [GitHub Discussion](https://github.com/courtneyr-dev/post-kinds-for-indieweb/discussions)
- Review the [WordPress Security Best Practices](https://developer.wordpress.org/plugins/security/)

---

Thank you for helping keep Post Kinds for IndieWeb secure!
