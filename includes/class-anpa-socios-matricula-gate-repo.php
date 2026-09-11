<?php
/**
 * wpdb glue for the single enrolment rule (E3, 1.51.0).
 *
 * Reads the course row and its trimester/window rows and evaluates
 * ANPA_Socios_Matricula_Gate. Also keeps the legacy cursos.matriculas_abertas
 * column in sync as a derived cache (listings/exports only).
 *
 * @since  1.51.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Matricula_Gate_Repo {

	const CURSO_COLUMNS = 'curso_escolar, estado, matriculas_abertas, data_inicio, data_peche, t1_peche_operativo, t2_peche_operativo';

	/**
	 * Evaluates the rule for a course (read-only, no locks). Fails closed.
	 *
	 * @param  string      $curso Curso escolar.
	 * @param  string|null $hoxe  Y-m-d to evaluate (default today).
	 * @return array{abertas:bool,trimestre:int,ventana:string,estado_curso:string,motivo:string}
	 */
	public static function para_curso( string $curso, ?string $hoxe = null ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return ANPA_Socios_Matricula_Gate::avaliar( null, array(), $hoxe );
		}
		global $wpdb;
		$table            = ANPA_Socios_DB::tabela_cursos();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only gate lookup.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::CURSO_COLUMNS . " FROM {$table} WHERE curso_escolar = %s", $curso ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return ANPA_Socios_Matricula_Gate::avaliar( null, array(), $hoxe );
		}
		return self::avaliar_fila( $row, $hoxe );
	}

	/**
	 * Evaluates the rule for an already-loaded cursos row.
	 *
	 * @param  array<string,mixed> $row  Cursos row (must contain curso_escolar, estado and the dates).
	 * @param  string|null         $hoxe Y-m-d to evaluate.
	 * @return array{abertas:bool,trimestre:int,ventana:string,estado_curso:string,motivo:string}
	 */
	public static function avaliar_fila( array $row, ?string $hoxe = null ): array {
		$curso = (string) ( $row['curso_escolar'] ?? '' );
		$tris  = ANPA_Socios_Curso_Escolar::is_valid( $curso ) ? ANPA_Socios_Trimestre_Repo::for_curso( $curso ) : array();
		return ANPA_Socios_Matricula_Gate::avaliar( $row, $tris, $hoxe );
	}

	/**
	 * Writes the derived value into the legacy column (cache for listings).
	 * Never touches actualizado_en (the active-course resolver orders by it).
	 *
	 * @param  string $curso Curso escolar.
	 * @return bool|null Derived state, or null when the course row does not exist.
	 */
	public static function sincronizar_flag( string $curso ): ?bool {
		$gate = self::para_curso( $curso );
		if ( ANPA_Socios_Matricula_Gate::MOTIVO_SEN_CURSO === $gate['motivo'] ) {
			return null;
		}
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_cursos();
		$flag  = $gate['abertas'] ? 1 : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- derived-cache write, idempotent.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET matriculas_abertas = %d WHERE curso_escolar = %s AND matriculas_abertas <> %d", $flag, $curso, $flag ) );
		return $gate['abertas'];
	}
}
