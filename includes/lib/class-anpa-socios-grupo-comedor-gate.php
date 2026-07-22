<?php
/**
 * Canonical meal-availability gate for annual activity-group series.
 *
 * Resolves the authoritative annual meal catalogue and delegates interval
 * arithmetic to ANPA_Socios_Disponibilidade_Horaria. Callers must own an open
 * transaction; locking reads keep the selected levels and schedules stable
 * until their write commits.
 *
 * @since   1.44.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Grupo_Comedor_Gate {

	/**
	 * Checks a normalized group series against annual meal windows.
	 *
	 * @param  array<string,mixed> $payload Normalized series payload.
	 * @param  bool                $lock    Whether to lock catalogue rows.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function conflicts_for_series( array $payload, bool $lock = true ) {
		global $wpdb;

		if ( 'aberto' !== (string) ( $payload['estado'] ?? '' ) ) {
			return array();
		}

		$expected = array();
		foreach ( (array) ( $payload['cursos'] ?? array() ) as $curso ) {
			foreach ( (array) ( $payload['niveis_por_ano'][ $curso ] ?? array() ) as $nivel_id ) {
				$expected[ (int) $nivel_id ] = (string) $curso;
			}
		}
		if ( array() === $expected ) {
			return array();
		}

		$nivel_ids = array_keys( $expected );
		sort( $nivel_ids, SORT_NUMERIC );
		$placeholders = implode( ',', array_fill( 0, count( $nivel_ids ), '%d' ) );
		$niveis_t   = ANPA_Socios_DB::tabela_niveis();
		$lock_sql   = $lock ? ' FOR UPDATE' : '';

		// Levels are GLOBAL since 1.35.0 (no curso_escolar column) and their
		// comedor schedule is per-course in the wp_anpa_niveis_curso pivot since
		// 1.36.0. Validate the levels exist/active here (locking them); resolve
		// each (nivel, curso) meal window from the pivot below.
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, codigo FROM {$niveis_t} WHERE id IN ({$placeholders}) AND estado = 'activo' ORDER BY id{$lock_sql}",
			...$nivel_ids
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido comprobar a dispoñibilidade dos niveis.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( count( $rows ) !== count( $nivel_ids ) ) {
			return new WP_Error( 'anpa_admin_grupo_niveis', __( 'Non se atoparon todos os niveis seleccionados.', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$codigo_by_id = array();
		foreach ( $rows as $row ) {
			$codigo_by_id[ (int) ( $row['id'] ?? 0 ) ] = (string) ( $row['codigo'] ?? '' );
		}

		// Per (nivel, curso) meal window from the pivot. A level with no comedor
		// that course yields an empty window (never a conflict).
		$meals = array();
		$meta  = array();
		foreach ( $expected as $nivel_id => $curso ) {
			$nivel_id = (int) $nivel_id;
			$curso    = (string) $curso;
			$interval = ANPA_Socios_DB::get_nivel_comedor_interval( $nivel_id, $curso );
			$meals[ $nivel_id ] = ( null === $interval ) ? array() : $interval;
			$meta[ $nivel_id ]  = array( 'curso_escolar' => $curso, 'nivel' => $codigo_by_id[ $nivel_id ] ?? '' );
		}

		$dias = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $payload['dias'] ?? '' ) ) ) ) );
		$conflicts = ANPA_Socios_Disponibilidade_Horaria::conflicts(
			array(
				'horario' => (string) ( $payload['franxa'] ?? '' ),
				'dias'    => $dias,
			),
			$meals
		);
		foreach ( $conflicts as &$conflict ) {
			$nivel_id = (int) $conflict['nivel_id'];
			$conflict['curso_escolar'] = $meta[ $nivel_id ]['curso_escolar'] ?? '';
			$conflict['nivel']          = $meta[ $nivel_id ]['nivel'] ?? '';
			$conflict['franxa']         = (string) ( $payload['franxa'] ?? '' );
			$conflict['dias']           = $dias;
		}
		unset( $conflict );

		return $conflicts;
	}
}
