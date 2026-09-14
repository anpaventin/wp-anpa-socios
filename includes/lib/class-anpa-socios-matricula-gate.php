<?php
/**
 * Single enrolment rule (E3, 1.51.0).
 *
 *   matrículas abertas  ⇔  curso.estado = activo  ∧  ventá do trimestre actual = aberta
 *
 * The trimester is derived from the course's operative dates (fallback: month
 * model) and the window state comes from wp_anpa_curso_trimestres. The legacy
 * `cursos.matriculas_abertas` column is kept only as a derived cache for
 * listings/exports; no code decides on it anymore.
 *
 * Pure PHP: no WordPress dependency besides __() for labels.
 *
 * @since  1.51.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Matricula_Gate {

	const MOTIVO_ABERTAS                  = 'abertas';
	const MOTIVO_SEN_CURSO                = 'sen_curso';
	const MOTIVO_CURSO_NON_ACTIVO         = 'curso_non_activo';
	const MOTIVO_TRIMESTRE_SEN_CONFIGURAR = 'trimestre_sen_configurar';
	const MOTIVO_VENTANA_PECHADA          = 'ventana_pechada';

	/**
	 * Extracts the operative calendar from a cursos row (MySQL zero dates → '').
	 *
	 * @param  array<string,mixed> $row Cursos row.
	 * @return array{inicio:string,t1:string,t2:string,peche:string}
	 */
	public static function datas_de_fila( array $row ): array {
		$clean = static function ( $v ): string {
			$v = trim( (string) $v );
			return ( '' === $v || '0000-00-00' === $v ) ? '' : $v;
		};
		return array(
			'inicio' => $clean( $row['data_inicio'] ?? '' ),
			't1'     => $clean( $row['t1_peche_operativo'] ?? '' ),
			't2'     => $clean( $row['t2_peche_operativo'] ?? '' ),
			'peche'  => $clean( $row['data_peche'] ?? '' ),
		);
	}

	/**
	 * Evaluates the rule. Fails closed on any missing piece.
	 *
	 * @param  array<string,mixed>|null $curso_row  Cursos row (estado + dates) or null when unknown.
	 * @param  array<int,array>         $trimestres Rows as ANPA_Socios_Trimestre_Repo::for_curso() returns them.
	 * @param  string|null              $hoxe       Y-m-d to evaluate (default today, UTC).
	 * @return array{abertas:bool,trimestre:int,ventana:string,estado_curso:string,motivo:string}
	 */
	public static function avaliar( ?array $curso_row, array $trimestres, ?string $hoxe = null ): array {
		$out = array( 'abertas' => false, 'trimestre' => 0, 'ventana' => '', 'estado_curso' => '', 'motivo' => self::MOTIVO_SEN_CURSO );
		if ( ! is_array( $curso_row ) ) {
			return $out;
		}
		$out['estado_curso'] = (string) ( $curso_row['estado'] ?? '' );
		$out['trimestre']    = ANPA_Socios_Trimestre::actual_por_datas( self::datas_de_fila( $curso_row ), $hoxe );

		if ( ANPA_Socios_Season::ESTADO_ACTIVO !== $out['estado_curso'] ) {
			$out['motivo'] = self::MOTIVO_CURSO_NON_ACTIVO;
			return $out;
		}
		$fila = $trimestres[ $out['trimestre'] ] ?? null;
		if ( ! is_array( $fila ) || empty( $fila['presente'] ) ) {
			$out['motivo'] = self::MOTIVO_TRIMESTRE_SEN_CONFIGURAR;
			return $out;
		}
		$out['ventana'] = (string) ( $fila['ventana_estado'] ?? ANPA_Socios_Ventana_Estado::PECHADA );
		if ( ANPA_Socios_Ventana_Estado::ABERTA !== $out['ventana'] ) {
			$out['motivo'] = self::MOTIVO_VENTANA_PECHADA;
			return $out;
		}
		$out['abertas'] = true;
		$out['motivo']  = self::MOTIVO_ABERTAS;
		return $out;
	}

	/**
	 * User-facing summary of an evaluation.
	 *
	 * @param  array<string,mixed> $gate Result of avaliar().
	 * @return string
	 */
	public static function etiqueta( array $gate ): string {
		$tri = (int) ( $gate['trimestre'] ?? 0 );
		switch ( (string) ( $gate['motivo'] ?? '' ) ) {
			case self::MOTIVO_ABERTAS:
				/* translators: %d: trimester number */
				return sprintf( __( 'Matrículas ABERTAS (ventá do %dº trimestre aberta)', 'anpa-socios' ), $tri );
			case self::MOTIVO_VENTANA_PECHADA:
				/* translators: %d: trimester number */
				return sprintf( __( 'Matrículas PECHADAS (ventá do %dº trimestre pechada)', 'anpa-socios' ), $tri );
			case self::MOTIVO_TRIMESTRE_SEN_CONFIGURAR:
				/* translators: %d: trimester number */
				return sprintf( __( 'Matrículas PECHADAS: o %dº trimestre non está inicializado', 'anpa-socios' ), $tri );
			case self::MOTIVO_CURSO_NON_ACTIVO:
				return __( 'Matrículas PECHADAS: o curso non está activo', 'anpa-socios' );
			default:
				return __( 'Matrículas PECHADAS: curso sen configurar', 'anpa-socios' );
		}
	}

	/**
	 * Notice shown next to the enrolment listings (company/canteen panel and
	 * Xestión → Matrículas) so the reader knows whether the list can still
	 * change: while the current trimester window is open, families may enrol
	 * or withdraw and the list varies; once closed it is stable.
	 *
	 * Pure: no DB, no dates — everything comes from avaliar().
	 *
	 * @since  1.60.0
	 * @param  array<string,mixed> $gate Result of avaliar().
	 * @return array{estado:string,trimestre:int,titulo:string,texto:string}
	 */
	public static function aviso_listado( array $gate ): array {
		$tri       = (int) ( $gate['trimestre'] ?? 0 );
		$motivo    = (string) ( $gate['motivo'] ?? '' );
		$tri_label = $tri > 0
			/* translators: %d: trimester number */
			? sprintf( __( '%dº trimestre', 'anpa-socios' ), $tri )
			: __( 'Trimestre sen determinar', 'anpa-socios' );

		if ( self::MOTIVO_ABERTAS === $motivo && ! empty( $gate['abertas'] ) ) {
			return array(
				'estado'    => 'abertas',
				'trimestre' => $tri,
				/* translators: %s: trimester label ("2º trimestre") */
				'titulo'    => sprintf( __( '%s · Matrículas ABERTAS', 'anpa-socios' ), $tri_label ),
				'texto'     => __( 'O listado pode variar: mentres o prazo estea aberto admítense altas e baixas.', 'anpa-socios' ),
			);
		}

		$texto = self::MOTIVO_VENTANA_PECHADA === $motivo
			? __( 'O listado é estable: o prazo de matriculación está pechado.', 'anpa-socios' )
			: self::etiqueta( $gate );

		return array(
			'estado'    => 'pechadas',
			'trimestre' => $tri,
			/* translators: %s: trimester label ("2º trimestre") */
			'titulo'    => sprintf( __( '%s · Matrículas PECHADAS', 'anpa-socios' ), $tri_label ),
			'texto'     => $texto,
		);
	}
}
