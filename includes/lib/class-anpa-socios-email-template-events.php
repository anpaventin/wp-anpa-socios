<?php
/**
 * The declared template events (fase36, PR-36s1b2).
 *
 * Data, not logic: the variable dictionary and the catalogued events, handed to
 * `ANPA_Socios_Email_Template_Registry` which validates them and returns an immutable
 * fingerprinted set. Every rule about what a valid declaration looks like lives in the
 * engine; this file only declares.
 *
 * Validation happens on first access and **throws**. A registry with an alias pointing
 * nowhere or a variable with no example is a plugin bug for CI to catch, not a runtime
 * condition an operator can fix, so there is no degraded mode.
 *
 * LANGUAGE NOTE. Labels and descriptions are plain Galician strings, never wrapped in a
 * translation function, because this class must stay usable without WordPress loaded — the
 * purity test greps for exactly that. That matches the decision
 * already recorded for the token names themselves (see `template-catalogue.md`): the
 * variable panel is an editing surface for a Galician-speaking board, not a code
 * identifier. If the plugin is ever localised, these move to a translation layer keyed by
 * token — recorded as a recommended improvement, deliberately not done here.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Events {

	/** @var ANPA_Socios_Email_Template_Set|null Memoised set. */
	private static ?ANPA_Socios_Email_Template_Set $set = null;

	/**
	 * The validated, immutable event set.
	 *
	 * @since  1.40.0
	 * @return ANPA_Socios_Email_Template_Set
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the declarations are inconsistent.
	 */
	public static function set(): ANPA_Socios_Email_Template_Set {
		if ( null === self::$set ) {
			self::$set = ANPA_Socios_Email_Template_Registry::build(
				self::dictionary(),
				self::globals(),
				self::declarations()
			);
		}

		return self::$set;
	}

	/**
	 * Tokens available to every event, resolved from configuration at render time.
	 *
	 * @since  1.40.0
	 * @return string[]
	 */
	public static function globals(): array {
		return array( 'nome_anpa', 'correo_anpa', 'ligazon_web', 'ligazon_area_socios', 'sinatura' );
	}

	/**
	 * Every canonical token, declared once so its label, description and example cannot
	 * diverge between the events that use it.
	 *
	 * @since  1.40.0
	 * @return array<string,array<string,mixed>>
	 */
	public static function dictionary(): array {
		$text      = ANPA_Socios_Email_Template_Variable::TYPE_TEXT;
		$multiline = ANPA_Socios_Email_Template_Variable::TYPE_MULTILINE;
		$url       = ANPA_Socios_Email_Template_Variable::TYPE_URL;
		$email     = ANPA_Socios_Email_Template_Variable::TYPE_EMAIL;
		$number    = ANPA_Socios_Email_Template_Variable::TYPE_NUMBER;
		$date      = ANPA_Socios_Email_Template_Variable::TYPE_DATE;

		return array(
			// ── Globals ────────────────────────────────────────────────
			'nome_anpa'               => array(
				'label'       => 'Nome da asociación',
				'description' => 'Nome da ANPA, tomado dos axustes. Nunca escribas o nome a man.',
				'example'     => 'ANPA Exemplo',
				'type'        => $text,
			),
			'correo_anpa'             => array(
				'label'       => 'Correo de contacto',
				'description' => 'Enderezo de contacto configurado nos axustes.',
				'example'     => 'contacto@example.org',
				'type'        => $email,
			),
			'ligazon_web'             => array(
				'label'       => 'Ligazón á web',
				'description' => 'Páxina principal da web da asociación.',
				'example'     => 'https://example.org',
				'type'        => $url,
			),
			'ligazon_area_socios'     => array(
				'label'       => 'Ligazón á área de socios',
				'description' => 'Páxina de acceso á área persoal das familias.',
				'example'     => 'https://example.org/area-socios/',
				'type'        => $url,
			),
			'sen_ligazon_area_socios' => array(
				'label'       => 'Sen ligazón á área de socios',
				'description' => 'Marca interna: enche o parágrafo alternativo cando non hai ligazón. Non se amosa nunca no correo; só activa ou desactiva un bloque. Constrúea sempre ANPA_Socios_Email_Template_Context::area_link().',
				'example'     => '1',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
			),
			'sinatura'                => array(
				'label'       => 'Sinatura',
				'description' => 'Sinatura configurada para os correos. Engádese ao final.',
				'example'     => "A Xunta Directiva\nANPA Exemplo",
				'type'        => $multiline,
			),

			// ── Persoas ────────────────────────────────────────────────
			'codigo'                  => array(
				'label'       => 'Código de acceso',
				'description' => 'Código dun só uso. Non se rexistra en ningún historial.',
				'example'     => '123456',
				'type'        => $text,
			),
			'nome_socio'              => array(
				'label'       => 'Nome do socio/a',
				'description' => 'Nome e apelidos da persoa socia.',
				'example'     => 'Uxía Exemplo Ficticio',
				'type'        => $text,
			),
			'correo_socio'            => array(
				'label'       => 'Correo do socio/a',
				'description' => 'Enderezo da persoa socia á que se refire o aviso.',
				'example'     => 'nai@example.com',
				'type'        => $email,
			),
			'nome_solicitante'        => array(
				'label'       => 'Nome do solicitante',
				'description' => 'Nome de quen presentou a solicitude de alta.',
				'example'     => 'Uxía Exemplo Ficticio',
				'type'        => $text,
			),
			'correo_solicitante'      => array(
				'label'       => 'Correo do solicitante',
				'description' => 'Enderezo de quen presentou a solicitude de alta.',
				'example'     => 'nai@example.com',
				'type'        => $email,
			),
			'nome_alumno'             => array(
				'label'       => 'Nome do alumno/a',
				'description' => 'Alumno/a ao que se refire a matrícula ou a baixa.',
				'example'     => 'Antía Exemplo',
				'type'        => $text,
			),
			'nome_proxenitor'         => array(
				'label'       => 'Nome do proxenitor/a',
				'description' => 'Persoa á que se dirixe o correo. Non é o mesmo que o socio/a titular: nunha familia con dous contactos, cada un recibe o seu correo co seu nome.',
				'example'     => 'Uxía Exemplo',
				'type'        => $text,
			),
			'curso_alumno'            => array(
				'label'       => 'Curso e grupo do alumno/a',
				'description' => 'Curso académico e aula do alumno/a. Non se pode derivar de {{curso_escolar}}: ese é o ano, este é onde está o rapaz ou a rapaza.',
				'example'     => '4º de Primaria A',
				'type'        => $text,
			),

			// ── Actividades e grupos ───────────────────────────────────
			'nome_actividade'         => array(
				'label'       => 'Nome da actividade',
				'description' => 'Actividade extraescolar á que se refire o correo.',
				'example'     => 'Robótica',
				'type'        => $text,
			),
			'nome_grupo'              => array(
				'label'       => 'Nome do grupo',
				'description' => 'Grupo concreto dentro da actividade.',
				'example'     => 'Robótica Martes',
				'type'        => $text,
			),
			'horario_grupo'           => array(
				'label'       => 'Horario do grupo',
				'description' => 'Días e horas do grupo, tal e como se publican.',
				'example'     => 'Martes e xoves, 16:30 a 17:30',
				'type'        => $text,
			),
			'numero_alumnos'          => array(
				'label'       => 'Número de alumnos/as',
				'description' => 'Cantos alumnos/as ten o grupo. Só un número: nunca unha lista de nomes nun correo.',
				'example'     => '14',
				'type'        => $number,
			),
			'estado_grupo'            => array(
				'label'       => 'Estado do grupo',
				'description' => 'Etiqueta humana do estado do grupo. Constrúea ANPA_Socios_Email_Template_Context::group_state_label() a partir dun estado validado: nunca texto libre que veña dunha petición.',
				'example'     => 'Confirmado',
				'type'        => $text,
			),
			'estado_matricula'        => array(
				'label'       => 'Estado da matrícula',
				'description' => 'Etiqueta humana do estado da matrícula. Constrúea ANPA_Socios_Email_Template_Context::enrollment_state_label() a partir dun estado validado: nunca texto libre que veña dunha petición.',
				'example'     => 'Activa',
				'type'        => $text,
			),
			'prazas_minimas'          => array(
				'label'       => 'Prazas mínimas',
				'description' => 'Número mínimo de alumnos/as para que o grupo se manteña.',
				'example'     => '8',
				'type'        => $number,
			),
			'posicion_lista'          => array(
				'label'       => 'Posición na lista de espera',
				'description' => 'Posición actual na lista de espera.',
				'example'     => '3',
				'type'        => $number,
			),
			'dias_prazo'              => array(
				'label'       => 'Días de prazo',
				'description' => 'Días dos que dispón a familia para responder.',
				'example'     => '3',
				'type'        => $number,
			),
			'motivo_cambio'           => array(
				'label'       => 'Motivo do cambio',
				'description' => 'Explicación do cambio na actividade, redactada pola xunta.',
				'example'     => 'Cambio de aula por obras no pavillón.',
				'type'        => $multiline,
			),

			// ── Calendario ─────────────────────────────────────────────
			'curso_escolar'           => array(
				'label'       => 'Curso escolar',
				'description' => 'Curso escolar ao que se refire o correo.',
				'example'     => '2026/2027',
				'type'        => $text,
			),
			'trimestre_actual'        => array(
				'label'       => 'Trimestre actual',
				'description' => 'Trimestre en curso.',
				'example'     => 'Segundo trimestre',
				'type'        => $text,
			),
			'trimestre_seguinte'      => array(
				'label'       => 'Trimestre seguinte',
				'description' => 'Trimestre que está a piques de comezar.',
				'example'     => 'Terceiro trimestre',
				'type'        => $text,
			),
			'data_efectiva'           => array(
				'label'       => 'Data efectiva',
				'description' => 'Data na que o cambio ou a baixa ten efecto.',
				'example'     => '30/06/2027',
				'type'        => $date,
			),
			'data_caducidade'         => array(
				'label'       => 'Data de caducidade',
				'description' => 'Data na que deixa de estar dispoñible unha ligazón.',
				'example'     => '15/10/2026',
				'type'        => $date,
			),
			'data_inicio'             => array(
				'label'       => 'Data de inicio',
				'description' => 'Día no que comeza a actividade ou o grupo.',
				'example'     => '05/10/2026',
				'type'        => $date,
			),
			'data_limite'             => array(
				'label'       => 'Data límite',
				'description' => 'Data na que remata o prazo para responder ou actuar.',
				'example'     => '20/10/2026',
				'type'        => $date,
			),

			// ── Recordatorios e incidencias ────────────────────────────
			'accion_pendente'         => array(
				'label'       => 'Acción pendente',
				'description' => 'Que ten que facer a familia. NON é texto libre: constrúeo ANPA_Socios_Email_Template_Context::pending_action_label() a partir dun tipo de acción controlado. Un recordatorio cuxo corpo escribe o emisor é un recordatorio que ninguén pode revisar nin manter coherente.',
				'example'     => 'Confirmar unha praza ofrecida nunha actividade extraescolar.',
				'type'        => $multiline,
			),
			'motivo_erro'             => array(
				'label'       => 'Motivo do erro',
				'description' => 'Descrición do fallo en linguaxe comprensible. Nunca trazas internas, credenciais nin mensaxes técnicas do servidor.',
				'example'     => 'O servidor de correo rexeitou o enderezo da empresa.',
				'type'        => $multiline,
			),
			'accion_recomendada'      => array(
				'label'       => 'Acción recomendada',
				'description' => 'Que debería facer a xunta a continuación. Un aviso de fallo sen acción recomendada só transmite alarma.',
				'example'     => 'Revisa o enderezo da empresa nos seus datos e reintenta o envío.',
				'type'        => $multiline,
			),
			'consecuencia'            => array(
				'label'       => 'Que ocorre se non se actúa',
				'description' => 'Consecuencia de non responder a tempo. Un recordatorio sen consecuencia non explica por que urxe.',
				'example'     => 'Se non respondes antes desa data, a praza ofrecerase á seguinte familia da lista de espera.',
				'type'        => $multiline,
			),
			'data_intento'            => array(
				'label'       => 'Data do intento',
				'description' => 'Momento no que se intentou o envío.',
				'example'     => '02/10/2026',
				'type'        => $date,
			),
			'id_correlacion'          => array(
				'label'       => 'Identificador de correlación',
				'description' => 'Identificador que permite atopar todo o fluxo no historial. Non é un dato persoal.',
				'example'     => '0192f3a4-5b6c-7d8e-9f01-234567890abc',
				'type'        => $text,
			),

			// ── Empresas ───────────────────────────────────────────────
			'nome_empresa'            => array(
				'label'       => 'Nome da empresa',
				'description' => 'Empresa que imparte a actividade.',
				'example'     => 'Actividades Exemplo S.L.',
				'type'        => $text,
			),
			'ligazon_descarga_segura' => array(
				'label'       => 'Ligazón de descarga',
				'description' => 'Descarga autenticada do listado. Se está baleira, omítese todo o parágrafo. Nunca se anexa un listado a un correo.',
				'example'     => 'https://example.org/empresas/descarga/',
				'type'        => $url,
			),

			// ── Ligazóns de acción e campañas ──────────────────────────
			'listado_actividades'     => array(
				'label'       => 'Listado de actividades',
				'description' => 'Actividades ofertadas, unha por liña, en TEXTO PLANO. Non leva marcado: o renderer escapa todos os valores, así que a presentación vai na plantela (o HTML envólveo nun parágrafo con white-space: pre-line, igual que a sinatura). Constrúeo ANPA_Socios_Email_Template_Context::activity_list().',
				'example'     => "Robótica — martes e xoves, 16:30 a 17:30\nXadrez — mércores, 16:30 a 17:30\nBaile — venres, 16:30 a 18:00",
				'type'        => $multiline,
			),
			'ligazon_matriculas'      => array(
				'label'       => 'Ligazón ás matrículas',
				'description' => 'Páxina onde se fai a matrícula.',
				'example'     => 'https://example.org/matriculas/',
				'type'        => $url,
			),
			'ligazon_axustes'         => array(
				'label'       => 'Ligazón ao panel',
				'description' => 'Páxina de xestión para a xunta directiva.',
				'example'     => 'https://example.org/wp-admin/admin.php?page=anpa-socios-settings',
				'type'        => $url,
			),
			'ligazon_confirmacion'    => array(
				'label'       => 'Ligazón de confirmación',
				'description' => 'Ligazón dun só uso para aceptar. Ata a fase39 non existe emisor: se está baleira, omítese o parágrafo.',
				'example'     => 'https://example.org/confirmar/exemplo/',
				'type'        => $url,
			),
			'ligazon_cancelacion'     => array(
				'label'       => 'Ligazón de renuncia',
				'description' => 'Ligazón dun só uso para renunciar. Ata a fase39 non existe emisor: se está baleira, omítese o parágrafo.',
				'example'     => 'https://example.org/renunciar/exemplo/',
				'type'        => $url,
			),
			'ligazon_enquisa'         => array(
				'label'       => 'Ligazón á enquisa',
				'description' => 'Enquisa de valoración. Se está baleira, omítese todo o parágrafo.',
				'example'     => 'https://example.org/enquisa/',
				'type'        => $url,
			),
			'nome_campana'            => array(
				'label'       => 'Nome da campaña',
				'description' => 'Nome interno do envío masivo, para identificalo no historial.',
				'example'     => 'Matrículas de outono',
				'type'        => $text,
				// Three spellings, because somebody will type each of them. The canonical
				// token is ASCII on purpose: an accent inside a token depends on the encoding
				// surviving every editor, collation and regex in the path, and this project
				// has already had one real accent-corruption incident.
				'aliases'     => array( 'nome_campaña', 'nombre_campana' ),
			),
			'ligazon_campana'         => array(
				'label'       => 'Ligazón da campaña',
				'description' => 'Páxina de detalle do envío no panel de comunicacións.',
				'example'     => 'https://example.org/wp-admin/admin.php?page=anpa-socios-communications',
				'type'        => $url,
				'aliases'     => array( 'ligazon_campaña', 'enlace_campana' ),
			),
			'resumo_envio'            => array(
				'label'       => 'Resumo do envío',
				'description' => 'Resultado do envío: aceptados, fallidos e omitidos.',
				'example'     => "Aceptados: 386\nFallidos: 2\nOmitidos: 4",
				'type'        => $multiline,
			),
		);
	}

	/**
	 * The catalogued events, in the order the editor lists them.
	 *
	 * A LIST, not a map, so a duplicated key is rejected instead of silently overwriting
	 * its twin. Order is part of the contract: it is what the board sees, and it is hashed
	 * into the registry fingerprint.
	 *
	 * `legacy_emitter` is present exactly on the events an existing method already sends,
	 * and its value is that method's golden-file stem — which is what lets a test assert a
	 * bijection between this registry and the oracle instead of asserting a total.
	 *
	 * @since  1.40.0
	 * @return array<int,array<string,mixed>>
	 */
	private static function declarations(): array {
		$membership = ANPA_Socios_Email_Template_Definition::CATEGORY_MEMBERSHIP;
		$activities = ANPA_Socios_Email_Template_Definition::CATEGORY_ACTIVITIES;
		$companies  = ANPA_Socios_Email_Template_Definition::CATEGORY_COMPANIES;
		$comms      = ANPA_Socios_Email_Template_Definition::CATEGORY_COMMUNICATIONS;
		$system     = ANPA_Socios_Email_Template_Definition::CATEGORY_SYSTEM;

		$family  = ANPA_Socios_Email_Template_Definition::AUDIENCE_FAMILY;
		$board   = ANPA_Socios_Email_Template_Definition::AUDIENCE_BOARD;
		$company = ANPA_Socios_Email_Template_Definition::AUDIENCE_COMPANY;

		$live = ANPA_Socios_Email_Template_Phase::LIVE;
		$f34  = ANPA_Socios_Email_Template_Phase::FASE34;
		$f35  = ANPA_Socios_Email_Template_Phase::FASE35;
		$f37  = ANPA_Socios_Email_Template_Phase::FASE37;
		$f39  = ANPA_Socios_Email_Template_Phase::FASE39;

		return array(
			// ── Acceso (T1, T1b) ───────────────────────────────────────
			// Two templates, not one: enviar_codigo already branches on context and sends
			// different subjects. Collapsing them would change what a family receives.
			array(
				'event_key'      => 'auth_access_code',
				'display_name'   => 'Código de acceso á área de socios',
				'description'    => 'Código dun só uso para entrar na área persoal. O código nunca se encola nin se rexistra.',
				'category'       => $system,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_codigo_verificacion',
				'variables'      => array( 'codigo' => true ),
			),
			array(
				'event_key'      => 'auth_access_code_signup',
				'display_name'   => 'Código para continuar a alta',
				'description'    => 'Código dun só uso durante o proceso de alta. O código nunca se encola nin se rexistra.',
				'category'       => $system,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_codigo_alta',
				'variables'      => array( 'codigo' => true ),
			),

			// ── Altas e baixas de socios (T2–T8, T28) ──────────────────
			array(
				'event_key'    => 'member_application_received',
				'display_name' => 'Recibimos a túa solicitude de alta',
				'description'  => 'Confirmación á familia de que a solicitude chegou. Hoxe non existe: o código só avisa á xunta.',
				'category'     => $membership,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array( 'nome_solicitante' => false ),
			),
			array(
				'event_key'      => 'member_application_admin_pending',
				'display_name'   => 'Aviso á xunta: alta pendente',
				'description'    => 'Avisa a xunta de que hai unha solicitude de alta esperando revisión.',
				'category'       => $membership,
				'audience'       => $board,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_aviso_pendente_aprobacion',
				'variables'      => array(
					'nome_solicitante'   => true,
					'correo_solicitante' => true,
					'ligazon_axustes'    => false,
				),
			),
			array(
				'event_key'      => 'member_application_approved',
				'display_name'   => 'A túa alta foi aprobada',
				'description'    => 'Comunica á familia que a xunta aprobou a alta e como acceder.',
				'category'       => $membership,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_aprobacion',
				// The complementary flag of the members'-area pair. The URL itself is a global;
				// this token only enables the alternative paragraph when there is no URL, which
				// is how the live if/else survives a renderer with no `else`.
				'variables'      => array( 'sen_ligazon_area_socios' => false ),
			),
			array(
				'event_key'      => 'member_application_completed',
				'display_name'   => 'Alta completada',
				'description'    => 'Benvida cando a alta non require aprobación. É distinta da aprobación: son dous correos diferentes.',
				'category'       => $membership,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_benvida_alta',
				'variables'      => array( 'sen_ligazon_area_socios' => false ),
			),
			array(
				'event_key'      => 'member_application_changes_required',
				'display_name'   => 'Sobre a túa solicitude de alta',
				'description'    => 'Comunica que a solicitude non se aprobou e como contactar coa xunta.',
				'category'       => $membership,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_rexeitamento',
				'variables'      => array(),
			),
			array(
				'event_key'    => 'member_cancellation_requested',
				'display_name' => 'Recibimos a túa solicitude de baixa',
				'description'  => 'Confirmación á familia de que a solicitude de baixa quedou rexistrada.',
				'category'     => $membership,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array( 'data_efectiva' => false ),
			),
			array(
				'event_key'      => 'member_cancellation_admin_notice',
				'display_name'   => 'Aviso á xunta: solicitude de baixa',
				'description'    => 'Avisa a xunta de que unha persoa socia solicitou a baixa.',
				'category'       => $membership,
				'audience'       => $board,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_aviso_baixa_socio',
				'variables'      => array(
					'nome_socio'   => true,
					'correo_socio' => true,
				),
			),
			array(
				'event_key'    => 'member_cancellation_end_of_year',
				'display_name' => 'A túa baixa será efectiva a fin de curso',
				'description'  => 'Explica cando ten efecto a baixa. Non menciona ningún cobro: o plugin non xestiona pagamentos.',
				'category'     => $membership,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'curso_escolar' => false,
					'data_efectiva' => false,
				),
			),
			array(
				'event_key'      => 'member_reactivation_admin_notice',
				'display_name'   => 'Aviso á xunta: solicitude de reactivación',
				'description'    => 'Avisa a xunta de que unha antiga persoa socia quere reactivar a conta.',
				'category'       => $membership,
				'audience'       => $board,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_aviso_reactivacion',
				'variables'      => array( 'correo_socio' => true ),
			),

			// ── Matrículas e grupos (T6, T9–T15) ───────────────────────
			array(
				'event_key'    => 'school_year_enrollment_open',
				'display_name' => 'Abrimos as matrículas do curso',
				'description'  => 'Envío masivo ás familias anunciando a apertura de matrículas do curso.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'curso_escolar'       => true,
					'listado_actividades' => true,
					'ligazon_matriculas'  => false,
				),
			),
			array(
				'event_key'    => 'enrollment_received_pending_group',
				'display_name' => 'Recibimos a solicitude de matrícula',
				'description'  => 'Confirma a solicitude e avisa de que o grupo aínda non está creado.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_proxenitor'  => false,
					'nome_alumno'      => true,
					'nome_actividade'  => true,
					'curso_alumno'     => false,
					'trimestre_actual' => false,
				),
			),
			array(
				'event_key'    => 'enrollment_waitlist_capacity',
				'display_name' => 'Lista de espera por falta de prazas',
				'description'  => 'Comunica que a solicitude queda en lista de espera porque non hai prazas.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'posicion_lista'  => false,
				),
			),
			array(
				'event_key'    => 'enrollment_waitlist_next_term',
				'display_name' => 'Solicitude pendente para o trimestre seguinte',
				'description'  => 'A solicitude queda rexistrada para o trimestre seguinte.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'        => true,
					'nome_actividade'    => true,
					'trimestre_seguinte' => false,
				),
			),
			array(
				'event_key'    => 'group_created_enrollment_confirmed',
				'display_name' => 'Praza confirmada',
				'description'  => 'O grupo creouse e a praza queda confirmada.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'nome_grupo'      => false,
					'horario_grupo'   => false,
					'data_inicio'     => false,
					'nome_empresa'    => false,
				),
			),
			array(
				'event_key'    => 'group_created_enrollment_waitlisted',
				'display_name' => 'O grupo creouse, pero quedas en espera',
				'description'  => 'O grupo creouse sen praza para este alumno/a.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'posicion_lista'  => false,
				),
			),
			array(
				'event_key'    => 'group_not_created',
				'display_name' => 'Non foi posible crear o grupo',
				'description'  => 'A actividade non acadou o mínimo. A solicitude queda pechada sen matrícula efectiva; non se afirma nada sobre cobros.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
				),
			),
			array(
				'event_key'    => 'group_created_below_minimum',
				'display_name' => 'Praza confirmada nun grupo por debaixo do mínimo',
				'description'  => 'O grupo mantense aínda sen acadar o mínimo previsto.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'nome_grupo'      => false,
					'prazas_minimas'  => false,
					'horario_grupo'   => false,
					'data_inicio'     => false,
					'nome_empresa'    => false,
				),
			),

			// ── Empresas (T16) ─────────────────────────────────────────
			array(
				'event_key'    => 'company_group_confirmed',
				'display_name' => 'Grupo confirmado (empresa)',
				'description'  => 'Comunica á empresa que o grupo queda confirmado. O listado é sempre unha descarga autenticada, nunca un anexo.',
				'category'     => $companies,
				'audience'     => $company,
				'phase'        => $f37,
				'variables'    => array(
					'nome_empresa'            => true,
					'nome_actividade'         => true,
					'curso_escolar'           => true,
					'nome_grupo'              => false,
					'horario_grupo'           => false,
					'numero_alumnos'          => false,
					'estado_grupo'            => false,
					'ligazon_descarga_segura' => false,
					'data_caducidade'         => false,
				),
			),

			// ── Baixas de actividade e listas de espera (T17–T22) ──────
			array(
				'event_key'      => 'activity_cancellation_admin_notice',
				'display_name'   => 'Aviso á xunta: baixa nunha extraescolar',
				'description'    => 'Avisa a xunta de que unha familia solicitou a baixa nunha actividade.',
				'category'       => $activities,
				'audience'       => $board,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_aviso_baixa_extraescolar',
				'variables'      => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'correo_socio'    => true,
				),
			),
			array(
				'event_key'    => 'activity_cancellation_confirmed',
				'display_name' => 'Baixa confirmada na actividade',
				'description'  => 'Confirma á familia que a baixa quedou tramitada.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_proxenitor'    => false,
					'nome_alumno'        => true,
					'nome_actividade'    => true,
					'data_efectiva'      => false,
					'trimestre_seguinte' => false,
				),
			),
			array(
				'event_key'      => 'waitlist_place_offer',
				'display_name'   => 'Hai unha praza dispoñible',
				'description'    => 'Ofrece unha praza liberada. As ligazóns dun só uso chegan coa fase39; ata entón o correo dirixe á área persoal.',
				'category'       => $activities,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_oferta_extraescolar',
				'variables'      => array(
					'nome_actividade'      => true,
					'dias_prazo'           => true,
					'nome_alumno'          => false,
					'ligazon_confirmacion' => false,
					'ligazon_cancelacion'  => false,
				),
			),
			// Added 2026-07-26 after the I8 coverage analysis: a reminder is a SECOND send whose
			// point is that time is running out. Reusing the offer template would send the same
			// message twice, which is how a reminder teaches people to ignore it.
			array(
				'event_key'    => 'waitlist_place_offer_reminder',
				'display_name' => 'A oferta de praza está a piques de caducar',
				'description'  => 'Recorda que o prazo para confirmar unha praza ofrecida está a piques de rematar.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_proxenitor'      => false,
					'nome_alumno'          => true,
					'nome_actividade'      => true,
					'data_limite'          => true,
					'consecuencia'         => false,
					'ligazon_confirmacion' => false,
					'ligazon_cancelacion'  => false,
				),
			),
			array(
				'event_key'    => 'waitlist_place_accepted',
				'display_name' => 'Confirmaches a praza',
				'description'  => 'Confirma que a familia aceptou a praza ofrecida.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_proxenitor'  => false,
					'nome_alumno'      => true,
					'nome_actividade'  => true,
					'estado_matricula' => false,
				),
			),
			array(
				'event_key'    => 'waitlist_place_declined',
				'display_name' => 'Rexistramos a renuncia á praza',
				'description'  => 'Confirma que a familia renunciou á praza ofrecida.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
				),
			),
			array(
				'event_key'    => 'waitlist_place_expired',
				'display_name' => 'Caducou a oferta de praza',
				'description'  => 'Avisa de que o prazo para aceptar a praza rematou.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'dias_prazo'      => false,
				),
			),

			// ── Calendario trimestral (T23, T24) ───────────────────────
			array(
				'event_key'    => 'term_end_admin_notice',
				'display_name' => 'Aviso á xunta: revisión de trimestre',
				'description'  => 'Recorda á xunta que hai que revisar o trimestre que remata.',
				'category'     => $activities,
				'audience'     => $board,
				'phase'        => $f34,
				'variables'    => array(
					'trimestre_actual' => true,
					'curso_escolar'    => true,
					'ligazon_axustes'  => false,
				),
			),
			array(
				'event_key'    => 'next_term_enrollment_open',
				'display_name' => 'Abrimos a xestión do trimestre seguinte',
				'description'  => 'Envío masivo ás familias anunciando a apertura do trimestre seguinte.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f34,
				'variables'    => array(
					'trimestre_seguinte' => true,
					'curso_escolar'      => false,
					'ligazon_matriculas' => false,
				),
			),

			// ── Comunicacións (T25, T26, T27) ──────────────────────────
			array(
				'event_key'    => 'extracurricular_year_thanks',
				'display_name' => 'Grazas por participar nas extraescolares',
				'description'  => 'Envío masivo de fin de curso. O parágrafo da enquisa desaparece se non hai ligazón.',
				'category'     => $comms,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'curso_escolar'   => true,
					'ligazon_enquisa' => false,
				),
			),
			array(
				'event_key'    => 'activity_change_notice',
				'display_name' => 'Cambio importante nunha actividade',
				'description'  => 'Avisa as familias dun cambio relevante: horario, aula ou empresa.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_proxenitor' => false,
					'nome_alumno'     => false,
					'nome_actividade' => true,
					'nome_grupo'      => false,
					'motivo_cambio'   => false,
					'data_efectiva'   => false,
				),
			),
			// ── Incidencias de notificación a empresas (I8) ────────────
			// Two events, not one with a conditional: the success notice needs no action, the
			// failure notice requires one, and a shared subject line would hide the failures
			// among the successes in an inbox.
			// Renamed before freezing: `company_notification_succeeded` did not say WHO receives
			// it, and read as if it went to the company — which would be a company being emailed
			// to be told it was emailed. The `_admin_` infix follows the convention the rest of
			// the board notices already use, and renaming now is far cheaper than after fase37
			// has an emitter calling the key.
			array(
				'event_key'    => 'company_notification_admin_confirmation',
				'display_name' => 'Aviso á xunta: empresa notificada',
				'description'  => 'Confirma á xunta que o aviso á empresa foi aceptado polo sistema de correo. Aceptado non significa entregado.',
				'category'     => $companies,
				'audience'     => $board,
				'phase'        => $f37,
				'variables'    => array(
					'nome_empresa'    => true,
					'nome_actividade' => true,
					'nome_grupo'      => false,
					'curso_escolar'   => false,
					'data_intento'    => false,
					'id_correlacion'  => false,
				),
			),
			array(
				'event_key'    => 'company_notification_admin_failure',
				'display_name' => 'Aviso á xunta: fallo ao notificar a empresa',
				'description'  => 'Avisa a xunta de que o aviso á empresa non saíu e require unha acción: reintentar, revisar o enderezo ou avisar por outra vía.',
				'category'     => $companies,
				'audience'     => $board,
				'phase'        => $f37,
				'variables'    => array(
					'nome_empresa'       => true,
					'nome_actividade'    => true,
					'motivo_erro'        => true,
					'accion_recomendada' => true,
					'nome_grupo'         => false,
					'data_intento'       => false,
					'estado_grupo'       => false,
					'id_correlacion'     => false,
					'ligazon_axustes'    => false,
				),
			),

			// Generic on purpose: one reminder template per flow would be a dozen near-identical
			// templates. The declared variables force the emitter to say what is pending and by
			// when, so "generic" does not become "vague".
			array(
				'event_key'    => 'pending_action_reminder',
				'display_name' => 'Tes unha acción pendente',
				'description'  => 'Recorda á familia que hai algo esperando pola súa resposta. Non anuncia un cambio: anuncia que non houbo ningún.',
				'category'     => $system,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_proxenitor' => false,
					'accion_pendente' => true,
					'nome_alumno'     => false,
					'nome_actividade' => false,
					'data_limite'     => false,
					'consecuencia'    => false,
				),
			),

			array(
				'event_key'    => 'email_campaign_summary_admin',
				'display_name' => 'Resumo dun envío masivo',
				'description'  => 'Informe interno do resultado dunha campaña. É o sistema informando sobre si mesmo, non sobre a asociación.',
				'category'     => $system,
				'audience'     => $board,
				'phase'        => $f35,
				'variables'    => array(
					'nome_campana'    => true,
					'resumo_envio'    => false,
					'ligazon_campana' => false,
				),
			),
		);
	}
}
