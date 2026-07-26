<?php
/**
 * Builders for template contexts that must satisfy an invariant (fase36, PR-36s1c-2).
 *
 * The frozen renderer has optional blocks but no `else`. Two live emails —
 * `member_application_approved` and `member_application_completed` — branch on whether a
 * members'-area URL was supplied, **with different wording in each branch**, so the
 * behaviour is reproduced with two complementary optional blocks:
 *
 *     {{#ligazon_area_socios}}…with the link…{{/}}
 *     {{#sen_ligazon_area_socios}}…without the link…{{/}}
 *
 * That only works if exactly one of the pair is ever set. Leaving that to each emitter
 * would mean the same invariant written twice and, eventually, once — so it is built here,
 * in one place, and asserted.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Context {

	/** Token carrying the members'-area URL when there is one. */
	const TOKEN_AREA_LINK = 'ligazon_area_socios';

	/** Complementary flag, truthy only when there is NO members'-area URL. */
	const TOKEN_NO_AREA_LINK = 'sen_ligazon_area_socios';

	/** Internal value of the complementary flag. Never shown to anybody. */
	const FLAG = '1';

	/**
	 * Upper bound on the activity list.
	 *
	 * Not a style rule: past this, the caller has passed the wrong scope — every activity of
	 * every course instead of one course — and the email would be unreadable either way.
	 */
	const MAX_ACTIVITY_LINES = 60;

	/**
	 * Builds the mutually exclusive members'-area pair.
	 *
	 * @since  1.40.0
	 * @param  string $url Members'-area URL, empty when there is none.
	 * @return array<string,string> Exactly one of the two tokens is non-empty.
	 */
	public static function area_link( string $url ): array {
		$url = trim( $url );

		if ( '' !== $url ) {
			return array(
				self::TOKEN_AREA_LINK    => $url,
				self::TOKEN_NO_AREA_LINK => '',
			);
		}

		return array(
			self::TOKEN_AREA_LINK    => '',
			self::TOKEN_NO_AREA_LINK => self::FLAG,
		);
	}

	/**
	 * Verifies the invariant on a context about to be rendered.
	 *
	 * Both set would print two contradictory paragraphs; both empty would print neither and
	 * leave the family with no instructions at all. The second is the worse failure and the
	 * easier one to introduce, because "no URL configured" looks like a harmless empty
	 * value.
	 *
	 * @since  1.40.0
	 * @param  array<string,mixed> $context Context to check.
	 * @return void
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the pair is not exclusive.
	 */
	public static function assert_area_link_exclusive( array $context ): void {
		$with    = '' !== trim( (string) ( $context[ self::TOKEN_AREA_LINK ] ?? '' ) );
		$without = '' !== trim( (string) ( $context[ self::TOKEN_NO_AREA_LINK ] ?? '' ) );

		if ( $with && $without ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				'both members-area branches are active; the email would contradict itself'
			);
		}
		if ( ! $with && ! $without ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				'neither members-area branch is active; the email would give no access instructions'
			);
		}
	}

	/**
	 * Builds the activity list as PLAIN TEXT, one activity per line.
	 *
	 * No markup, deliberately. The renderer escapes every value in the HTML channel, so a token
	 * carrying `<ul>` would reach the family as literal tags. That is a feature: it means no
	 * emitter can inject markup into an email, and presentation stays in the template — the HTML
	 * default wraps this in a paragraph with `white-space: pre-line`, exactly as the signature
	 * already does.
	 *
	 * Built here so no emitter ever concatenates a list by hand.
	 *
	 * @since  1.40.0
	 * ORDER IS THE CALLER'S. The list is preserved exactly as received, because the order a
	 * family reads is a product decision, not a side effect of a query. The emitter must supply
	 * a deterministic order — an SQL query with no `ORDER BY` is not one.
	 *
	 * An EMPTY list is refused rather than rendered. An enrollment-opening email whose activity
	 * block is blank is worse than no email: it announces an opening and then shows nothing.
	 *
	 * @since  1.40.0
	 * @param  array<int,array<string,string>> $activities Each with `nome` and optionally `horario`,
	 *                                                    in the order the reader should see.
	 * @return string One line per activity.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the list is empty or implausibly long.
	 */
	public static function activity_list( array $activities ): string {
		$lines = array();

		foreach ( $activities as $activity ) {
			$name = self::single_line( (string) ( $activity['nome'] ?? '' ) );
			if ( '' === $name ) {
				continue; // An unnamed activity is a data bug, not a line reading "—".
			}

			$schedule = self::single_line( (string) ( $activity['horario'] ?? '' ) );
			$lines[]  = '' === $schedule ? $name : $name . ' — ' . $schedule;
		}

		if ( array() === $lines ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				'the activity list is empty; do not announce an opening with nothing to show'
			);
		}
		if ( count( $lines ) > self::MAX_ACTIVITY_LINES ) {
			// Not a style rule: a list this long means the caller passed the wrong scope, and the
			// email would be unreadable either way.
			throw new ANPA_Socios_Email_Template_Registry_Error(
				sprintf( 'the activity list has %d entries, more than the %d allowed', count( $lines ), self::MAX_ACTIVITY_LINES )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Collapses any value that must occupy exactly one line of a list.
	 *
	 * CRLF included: a schedule pasted from a spreadsheet arrives with `\r\n`, and a stray
	 * newline inside an entry would silently turn one activity into two.
	 *
	 * @since  1.40.0
	 * @param  string $value Raw value.
	 * @return string
	 */
	private static function single_line( string $value ): string {
		$value = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $value );

		return trim( (string) preg_replace( '/ {2,}/', ' ', $value ) );
	}

	/**
	 * Canonical date in, human date out.
	 *
	 * One policy, in one place. Without it each emitter decides between `1/9/2026`,
	 * `01-09-2026` and `1 de setembro`, and the same family gets three formats from the same
	 * association.
	 *
	 *   - input: canonical `YYYY-MM-DD`, already resolved to the site's timezone by the
	 *     integration layer. This class stays free of WordPress, so it does not resolve
	 *     timezones itself — it refuses anything that is not already canonical.
	 *   - output: `dd/mm/YYYY`, the form used in Galician administrative writing.
	 *   - absent: an empty string, so the template's optional block removes the paragraph
	 *     instead of printing a date-shaped gap.
	 *
	 * @since  1.40.0
	 * @param  string $iso_date Canonical `YYYY-MM-DD`, or empty.
	 * @return string Presentation date, or empty.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the value is neither empty nor canonical.
	 */
	public static function format_date( string $iso_date ): string {
		$iso_date = trim( $iso_date );
		if ( '' === $iso_date ) {
			return '';
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso_date, $m ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"date '{$iso_date}' is not canonical YYYY-MM-DD; the emitter must not choose a format"
			);
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "date '{$iso_date}' does not exist" );
		}

		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}

	/**
	 * Human description of a pending action.
	 *
	 * `pending_action_reminder` is generic by design, and this is what keeps generic from
	 * becoming arbitrary: the emitter supplies one of a controlled set of action types, not a
	 * free sentence. A reminder whose body is emitter-supplied prose is a reminder nobody can
	 * review, translate or keep consistent.
	 *
	 * The set covers the actions already on the roadmap. A new flow adds an entry here
	 * deliberately; it cannot pass a sentence through.
	 *
	 * @since  1.40.0
	 * @param  string $action_type Controlled action type.
	 * @return string Galician description.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown type.
	 */
	public static function pending_action_label( string $action_type ): string {
		$labels = array(
			'confirmar_praza'          => 'Confirmar unha praza ofrecida nunha actividade extraescolar.',
			'responder_oferta'         => 'Responder a unha oferta de praza: aceptala ou renunciar a ela.',
			'corrixir_solicitude_alta' => 'Corrixir ou completar os datos da solicitude de alta como socio/a.',
			'confirmar_datos_familia'  => 'Revisar e confirmar os datos da unidade familiar.',
			'completar_matricula'      => 'Completar os datos que faltan nunha solicitude de matrícula.',
			'revisar_baixa'            => 'Revisar unha solicitude de baixa pendente de confirmación.',
		);

		return self::label( $labels, $action_type, 'pending action' );
	}

	/**
	 * @since  1.40.0
	 * @return string[] Valid pending-action types.
	 */
	public static function pending_action_types(): array {
		return array(
			'confirmar_praza',
			'responder_oferta',
			'corrixir_solicitude_alta',
			'confirmar_datos_familia',
			'completar_matricula',
			'revisar_baixa',
		);
	}

	/**
	 * Human label for a group state.
	 *
	 * A domain state, not free text. The emitter supplies a validated state identifier and gets a
	 * label back; passing arbitrary text through to a family — text that in a web flow could come
	 * from a request — is refused. The identifiers are the group states the SDD already defines
	 * for fase39.
	 *
	 * @since  1.40.0
	 * @param  string $state Validated state identifier.
	 * @return string Galician label.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown state.
	 */
	public static function group_state_label( string $state ): string {
		$labels = array(
			'borrador'                 => 'Borrador',
			'matricula_aberta'         => 'Matrícula aberta',
			'en_revision'              => 'En revisión',
			'confirmado'               => 'Confirmado',
			'confirmado_baixo_minimo'  => 'Confirmado excepcionalmente baixo mínimo',
			'non_creado'               => 'Non creado',
			'pechado'                  => 'Pechado',
			'reaberto'                 => 'Reaberto',
			'cancelado'                => 'Cancelado',
		);

		return self::label( $labels, $state, 'group' );
	}

	/**
	 * Human label for an enrollment state. Same rule as the group state.
	 *
	 * @since  1.40.0
	 * @param  string $state Validated state identifier.
	 * @return string Galician label.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown state.
	 */
	public static function enrollment_state_label( string $state ): string {
		$labels = array(
			'solicitude_recibida'         => 'Solicitude recibida',
			'pendente_grupo'              => 'Pendente de formación do grupo',
			'espera_capacidade'           => 'Lista de espera por falta de prazas',
			'espera_seguinte_trimestre'   => 'Lista de espera para o seguinte trimestre',
			'pendente_confirmacion'       => 'Pendente de confirmación familiar',
			'confirmada'                  => 'Confirmada',
			'activa'                      => 'Activa',
			'baixa_solicitada'            => 'Baixa solicitada',
			'baixa_aprobada'              => 'Baixa aprobada, pendente de ser efectiva',
			'baixa_efectiva'              => 'Baixa efectiva',
			'cancelada_familia'           => 'Cancelada pola familia',
			'rexeitada'                   => 'Rexeitada',
			'grupo_non_creado'            => 'Grupo non creado',
			'finalizada'                  => 'Finalizada',
		);

		return self::label( $labels, $state, 'enrollment' );
	}

	/**
	 * @since  1.40.0
	 * @return string[] Valid group state identifiers.
	 */
	public static function group_states(): array {
		return array(
			'borrador',
			'matricula_aberta',
			'en_revision',
			'confirmado',
			'confirmado_baixo_minimo',
			'non_creado',
			'pechado',
			'reaberto',
			'cancelado',
		);
	}

	/**
	 * @since  1.40.0
	 * @return string[] Valid enrollment state identifiers.
	 */
	public static function enrollment_states(): array {
		return array(
			'solicitude_recibida',
			'pendente_grupo',
			'espera_capacidade',
			'espera_seguinte_trimestre',
			'pendente_confirmacion',
			'confirmada',
			'activa',
			'baixa_solicitada',
			'baixa_aprobada',
			'baixa_efectiva',
			'cancelada_familia',
			'rexeitada',
			'grupo_non_creado',
			'finalizada',
		);
	}

	/**
	 * @param  array<string,string> $labels Identifier => label.
	 * @param  string               $state  Requested identifier.
	 * @param  string               $kind   For the error message.
	 * @return string
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown state.
	 */
	private static function label( array $labels, string $state, string $kind ): string {
		if ( ! isset( $labels[ $state ] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"unknown {$kind} state '{$state}'; a state label may never be free text"
			);
		}

		return $labels[ $state ];
	}

	/**
	 * Whether an event's templates depend on the exclusive pair.
	 *
	 * Declared here rather than inferred from the template text, so an emitter can be
	 * checked before it renders anything.
	 *
	 * @since  1.40.0
	 * @return string[] Event keys.
	 */
	public static function events_requiring_area_link(): array {
		return array( 'member_application_approved', 'member_application_completed' );
	}
}
