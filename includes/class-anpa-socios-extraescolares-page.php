<?php
/**
 * Public Extraescolares shortcodes.
 *
 * [anpa_extraescolares_horario] renders the timetable from active group slots.
 * [anpa_extraescolares_ofertadas] renders the offered activity cards dynamically.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders public extraescolares blocks.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Extraescolares_Page {

	/**
	 * Shortcode [anpa_extraescolares_horario].
	 *
	 * @since  1.9.0
	 * @param  array $atts Shortcode attributes (none used).
	 * @return string Escaped HTML.
	 */
	public static function render( $atts ): string {
		$rows = self::active_group_slots();
		$grid = ANPA_Socios_Horario_Builder::build( $rows );

		if ( array() === $grid ) {
			return '<div class="anpa-extra-horario anpa-extra-empty"><p>'
				. esc_html__( 'Aínda non hai actividades extraescolares dispoñibles. Volve máis adiante.', 'anpa-socios' )
				. '</p></div>';
		}

		$html  = '<div class="anpa-extra-horario">';
		$html .= '<table class="anpa-extra-grid"><thead><tr>';
		$html .= '<th scope="col">' . esc_html__( 'Franxa horaria', 'anpa-socios' ) . '</th>';
		foreach ( ANPA_Socios_Horario_Builder::DIA_LABELS as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		$maña_shown   = false;
		$com_shown    = false;
		$tarde_shown  = false;
		$colspan      = 1 + count( ANPA_Socios_Actividade_Options::DIAS );
		foreach ( $grid as $row ) {
			$periodo = $row['periodo'] ?? '';
			if ( 'maña' === $periodo && ! $maña_shown ) {
				$maña_shown = true;
				$html      .= '<tr class="anpa-extra-periodo"><td class="anpa-extra-periodo-cell" colspan="' . (int) $colspan . '">'
					. esc_html__( 'Mañá', 'anpa-socios' ) . '</td></tr>';
			}
			if ( 'manha' === $periodo && ! $com_shown ) {
				$com_shown = true;
				$html     .= '<tr class="anpa-extra-periodo"><td class="anpa-extra-periodo-cell" colspan="' . (int) $colspan . '">'
					. esc_html__( 'Comedor', 'anpa-socios' ) . '</td></tr>';
			}
			if ( 'tarde' === $periodo && ! $tarde_shown ) {
				$tarde_shown = true;
				$html       .= '<tr class="anpa-extra-periodo"><td class="anpa-extra-periodo-cell" colspan="' . (int) $colspan . '">'
					. esc_html__( 'Tarde', 'anpa-socios' ) . '</td></tr>';
			}
			$html .= '<tr class="' . ( 'tarde' === $periodo ? 'anpa-extra-fila-tarde' : '' ) . '">';
			$html .= '<th scope="row">' . esc_html( $row['label'] ) . '</th>';
			foreach ( ANPA_Socios_Actividade_Options::DIAS as $dia ) {
				$html .= '<td>';
				if ( ! empty( $row['dias'][ $dia ] ) ) {
					$html .= '<ul class="anpa-extra-lista">';
					foreach ( $row['dias'][ $dia ] as $entry ) {
						$grupos = '';
						if ( ! empty( $entry['grupos'] ) ) {
							$grupos = ' <span class="anpa-extra-grupos">(' . esc_html( implode( ', ', $entry['grupos'] ) ) . ')</span>';
						}
						$html .= '<li><span class="anpa-extra-act">' . esc_html( $entry['nome'] ) . '</span>' . $grupos . '</li>';
					}
					$html .= '</ul>';
				}
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Shortcode [anpa_extraescolares_ofertadas].
	 *
	 * Shows activity cards with enrolment stats per group and a link to the empresa website.
	 *
	 * @since  1.11.0
	 * @param  array $atts Shortcode attributes (none used).
	 * @return string Escaped HTML.
	 */
	public static function render_ofertadas( $atts ): string {
		$curso = ANPA_Socios_Curso_Activo::get();
		$rows  = self::active_activities();
		if ( array() === $rows ) {
			return '<div class="anpa-extra-ofertadas anpa-extra-empty"><p>'
				. esc_html__( 'Aínda non hai actividades extraescolares publicadas para este curso.', 'anpa-socios' )
				. '</p></div>';
		}

		$html = '<div class="anpa-extra-ofertadas">';
		if ( null !== $curso ) {
			/* translators: %s: school year like "2026/2027" */
			$html .= '<p class="anpa-extra-curso-activo">'
				. esc_html( sprintf( __( 'Actividades activas no curso actual %s', 'anpa-socios' ), $curso ) )
				. '</p>';
		}
		$html .= '<div class="anpa-card-grid">';
		foreach ( $rows as $act ) {
			$html .= '<div class="anpa-card anpa-extra-card">';
			$html .= '<p class="anpa-icon-circle">' . esc_html( self::activity_icon( (string) ( $act['icono'] ?? '' ) ) ) . '</p>';
			$html .= '<h3>' . esc_html( (string) ( $act['nome'] ?? '' ) ) . '</h3>';

			// Empresa — the name itself is the link when a website is set
			// (fase22 S8.1); no bare URL in parentheses.
			$html .= '<p class="anpa-extra-meta"><strong>' . esc_html__( 'Empresa:', 'anpa-socios' ) . '</strong> ';
			if ( ! empty( $act['empresa_nome'] ) ) {
				$empresa_nome = (string) $act['empresa_nome'];
				if ( ! empty( $act['url_web'] ) ) {
					$html .= '<a href="' . esc_url( $act['url_web'] ) . '" target="_blank" rel="noopener">'
						. esc_html( $empresa_nome ) . '</a>';
				} else {
					$html .= esc_html( $empresa_nome );
				}
			}
			$html .= '</p>';

			// Descripción (só se ten contido).
			if ( ! empty( $act['descripcion'] ) ) {
				$html .= '<p class="anpa-extra-meta"><strong>' . esc_html__( 'Descripción:', 'anpa-socios' ) . '</strong> '
					. esc_html( (string) $act['descripcion'] ) . '</p>';
			}

			// Horario — días e franxa separados.
			$html .= '<p class="anpa-extra-meta"><strong>' . esc_html__( 'Horario:', 'anpa-socios' ) . '</strong></p>';
			$html .= self::schedule_detail_html( $act );

			// Prazas (fase22 S8): aggregated activos/max con cor semántica.
			// Omítese cando non hai grupos abertos (caso do slot provisional).
			$html .= self::prazas_html( $act );

			// Prezo.
			$html .= '<p class="anpa-extra-meta"><strong>' . esc_html__( 'Prezo:', 'anpa-socios' ) . '</strong> '
				. esc_html( self::price_label( $act['custo'] ?? null ) ) . '</p>';

			$html .= '</div>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Enqueues the schedule stylesheet only on pages hosting the shortcodes.
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		$content = ( $post instanceof WP_Post ) ? (string) $post->post_content : '';
		if ( ! has_shortcode( $content, 'anpa_extraescolares_horario' ) && ! has_shortcode( $content, 'anpa_extraescolares_ofertadas' ) ) {
			return;
		}

		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/extraescolares.css';
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_style( 'anpa-extraescolares', plugins_url( 'assets/css/extraescolares.css', ANPA_SOCIOS_PLUGIN_FILE ), array(), $css_version );
	}

	/**
	 * Returns active group slots for the current course.
	 *
	 * Also synthesizes provisional rows for activities that have valid annual
	 * franxa + dias on actividades_cursos but ZERO groups for the current year
	 * (design.md §8.6 provisional-slot fallback).
	 *
	 * @since  1.12.0
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_group_slots(): array {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$curso = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso ) {
			return array();
		}

		// Only real annual groups are schedule sources. There is no activity-level
		// provisional fallback in the revised fase24 model.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.nome, g.nome AS grupo_nome, g.horario, g.franxa, g.dias
				 FROM {$act_t} a
				 INNER JOIN {$acy_t} ac ON ac.actividad_id = a.id AND ac.curso_escolar = %s
				 INNER JOIN {$gru_t} g ON g.actividad_id = a.id AND g.curso_escolar = ac.curso_escolar
				 WHERE a.estado = 'activo' AND ac.estado = 'activo' AND g.estado = 'aberto'
				   AND g.horario IN ('maña','manha','tarde') AND g.franxa <> '' AND g.dias <> ''
				 ORDER BY g.franxa ASC, a.nome ASC, g.nome ASC",
				$curso
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns active activities for the current course, enriched with
	 * per-group enrolment counts and the empresa website URL.
	 *
	 * @since  1.11.0
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_activities(): array {
		global $wpdb;

		$act_t    = ANPA_Socios_DB::tabela_actividades();
		$acy_t    = ANPA_Socios_DB::tabela_actividades_cursos();
		$gru_t    = ANPA_Socios_DB::tabela_grupos();
		$mat_t    = ANPA_Socios_DB::tabela_matriculas();
		$empresas = ANPA_Socios_DB::tabela_empresas();
		$curso    = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso ) {
			return array();
		}

		// Main activity + empresa query (without group_detail — added per activity).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only public blocks from activity/group tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.nome, a.icono, a.descripcion, ac.custo,
				        e.nome AS empresa_nome, e.url_web,
				        MIN(g.franxa) AS sort_franxa,
				        GROUP_CONCAT(DISTINCT g.nome ORDER BY g.nome SEPARATOR ',') AS grupos,
				        GROUP_CONCAT(DISTINCT CONCAT(g.nome, '|', g.horario, '|', g.franxa, '|', g.dias) ORDER BY g.franxa, g.nome SEPARATOR ';;') AS horarios_grupos
				 FROM {$act_t} a
				 INNER JOIN {$acy_t} ac ON ac.actividad_id = a.id AND ac.curso_escolar = %s
				 LEFT JOIN {$empresas} e ON e.id = a.empresa_id
				 INNER JOIN {$gru_t} g ON g.actividad_id = a.id AND g.curso_escolar = ac.curso_escolar AND g.estado = 'aberto'
				 WHERE a.estado = 'activo' AND ac.estado = 'activo'
				 GROUP BY a.id, a.nome, a.icono, a.descripcion, ac.custo, e.nome, e.url_web
				 ORDER BY sort_franxa ASC, a.nome ASC",
				$curso
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		// Enrich each activity with group-level enrolment data.
		$act_ids = array();
		foreach ( $rows as $r ) {
			$act_ids[] = (int) $r['id'];
		}
		$placeholders = implode( ',', array_fill( 0, count( $act_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only group enrolment stats.
		$grupos_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id, g.actividad_id, g.nome, g.min_pupilos, g.max_pupilos,
				        COUNT(DISTINCT CASE WHEN m.estado = 'activo' THEN m.id END) AS activos,
				        COUNT(DISTINCT CASE WHEN m.estado = 'lista_espera' THEN m.id END) AS espera
				 FROM {$gru_t} g
				 LEFT JOIN {$mat_t} m ON m.grupo_id = g.id
				 WHERE g.actividad_id IN ({$placeholders}) AND g.curso_escolar = %s AND g.estado = 'aberto'
				 GROUP BY g.id, g.actividad_id, g.nome, g.min_pupilos, g.max_pupilos
				 ORDER BY g.nome ASC",
				...array_merge( $act_ids, array( $curso ) )
			),
			ARRAY_A
		);

		$by_act = array();
		foreach ( is_array( $grupos_raw ) ? $grupos_raw : array() as $g ) {
			$aid = (int) $g['actividad_id'];
			if ( ! isset( $by_act[ $aid ] ) ) {
				$by_act[ $aid ] = array();
			}
			$by_act[ $aid ][] = array(
				'nome'        => $g['nome'],
				'min_pupilos' => $g['min_pupilos'],
				'max_pupilos' => $g['max_pupilos'],
				'activos'     => $g['activos'],
				'espera'      => $g['espera'],
			);
		}

		foreach ( $rows as &$r ) {
			$aid = (int) $r['id'];
			if ( isset( $by_act[ $aid ] ) ) {
				$r['grupos_detail'] = wp_json_encode( $by_act[ $aid ] );
			} else {
				$r['grupos_detail'] = '[]';
			}
		}
		unset( $r );

		return $rows;
	}

	private static function activity_icon( string $icon ): string {
		$icon = trim( $icon );
		return '' === $icon ? '🎒' : $icon;
	}

	/**
	 * Returns HTML with separate lines for schedule days and time, with
	 * human-friendly Mañá/Tarde labels.
	 *
	 * @since  1.39.0
	 * @param  array $act Activity row (needs 'horarios_grupos').
	 * @return string Escaped HTML.
	 */
	private static function schedule_detail_html( array $act ): string {
		$raw   = (string) ( $act['horarios_grupos'] ?? '' );
		$parts = array();
		foreach ( array_filter( explode( ';;', $raw ) ) as $chunk ) {
			list( $grupo_nome, $horario, $franxa, $dias_csv ) = array_pad( explode( '|', $chunk, 4 ), 4, '' );
			$dias   = ANPA_Socios_Actividade_Options::parse( $dias_csv, ANPA_Socios_Actividade_Options::DIAS );
			$labels = array();
			foreach ( $dias as $dia ) {
				$labels[] = ANPA_Socios_Horario_Builder::DIA_LABELS[ $dia ] ?? $dia;
			}
			$parts[] = array(
				'grupo'  => $grupo_nome,
				'horario'=> ANPA_Socios_Grupo_Serie::horario_label( $horario ),
				'dias'   => implode( ', ', $labels ),
				'franxa' => self::franxa_label( $franxa, $horario ),
			);
		}

		if ( array() === $parts ) {
			return '<p class="anpa-extra-meta anpa-extra-horario-line">'
				. esc_html__( 'consultar condicións', 'anpa-socios' ) . '</p>';
		}

		$html = '';
		foreach ( $parts as $part ) {
			$html .= '<p class="anpa-extra-meta anpa-extra-horario-line"><strong>' . esc_html( $part['grupo'] ) . '</strong> — ' . esc_html( $part['horario'] ) . '</p>';
			$html .= '<p class="anpa-extra-meta anpa-extra-horario-line">' . esc_html( $part['dias'] ) . '</p>';
			$html .= '<p class="anpa-extra-meta anpa-extra-horario-line">' . esc_html( $part['franxa'] ) . '</p>';
		}
		return $html;
	}

	/**
	 * Returns the "Prazas:" block HTML for an activity card (fase22 S8).
	 *
	 * Aggregates the open groups' places via the pure ANPA_Socios_Prazas
	 * helper. Omits the block entirely when the activity has no open groups
	 * (the provisional-slot case has no real capacity to show). The waitlist
	 * part is only appended when the activity is full. Colours are applied via
	 * semantic CSS classes, never inline.
	 *
	 * @since  1.41.0
	 * @param  array $act Activity row (needs 'grupos_detail' JSON).
	 * @return string Escaped HTML, or '' when there is nothing to show.
	 */
	private static function prazas_html( array $act ): string {
		$grupos = json_decode( (string) ( $act['grupos_detail'] ?? '[]' ), true );
		if ( ! is_array( $grupos ) ) {
			$grupos = array();
		}

		$s = ANPA_Socios_Prazas::summary( $grupos );
		if ( empty( $s['has_groups'] ) ) {
			return '';
		}

		$activos_class = ANPA_Socios_Prazas::activos_class( $s );

		$html  = '<p class="anpa-extra-meta anpa-extra-prazas"><strong>' . esc_html__( 'Prazas:', 'anpa-socios' ) . '</strong> ';
		$html .= '<span class="' . esc_attr( $activos_class ) . '">' . (int) $s['activos'] . '</span>';
		$html .= '/' . (int) $s['max_pupilos'];
		if ( ! empty( $s['espera_visible'] ) ) {
			/* translators: %s: number of people on the waitlist */
			$html .= ' + <span class="anpa-extra-prazas-espera">' . (int) $s['espera'] . '</span> '
				. esc_html__( 'en espera', 'anpa-socios' );
		}
		$html .= '</p>';

		return $html;
	}

	/**
	 * Formats a raw franxa (HH:MM-HH:MM) into a human label with Mañá/Comedor/Tarde.
	 *
	 * @since  1.39.0
	 * @param  string $franxa  Raw time range, e.g. "16:45-17:45".
	 * @param  string $horario Optional. Group's horario ('maña'|'manha'|'tarde').
	 *                         When provided, it overrides the hour-based inference
	 *                         so a comedor group (manha) always says 'Comedor'
	 *                         regardless of start time.
	 * @return string Human label, e.g. "Tarde de 16:45 a 17:45".
	 */
	private static function franxa_label( string $franxa, string $horario = '' ): string {
		if ( preg_match( '/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $franxa, $m ) ) {
			if ( 'maña' === $horario ) {
				$period = __( 'Mañá', 'anpa-socios' );
			} elseif ( 'manha' === $horario ) {
				$period = __( 'Comedor', 'anpa-socios' );
			} elseif ( 'tarde' === $horario ) {
				$period = __( 'Tarde', 'anpa-socios' );
			} else {
				$period = (int) $m[1] < 12
					? __( 'Mañá', 'anpa-socios' )
					: __( 'Tarde', 'anpa-socios' );
			}
			/* translators: %1$s: Mañá/Comedor/Tarde, %2$s: HH:MM, %3$s: HH:MM */
			return sprintf( __( '%1$s de %2$s a %3$s', 'anpa-socios' ), $period, "{$m[1]}:{$m[2]}", "{$m[3]}:{$m[4]}" );
		}
		return str_replace( '-', '–', $franxa );
	}

	private static function price_label( $value ): string {
		$price = is_numeric( $value ) ? (float) $value : 0.0;
		if ( $price <= 0 ) {
			return __( 'consultar condicións', 'anpa-socios' );
		}

		/* translators: %s: formatted price with decimals */
		return sprintf( __( '%s €/mes', 'anpa-socios' ), number_format_i18n( $price, 2 ) );
	}
}
