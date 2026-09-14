# Changelog — ANPA Socios

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.59.0] - 2026-09-14

### Changed

- **Panel da empresa e do comedor: descargas arriba, alumnado antes da oferta.** Os botóns «Descargar … (CSV)» e
  «Pechar sesión» pasan ao principio do panel, xusto baixo a descrición, para que se vexan ao entrar sen ter que
  baixar todo o listado. A sección «Actividades ofertadas» vai agora despois de «Alumnado matriculado e baixas»,
  que é o que o persoal de comedor e as empresas veñen consultar.
- **Listado de alumnado con busca e ordenación por columnas** nos dous paneis (empresa e comedor), igual que nos
  listados de Xestión: caixa «Buscar…» que filtra por calquera columna visible (sen distinguir maiúsculas) e
  cabeceiras clicables que ordenan ▲/▼ (orde alfabética local e numérica: «1º A» antes de «10º»). Reutiliza os
  mesmos helpers puros de Xestión (`admin-table.js` e `anpa-utils.js`), que agora tamén se cargan na área e na
  páxina unificada como dependencias de `area.js`. A busca filtra sen redibuxar a caixa, así que non se perde o
  foco ao escribir. Sen busca, mantense a orde do servidor (actividade, empresa, grupo, estado, apelidos).
- **Listados de Xestión homoxéneos.** Os seis listados con busca (socios, fillos, empresas, actividades,
  matrículas, auditoría) seguen agora o mesmo esquema: filtrar → ordenar → conectar a busca → «sen resultados»
  con `emptyEl()` → paxinar → táboa → paxinación. Elimínase a segunda chamada redundante a `wireSearchInput()`
  ao final de cada render e o restablecemento do foco na caixa de busca é dun só uso: ordenar por unha columna ou
  cambiar de páxina xa non devolve o foco á caixa despois de ter buscado algo.

## [1.58.0] - 2026-09-14

### Added

- **Xestión → Socios → Lista Gmail.** Procedemento manual, sen credenciais de Google nin tarefas en segundo plano,
  para manter en Gmail a etiqueta de contactos «Socios Web ANPA» cos socios/as activos: botón «Descargar CSV para
  Google Contactos» (formato CSV de Google, columnas `First Name`, `Last Name`, `E-mail 1 - Value` e `Labels` con
  `Socios Web ANPA ::: * myContacts`; UTF-8 sen BOM), botón «Abrir Google Contactos» (con `authuser=` cando se
  configura a conta da xunta), instrucións paso a paso (confirmar baixas → descargar → abrir → eliminar etiqueta cos
  seus contactos → importar) e aviso explícito de que as baixas sen confirmar en Baixas solicitadas seguen na lista.
- A exportación gárdase como referencia (`anpa_socios_contactos_google_snapshot`): o panel amosa a última exportación,
  as altas e baixas desde entón (con nome e correo) e se fai falta importar de novo. Queda na auditoría.
- REST admin: `GET /contactos-google/estado`, `GET /contactos-google/export` (`ANPA_Socios_Admin_Contactos_Google_Handler`).
- Axustes → Xeral → Configuración: «Conta de Google da xunta (Lista Gmail)», opcional.

### Changed

- Documentación: nova entrada «Lista de correo en Gmail» na sección Socios.

## [1.57.0] - 2026-09-14

### Added

- **Xestión → Socios → Baixas solicitadas.** Panel novo coas dúas colas que as familias abren desde a área:
  baixas de socio/a (`baixa_estado = solicitada`, con fillos activos e matrículas vixentes da familia) e baixas de
  actividade (`matriculas.estado = baixa_solicitada`, con alumno/a, curso/aula, actividade, grupo e correo da familia),
  ordenadas pola data da solicitude e con botóns «Confirmar baixa» e «Rexeitar». Ata agora só chegaba o correo á
  xunta e os endpoints de confirmación existían sen botón; a documentación remitía a un paso que non estaba na
  interface.
- REST admin: `GET /baixas-pendentes`, `POST /socio/<email>/baixa/reject` e `POST /matricula/<id>/baixa/reject`
  (novo handler `ANPA_Socios_Admin_Baixas_Handler`). Rexeitar devolve a matrícula a «activo» ou limpa a solicitude do
  socio/a; ambos quedan na auditoría (`baixa_reject`). A confirmación segue nos handlers existentes (garda do
  administrador raíz; liberación da praza para a lista de espera).

### Changed

- Documentación: os pasos de fin de curso e de «Baixas e reactivacións» apuntan á nova sección.

## [1.56.4] - 2026-09-14

### Fixed

- **«O curso do alumno/a non encaixa neste grupo» para fillos engadidos pola familia.** A vía do área
  (POST /fillos e PATCH /fillo/<id>) escribía `fillos_cursos` só con curso e aula, sen `nivel_id`/`aula_id`.
  Como o filtro de grupos non se aplica con nivel 0 pero a matrícula exixe un nivel enlazado ao grupo, todo
  intento de matrícula dun fillo engadido ou editado dende o área fallaba (13 fillos en produción dende o
  12-09). A corrección D94 (fase 23) aplicárase só ao handler de Xestión; agora o área usa o mesmo
  `upsert_fillo_curso_assignment()`, que resolve `nivel_id`/`aula_id`, e alta/edición son atómicas
  (transacción, fallo pechado). Un cambio de curso dende o área xa non deixa un `nivel_id` desfasado.
- Auditoría «fillo_engadido»: gardaba o id da fila de `fillos_cursos` en vez do id do fillo.
- Script de reparación de datos (monorepo `scripts/remote-repair-fillos-cursos-nivel.php`, dry-run por
  defecto) que resolve o nivel das filas do curso activo sen nivel ou co nivel desfasado.

## [1.56.3] - 2026-09-14

### Fixed

- **Listas de matrículas baleiras en Xestión (regresión de 1.56.2).** Xestión → Matrículas e o alumnado por
  grupo devolvían 0 filas con HTTP 200. O SQL de 1.56.2 levaba o literal «º» e un `TRIM(TRAILING 'º' FROM …)`:
  ao non ser ASCII, `wpdb` executa a súa comprobación de texto inválido e o seu analizador de táboas
  (`get_table_from_query()`) tomaba ese FROM interior como o principal, resolvía a táboa como «COALESCE» e
  rexeitaba a consulta enteira («non se puido realizar a consulta porque contén datos non válidos»). A etiqueta
  «Curso/Aula» calcúlase agora en PHP (`ANPA_Socios_Admin_Shared::curso_completo()`, «3ºD» ou «3º»; baleira
  sen nivel) e as dúas consultas quedan en ASCII puro, de xeito que `wpdb` nin sequera entra nese camiño.
  Test de contrato: o SQL dos dous handlers debe seguir sendo ASCII. Reproducido e verificado en LXC103
  (WP 7.1) con `wpdb` real.

## [1.56.2] - 2026-09-12

### Fixed

- **«3ººD» en Xestión.** As columnas «Curso/Aula» do listado de matrículas (Xestión → Matrículas) e do
  alumnado por grupo engadían «º» a un curso que xa é canónico («3º» desde 1.51.x), e quedaban en
  branco (NULL) cando o fillo/a aínda non ten letra (1.54.0). O SQL retira o «º» final antes de engadilo
  e trata a letra baleira como cadea vacía: «3ºD» ou «3º». Non hai máis puntos afectados (os demais
  listados e CSV amosan curso e aula en columnas separadas).
## [1.56.1] - 2026-09-12

### Fixed

- **As matrículas da familia non se vían na área.** `GET /area/me/matriculas` devolve
  `{ matriculas, current, available_courses }` desde a importación inicial, pero `area.js` lía a resposta
  como se fose un array, polo que «Extraescolares» e o panel amosaban sempre «Aínda non tes ningunha
  matrícula» aínda que o fillo/a estivese matriculado (e a actividade xa non aparecía na oferta). Nova
  `matriculasList()` nos dous puntos de lectura; cada matrícula amosa o curso escolar. Incidencia
  comunicada por unha familia o 2026-09-12.
- **Conta do comedor:** a descarga do CSV devolvía 500 porque o gardián do export rexeitaba o id 0 da
  conta sintética.
## [1.56.0] - 2026-09-12

### Added

- **Conta do comedor.** En Axustes → Configuración, campo «Correo da persoa responsable do comedor»
  (`anpa_socios_comedor_email`, `ANPA_Socios_Config::comedor_email()`). Ese correo entra pola entrada
  unificada como conta de empresa sintética (`ANPA_Socios_Empresa_REST::comedor_profile()`, id 0 =
  todas as empresas): o preflight devolve `empresa`, o código pídese por `/empresa/solicitar-codigo` e
  a sesión por `/empresa/session`. O panel («Panel do comedor») lista o alumnado con matrícula activa en
  todas as actividades do curso, clasificado por actividade e empresa, coas opcións e autorizacións das
  familias (autorización ao persoal de comedor, transición tras o comedor, Tardes divertidas, recollida,
  cesión de datos) e o contacto da familia; a descarga é sempre o listado completo sen baixas
  (`alumnos-comedor.csv`, columnas `columns_panel_comedor()`, auditada como `export_alumnos_comedor`).
- **Exclusividade do correo:** o correo do comedor non pode ser o dun socio/a nin dunha empresa
  (Axustes rexeita o cambio cun aviso e garda o resto), e a alta, a área, Xestión → Empresas e as
  importacións rexeitan o correo do comedor (`ANPA_Socios_Email_Ownership`, 409).
- **Panel da empresa e CSV:** nova columna «Opcións e autorizacións» (e as columnas
  `autorizacion_comedor`, `tarde_transicion`, `tardes_divertidas_continua`, `recollida_autorizada`,
  `cesion_datos_empresa` no CSV).
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
