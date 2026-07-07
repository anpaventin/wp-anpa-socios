<p align="center">
  <img src="assets/img/anpa-socios-logo.png" alt="ANPA Socios" width="160">
</p>

<h1 align="center">ANPA Socios</h1>

<p align="center">
  <strong>Member management for parents' associations (ANPA/AMPA) on WordPress.</strong><br>
  Passwordless member area, children & extracurricular enrolment, encrypted SEPA banking,
  season lifecycle and an admin dashboard — with self-hosted updates from Gitea.
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-1.23.0-blue">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4">
  <img alt="Tests" src="https://img.shields.io/badge/PHPUnit-strict%20TDD-2eac68">
</p>

---

## 📁 Repository Structure

```
wp-anpa-socios/
├── anpa-socios.php              # Plugin bootstrap: constants, requires, hooks
├── includes/                    # Runtime classes
│   ├── class-anpa-socios-*.php  # DB, REST, area, admin handlers, email, backup…
│   └── lib/                     # Pure, unit-tested logic (payload, crypto, flow, season…)
├── assets/
│   ├── css/                     # unified.css, area.css
│   ├── js/                      # unified.js, area.js, asociarse.js, admin-table.js…
│   └── img/                     # logo and static images
├── tests/                       # PHPUnit tests for the pure-logic classes
├── composer.json                # Dev dependencies (PHPUnit); no runtime deps
├── phpunit.xml                  # Test suite configuration
├── .gitattributes / .gitignore
└── README.md
```

## 🎯 Vision

Give volunteer-run parents' associations a **simple, self-hostable, low-maintenance**
tool to manage memberships and extracurricular activities without paper, spreadsheets,
or fragile per-person knowledge. The plugin favours **clarity for families** and
**reliable administration** for the board (*xunta directiva*), keeping configuration,
content and secrets strictly separated.

## 🚀 Get Started

1. **Install**: download the latest release ZIP from the [Releases](../../releases)
   page and upload it in *WordPress → Plugins → Add New → Upload Plugin*, or clone this
   repo into `wp-content/plugins/anpa-socios/`.
2. **Activate** the plugin in WordPress.
3. **Configure** it once in *ANPA Socios → Axustes*: master email, banking passphrase,
   admin password, socios page and current school year. This creates the database,
   the master account and the members page.
4. **Log in** on the socios page with the master email (a one-time code is emailed).

> Requires WordPress 6.0+ and PHP 7.4+. No Composer install is needed on the server —
> runtime code has no external dependencies.

## 🌟 Key Features

- **Passwordless member area** — email one-time-code login; no passwords for families.
- **Membership lifecycle** — alta, baixa, reactivation, optional **board approval** of
  new members, and a protected master/admin role.
- **Children & extracurriculars** — per-child enrolment, group capacity, waitlists,
  authorisations, per-activity min/max places and school-year scoping.
- **Encrypted SEPA banking** — sealed-box (public-key) encryption of IBAN/NIF.
- **Season lifecycle** — automatic course open/close with configurable dates.
- **Admin dashboard** — members, activities, courses, enrolments, exports (CSV),
  approvals and audit log.
- **Backup / restore / wipe** — encrypted `.anpabak` export protected by the admin
  password; full reinstall path.
- **Self-hosted updates** — one-click updates from this repo's Gitea Releases.

## 📖 Documentation

- In-plugin **Docs** screen (*ANPA Socios → Docs*): setup, season cycle, and the
  shortcodes for the public extracurriculars/timetable pages.
- Shortcodes: `[anpa_socios_area_unified]` (login/area), `[anpa_socios_asociarse]`
  (signup), `[anpa_extraescolares_ofertadas]`, `[anpa_extraescolares_horario]`.
- Development specs live in the private project repo under `openspec/`.

## 🤝 Community Support

This is a purpose-built plugin for **ANPA As Brañas (CEP Ventín, Ames)**. Issues and
suggestions are welcome via the repository's issue tracker. For association matters,
contact the *xunta directiva* at the email configured in the plugin.

## 🙌 Shout Outs

- [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
  — the mature library powering self-hosted updates.
- The WordPress **Settings API** and coding standards.
- Built for and with the families of **ANPA As Brañas**.

## 🔌 Canonical Plugin

`anpa-socios` is the **canonical member-management plugin** for the ANPA Ventín site.
It absorbs the former `anpa-verificacion` (email verification) module and supersedes the
`anpa-manager` prototype. The design-system and social-auto plugins remain separate.

## 🛠 Privacy & Telemetry

- **No telemetry.** The plugin sends **no analytics or usage data** anywhere.
- The only outbound request is the **update check** against this repository's public
  Gitea Releases (version metadata only) and the update download when you choose to
  update.
- Personal and banking data stay in your WordPress database; IBAN/NIF are stored
  **encrypted** (sealed box). Verification codes are hashed and never logged.
- Emails are sent through your own WordPress mail configuration.

---

<p align="center"><sub>Made for families, by families · ANPA As Brañas — CEP Ventín, Ames</sub></p>
