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

	/** Upper bound on a URL that reaches a family. A longer one is a bug, not a link. */
	const MAX_URL_LENGTH = 2000;

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
	 * DUPLICATES ARE AN ERROR, not something to silently keep or quietly drop. Two identical
	 * lines in an enrollment email almost always mean a join without `DISTINCT` or a context
	 * built twice; both are bugs worth failing on rather than emailing.
	 *
	 * @since  1.40.0
	 * @param  string                          $event_key  Event being rendered, for the error message.
	 * @param  array<int,array<string,string>> $activities Each with `nome` and optionally `horario`,
	 *                                                     in the order the reader should see.
	 * @return string One line per activity.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When empty, duplicated or implausibly long.
	 */
	public static function activity_list( string $event_key, array $activities ): string {
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
				"the activity list for '{$event_key}' is empty; do not announce an opening with nothing to show"
			);
		}

		// The message deliberately carries counts, never the activity names: an error message is
		// the one place a list of names has no reason to be, and it may end up in a log.
		$unique = array_values( array_unique( $lines ) );
		if ( count( $unique ) !== count( $lines ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				sprintf(
					"the activity list for '%s' has %d entries but only %d distinct; a duplicate usually means a query without DISTINCT",
					$event_key,
					count( $lines ),
					count( $unique )
				)
			);
		}

		// A TECHNICAL GUARD, not a limit on how many activities the association may offer. Past
		// this, the caller has passed the wrong scope — every activity of every course instead of
		// one course — and the list is never truncated silently. Raising it is a deliberate
		// decision, not a workaround.
		if ( count( $lines ) > self::MAX_ACTIVITY_LINES ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				sprintf(
					"the activity list for '%s' has %d entries, more than the %d allowed by the technical guard",
					$event_key,
					count( $lines ),
					self::MAX_ACTIVITY_LINES
				)
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
	 * `YYYY-MM-DD` is a **civil date, not an instant.** No timezone is applied here. The
	 * integration layer takes the instant, converts it to the site's timezone, picks the civil
	 * date and passes it in — which is what stops a deadline from moving a day when it is
	 * processed in UTC. Technical timestamps such as a send attempt do NOT use this helper: they
	 * follow the system's UTC policy and have their own presentation.
	 *
	 * @since  1.40.0
	 * @param  string $iso_date Canonical `YYYY-MM-DD`, or empty.
	 * @param  string $token    Token name, for the error message.
	 * @return string Presentation date, or empty.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the value is neither empty nor canonical.
	 */
	public static function format_optional_date( string $iso_date, string $token = 'data' ): string {
		$iso_date = trim( $iso_date );
		if ( '' === $iso_date ) {
			return '';
		}

		return self::parse_civil_date( $iso_date, $token );
	}

	/**
	 * Same policy, but an absent value is an error.
	 *
	 * This is the distinction that matters. An optional block removes its paragraph when the
	 * value is empty, which is correct for a date that may not exist — and silently wrong for one
	 * that must. Without this method, an emitter that forgot a mandatory deadline would produce an
	 * email that looks complete and simply never mentions the date, which is the worst kind of
	 * bug: it validates, it sends, and nobody notices until a family misses a deadline they were
	 * never told about.
	 *
	 * @since  1.40.0
	 * @param  string $iso_date Canonical `YYYY-MM-DD`.
	 * @param  string $token    Token name, for the error message.
	 * @return string Presentation date.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When absent or not canonical.
	 */
	public static function format_required_date( string $iso_date, string $token = 'data' ): string {
		$iso_date = trim( $iso_date );
		if ( '' === $iso_date ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"'{$token}' is required for this event; an optional block would hide its absence instead of reporting it"
			);
		}

		return self::parse_civil_date( $iso_date, $token );
	}

	/**
	 * @param  string $iso_date Candidate.
	 * @param  string $token    Token name, for the error message.
	 * @return string
	 * @throws ANPA_Socios_Email_Template_Registry_Error On a non-canonical or impossible date.
	 */
	private static function parse_civil_date( string $iso_date, string $token ): string {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso_date, $m ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"'{$token}' is not a canonical YYYY-MM-DD civil date; the emitter must not choose a format"
			);
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "'{$token}' is not a date that exists" );
		}

		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}

	/**
	 * Validates a URL that is about to reach a family.
	 *
	 * Centralised for the same reason as the dates: otherwise each emitter decides whether to
	 * trim, whether `http` is acceptable and whether a newline matters. A newline in a URL that
	 * ends up in a header is a header-injection vector, and `http` in an email a family clicks is
	 * a downgrade nobody chose.
	 *
	 * `http` is accepted **only** for `localhost` and the reserved example domains, so a
	 * development or preview context works without opening a hole in production.
	 *
	 * Fase36 never GENERATES an action URL. The single-use tokens belong to fase39; here the URL
	 * is received and checked, never built, and no parameter is concatenated inside a template.
	 *
	 * @since  1.40.0
	 * @param  string $url      Candidate URL, may be empty when the variable is optional.
	 * @param  string $token    Token name, for the error message.
	 * @param  bool   $required Whether an empty value is an error.
	 * @return string The URL, or empty when optional and absent.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unusable URL.
	 */
	public static function format_url( string $url, string $token = 'ligazon', bool $required = false ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			if ( $required ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"'{$token}' is required for this event; the reader would be told to act with nowhere to do it"
				);
			}

			return '';
		}

		if ( 1 === preg_match( '/[\r\n\t]/', $url ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "'{$token}' contains a line break" );
		}
		if ( mb_strlen( $url ) > self::MAX_URL_LENGTH ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				sprintf( "'%s' is %d characters, more than the %d allowed", $token, mb_strlen( $url ), self::MAX_URL_LENGTH )
			);
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' === $scheme ) {
			return $url;
		}
		if ( 'http' === $scheme && self::is_development_host( $host ) ) {
			return $url;
		}

		throw new ANPA_Socios_Email_Template_Registry_Error(
			"'{$token}' must use https (http is allowed only for localhost and the reserved example domains)"
		);
	}

	/**
	 * @param  string $host Lowercased host.
	 * @return bool Whether plain http is tolerated for it.
	 */
	private static function is_development_host( string $host ): bool {
		if ( 'localhost' === $host || '127.0.0.1' === $host ) {
			return true;
		}

		// RFC 2606 reserved names: safe in previews and documentation, never a real recipient.
		foreach ( array( 'example.org', 'example.com', 'example.net' ) as $reserved ) {
			if ( $host === $reserved || self::ends_with( $host, '.' . $reserved ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param  string $haystack Subject.
	 * @param  string $needle   Suffix.
	 * @return bool
	 */
	private static function ends_with( string $haystack, string $needle ): bool {
		$length = strlen( $needle );

		return 0 !== $length && substr( $haystack, -$length ) === $needle;
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
		return ANPA_Socios_Email_Template_Actions::description( $action_type );
	}

	/**
	 * @since  1.40.0
	 * @return string[] Valid pending-action types, a fresh copy on every call.
	 */
	public static function pending_action_types(): array {
		return ANPA_Socios_Email_Template_Actions::supported_types();
	}

	/**
	 * Builds the whole reminder context for one pending action.
	 *
	 * This is where the action catalogue's `requires_deadline` and `requires_link` become
	 * enforcement rather than documentation: an action that only makes sense with a date cannot
	 * render without one, and an action the family must perform somewhere cannot render without
	 * saying where.
	 *
	 * @since  1.40.0
	 * @param  string $action_type Controlled action type.
	 * @param  string $iso_deadline Canonical civil date, or empty.
	 * @param  string $url          Where to act, or empty.
	 * @return array<string,string> Tokens for `pending_action_reminder`.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown type or a missing requirement.
	 */
	public static function pending_action( string $action_type, string $iso_deadline = '', string $url = '' ): array {
		$declaration = ANPA_Socios_Email_Template_Actions::declaration( $action_type );

		$deadline = ANPA_Socios_Email_Template_Actions::requires_deadline( $action_type )
			? self::format_required_date( $iso_deadline, 'data_limite' )
			: self::format_optional_date( $iso_deadline, 'data_limite' );

		$link = self::format_url(
			$url,
			'ligazon_confirmacion',
			ANPA_Socios_Email_Template_Actions::requires_link( $action_type )
		);

		return array(
			'accion_pendente'      => (string) $declaration['description'],
			'consecuencia'         => (string) $declaration['consequence'],
			'data_limite'          => $deadline,
			'ligazon_confirmacion' => $link,
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
