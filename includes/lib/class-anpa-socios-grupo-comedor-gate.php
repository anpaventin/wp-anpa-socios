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
		$horarios_t = ANPA_Socios_DB::tabela_horarios_comedor();
		$lock_sql   = $lock ? ' FOR UPDATE' : '';
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT n.id, n.codigo, n.curso_escolar, h.inicio AS comedor_inicio, h.fin AS comedor_fin
			 FROM {$niveis_t} n
			 LEFT JOIN {$horarios_t} h ON h.id = n.horario_comedor_id AND h.curso_escolar = n.curso_escolar AND h.estado = 'activo'
			 WHERE n.id IN ({$placeholders}) AND n.estado = 'activo'
			 ORDER BY n.id{$lock_sql}",
			...$nivel_ids
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido comprobar a dispoñibilidade dos niveis.', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$meals = array();
		$meta  = array();
		foreach ( $rows as $row ) {
			$nivel_id = (int) ( $row['id'] ?? 0 );
			$curso     = (string) ( $row['curso_escolar'] ?? '' );
			if ( ! isset( $expected[ $nivel_id ] ) || $expected[ $nivel_id ] !== $curso ) {
				return new WP_Error( 'anpa_admin_grupo_niveis', __( 'Os niveis seleccionados deben pertencer ao curso escolar correspondente.', 'anpa-socios' ), array( 'status' => 400 ) );
			}
			$interval = ANPA_Socios_Disponibilidade_Horaria::normalize_interval(
				$row['comedor_inicio'] ?? null,
				$row['comedor_fin'] ?? null
			);
			if ( null === $interval ) {
				return new WP_Error( 'anpa_admin_db_error', __( 'Hai un horario de comedor inválido na estrutura escolar.', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			$meta[ $nivel_id ]  = array( 'curso_escolar' => $curso, 'nivel' => (string) ( $row['codigo'] ?? '' ) );
			$meals[ $nivel_id ] = $interval;
		}
		if ( count( $rows ) !== count( $expected ) ) {
			return new WP_Error( 'anpa_admin_grupo_niveis', __( 'Non se atoparon todos os niveis seleccionados.', 'anpa-socios' ), array( 'status' => 400 ) );
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
