<?php
/**
 * «A ANPA en cifras» (1.95.0).
 *
 * - Shortcode [anpa_cifras]: public block with aggregate figures (member
 *   families, their pupils, children in extraescolares, activities, groups
 *   with free places). Only totals, never names; small public figures are
 *   shown as «menos de 5» (ANPA_Socios_Cifras::valor_publico()).
 * - REST GET anpa-socios/v1/area/cifras: the same figures plus the socios-only
 *   detail (altas and baixas of the course, waiting list, occupancy per
 *   activity), for the «Inicio» panel of the personal area. Needs an area
 *   session.
 *
 * Families are counted once: only the first parent (rol_familia «principal»)
 * of each active family, never the emails of the second parent.
 *
 * @since  1.95.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statistics shortcode and socios endpoint.
 *
 * @since 1.95.0
 */
final class ANPA_Socios_Cifras_Page {

	/**
	 * Transient prefix of the cached figures (10 minutes).
	 */
	const CACHE_KEY = 'anpa_socios_cifras_v3_'; // v3 (1.98.4): group levels.

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 600;

	/**
	 * Registers the shortcode and the REST route.
	 *
	 * @since  1.95.0
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'anpa_cifras', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * REST route for the socios panel.
	 *
	 * @since  1.95.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'anpa-socios/v1',
			'/area/cifras',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_area' ),
				'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
			)
		);
	}

	/**
	 * Shortcode [anpa_cifras titulo="…" mostrar="familias,alumnado,…"].
	 *
	 * @since  1.95.0
	 * @param  array|string $atts Shortcode attributes.
	 * @return string Escaped HTML ('' when there is nothing to show).
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'titulo'  => __( 'A ANPA en cifras', 'anpa-socios' ),
				'mostrar' => implode( ',', ANPA_Socios_Cifras::PUBLICAS_DEFECTO ),
			),
			is_array( $atts ) ? $atts : array(),
			'anpa_cifras'
		);

		$datos = self::datos();
		$items = ANPA_Socios_Cifras::items( $datos['publicas'], ANPA_Socios_Cifras::claves( (string) $atts['mostrar'] ), true );
		if ( array() === $items ) {
			return '';
		}

		self::enqueue_style();

		// titulo="" drops the heading (e.g. inside a section that already has one).
		$aria = '' !== trim( (string) $atts['titulo'] ) ? (string) $atts['titulo'] : __( 'A ANPA en cifras', 'anpa-socios' );
		$html = '<section class="anpa-cifras" aria-label="' . esc_attr( $aria ) . '">';
		if ( '' !== trim( (string) $atts['titulo'] ) ) {
			$html .= '<h2 class="anpa-cifras-titulo">' . esc_html( (string) $atts['titulo'] ) . '</h2>';
		}
		if ( '' !== $datos['curso'] ) {
			/* translators: %s: school year like "2026/2027" */
			$html .= '<p class="anpa-cifras-curso">' . esc_html( sprintf( __( 'Curso %s', 'anpa-socios' ), $datos['curso'] ) ) . '</p>';
		}
		$html .= self::items_html( $items ) . '</section>';

		return $html;
	}

	/**
	 * GET /area/cifras — public figures plus the socios-only detail.
	 *
	 * @since  1.95.0
	 * @param  WP_REST_Request $request Authenticated request.
	 * @return WP_REST_Response
	 */
	public static function handle_area( WP_REST_Request $request ): WP_REST_Response {
		$datos = self::datos();
		$todas = array_merge( $datos['publicas'], $datos['socios'] );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'curso'       => $datos['curso'],
				'cifras'      => ANPA_Socios_Cifras::items( $todas, array( 'familias', 'alumnado', 'extraescolares', 'inscricions', 'actividades', 'sen_inscricion', 'empresas', 'comedor', 'tarde' ), false ),
				'altas'       => (int) $datos['socios']['altas'],
				'baixas'      => (int) $datos['socios']['baixas'],
				'espera'      => (int) $datos['socios']['espera'],
				'prazas'      => $datos['prazas'],
				'actividades' => $datos['actividades'],
				'actualizado' => $datos['actualizado'],
			),
			200
		);
	}

	/**
	 * Cached figures for the active course.
	 *
	 * @since  1.95.0
	 * @return array{curso:string,publicas:array<string,int>,socios:array<string,int>,prazas:array<string,int>,actividades:array<int,array<string,mixed>>,actualizado:string}
	 */
	public static function datos(): array {
		$curso = ANPA_Socios_Curso_Activo::get();
		$curso = null === $curso ? '' : (string) $curso;
		$key   = self::CACHE_KEY . md5( $curso );

		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['publicas'], $cached['socios'] ) ) {
			return $cached;
		}

		$datos = self::calcular( $curso );
		set_transient( $key, $datos, self::CACHE_TTL );
		return $datos;
	}

	/**
	 * Runs the aggregate queries. Only counts leave this method.
	 *
	 * @since  1.95.0
	 * @param  string $curso Active school year ('' = none).
	 * @return array<string,mixed> Same shape as datos().
	 */
	private static function calcular( string $curso ): array {
		global $wpdb;

		$soc_t = ANPA_Socios_DB::tabela_socios();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gn_t  = ANPA_Socios_DB::tabela_grupos_niveis();
		$niv_t = ANPA_Socios_DB::tabela_niveis();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only aggregate counts, no personal data leaves.
		// One family = its first parent (principal). Second parents never count.
		$familias = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$soc_t} WHERE estado = 'activo' AND rol <> 'master' AND rol_familia = 'principal'"
		);
		// Children of active families (fillos.familia_id = the family id of the principal).
		$alumnado = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT f.id) FROM {$fil_t} f
			 INNER JOIN {$soc_t} p ON COALESCE(NULLIF(p.familia_id, 0), p.id) = f.familia_id
			 WHERE f.estado = 'activo' AND p.estado = 'activo' AND p.rol <> 'master' AND p.rol_familia = 'principal'"
		);

		$publicas = array(
			'familias'       => $familias,
			'alumnado'       => $alumnado,
			'extraescolares' => 0,
			'inscricions'    => 0,
			'actividades'    => 0,
			'empresas'       => 0,
			'prazas'         => 0,
			'comedor'        => 0,
			'tarde'          => 0,
			'sen_inscricion' => 0,
		);
		$socios      = array( 'altas' => 0, 'baixas' => 0, 'espera' => 0 );
		$prazas      = ANPA_Socios_Cifras::prazas( array() );
		$actividades = array();

		$inicio = ANPA_Socios_Cifras::inicio_altas( $curso );
		if ( '' !== $inicio ) {
			$socios['altas']  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$soc_t} WHERE estado = 'activo' AND rol <> 'master' AND rol_familia = 'principal' AND creado_en >= %s",
				$inicio . ' 00:00:00'
			) );
			$socios['baixas'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$soc_t} WHERE estado = 'baixa' AND rol <> 'master' AND rol_familia = 'principal' AND baixa_en >= %s",
				$inicio . ' 00:00:00'
			) );
		}

		// Same groups as the public offer ([anpa_extraescolares_ofertadas]).
		$ids = '' === $curso ? array() : ANPA_Socios_Extraescolares_Page::grupos_publicos_ids( $curso );
		if ( array() !== $ids ) {
			$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// An enrolment still counts while its baixa is only requested.
			$vivas = "m.estado IN ('activo','baixa_solicitada')";

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(DISTINCT m.fillo_id) AS nenos,
				        COUNT(DISTINCT CONCAT(m.fillo_id, '-', g.actividad_id)) AS inscricions,
				        COUNT(DISTINCT CASE WHEN g.horario = 'manha' THEN m.fillo_id END) AS comedor,
				        COUNT(DISTINCT CASE WHEN g.horario = 'tarde' THEN m.fillo_id END) AS tarde
				 FROM {$mat_t} m
				 INNER JOIN {$gru_t} g ON g.id = m.grupo_id
				 INNER JOIN {$act_t} a ON a.id = g.actividad_id AND a.estado = 'activo'
				 WHERE {$vivas} AND g.id IN ({$ph})",
				...$ids
			), ARRAY_A );
			if ( is_array( $row ) ) {
				$publicas['extraescolares'] = (int) $row['nenos'];
				$publicas['inscricions']    = (int) $row['inscricions'];
				$publicas['comedor']        = (int) $row['comedor'];
				$publicas['tarde']          = (int) $row['tarde'];
			}

			$socios['espera'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$mat_t} m WHERE m.estado = 'lista_espera' AND m.grupo_id IN ({$ph})",
				...$ids
			) );

			// 1.97.0: every group of the course (not only the public ones), so the
			// socios panel shows each group with its state; places are per group.
			$grupos = $wpdb->get_results( $wpdb->prepare(
				"SELECT g.id, g.actividad_id, g.nome AS grupo, g.estado, g.horario, g.franxa, g.dias,
				        g.min_pupilos, g.max_pupilos, g.aviso_comezo_en,
				        a.nome, a.empresa_id, a.sen_inscricion,
				        (SELECT GROUP_CONCAT(n.etiqueta ORDER BY n.orde SEPARATOR ', ') FROM {$gn_t} gn INNER JOIN {$niv_t} n ON n.id = gn.nivel_id WHERE gn.grupo_id = g.id) AS niveis,
				        COUNT(DISTINCT CASE WHEN {$vivas} THEN m.fillo_id END) AS activos,
				        COUNT(DISTINCT CASE WHEN m.estado = 'lista_espera' THEN m.id END) AS espera,
				        COUNT(DISTINCT m.id) AS total
				 FROM {$gru_t} g
				 INNER JOIN {$act_t} a ON a.id = g.actividad_id AND a.estado = 'activo'
				 LEFT JOIN {$mat_t} m ON m.grupo_id = g.id
				 WHERE g.curso_escolar = %s
				 GROUP BY g.id, g.actividad_id, g.nome, g.estado, g.horario, g.franxa, g.dias, g.min_pupilos, g.max_pupilos, g.aviso_comezo_en, a.nome, a.empresa_id, a.sen_inscricion
				 ORDER BY a.nome ASC, g.franxa ASC, g.nome ASC",
				$curso
			), ARRAY_A );
			$grupos   = is_array( $grupos ) ? $grupos : array();
			$publicos = array_flip( $ids );

			$abertos  = array();
			$empresas = array();
			$abertas  = array();
			foreach ( $grupos as $g ) {
				$aid      = (int) $g['actividad_id'];
				$publico  = isset( $publicos[ (int) $g['id'] ] );
				$sen_insc = ! empty( $g['sen_inscricion'] );
				$aviso    = null === $g['aviso_comezo_en'] ? null : (string) $g['aviso_comezo_en'];
				$estado   = $sen_insc ? 'sen_inscricion' : ANPA_Socios_Oferta_Publica::estado_grupo( (string) $g['estado'], $aviso, (int) $g['activos'], (int) $g['min_pupilos'], (int) $g['total'] );
				if ( '' === $estado || ( $sen_insc && ! $publico ) ) {
					continue; // Disabled or never offered: not shown.
				}
				if ( $publico ) {
					if ( (int) $g['empresa_id'] > 0 ) {
						$empresas[ (int) $g['empresa_id'] ] = true;
					}
					if ( $sen_insc ) {
						$abertas[ $aid ] = true;
					} elseif ( in_array( (string) $g['estado'], array( 'aberto', 'sen_minimo' ), true ) ) {
						// Only groups still taking enrolments count for free places.
						$abertos[] = $g;
					}
				}
				if ( ! isset( $actividades[ $aid ] ) ) {
					$actividades[ $aid ] = array( 'nome' => (string) $g['nome'], 'sen_inscricion' => $sen_insc, 'publica' => false, 'inscritos' => 0, 'prazas' => 0, 'sen_limite' => false, 'espera' => 0, 'grupos' => array() );
				}
				$actividades[ $aid ]['publica'] = $actividades[ $aid ]['publica'] || $publico;
				// Groups that did not reach the minimum are listed but add no places.
				if ( ANPA_Socios_Oferta_Publica::NON_ACADADO !== $estado ) {
					$actividades[ $aid ]['inscritos'] += (int) $g['activos'];
					$actividades[ $aid ]['espera']    += (int) $g['espera'];
					if ( (int) $g['max_pupilos'] <= 0 ) {
						$actividades[ $aid ]['sen_limite'] = true;
					} else {
						$actividades[ $aid ]['prazas'] += (int) $g['max_pupilos'];
					}
				}
				$dias = array();
				foreach ( ANPA_Socios_Actividade_Options::parse( (string) $g['dias'], ANPA_Socios_Actividade_Options::DIAS ) as $d ) {
					$dias[] = ANPA_Socios_Horario_Builder::DIA_LABELS[ $d ] ?? $d;
				}
				$actividades[ $aid ]['grupos'][] = array(
					'nome'      => ANPA_Socios_Cifras::nome_grupo( (string) $g['nome'], (string) $g['grupo'], (string) ( $g['niveis'] ?? '' ) ),
					'estado'    => $estado,
					'horario'   => ANPA_Socios_Grupo_Serie::horario_label( (string) $g['horario'] ),
					'franxa'    => str_replace( '-', '–', (string) $g['franxa'] ),
					'dias'      => implode( ', ', $dias ),
					'inscritos' => (int) $g['activos'],
					'minimo'    => (int) $g['min_pupilos'],
					'maximo'    => (int) $g['max_pupilos'],
					'espera'    => (int) $g['espera'],
				);
			}
			// Activities only taken by groups that are no longer offered drop out of the public count.
			$prazas                      = ANPA_Socios_Cifras::prazas( $abertos );
			$publicas['actividades']     = count( array_filter( $actividades, static function ( $a ) { return $a['publica']; } ) );
			$publicas['empresas']        = count( $empresas );
			$publicas['prazas']          = (int) $prazas['grupos_con_prazas'];
			$publicas['sen_inscricion']  = count( $abertas );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array(
			'curso'       => $curso,
			'publicas'    => $publicas,
			'socios'      => $socios,
			'prazas'      => $prazas,
			'actividades' => array_values( $actividades ),
			'actualizado' => current_time( 'mysql' ),
		);
	}

	/**
	 * Figure tiles markup.
	 *
	 * @since  1.95.0
	 * @param  array<int,array{clave:string,valor:string,etiqueta:string}> $items Figures.
	 * @return string Escaped HTML.
	 */
	private static function items_html( array $items ): string {
		// 1.96.3: the column count follows the number of figures (no lone tile).
		$html = '<ul class="anpa-cifras-lista anpa-cifras-lista--n' . count( $items ) . '">';
		foreach ( $items as $item ) {
			$html .= '<li class="anpa-cifras-item anpa-cifras-item--' . esc_attr( $item['clave'] ) . '">'
				. '<span class="anpa-cifras-valor">' . esc_html( $item['valor'] ) . '</span>'
				. '<span class="anpa-cifras-etiqueta">' . esc_html( $item['etiqueta'] ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Enqueues the block stylesheet (also from the shortcode itself, so the
	 * block works in widgets and theme templates).
	 *
	 * @since  1.95.0
	 * @return void
	 */
	public static function enqueue_style(): void {
		$path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/cifras.css';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_style( 'anpa-socios-cifras', plugins_url( 'assets/css/cifras.css', ANPA_SOCIOS_PLUGIN_FILE ), array(), $version );
	}

	/**
	 * Loads the stylesheet in the head on pages whose content has the shortcode
	 * (avoids a flash of unstyled figures).
	 *
	 * @since  1.95.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'anpa_cifras' ) ) {
			self::enqueue_style();
		}
	}
}
