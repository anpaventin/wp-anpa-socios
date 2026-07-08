# Contributing & Project Guidelines — ANPA Socios

`anpa-socios` is a **public, reusable WordPress plugin** for any parents' association
(ANPA/AMPA). These guidelines are binding for every future change (human or AI). Keep
the codebase clean, generic, and safe to publish.

## 1. No hardcoded association or personal data (MANDATORY)

The plugin must never contain data tied to a single association or person. This includes:

- Association name, contact email, postal address, membership fee.
- Country, province, town, phone numbers, IBAN, DNI/NIF.
- Domains, personal servers, real people's names or emails.

**Every data value the deployer might change lives in a WordPress option**, resolved
through `ANPA_Socios_Config`, and is editable from **Axustes** (the plugin settings
screen). Code reads the getter, never a literal.

When you add a new user-facing data point:

1. Add an `OPTION_*` constant + a getter in `class-anpa-socios-config.php`, with a
   **neutral default** (empty, or generic like `ANPA`). Never a real value.
2. Add a field for it in the correct **Axustes** tab (`class-anpa-socios-admin-settings.php`)
   and save it in an isolated `admin_post` handler (a partial form must never clear
   unrelated options).
3. Use the getter everywhere the value is rendered or emailed.

Examples already in place: `association_name()`, `contact_email()`, `association_address()`,
`membership_fee()`, `country()`, `default_province()`, `default_town()`, `language()`,
`master_email()` (constant/option/`wp-config` precedence).

## 2. Public-repo hygiene

- **No secrets** in the repo. Tokens live in `wp-config.php` constants
  (`ANPA_SOCIOS_GITEA_TOKEN`, `ANPA_SOCIOS_MASTER_EMAIL`, `ANPA_SOCIOS_UPDATE_URL`) or the
  deployer's environment — never committed.
- **No real PII** anywhere, including tests and fixtures: use `example.com` /
  `example.org`, `00000`, fictional towns.
- Installation-specific data (backups, SQL dumps, `.secrets`, deploy scripts) stays
  **out of this repo**.
- Keep `.gitignore` covering `vendor/`, `node_modules/`, `*.log`, PHPUnit caches.

## 3. Architecture & file layout

- `anpa-socios.php` — bootstrap: constants, `require_once`, hooks. Nothing else.
- `includes/*.php` — WordPress-coupled classes (REST, pages, admin handlers, email…).
- `includes/lib/*.php` — **pure logic, no WordPress, no I/O** — unit-tested.
- `includes/lib/plugin-update-checker/` — vendored third party; do not edit.
- `assets/{css,js}/` — front-end assets, enqueued conditionally per page.
- `tests/` — PHPUnit for the pure-logic classes.
- One responsibility per class; keep handlers small; prefer composition over duplication.

## 4. Testing (TDD)

- **Pure logic** (`includes/lib/`): write the PHPUnit test first (RED), then implement
  (GREEN). Add every new `Test_*.php` to `phpunit.xml` and `tests/bootstrap.php`.
- **WordPress glue** (REST/DB/pages): verify with `php -l` + code review + staging E2E.
- The whole suite must stay green before any release.

## 5. Security defaults

- All SQL through `$wpdb->prepare` (or whitelisted table names only).
- Escape all output (`esc_html`, `esc_attr`, `esc_url`).
- Admin/area writes gated by capability + nonce/CSRF; public passwordless endpoints
  protected by rate-limit + anti-bot + single-use hashed codes. Never log codes.
- A socio is only inserted once the full alta form (with the required minimum data) is
  submitted and validated server-side.

## 6. Updates, versioning & releases

- Bump the version in **both** the plugin header and `ANPA_SOCIOS_VERSION` on every
  release.
- The self-hosted updater reads `details.json`; its URL defaults to a constant but is
  overridable per install via the `ANPA_SOCIOS_UPDATE_URL` constant.
- Regenerate `details.json` at release time **without a BOM** (PHP `json_decode` fails on
  a BOM). Advertise a version only after its release asset exists.

## 7. Language & i18n

- Source language is Galician. User-facing strings should move to `__()/esc_html__()`
  with the `anpa-socios` text domain; translations ship as `.mo` files under
  `/languages`. The active language is chosen in *Axustes → Localización e idioma*.

---

When in doubt, ask: *"Would this leak one association's data, a secret, or PII into a
public repo?"* If yes, make it a configurable option instead.
