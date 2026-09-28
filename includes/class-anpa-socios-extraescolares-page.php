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
		// 1.71.0: activity dates (Axustes → Xeral) and the groups that did not reach their minimum.
		$inicio     = ANPA_Socios_Config::data_inicio_actividades();
		$remate     = ANPA_Socios_Config::data_remate_actividades();
		$aviso      = ANPA_Socios_Oferta_Publica::aviso_datas( $inicio, $remate );
		$curto      = ANPA_Socios_Oferta_Publica::texto_curto( $inicio, $remate );
		$datas_html = '' === $aviso ? '' : '<div class="anpa-extra-datas" role="note"><p>' . esc_html( $aviso ) . '</p></div>';
		$non_html   = self::non_acadados_html( null !== $curso ? (string) $curso : '' );
		if ( array() === $rows ) {
			return '<div class="anpa-extra-ofertadas anpa-extra-empty">' . $datas_html . '<p>'
				. esc_html__( 'Aínda non hai actividades extraescolares publicadas para este curso.', 'anpa-socios' )
				. '</p>' . $non_html . '</div>';
		}

		$html = '<div class="anpa-extra-ofertadas">' . $datas_html;
		if ( null !== $curso ) {
			/* translators: %s: school year like "2026/2027" */
			$html .= '<p class="anpa-extra-curso-activo">'
				. esc_html( sprintf( __( 'Actividades activas no curso actual %s', 'anpa-socios' ), $curso ) )
				. '</p>';
		}
		// 1.68.2: one titled section per time-of-day block (comedor / tarde), with a
		// divider between them and at most three cards per row (CSS).
		foreach ( ANPA_Socios_Oferta_Seccions::agrupar( $rows ) as $seccion ) {
		$html .= '<section class="anpa-extra-seccion anpa-extra-seccion--' . esc_attr( ANPA_Socios_Oferta_Seccions::clase( (string) $seccion['horario'] ) ) . '">';
		$html .= '<h2 class="anpa-extra-seccion-titulo">' . esc_html( (string) $seccion['titulo'] ) . '</h2>';
		$html .= '<div class="anpa-card-grid">';
		foreach ( $seccion['rows'] as $act ) {
			$html .= '<div class="anpa-card anpa-extra-card">';
			$html .= '<p class="anpa-icon-circle">' . esc_html( self::activity_icon( (string) ( $act['icono'] ?? '' ) ) ) . '</p>';
			$html .= '<h3>' . esc_html( (string) ( $act['nome'] ?? '' ) ) . '</h3>';
			if ( '' !== $curto ) {
				$html .= '<p class="anpa-extra-meta anpa-extra-card-datas">' . esc_html( $curto ) . '</p>';
			}

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

			// Prezo.
			$html .= '<p class="anpa-extra-meta"><strong>' . esc_html__( 'Prezo:', 'anpa-socios' ) . '</strong> '
				. esc_html( self::price_label( $act['custo'] ?? null ) ) . '</p>';

			$html .= '</div>';
		}
		$html .= '</div></section>';
		}
		$html .= $non_html . '</div>';

		return $html;
	}

	/**
	 * Closed groups the junta confirmed («grupo creado» notice sent or marked by
	 * hand) for the course. They still show on the public page with «Creado».
	 *
	 * @since  1.71.0
	 * @param  string $curso School year.
	 * @return int[]
	 */
	private static function grupos_creados_ids( string $curso ): array {
		global $wpdb;
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only public listing.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.id, g.curso_escolar, g.franxa, g.dias FROM {$gru_t} g
			 WHERE g.curso_escolar = %s AND g.estado = 'pechado' AND g.aviso_comezo_en IS NOT NULL
			   AND EXISTS (SELECT 1 FROM {$mat_t} m WHERE m.grupo_id = g.id AND m.estado = 'activo')
			 ORDER BY g.id",
			$curso
		), ARRAY_A );
		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Same levels + canteen-timetable gate as the open groups (available_group_ids).
			$niveis = ANPA_Socios_DB::get_niveis_for_grupo( (int) $row['id'] );
			if ( array() === $niveis ) {
				continue;
			}
			$conflicts = ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series( array(
				'estado'         => 'aberto',
				'cursos'         => array( (string) $row['curso_escolar'] ),
				'niveis_por_ano' => array( (string) $row['curso_escolar'] => $niveis ),
				'franxa'         => (string) $row['franxa'],
				'dias'           => (string) $row['dias'],
			), false );
			if ( ! is_wp_error( $conflicts ) && array() === $conflicts ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * «Non acadaron o mínimo»: activity and group names only (no description,
	 * schedule or price). '' when there is none.
	 *
	 * @since  1.71.0
	 * @param  string $curso School year ('' = none).
	 * @return string Escaped HTML.
	 */
	private static function non_acadados_html( string $curso ): string {
		if ( '' === $curso ) {
			return '';
		}
		global $wpdb;
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only public listing, no personal data.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.nome AS actividade, g.nome AS grupo, g.estado, g.aviso_comezo_en, g.min_pupilos,
			        COUNT(DISTINCT CASE WHEN m.estado = 'activo' THEN m.id END) AS activos,
			        COUNT(DISTINCT m.id) AS total
			 FROM {$gru_t} g
			 INNER JOIN {$act_t} a ON a.id = g.actividad_id
			 LEFT JOIN {$mat_t} m ON m.grupo_id = g.id
			 WHERE g.curso_escolar = %s AND a.estado = 'activo' AND g.estado IN ('pechado', 'deshabilitado')
			 GROUP BY g.id, a.nome, g.nome, g.estado, g.aviso_comezo_en, g.min_pupilos",
			$curso
		), ARRAY_A );
		$lista = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$aviso = null === $r['aviso_comezo_en'] ? null : (string) $r['aviso_comezo_en'];
			if ( ANPA_Socios_Oferta_Publica::NON_ACADADO === ANPA_Socios_Oferta_Publica::estado_grupo( (string) $r['estado'], $aviso, (int) $r['activos'], (int) $r['min_pupilos'], (int) $r['total'] ) ) {
				$lista[] = array( 'actividade' => (string) $r['actividade'], 'grupo' => (string) $r['grupo'] );
			}
		}
		$lista = ANPA_Socios_Oferta_Publica::non_acadados( $lista );
		if ( array() === $lista ) {
			return '';
		}
		$html  = '<section class="anpa-extra-seccion anpa-extra-non-acadados">';
		$html .= '<h2 class="anpa-extra-seccion-titulo">' . esc_html__( 'Non acadaron o mínimo', 'anpa-socios' ) . '</h2>';
		$html .= '<p class="anpa-extra-meta">' . esc_html__( 'Estes grupos non se forman este curso porque non chegaron ao número mínimo de inscricións.', 'anpa-socios' ) . '</p><ul>';
		foreach ( $lista as $item ) {
			$html .= '<li><strong>' . esc_html( $item['actividade'] ) . '</strong>: ' . esc_html( implode( ', ', $item['grupos'] ) ) . '</li>';
		}
		return $html . '</ul></section>';
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
	 * Returns open group IDs that pass the canonical meal-availability gate.
	 *
	 * @since  1.44.0
	 * @param  string $curso School year.
	 * @return int[]
	 */
	private static function available_group_ids( string $curso ): array {
		global $wpdb;

		static $cache = array();
		if ( isset( $cache[ $curso ] ) ) {
			return $cache[ $curso ];
		}

		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, curso_escolar, franxa, dias FROM {$gru_t}
				 WHERE curso_escolar = %s AND estado = 'aberto' ORDER BY id",
				$curso
			),
			ARRAY_A
		);
		$available = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$grupo_id = (int) $row['id'];
			$niveis   = ANPA_Socios_DB::get_niveis_for_grupo( $grupo_id );
			if ( array() === $niveis ) {
				continue;
			}
			$payload = array(
				'estado'          => 'aberto',
				'cursos'          => array( (string) $row['curso_escolar'] ),
				'niveis_por_ano' => array( (string) $row['curso_escolar'] => $niveis ),
				'franxa'          => (string) $row['franxa'],
				'dias'            => (string) $row['dias'],
			);
			$conflicts = ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series( $payload, false );
			if ( ! is_wp_error( $conflicts ) && array() === $conflicts ) {
				$available[] = $grupo_id;
			}
		}

		$cache[ $curso ] = $available;
		return $available;
	}

	/**
	 * Returns valid active group slots for the current course.
	 *
	 * @since  1.12.0
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_group_slots(): array {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();

		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$curso = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso ) {
			return array();
		}
		// 1.71.0: open groups plus the closed ones the junta confirmed («grupo creado»): they run this course.
		$available_ids = array_values( array_unique( array_merge( self::available_group_ids( $curso ), self::grupos_creados_ids( $curso ) ) ) );
		if ( array() === $available_ids ) {
			return array();
		}
		$available_placeholders = implode( ',', array_fill( 0, count( $available_ids ), '%d' ) );

		// Only real annual groups are schedule sources. There is no activity-level
		// provisional fallback in the revised fase24 model.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.nome, g.nome AS grupo_nome, g.horario, g.franxa, g.dias, g.max_pupilos,
				        COUNT(DISTINCT CASE WHEN m.estado = 'activo' THEN m.id END) AS activos
				 FROM {$act_t} a
				 INNER JOIN {$gru_t} g ON g.actividad_id = a.id AND g.curso_escolar = %s
				 LEFT JOIN {$mat_t} m ON m.grupo_id = g.id
				 WHERE a.estado = 'activo'
				   AND g.id IN ({$available_placeholders})
				   AND g.horario IN ('maña','manha','tarde') AND g.franxa <> '' AND g.dias <> ''
				 GROUP BY g.id, a.nome, g.nome, g.horario, g.franxa, g.dias, g.max_pupilos
				 ORDER BY g.franxa ASC, a.nome ASC, g.nome ASC",
				...array_merge( array( $curso ), $available_ids )
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
		$gru_t    = ANPA_Socios_DB::tabela_grupos();
		$gn_t     = ANPA_Socios_DB::tabela_grupos_niveis();
		$niv_t    = ANPA_Socios_DB::tabela_niveis();
		$mat_t    = ANPA_Socios_DB::tabela_matriculas();
		$empresas = ANPA_Socios_DB::tabela_empresas();
		$curso    = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso ) {
			return array();
		}
		// 1.71.0: open groups plus the closed ones the junta confirmed («grupo creado»): they run this course.
		$available_ids = array_values( array_unique( array_merge( self::available_group_ids( $curso ), self::grupos_creados_ids( $curso ) ) ) );
		if ( array() === $available_ids ) {
			return array();
		}
		$available_placeholders = implode( ',', array_fill( 0, count( $available_ids ), '%d' ) );

		// Main activity + empresa query (without group_detail — added per activity).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only public blocks from activity/group tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.nome, a.icono, a.descripcion, a.custo,
				        e.nome AS empresa_nome, e.url_web,
				        MIN(g.franxa) AS sort_franxa,
				        GROUP_CONCAT(DISTINCT g.nome ORDER BY g.nome SEPARATOR ',') AS grupos,
				        GROUP_CONCAT(DISTINCT CONCAT(g.id, '|', g.nome, '|', g.horario, '|', g.franxa, '|', g.dias) ORDER BY g.franxa, g.nome SEPARATOR ';;') AS horarios_grupos
				 FROM {$act_t} a
				 LEFT JOIN {$empresas} e ON e.id = a.empresa_id
				 INNER JOIN {$gru_t} g ON g.actividad_id = a.id AND g.curso_escolar = %s
				 WHERE a.estado = 'activo'
				   AND g.id IN ({$available_placeholders})
				 GROUP BY a.id, a.nome, a.icono, a.descripcion, a.custo, e.nome, e.url_web
				 ORDER BY sort_franxa ASC, a.nome ASC",
				...array_merge( array( $curso ), $available_ids )
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
				"SELECT g.id, g.actividad_id, g.nome, g.min_pupilos, g.max_pupilos, g.estado, g.aviso_comezo_en,
				        GROUP_CONCAT(DISTINCT n.etiqueta ORDER BY n.orde SEPARATOR ', ') AS niveis,
				        COUNT(DISTINCT CASE WHEN m.estado = 'activo' THEN m.id END) AS activos,
				        COUNT(DISTINCT CASE WHEN m.estado = 'lista_espera' THEN m.id END) AS espera
				 FROM {$gru_t} g
				 INNER JOIN {$gn_t} gn ON gn.grupo_id = g.id
				 INNER JOIN {$niv_t} n ON n.id = gn.nivel_id
				 LEFT JOIN {$mat_t} m ON m.grupo_id = g.id
				 WHERE g.actividad_id IN ({$placeholders}) AND g.curso_escolar = %s
				   AND g.id IN ({$available_placeholders})
				 GROUP BY g.id, g.actividad_id, g.nome, g.min_pupilos, g.max_pupilos, g.estado, g.aviso_comezo_en
				 ORDER BY g.nome ASC",
				...array_merge( $act_ids, array( $curso ), $available_ids )
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
				'id'          => $g['id'],
				'nome'        => $g['nome'],
				'niveis'      => $g['niveis'],
				'min_pupilos' => $g['min_pupilos'],
				'max_pupilos' => $g['max_pupilos'],
				'activos'     => $g['activos'],
				'espera'      => $g['espera'],
				// 1.71.0: closed and confirmed by the junta -> «Creado».
				'creado'      => ANPA_Socios_Oferta_Publica::CREADO === ANPA_Socios_Oferta_Publica::estado_grupo( (string) $g['estado'], null === $g['aviso_comezo_en'] ? null : (string) $g['aviso_comezo_en'], (int) $g['activos'], (int) $g['min_pupilos'] ),
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
		$raw           = (string) ( $act['horarios_grupos'] ?? '' );
		$group_details = json_decode( (string) ( $act['grupos_detail'] ?? '[]' ), true );
		$details_by_id = array();
		foreach ( is_array( $group_details ) ? $group_details : array() as $group ) {
			$details_by_id[ (int) ( $group['id'] ?? 0 ) ] = $group;
		}
		$parts = array();
		foreach ( array_filter( explode( ';;', $raw ) ) as $chunk ) {
			// Chunk layout: id|nome|horario|franxa|dias. The NAME is the
			// only field allowed to contain a literal '|', so split defensively:
			// take segment 0 as id, the last 3 as horario/franxa/dias, and
			// re-join everything in between back into the group name.
			$segs         = explode( '|', $chunk );
			$grupo_id     = (int) ( $segs[0] ?? 0 );
			$horario      = (string) ( $segs[ count( $segs ) - 3 ] ?? '' );
			$franxa       = (string) ( $segs[ count( $segs ) - 2 ] ?? '' );
			$dias_csv     = (string) ( $segs[ count( $segs ) - 1 ] ?? '' );
			$grupo_nome   = implode( '|', array_slice( $segs, 1, -3 ) );
			$dias   = ANPA_Socios_Actividade_Options::parse( $dias_csv, ANPA_Socios_Actividade_Options::DIAS );
			$labels = array();
			foreach ( $dias as $dia ) {
				$labels[] = ANPA_Socios_Horario_Builder::DIA_LABELS[ $dia ] ?? $dia;
			}
			$parts[] = array(
				'grupo'   => $grupo_nome,
				'dias'    => implode( ', ', $labels ),
				'franxa'  => self::franxa_label( $franxa, $horario ),
				'capacity' => $details_by_id[ (int) $grupo_id ] ?? null,
			);
		}

		if ( array() === $parts ) {
			return '<p class="anpa-extra-meta anpa-extra-horario-line">'
				. esc_html__( 'consultar condicións', 'anpa-socios' ) . '</p>';
		}

		$html = '';
		foreach ( $parts as $part ) {
			$html .= '<div class="anpa-extra-horario-grupo">';
			$creado = is_array( $part['capacity'] ) && ! empty( $part['capacity']['creado'] );
			$html  .= '<p class="anpa-extra-meta anpa-extra-horario-line"><strong>' . esc_html( $part['grupo'] ) . '</strong>'
				. ( $creado ? ' <span class="anpa-extra-grupo-creado">' . esc_html__( 'Creado', 'anpa-socios' ) . '</span>' : '' )
				. ' — ' . esc_html( $part['franxa'] ) . '</p>';
			$html .= '<p class="anpa-extra-meta anpa-extra-horario-line anpa-extra-horario-dias">' . esc_html( $part['dias'] ) . '</p>';
			if ( is_array( $part['capacity'] ) ) {
				if ( ! empty( $part['capacity']['niveis'] ) ) {
					$html .= '<p class="anpa-extra-meta anpa-extra-grupo-niveis"><strong>'
						. esc_html__( 'Cursos:', 'anpa-socios' ) . '</strong> '
						. esc_html( (string) $part['capacity']['niveis'] ) . '</p>';
				}
				$html .= self::group_prazas_html( $part['capacity'] );
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * Returns the "Prazas:" block HTML for one annual group.
	 *
	 * Capacity and minimum are group properties and must never be aggregated at
	 * activity level. The waitlist is only appended when this group is full.
	 *
	 * @since  1.42.2
	 * @param  array $group Annual group capacity row.
	 * @return string Escaped HTML.
	 */
	private static function group_prazas_html( array $group ): string {
		$s              = ANPA_Socios_Prazas::summary( array( $group ) );
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
		$minimo = (int) ( $group['min_pupilos'] ?? 0 );
		// 1.71.0: a created group no longer needs its minimum.
		if ( $minimo > 0 && empty( $group['creado'] ) ) {
			/* translators: %d: minimum pupils required to form this group. */
			$html .= '<p class="anpa-extra-meta anpa-extra-prazas-minimo">'
				. esc_html( sprintf( __( 'Mínimo de %d para crear grupo.', 'anpa-socios' ), $minimo ) )
				. '</p>';
		}

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
