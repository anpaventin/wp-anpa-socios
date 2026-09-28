<?php
/**
 * Alumnos export column/row logic for empresa and admin CSV exports.
 *
 * Pure column definitions are testable without WordPress. The rows()
 * method requires $wpdb (WordPress integration layer).
 *
 * @since  1.5.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Provides column definitions and data retrieval for alumnos CSV exports.
 *
 * @since 1.5.0
 */
final class ANPA_Socios_Alumnos_Export {

	/**
	 * Returns the column list for alumnos export.
	 *
	 * @since  1.5.0
	 * @param  bool $with_empresa Whether to include the empresa_nome column (admin view).
	 * @return string[]
	 */
	public static function columns( bool $with_empresa ): array {
		$empresa_cols = array(
			'actividade_nome',
			'nome',
			'apelidos',
			'curso',
			'aula',
			'comedor',
			'tarde',
			'socio_email',
		);

		if ( $with_empresa ) {
			return array_merge( array( 'empresa_nome' ), $empresa_cols );
		}

		return $empresa_cols;
	}

	/**
	 * Fetches alumnos rows for export.
	 *
	 * When $empresa_id is provided (int), returns only active enrolments
	 * for that empresa. When null, returns all active enrolments across
	 * all empresas (admin export).
	 *
	 * @since  1.5.0
	 * @param  int|null $empresa_id Empresa ID filter, or null for all.
	 * @return array<int,array<string,string>>|null Rows or null on DB error.
	 */
	public static function rows( ?int $empresa_id ): ?array {
		global $wpdb;

		$matriculas  = ANPA_Socios_DB::tabela_matriculas();
		$fillos      = ANPA_Socios_DB::tabela_fillos();
		$actividades = ANPA_Socios_DB::tabela_actividades();
		$empresas    = ANPA_Socios_DB::tabela_empresas();

		if ( null === $empresa_id ) {
			// Admin: all empresas, prepend empresa_nome.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bulk admin export gated by permission_master.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from DB helper.
			$rows = $wpdb->get_results(
				"SELECT e.nome AS empresa_nome, a.nome AS actividade_nome, "
				. "f.nome, f.apelidos, COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula, m.comedor, m.tarde, f.socio_email "
				. "FROM {$matriculas} m "
				. "JOIN {$fillos} f ON f.id = m.fillo_id "
				. "JOIN {$actividades} a ON a.id = m.activitad_id "
				. "JOIN {$empresas} e ON e.id = a.empresa_id "
				. "LEFT JOIN {$wpdb->prefix}anpa_grupos g ON g.id = m.grupo_id "
				. "LEFT JOIN {$wpdb->prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar "
				. "WHERE m.estado = 'activo' "
				. "ORDER BY e.nome, a.nome, f.apelidos, f.nome",
				ARRAY_A
			);
		} else {
			// Empresa: scoped to one empresa_id.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- scoped empresa export gated by permission_empresa.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from DB helper.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.nome AS actividade_nome, "
					. "f.nome, f.apelidos, COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula, m.comedor, m.tarde, f.socio_email "
					. "FROM {$matriculas} m "
					. "JOIN {$fillos} f ON f.id = m.fillo_id "
					. "JOIN {$actividades} a ON a.id = m.activitad_id "
					. "LEFT JOIN {$wpdb->prefix}anpa_grupos g ON g.id = m.grupo_id "
					. "LEFT JOIN {$wpdb->prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar "
					. "WHERE a.empresa_id = %d AND m.estado = 'activo' "
					. "ORDER BY a.nome, f.apelidos, f.nome",
					$empresa_id
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * Columns of the company panel listing / export (1.55.0). Superset of
	 * columns( false ) with the group, the enrolment state and the trimester.
	 *
	 * @return string[]
	 */
	public static function columns_panel_empresa(): array {
		return array( 'actividade_nome', 'grupo_nome', 'horario', 'franxa', 'dias', 'nome', 'apelidos', 'curso', 'aula', 'estado', 'trimestre', 'comedor', 'tarde', 'autorizacion_comedor', 'tarde_transicion', 'tardes_divertidas_continua', 'recollida_autorizada', 'cesion_datos_empresa', 'proxenitor1_nome', 'proxenitor1_telefono', 'proxenitor1_email', 'proxenitor2_nome', 'proxenitor2_telefono', 'proxenitor2_email' );
	}

	/**
	 * Columns of the canteen account listing (1.56.0): the company columns plus the company name.
	 *
	 * @return string[]
	 */
	public static function columns_panel_comedor(): array {
		return array_merge( array( 'empresa_nome' ), self::columns_panel_empresa() );
	}

	/**
	 * Enrolments of one company for a school year, with their state (1.55.0).
	 *
	 * @param  int         $empresa_id    Company id; 0 = every company (canteen account, 1.56.0).
	 * @param  string|null $curso_escolar Groups' school year; null = all years.
	 * @param  bool        $so_activos    True = only estado 'activo'; false = every state incl. baixa.
	 * @return array<int,array<string,string>>|null Rows or null on DB error.
	 */
	public static function rows_panel_empresa( int $empresa_id, ?string $curso_escolar, bool $so_activos ): ?array {
		global $wpdb;
		$params = array( $empresa_id, $empresa_id );
		$where  = '( %d = 0 OR a.empresa_id = %d )';
		if ( null !== $curso_escolar && '' !== $curso_escolar ) {
			$where   .= ' AND g.curso_escolar = %s';
			$params[] = $curso_escolar;
		}
		$where .= $so_activos ? " AND m.estado = 'activo'" : " AND m.estado IN ('activo','lista_espera','oferta','baixa_solicitada','pendente_aprobacion','baixa')";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from DB helper; where built from placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::panel_select_sql( '' )
				. "WHERE {$where} "
				. "ORDER BY a.nome, e.nome, g.nome, FIELD(m.estado, 'activo', 'oferta', 'lista_espera', 'baixa_solicitada', 'pendente_aprobacion', 'baixa'), f.apelidos, f.nome",
				$params
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * One enrolment with the same columns as the company listing, plus the
	 * company email and the group's school year (1.71.0: mid-course notice to
	 * the company and the canteen).
	 *
	 * @param  int $matricula_id Enrolment id.
	 * @return array<string,string>|null Null when not found.
	 */
	public static function row_panel_matricula( int $matricula_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from DB helper.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				self::panel_select_sql( "COALESCE(e.email, '') AS empresa_email, COALESCE(g.curso_escolar, '') AS curso_escolar, " ) . 'WHERE m.id = %d',
				$matricula_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * SELECT ... FROM ... JOINs shared by the listing and the single-enrolment
	 * lookup (no WHERE). Pure ASCII (wpdb trap, 1.56.2).
	 *
	 * @param  string $extra Extra select expressions, each ending in ", ".
	 * @return string
	 */
	private static function panel_select_sql( string $extra ): string {
		$matriculas  = ANPA_Socios_DB::tabela_matriculas();
		$fillos      = ANPA_Socios_DB::tabela_fillos();
		$actividades = ANPA_Socios_DB::tabela_actividades();
		$grupos      = ANPA_Socios_DB::tabela_grupos();
		$fc          = ANPA_Socios_DB::tabela_fillos_cursos();
		$empresas    = ANPA_Socios_DB::tabela_empresas();
		$socios      = ANPA_Socios_DB::tabela_socios();
		return "SELECT {$extra}COALESCE(e.nome, '') AS empresa_nome, a.nome AS actividade_nome, COALESCE(g.nome, '') AS grupo_nome, COALESCE(g.horario, '') AS horario, COALESCE(g.franxa, '') AS franxa, COALESCE(g.dias, '') AS dias, "
			. "f.nome, f.apelidos, COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula, '') AS aula, m.estado, m.trimestre, m.comedor, m.tarde, m.autorizacion_comedor, m.tarde_transicion, m.tardes_divertidas_continua, m.recollida_autorizada, m.cesion_datos_empresa, "
			. "TRIM(CONCAT(COALESCE(p1.nome, ''), ' ', COALESCE(p1.apelidos, ''))) AS proxenitor1_nome, COALESCE(p1.telefono, '') AS proxenitor1_telefono, COALESCE(NULLIF(p1.email, ''), f.socio_email, '') AS proxenitor1_email, "
			. "TRIM(CONCAT(COALESCE(p2.nome, ''), ' ', COALESCE(p2.apelidos, ''))) AS proxenitor2_nome, COALESCE(p2.telefono, '') AS proxenitor2_telefono, COALESCE(p2.email, '') AS proxenitor2_email "
			. "FROM {$matriculas} m "
			. "JOIN {$fillos} f ON f.id = m.fillo_id "
			. "JOIN {$actividades} a ON a.id = m.activitad_id "
			. "LEFT JOIN {$empresas} e ON e.id = a.empresa_id "
			. "LEFT JOIN {$grupos} g ON g.id = m.grupo_id "
			. "LEFT JOIN {$fc} fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar "
			// 1.70.0: parents' contact. The family is fillos.familia_id, or the family of
			// the member owning socio_email; p1 = its principal (fallback: that member),
			// p2 = its secundario. MIN() keeps one row per enrolment.
			. "LEFT JOIN {$socios} s0 ON s0.email = f.socio_email AND f.socio_email <> '' "
			. "LEFT JOIN {$socios} p1 ON p1.id = COALESCE( "
			. "(SELECT MIN(x.id) FROM {$socios} x WHERE x.rol_familia = 'principal' AND COALESCE(NULLIF(x.familia_id, 0), x.id) = COALESCE(NULLIF(f.familia_id, 0), NULLIF(s0.familia_id, 0), s0.id)), "
			. "s0.id) "
			. "LEFT JOIN {$socios} p2 ON p2.id = "
			. "(SELECT MIN(y.id) FROM {$socios} y WHERE y.rol_familia = 'secundario' AND y.id <> p1.id AND COALESCE(NULLIF(y.familia_id, 0), y.id) = COALESCE(NULLIF(p1.familia_id, 0), p1.id)) ";
	}
}
