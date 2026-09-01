# Changelog — ANPA Socios

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.49.2] - 2026-09-01

### Fixed

- Fix fatal error in Admin UX for Email Templates (`/wp-admin/admin.php?page=anpa-socios-templates`): replace undefined `admin_post_url()` with valid WordPress API `admin_url('admin-post.php?action=...')`.
  - Save action now correctly uses `admin-post.php?action=anpa_save_template`.
  - Restore action now correctly uses `admin-post.php?action=anpa_restore_template_<id>`.
- Add regression tests (`Test_ANPA_Socios_Admin_URL_Correctness`) to prevent re-introduction of `admin_post_url()`.

## [1.49.1] - 2026-08-30

### Changed

- Release preparation for FASE36 (Plantillas de Email).

## [1.49.0] - 2026-08-25

### Added

- **Email queue (Fase 35):** Persistent campaign/recipient/attempt queue with atomic row leases, bounded WP-Cron batch processing, exponential backoff retries, idempotent enqueue, orphan recovery, retention purging, and a read-only communications admin screen.
- **F35-SEC-001:** Email addresses are now redacted from persisted transport errors before storage.
- **Integration CI:** GitHub Actions workflow for real-database integration tests against MySQL 8.0 and MariaDB 10.6 (ephemeral services, never touches production).

### Fixed

- **Clean-install migration:** Historical migration 1.6.0 now skips cleanly when base columns already exist, preventing "Duplicate column name" errors on fresh installs.
- **Composer PHP 8.3:** `doctrine/instantiator` pinned to `^2.0` (PHP 8.1+) for PHP 8.3.33 compatibility.
- **WpCli-Remote portability:** Path resolution fix for non-Windows runners.

### Security

- Full security audit (Fase 33): 0 P0, 0 P1, 1 P2 (server hardening), 3 P3 (server hardening). `SECURITY.md` published.
- 8 controls verified: libsodium SEPA encryption, session HMAC+UA binding, prepared SQL, escaped output, CSRF nonces, updater HTTPS+SHA-256, zero secrets in releases, PII encrypted at rest.

## [1.48.0] - 2026-08-20

### Added

- Member area session management with HMAC-SHA256, User-Agent binding, and atomic usage counters.
- Company passwordless access via one-time codes.
- Backup/restore system with `.anpabak` encrypted format.

### Security

- One-time codes hashed with `wp_hash_password` before storage.
- Rate limiting on all public endpoints (3 requests/hour per email+IP).

## [1.47.0] - 2026-07-15

### Added

- Initial public release for WordPress.org preparation.
- SEPA banking data encryption via `sodium_crypto_secretbox`.
- Self-hosted updater from GitHub releases with SHA-256 integrity verification.

[1.49.0]: https://github.com/anpaventin/wp-anpa-socios/releases/tag/v1.49.0
[1.48.0]: https://github.com/anpaventin/wp-anpa-socios/releases/tag/v1.48.0
[1.47.0]: https://github.com/anpaventin/wp-anpa-socios/releases/tag/v1.47.0

## [1.49.1] - 2026-09-01

### Added

- **FASE36 — Sistema de Plantillas de Email:** sistema completo de plantillas
  transaccionales con 10 templates canónicos (verification_code, baixa_socio,
  reactivacion, baixa_extraescolar, oferta_extraescolar, pendente_aprobacion,
  aprobacion, benvida_alta, rexeitamento, send_from_master).
- **Renderizado de plantillas:** `ANPA_Socios_Email_Template_Renderer` con
  sustitución de variables, HTML/texto plano, subject, escaping/sanitización
  y fallback legacy.
- **Proveedor de plantillas:** `ANPA_Socios_Email_Template_Render_Provider`
  implementando `ANPA_Socios_Email_Render_Provider_Interface` (FASE35),
  registrado mediante filter `anpa_socios_email_render_provider`.
- **Integración transaccional:** los 10 métodos `enviar_*` de
  `ANPA_Socios_Email` integrados con `render_with_template()`.
- **Admin UX:** página "Plantillas de Email" bajo "Axustes" con lista,
  edición, preview, restore, gestión de capability y nonce.
- **Migración/Seed:** `ANPA_Socios_Email_Template_Migration` con activation
  hook y admin_init upgrade, idempotente, sin nuevas tablas.
- **Compatibilidad hacia atrás:** tests de retrocompatibilidad para los
  10 métodos transaccionales con fallback legacy.
- **Fix crítico:** `require_once` faltantes para
  `class-anpa-socios-email-template-store.php` y
  `class-anpa-socios-email-template-renderer.php` en `anpa-socios.php`.

### Fixed

- Corrección crítica: las clases del sistema de plantillas no se cargaban
  en producción por require_once faltantes.

### Notas técnicas

- Sin cambios de esquema de base de datos (DB 1.39.0).
- Sin nuevas dependencias, cola, scheduler, ni tablas.
- Suite PHPUnit: FASE36 tests pasan; errores/failures legacy preexistentes.
