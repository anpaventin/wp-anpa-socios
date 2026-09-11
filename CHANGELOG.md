# Changelog — ANPA Socios

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.50.0] - 2026-09-11

### Added

- **Grupos de extraescolares: estado «deshabilitado»** (E5). Ademais de `aberto` (visible e con matrícula) e
  `pechado` (oculto; as matrículas existentes seguen e unha nova iría a lista de espera), un grupo pode quedar
  `deshabilitado`: oculto en todas partes, **non admite matrículas** (a área devolve 409) e consérvase para o
  histórico ou para reutilizalo. Só se pode escoller cando o grupo non ten ningunha matrícula vixente
  (activa, en lista de espera, con oferta ou con baixa solicitada); a comprobación faise dentro da
  transacción, tanto no interruptor de estado como ao gardar o formulario (`anpa_admin_grupo_en_uso`).
- **Cores e etiquetas** nas filas de grupos de Xestión: verde aberto, vermello pechado, amarelo deshabilitado,
  con lenda e recontos de matrículas na etiqueta. O selector do formulario explica cada estado e bloquea
  «deshabilitado» cando hai matrículas vixentes.
- **Botón «Eliminar»** (E6) nas filas de grupos do curso activo sen ningunha matrícula nin histórico, con
  confirmación. O servidor segue rexeitando o borrado de grupos con rexistros (agora indica cantos).
- Test de contrato `Test_ANPA_Socios_Grupo_Estado_Deshabilitado`.

### Changed

- **Esquema 1.40.0**: `anpa_grupos.estado` pasa a `enum('aberto','pechado','deshabilitado')`. Migración
  idempotente en liña (só metadatos; os valores existentes non cambian) con postcondición.
- A listaxe de grupos de administración devolve `estado_label`, `matriculas_vixentes` e `matriculas_total`.
## [1.49.6] - 2026-09-11

### Changed

- **Axustes → Mantemento → «Actualizar niveis dos fillos»: un só botón.** Pulsalo calcula sempre primeiro
  sen gardar nada e amosa a distribución por curso e a lista de cambios. Só entón aparece o botón
  «Aplicar estes N cambios», ligado a unha pegada (`fingerprint`) do plan simulado: se algún dato cambiou
  desde a simulación, a aplicación párase sen escribir e pídese simular de novo. Desaparece o botón
  «Simular (non garda cambios)» separado (`ANPA_Socios_Nivel_Promotion_Service::run( false, $fingerprint )`).

### Added

- **Auditoría dos eventos iniciados polas familias**, independentemente de que estea activada ou non a
  aprobación de altas pola directiva. Rexístranse en `anpa_audit_log` con actor tipo `socio` (o correo da
  persoa que actúa) e sen ningún dato persoal adicional: `alta_activa` / `alta_pendente` /
  `alta_segundo_proxenitor`, `reactivacion_solicitada`, `baixa_solicitada` / `baixa_cancelada`,
  `iban_actualizado` (só o feito, nunca os datos bancarios), `fillo_engadido` / `fillo_actualizado` /
  `fillo_eliminado`, `matricula_creada_<estado>`, `matricula_baixa_solicitada` / `matricula_baixa` /
  `matricula_baixa_cancelada` e `oferta_aceptada`. Test de contrato `Test_ANPA_Socios_Member_Audit_Events`.

### Fixed

- Plantilla «Oferta de praza»: corrixido «Offerta» → «Oferta» no asunto por defecto (as instalacións que xa
  sementaran as plantillas deben pulsar «Restaurar» ou executar `restore_all()`).
- Tradución `es_ES` de 31 cadeas das plantillas de correo (só o texto por defecto en galego existía).
- Suite PHPUnit: `tests/bootstrap.php` rexistra as clases da cola de correo (fase 36) e un `$wpdb` mínimo;
  5 tests orfos engadidos a `phpunit.xml`; 4 tests dependentes do entorno (menú, capacidades, asunto do
  código de verificación) actualizados. Resultado: 0 erros, 0 fallos.

## [1.49.5] - 2026-09-11

### Fixed

- **Plantillas de email en galego.** Os textos por defecto das 10 plantillas transaccionais (código de
  verificación, benvida, aprobación, rexeitamento, baixas, oferta de praza, envío desde a directiva…)
  estaban en inglés en todas as releases publicadas: a tradución ao galego fixérase o 2026-09-05 no commit
  `6ccb39e` da rama `feature/fase36-plantillas-email` pero nunca chegou a `main`. Recupérase só ese cambio
  (46 cadeas) co seu test (`Test_ANPA_Socios_Email_Templates_Galego`, 10 tests). As instalacións que xa
  sementaran as plantillas en inglés deben pulsar «Restaurar» en cada plantilla (ou executar
  `ANPA_Socios_Email_Template_Store::restore_all()`) para recoller os textos galegos.

## [1.49.4] - 2026-09-10

### Fixed

- **Axustes → Mantemento → «Actualizar niveis dos fillos»**: os fillos que pola súa data de nacemento
  xa superarían o último nivel configurado **mantéñense nese nivel (6º)** en vez de quedar sen curso.
  Antes o botón quitáballes o curso («finalizados»); agora só a familia ou a directiva os dá de baixa.
- Novo botón **«Simular (non garda cambios)»**: executa exactamente a mesma validación e cálculo e amosa
  cantos fillos cambiarían de nivel, a distribución resultante por curso e a lista de cambios
  (fillo, idade, nivel actual → novo, aula), sen escribir nada.
- O aviso de resultado indica agora cantos fillos quedan no último nivel por idade e lista os correos
  das súas familias para comprobar se seguen no centro.
- A descrición do botón explica de onde sae a idade: o nivel cuxa «Idade alumnado» (Estrutura escolar)
  coincide coa idade que o alumno cumpre no ano final do curso activo.

## [1.49.3] - 2026-09-10

### Changed

- Área de socios, área unificada e formulario de alta: os campos de **correo electrónico** e de **código de 6 díxitos** amosan agora un exemplo en gris claro (`nome@exemplo.com`, `123456`) que desaparece ao escribir, para que as familias identifiquen mellor que teñen que introducir. Texto traducible (es_ES incluído).
- O campo do código no formulario de alta pasa a `inputmode="numeric"` e `autocomplete="one-time-code"` (teclado numérico e autocompletado do SMS/correo no móbil), como xa facían a área e a área unificada.
- `details.json` volve apuntar á release real (a metadata publicada en `main` quedara en 1.49.0 e a da etiqueta 1.49.2 en 1.48.0, polo que o actualizador nunca ofreceu 1.49.1/1.49.2).

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
