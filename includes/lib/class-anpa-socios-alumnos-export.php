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
				. "f.nome, f.apelidos, f.curso, f.aula, m.comedor, m.tarde, f.socio_email "
				. "FROM {$matriculas} m "
				. "JOIN {$fillos} f ON f.id = m.fillo_id "
				. "JOIN {$actividades} a ON a.id = m.activitad_id "
				. "JOIN {$empresas} e ON e.id = a.empresa_id "
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
					. "f.nome, f.apelidos, f.curso, f.aula, m.comedor, m.tarde, f.socio_email "
					. "FROM {$matriculas} m "
					. "JOIN {$fillos} f ON f.id = m.fillo_id "
					. "JOIN {$actividades} a ON a.id = m.activitad_id "
					. "WHERE a.empresa_id = %d AND m.estado = 'activo' "
					. "ORDER BY a.nome, f.apelidos, f.nome",
					$empresa_id
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : null;
	}
}
