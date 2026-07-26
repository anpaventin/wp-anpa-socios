<?php
/**
 * Preview scenarios for the email templates (fase36, PR-36s1c-4).
 *
 * INFRASTRUCTURE, NOT UI. The preview screen belongs to a later slice; the scenarios do not. If
 * they arrived with the screen, "what does this email look like when there is no deadline?" would
 * be answerable only by a human clicking, and the interesting cases — the ones where an optional
 * block disappears — are exactly the ones nobody clicks.
 *
 * Each scenario declares:
 *
 *   - a stable id, so a test, a screen and a review document can all refer to the same case;
 *   - a visible label for the board;
 *   - which events it applies to, DERIVED from the declared tokens rather than listed, so a new
 *     event falls under every scenario it qualifies for the moment it is declared;
 *   - a context built through the central helpers, never by hand.
 *
 * ONE COHERENT FIXTURE. Every scenario tells the same story: one family, one child, one activity,
 * one school year. Incoherent sample data makes a review meaningless — a reviewer comparing two
 * emails about two different children cannot see that one of them contradicts the other.
 *
 * ALL DATA IS FICTITIOUS and every domain is RFC 2606 reserved. Not a convention: a preview that
 * can be filled with real family data is a data-protection incident waiting for a screenshot.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Scenarios {

	/** Everything renders and no value is real. */
	const ID_DEFAULT = 'default';

	/** Required tokens only: what a minimally configured installation sends. */
	const ID_MINIMAL = 'minimal';

	/** The two branches of the members'-area pair. */
	const ID_WITH_AREA_LINK    = 'with-area-link';
	const ID_WITHOUT_AREA_LINK = 'without-area-link';

	/** The two branches of an optional deadline. */
	const ID_WITH_DEADLINE    = 'with-deadline';
	const ID_WITHOUT_DEADLINE = 'without-deadline';

	/** Addressed to the second contact of the family rather than the member. */
	const ID_SECONDARY_CONTACT = 'secondary-contact';

	/**
	 * THE fixture. One family, one child, one activity, one year.
	 *
	 * Kept as constants rather than inline literals so the review document, the tests and any
	 * future screen cannot drift into telling three different stories.
	 */
	const ANPA           = 'ANPA Exemplo';
	const MEMBER         = 'Antía Exemplo López';
	const MEMBER_EMAIL   = 'antia.exemplo@example.com';
	const PARENT         = 'Xoán Exemplo';
	const STUDENT        = 'Iria Exemplo';
	const STUDENT_COURSE = '3.º de Primaria B';
	const ACTIVITY       = 'Robótica';
	const GROUP          = 'Robótica Martes';
	const COMPANY        = 'Empresa Exemplo';
	const SCHOOL_YEAR    = '2026/2027';

	/** Reserved host every preview URL is built on. */
	const HOST = 'https://example.org';

	/**
	 * Scenario id => visible label.
	 *
	 * @since  1.40.0
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::ID_DEFAULT           => 'Todos os datos dispoñibles',
			self::ID_MINIMAL           => 'Só os datos obrigatorios',
			self::ID_WITH_AREA_LINK    => 'Con ligazón á área de socios',
			self::ID_WITHOUT_AREA_LINK => 'Sen ligazón á área de socios',
			self::ID_WITH_DEADLINE     => 'Con data límite',
			self::ID_WITHOUT_DEADLINE  => 'Sen data límite',
			self::ID_SECONDARY_CONTACT => 'Dirixido ao segundo contacto da familia',
		);
	}

	/**
	 * @since  1.40.0
	 * @return string[] Scenario ids, in the order a reviewer should read them.
	 */
	public static function ids(): array {
		return array_keys( self::labels() );
	}

	/**
	 * @since  1.40.0
	 * @param  string $id Scenario id.
	 * @return string Visible label.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown scenario.
	 */
	public static function label( string $id ): string {
		$labels = self::labels();

		if ( ! isset( $labels[ $id ] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "unknown preview scenario '{$id}'" );
		}

		return $labels[ $id ];
	}

	/**
	 * Whether a scenario says anything about a given event.
	 *
	 * DERIVED from the declaration, never listed. A scenario list would need updating with every
	 * new event, and the update nobody remembers is the one that matters — the event whose optional
	 * block has never been previewed empty.
	 *
	 * @since  1.40.0
	 * @param  string                                $id         Scenario id.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return bool
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown scenario.
	 */
	public static function applies( string $id, ANPA_Socios_Email_Template_Definition $definition ): bool {
		self::label( $id ); // Rejects an unknown id.

		$declared = $definition->variables();
		$required = $definition->required_tokens();

		switch ( $id ) {
			case self::ID_DEFAULT:
			case self::ID_MINIMAL:
				return true;

			case self::ID_WITH_AREA_LINK:
			case self::ID_WITHOUT_AREA_LINK:
				// Only the events whose wording BRANCHES on the pair. Every other event merely uses
				// the global URL, and previewing that twice would say nothing.
				return isset( $declared[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );

			case self::ID_WITH_DEADLINE:
				return isset( $declared['data_limite'] );

			case self::ID_WITHOUT_DEADLINE:
				// An event whose deadline is REQUIRED has no such case, by design: there is no
				// legitimate state in which it renders without one, so a preview of that state would
				// document a bug as a feature.
				return isset( $declared['data_limite'] ) && ! in_array( 'data_limite', $required, true );

			case self::ID_SECONDARY_CONTACT:
				return isset( $declared['nome_proxenitor'] );
		}

		return false;
	}

	/**
	 * Scenarios applicable to one event.
	 *
	 * @since  1.40.0
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return string[] Scenario ids.
	 */
	public static function for_event( ANPA_Socios_Email_Template_Definition $definition ): array {
		$ids = array();

		foreach ( self::ids() as $id ) {
			if ( self::applies( $id, $definition ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Builds the render context for one event under one scenario.
	 *
	 * Only tokens the event DECLARES are returned, so the result passes the pre-render gate: an
	 * undeclared key is refused there, and a preview that cannot pass the gate is a preview of
	 * something the plugin would never send.
	 *
	 * @since  1.40.0
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @param  string                                $id         Scenario id.
	 * @return array<string,string> Context ready to render.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown scenario or inapplicable case.
	 */
	public static function build( ANPA_Socios_Email_Template_Definition $definition, string $id ): array {
		if ( ! self::applies( $id, $definition ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"scenario '{$id}' does not apply to '{$definition->event_key()}'"
			);
		}

		$values  = self::values( $definition->event_key() );
		$context = array();

		foreach ( array_keys( $definition->variables() ) as $token ) {
			$token = (string) $token;

			if ( self::omitted( $token, $id, $definition ) ) {
				continue;
			}

			if ( array_key_exists( $token, $values ) ) {
				$context[ $token ] = $values[ $token ];
			}
		}

		$context = self::apply_area_pair( $context, $id, $definition );

		if ( self::ID_SECONDARY_CONTACT === $id ) {
			$context = self::as_secondary_contact( $context );
		}

		return $context;
	}

	/**
	 * Whether a scenario deliberately leaves a token out.
	 *
	 * @param  string                                $token      Canonical token.
	 * @param  string                                $id         Scenario id.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return bool
	 */
	private static function omitted(
		string $token,
		string $id,
		ANPA_Socios_Email_Template_Definition $definition
	): bool {
		$required = $definition->required_tokens();

		// A required token is never omitted by a scenario. Rendering without one is a bug the
		// validator reports, not a case to preview.
		if ( in_array( $token, $required, true ) ) {
			return false;
		}

		if ( self::ID_MINIMAL === $id ) {
			return ! in_array( $token, ANPA_Socios_Email_Template_Events::globals(), true );
		}

		if ( self::ID_WITHOUT_DEADLINE === $id ) {
			return 'data_limite' === $token;
		}

		return false;
	}

	/**
	 * Resolves the exclusive members'-area pair through the central builder.
	 *
	 * @param  array<string,string>                  $context    Context so far.
	 * @param  string                                $id         Scenario id.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return array<string,string>
	 */
	private static function apply_area_pair(
		array $context,
		string $id,
		ANPA_Socios_Email_Template_Definition $definition
	): array {
		$declared = $definition->variables();

		if ( ! isset( $declared[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] ) ) {
			return $context;
		}

		$url = self::ID_WITHOUT_AREA_LINK === $id ? '' : self::HOST . '/area-socios/';
		$pair = ANPA_Socios_Email_Template_Context::area_link( $url );

		foreach ( $pair as $token => $value ) {
			if ( isset( $declared[ $token ] ) || in_array( $token, ANPA_Socios_Email_Template_Events::globals(), true ) ) {
				$context[ $token ] = $value;
			}
		}

		return $context;
	}

	/**
	 * Fixture values for every canonical token, built through the central helpers.
	 *
	 * Dates go through `format_required_date()`, URLs through `format_url()` under the PREVIEW
	 * policy, the activity list through `activity_list()` and the state labels through their
	 * validated lookups. So a preview cannot show a format the emitters could not produce — which
	 * is the only way a preview is evidence of anything.
	 *
	 * @param  string $event_key Event being previewed, for the helpers that need it.
	 * @return array<string,string>
	 * @throws ANPA_Socios_Email_Template_Registry_Error When a helper refuses a fixture value.
	 */
	private static function values( string $event_key ): array {
		$preview = ANPA_Socios_Email_Template_Context::POLICY_PREVIEW;
		$url     = static function ( string $path ) use ( $preview ): string {
			return ANPA_Socios_Email_Template_Context::format_url( self::HOST . $path, 'ligazon', true, $preview );
		};
		$date    = static function ( string $iso ): string {
			return ANPA_Socios_Email_Template_Context::format_required_date( $iso );
		};

		return array(
			// Configuration.
			'nome_anpa'               => self::ANPA,
			'correo_anpa'             => 'contacto@example.org',
			'ligazon_web'             => $url( '/' ),
			'ligazon_area_socios'     => $url( '/area-socios/' ),
			'sinatura'                => "A Xunta Directiva\n" . self::ANPA,

			// People. Never a real name, never a real address.
			'codigo'                  => '482913',
			'nome_socio'              => self::MEMBER,
			'correo_socio'            => self::MEMBER_EMAIL,
			'nome_solicitante'        => self::MEMBER,
			'correo_solicitante'      => self::MEMBER_EMAIL,
			'nome_proxenitor'         => self::MEMBER,
			'nome_alumno'             => self::STUDENT,
			'curso_alumno'            => self::STUDENT_COURSE,

			// Activity and group.
			'nome_actividade'         => self::ACTIVITY,
			'nome_grupo'              => self::GROUP,
			'horario_grupo'           => 'Martes e xoves, 16:30 a 17:30',
			'numero_alumnos'          => '14',
			'estado_grupo'            => ANPA_Socios_Email_Template_Context::group_state_label( 'confirmado' ),
			'estado_matricula'        => ANPA_Socios_Email_Template_Context::enrollment_state_label( 'activa' ),
			'prazas_minimas'          => '8',
			'posicion_lista'          => '3',
			'dias_prazo'              => '3',
			'motivo_cambio'           => "Cambio de aula: a actividade pasa ao ximnasio.\nO horario non cambia.",

			// Calendar. One coherent school year.
			'curso_escolar'           => self::SCHOOL_YEAR,
			'trimestre_actual'        => 'Segundo trimestre',
			'trimestre_seguinte'      => 'Terceiro trimestre',
			'data_efectiva'           => $date( '2027-04-12' ),
			'data_caducidade'         => $date( '2026-10-31' ),
			'data_inicio'             => $date( '2026-10-05' ),
			'data_limite'             => $date( '2026-10-20' ),

			// Reminders and incidents.
			'accion_pendente'         => ANPA_Socios_Email_Template_Context::pending_action_label( 'confirmar_praza' ),
			'consecuencia'            => ANPA_Socios_Email_Template_Actions::consequence( 'confirmar_praza' ),
			'motivo_erro'             => 'O servidor de correo rexeitou o enderezo da empresa.',
			'accion_recomendada'      => 'Revisa o enderezo da empresa nos seus datos e reintenta o envío.',
			'data_intento'            => $date( '2026-10-02' ),
			'id_correlacion'          => '0192f3a4-5b6c-7d8e-9f01-234567890abc',

			// Companies.
			'nome_empresa'            => self::COMPANY,
			'ligazon_descarga_segura' => $url( '/empresas/descarga/exemplo/' ),

			// Lists, action links and campaigns.
			'listado_actividades'     => ANPA_Socios_Email_Template_Context::activity_list(
				$event_key,
				array(
					array( 'nome' => self::ACTIVITY, 'horario' => 'martes e xoves, 16:30 a 17:30' ),
					array( 'nome' => 'Xadrez', 'horario' => 'mércores, 16:30 a 17:30' ),
					array( 'nome' => 'Baile', 'horario' => 'venres, 16:30 a 18:00' ),
				)
			),
			'ligazon_matriculas'      => $url( '/matriculas/' ),
			'ligazon_axustes'         => $url( '/xestion/' ),
			'ligazon_confirmacion'    => $url( '/confirmar/exemplo/' ),
			'ligazon_cancelacion'     => $url( '/renunciar/exemplo/' ),
			'ligazon_enquisa'         => $url( '/enquisa/' ),
			'nome_campana'            => 'Matrículas de outono',
			'ligazon_campana'         => $url( '/comunicacions/exemplo/' ),
			'resumo_envio'            => "Destinatarios: 392\nAceptados polo sistema de correo: 386\nFallidos: 2\nOmitidos ou duplicados: 4",
		);
	}

	/**
	 * The one value that changes per scenario rather than per token.
	 *
	 * A family with two contacts gets one email each, with each contact's own name. The bug this
	 * scenario exists to catch is the email that greets the member while being addressed to the
	 * other parent.
	 *
	 * @since  1.40.0
	 * @param  array<string,string> $context Context built for the default scenario.
	 * @return array<string,string>
	 */
	public static function as_secondary_contact( array $context ): array {
		if ( isset( $context['nome_proxenitor'] ) ) {
			$context['nome_proxenitor'] = self::PARENT;
		}

		return $context;
	}
}
