<?php
/**
 * Pure helper: collapses (actividad, curso_escolar) rows into one row per
 * activity, following design §8.7 of fase23-estrutura-escolar-parametrizable.
 *
 * No WordPress dependency, no I/O. Takes plain arrays (as would come from
 * wpdb::get_results( ..., ARRAY_A )) and returns the collapsed admin
 * listing rows, so the logic is unit-testable without a database.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Collapses actividades_cursos fan-out into one row per activity.
 *
 * @since 23.0.0
 */
final class ANPA_Socios_Actividades_Collapse {

	/**
	 * Builds the collapsed admin listing rows.
	 *
	 * For each base activity, aggregates every `actividades_cursos` row it
	 * has into a chronologically sorted, deduplicated `cursos_ofertados`
	 * array, and chooses a single "source" annual row to populate the
	 * displayed franxa/horarios/grupos/dias/min_pupilos/max_pupilos/custo/
	 * estado/prazas_* fields:
	 *
	 *   1. the row for `$curso_activo`, if the activity offers that year;
	 *   2. otherwise the row for the most recent year in `cursos_ofertados`;
	 *   3. otherwise (no annual rows at all — legacy activity) the schedule
	 *      and capacity fields stay EMPTY: legacy `actividades` columns are
	 *      no longer a functional source (fase24 PR-GA5).
	 *
	 * @since  23.0.0
	 * @param  array<int,array<string,mixed>> $base_rows     Base `actividades` rows (one per activity id).
	 * @param  array<int,array<string,mixed>> $acy_rows      Every `actividades_cursos` row for those
	 *                                                        activities, pre-sorted by `curso_escolar` ASC.
	 * @param  string|null                     $curso_activo  Currently active school year, or null.
	 * @param  array<int,array<string,mixed>> $scoped_counts Rows of `{actividad_id, curso_escolar, estado, total}`
	 *                                                        — enrolment counts scoped to a specific annual row.
	 * @param  array<int,array<string,mixed>> $legacy_totals Rows of `{actividad_id, estado, total}` — unscoped
	 *                                                        enrolment totals, used only for legacy activities
	 *                                                        with zero `actividades_cursos` rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function collapse( array $base_rows, array $acy_rows, ?string $curso_activo, array $scoped_counts, array $legacy_totals ): array {
		$acy_by_actividad = array();
		foreach ( $acy_rows as $acy ) {
			$acy_by_actividad[ (int) $acy['actividad_id'] ][] = $acy;
		}

		$scoped_map = array();
		foreach ( $scoped_counts as $c ) {
			$aid       = (int) $c['actividad_id'];
			$curso_key = isset( $c['curso_escolar'] ) && null !== $c['curso_escolar'] ? (string) $c['curso_escolar'] : '';
			$scoped_map[ $aid ][ $curso_key ][ (string) $c['estado'] ] = (int) $c['total'];
		}

		$legacy_map = array();
		foreach ( $legacy_totals as $c ) {
			$legacy_map[ (int) $c['actividad_id'] ][ (string) $c['estado'] ] = (int) $c['total'];
		}

		$rows = array();
		foreach ( $base_rows as $base ) {
			$aid   = (int) $base['id'];
			$years = $acy_by_actividad[ $aid ] ?? array();

			// $years is assumed ASC by curso_escolar; array_unique keeps the
			// first (earliest) occurrence, so order stays chronological.
			$cursos_ofertados = array_values( array_unique( array_column( $years, 'curso_escolar' ) ) );

			$source = self::pick_source_row( $years, $curso_activo );

			$row = array(
				'id'               => $aid,
				'empresa_id'       => (int) $base['empresa_id'],
				'nome'             => $base['nome'],
				'icono'            => $base['icono'] ?? '',
				'descripcion'      => $base['descripcion'],
				'curso_escolar'    => null !== $source ? $source['curso_escolar'] : '',
				'franxa'           => null !== $source ? $source['franxa'] : '',
				'horarios'         => null !== $source ? $source['horarios'] : '',
				'grupos'           => null !== $source ? $source['grupos'] : '',
				'dias'             => null !== $source ? $source['dias'] : '',
				'min_pupilos'      => null !== $source ? (int) $source['min_pupilos'] : 0,
				'max_pupilos'      => null !== $source ? (int) $source['max_pupilos'] : 0,
				'custo'            => (float) ( null !== $source ? $source['custo'] : $base['custo'] ),
				'estado'           => null !== $source ? $source['estado'] : $base['estado'],
				// nivel_min_id/nivel_max_id (PR-ES9 task 84) live ONLY on
				// actividades_cursos — a legacy activity with no annual row
				// has no per-year niveis configured, so this is null, never
				// a fallback to curso_min/curso_max (different concept).
				'nivel_min_id'     => null !== $source ? self::nullable_int( $source['nivel_min_id'] ?? null ) : null,
				'nivel_max_id'     => null !== $source ? self::nullable_int( $source['nivel_max_id'] ?? null ) : null,
				'cursos_ofertados' => $cursos_ofertados,
			);

			if ( null !== $source ) {
				$curso_key              = (string) $source['curso_escolar'];
				$row['prazas_ocupadas'] = $scoped_map[ $aid ][ $curso_key ]['activo'] ?? 0;
				$row['prazas_espera']   = $scoped_map[ $aid ][ $curso_key ]['lista_espera'] ?? 0;
			} else {
				// Legacy activity without any actividades_cursos row: count
				// every matricula, unscoped by school year (fallback parity
				// with the previous COALESCE-based query).
				$row['prazas_ocupadas'] = $legacy_map[ $aid ]['activo'] ?? 0;
				$row['prazas_espera']   = $legacy_map[ $aid ]['lista_espera'] ?? 0;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Normalizes a possibly-null/possibly-string DB value into an int|null.
	 *
	 * @since  1.40.0
	 * @param  mixed $value Raw value from a wpdb row (may be null, '', or numeric string).
	 * @return int|null
	 */
	private static function nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Picks the source annual row for a single activity's `$years` list.
	 *
	 * @since  23.0.0
	 * @param  array<int,array<string,mixed>> $years        Annual rows for one activity, ASC by curso_escolar.
	 * @param  string|null                     $curso_activo Currently active school year, or null.
	 * @return array<string,mixed>|null
	 */
	private static function pick_source_row( array $years, ?string $curso_activo ) {
		if ( array() === $years ) {
			return null;
		}

		if ( null !== $curso_activo ) {
			foreach ( $years as $y ) {
				if ( $y['curso_escolar'] === $curso_activo ) {
					return $y;
				}
			}
		}

		// Fallback: most recent year. $years is ASC, so the last element is
		// the most recent.
		return $years[ count( $years ) - 1 ];
	}
}
