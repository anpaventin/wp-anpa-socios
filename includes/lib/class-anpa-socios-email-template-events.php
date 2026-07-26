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
				'variables'      => array(),
			),
			array(
				'event_key'      => 'member_application_completed',
				'display_name'   => 'Alta completada',
				'description'    => 'Benvida cando a alta non require aprobación. É distinta da aprobación: son dous correos diferentes.',
				'category'       => $membership,
				'audience'       => $family,
				'phase'          => $live,
				'legacy_emitter' => 'enviar_benvida_alta',
				'variables'      => array(),
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
					'curso_escolar'      => true,
					'ligazon_matriculas' => false,
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
					'nome_alumno'     => true,
					'nome_actividade' => true,
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
					'nome_alumno'     => true,
					'nome_actividade' => true,
					'data_efectiva'   => false,
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
			array(
				'event_key'    => 'waitlist_place_accepted',
				'display_name' => 'Confirmaches a praza',
				'description'  => 'Confirma que a familia aceptou a praza ofrecida.',
				'category'     => $activities,
				'audience'     => $family,
				'phase'        => $f39,
				'variables'    => array(
					'nome_alumno'     => true,
					'nome_actividade' => true,
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
					'nome_actividade' => true,
					'nome_grupo'      => false,
					'motivo_cambio'   => false,
					'data_efectiva'   => false,
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
