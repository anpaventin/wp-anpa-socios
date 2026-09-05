# Security Policy — ANPA Socios

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 1.49.x  | ✅ Yes             |
| < 1.49  | ❌ No              |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, report them privately to:

- **Email:** [security@anpaventin.es](mailto:security@anpaventin.es) *(replace with actual contact)*
- **Subject:** `[SECURITY] ANPA Socios vulnerability report`

Please include:

1. Description of the vulnerability
2. Steps to reproduce
3. Affected version(s)
4. Potential impact
5. Suggested fix (if any)

We aim to acknowledge reports within 48 hours and provide a fix or mitigation within 30 days.

## Security Model

### Authentication

- **Member area:** Passwordless one-time codes via email (6-digit, hashed with `wp_hash_password`, 15-minute TTL, single-use, rate-limited)
- **Company access:** Passwordless codes via email (same controls)
- **Admin area:** WordPress standard authentication + `manage_options` capability

### Authorization

- All REST endpoints use `permission_callback` with capability checks
- All `admin-post` handlers verify nonces (`check_admin_referer`) and capabilities
- Ownership verified server-side for all family/child/membership resources (`fetch_owned_fillo()`, etc.)
- Direct file access blocked via `ABSPATH` guard in all PHP files

### Data Protection

- **PII at rest:** Encrypted with `sodium_crypto_secretbox` (XSalsa20-Poly1305 AEAD)
- **Banking data (IBAN/NIF):** Encrypted with libsodium, external key management
- **Sessions:** HMAC-SHA256, User-Agent binding, TTL, atomic increment, secure destruction on failure
- **Logs:** No emails, codes, or PII written to error logs

### Database

- All queries use `$wpdb->prepare()` (42 prepared statements verified)
- No SQL injection vectors identified
- Custom tables use `dbDelta()` for idempotent migrations

### Input/Output

- All output escaped with `esc_*()` functions (42 uses verified)
- Nonces on all state-changing requests
- CSV exports protected against formula injection

### Updates

- Updates fetched via HTTPS from GitHub
- SHA-256 integrity verification on downloaded assets
- Static source URLs (no dynamic resolution)

## Security Audit History

| Date       | Scope                        | Result                     |
|------------|------------------------------|----------------------------|
| 2026-08-25 | Full security audit (Fase 33) | PASS_WITH_HARDENING        |

### Findings Summary

- **P0 (Critical):** 0
- **P1 (High):** 0
- **P2 (Medium):** 1 (server hardening recommendation)
- **P3 (Low):** 3 (server hardening recommendations)

### Hardening Recommendations (Not Vulnerabilities)

1. **F-001:** Change plugin directory permissions to 750/700 if hosting isolation is weak
2. **F-007:** Add global rate limiting at reverse proxy/CDN level
3. **F-008:** Configure CSP/HSTS headers at server level
4. **F-009:** Hide WordPress version in generator meta tag

## Dependencies

- **Runtime:** None (zero external runtime dependencies)
- **Dev:** PHPUnit 9.6+, Yoast PHPUnit Polyfills
- **Crypto:** libsodium (via PHP 7.2+ sodium extension)

## CI Security

- GitHub Actions workflows use read-only permissions
- All third-party actions are pinned to specific versions
- No secrets exposed to workflow logs
- Ephemeral test databases only (never touches production)
