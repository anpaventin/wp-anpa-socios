# Changelog — ANPA Socios

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.55.1] - 2026-09-12

### Fixed

- **Correo de empresa na entrada unificada.** O formulario de entrada de «Socios → Área persoal»
  (`[anpa_socios_area]` / unified.js) só coñecía os fluxos de socio/a e alta: un correo de empresa
  recibía o código de alta e acababa no formulario de alta de socio/a. Agora, cando o preflight devolve
  `empresa`, pídese o código polo endpoint da empresa (`/empresa/solicitar-codigo`), tras verificalo
  intercámbiase pola sesión de empresa (`/empresa/session`) e ábrese o panel da empresa no shell da
  área (`window.AnpaArea.openEmpresa`). Nunca se amosa o formulario de alta a un correo de empresa.
  Ensaiado no LXC103 cunha empresa sintética con dúas matrículas (activa + baixa).
## [1.55.0] - 2026-09-12

### Added

- **Un correo, un rol.** `ANPA_Socios_Email_Ownership`: un correo asignado a un socio/a non pode usarse para
  unha empresa nin ao revés. Compróbase ao crear ou editar empresas (Xestión), na importación CSV de empresas
  e socios, na alta pública (titular e segundo proxenitor), no cambio de correo do perfil e do segundo
  proxenitor na área. Mensaxe clara nos dous sentidos (409 `anpa_email_reservado_socio` /
  `anpa_email_reservado_empresa`). O formulario de alta público, se o correo é de empresa, amosa a ponte
  «Este correo pertence a unha empresa…» co acceso á área en vez do formulario.
- **Panel da empresa ampliado.** Ao entrar cun correo de empresa (o fluxo xa o detectaba) móstranse os
  datos da empresa e o curso escolar activo, as actividades ofertadas cos seus grupos (horario, días, estado,
  prazas activas/máximo, lista de espera, baixas) e a táboa de alumnado con estado (activo, lista de espera,
  oferta, baixa solicitada, baixa) e contacto da familia. Dúas descargas CSV: só activos ou listado completo
  (`GET /empresa/me/export?ambito=activos|todos`, columnas `columns_panel_empresa()`, auditadas).
- **Navegación da área.** Ao pulsar unha sección (Inicio, Extraescolares, Fillos/as, Os meus datos,
  Conta / IBAN, atallos do panel) a páxina despraza ao inicio dese apartado; «Editar» un fillo/a leva ao
  formulario e pon o foco no nome; gardar ou cancelar volve ao inicio da lista; o panel da empresa tamén.
- **Matrícula:** a autorización de cesión de datos á empresa vén marcada por defecto (segue sendo
  obrigatoria).
## [1.54.0] - 2026-09-11

### Changed

- **A letra da aula pode estar vacía.** A asignación anual (`upsert_fillo_curso_assignment`) acepta aula
  vacía e o plan de niveis xa non falla por «fillo sen aula». **«Actualizar niveis dos fillos» borra a letra
  aos fillos que cambian de nivel** (a letra antiga era doutro curso); os que non cambian conservan a súa.
  A táboa de cambios e a descrición de Mantemento explícano.
- **Área de socios:** a lista de fillos marca en vermello os que non teñen aula, o resumo do panel avisa
  á familia, e o formulario de matrícula non deixa escoller un fillo sen aula (o servidor tamén o rexeita
  con `anpa_extra_sen_aula`, 409). A familia arránxao en Fillos/as → Editar → curso e aula → Gardar.
- Operación única en produción (2026-09-11): baleiráronse as letras de aula de todos os fillos activos do
  curso 2026/2027 (a migración asignara letras provisionais), con copia previa (`scripts/remote-clear-aulas.php`).
## [1.53.0] - 2026-09-11

### Added

- **Contido administrativo (fase 37).** Nova pestana **Axustes → Contido** con cinco categorías fixas
  (transporte, libros, bos días, comedor, tardes divertidas). Para cada unha a directiva edita título,
  texto (HTML básico), icona, orde, documentos e ligazóns; comedor e libros admiten listas de elementos.
  Todo gárdase en `wp_options` (`anpa_socios_contenido_admin`), sen cambios de esquema. Shortcode público
  `[anpa_contenido categoria="comedor"]` que pinta a categoría en tarxetas (estilos en
  `assets/css/contenido-shortcode.css`). Gardado con nonce por categoría e sanitización por campo.
  Inclúe `SECURITY.md` e un workflow de release estable de execución manual. 190 tests novos.
  Rama `feature/fase37-contenido-rebase` (Hermes, rebaseada sobre 1.52.0) + corrección da fila duplicada
  de categorías; retirado o `package.json` de Playwright que non pertence ao plugin.
## [1.52.0] - 2026-09-11

### Changed

- **Erros dos formularios das familias, por campo e á vista (E8).**
  `ANPA_Socios_Admin_Payload::validar_fillo_con_erros()` explica cada campo rexeitado dun fillo/a (nome,
  apelidos, data de nacemento con formato esperado, curso ou aula inexistentes no ano escolar). A alta pública
  devolve eses erros como `fields` (`fillo_<n>_<campo>` + resumo `fillos`) co texto «Corrixe os campos
  marcados»; o formulario marca o campo en vermello dentro da fila do fillo/a, escribe a mensaxe debaixo e
  despraza e pon o foco no primeiro campo inválido. Os endpoints da área `POST/PATCH /fillos` devolven os
  mesmos `fields`; a área marca os campos e centra o aviso na pantalla en todos os fluxos. A matrícula sen
  actividade ou grupo di «Escolle a actividade e o grupo» en vez de «Datos inválidos».
  `validar_fillo()` mantén o seu contrato (devolve o fillo ou `null`).
## [1.51.2] - 2026-09-11

### Fixed

- **Alta pública con fillos: fallaba sempre con «Datos inválidos».** O formulario de alta non enviaba o
  `curso_escolar` de cada fillo e o servidor validaba entón o curso contra a lista fixa antiga (`1`…`6`),
  mentres o desplegable envía os códigos reais (`1º`…`6º`). Dende a estrea da web (2026-09-10) ningunha
  familia nova con fillos podía completar a alta. Agora `ANPA_Socios_REST::normalizar_fillos_alta()` asigna
  a cada fillo o curso escolar activo antes de validar (contra os niveis e aulas reais, como fai a área),
  a lista fixa acepta tamén os códigos canónicos (`CURSO_VALIDOS_CANONICOS`), a páxina unificada localiza
  `curso_escolar` e o formulario envíao. O desplegable de reserva usa os códigos canónicos.
- Formulario de alta: o aviso de erro desprázase á vista ao aparecer; eliminada unha opción en branco
  duplicada no desplegable de aula.
## [1.51.1] - 2026-09-11

### Changed

- **«Comunicacións» → «Rexistro de envíos»** (E4). A pantalla é o monitor da cola de correo dos envíos masivos
  («Enviar desde a directiva»); agora dío na propia páxina e aclara que os correos automáticos dun só
  destinatario non pasan por aquí (as súas plantillas están en «Plantillas de Email»). O slug do menú non cambia.
- **Documentación reescrita** (E7) en nove seccións: posta en marcha (con shortcodes); ciclo anual con regra
  única de matrículas, checklist de setembro e de fin de curso; socios/as (alta e aprobación, segundo
  proxenitor, baixas e reactivacións, auditoría, importación CSV); extraescolares (estados dos grupos con
  cores, eliminar, comedor, lista de espera e ofertas de tres días); comunicacións; exportacións e copias;
  privacidade e seguridade; **manual das familias** (texto reutilizable da entrada do blog); e **checklist
  para administradores/as novos**. Retirados os textos obsoletos («Importar listados é só unha guía»,
  «casilla Matrículas abertas», «lista CCO»).

## [1.51.0] - 2026-09-11

### Changed

- **Regra única de matrículas (E3).** As matrículas, baixas e solicitudes das familias están abertas **só**
  cando o curso está `activo` **e** a ventá do trimestre actual está `aberta`. O trimestre actual derívase
  das datas operativas do curso (con recurso ao modelo por meses se non están configuradas). Desaparece a
  casilla «Matrículas abertas» de Axustes → Cursos: o formulario amosa o estado derivado en só lectura e o
  único interruptor son os botóns «Abrir/Pechar matrículas (ventá)» do panel «Estado dos trimestres»
  (renomeado e explicado: estado lectivo informativo vs. ventá que abre as matrículas). O «Estado» da
  pestana Xeral e a listaxe de cursos (`GET /admin/cursos`) devolven o estado derivado
  (`matriculas_abertas`, `matriculas_motivo`, `matriculas_etiqueta`).
- A área de socios comproba a regra nas dúas gardas (lectura e baixo bloqueo): a fila do trimestre actual
  bloquéase `FOR UPDATE` xunto coa do curso, así unha directiva que pecha a ventá non pode cruzarse cunha
  matrícula. Sen filas de trimestre → pechado (fail-closed).
- `PUT /admin/curso` ignora `matriculas_abertas` do corpo; o asistente de posta en marcha, se se marca
  «abrir matrículas», inicializa os trimestres e abre a ventá do trimestre actual (transición auditada).
- A columna `cursos.matriculas_abertas` queda como caché derivada (listaxes/exportacións) e sincronízase
  en cada transición de ventá e cambio de ciclo. Ningún código decide sobre ela.

### Added

- `ANPA_Socios_Matricula_Gate` (regra pura) e `ANPA_Socios_Matricula_Gate_Repo` (lectura/bloqueo/sincronización).
- **Migración de datos 1.41.0**: cada curso activo coa casilla antiga marcada inicializa os trimestres e abre
  a ventá do trimestre actual (orixe `migracion`, rexistrada), de modo que as familias seguen podendo
  matricularse exactamente igual; despois reescríbese a caché para todos os cursos. Idempotente.
- Test `Test_ANPA_Socios_Matricula_Gate`.
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
