<?php
/**
 * When does a confirmed activity baixa take effect, and is anything charged?
 *
 * Rule agreed with the junta (1.62.0):
 *  - The running trimester has STARTED (its row in Axustes → Cursos → Estado dos
 *    trimestres is «activo»): the baixa is effective at the END of that
 *    trimester; the activity and its fee stand until then and the next
 *    trimester is not charged.
 *  - Otherwise (pre-enrolment: enrolments at the start of the course with the
 *    groups not yet closed, trimester still «pendente» or not configured): the
 *    baixa is immediate and nothing is charged.
 *
 * Pure: takes the gate evaluation, the trimester rows and the course dates;
 * the caller (admin grupos handler) loads them.
 *
 * @since   1.62.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Baixa_Extraescolar_Efectos {

	const FIN_TRIMESTRE = 'fin_trimestre';
	const INMEDIATA     = 'inmediata';

	/**
	 * Evaluates the rule.
	 *
	 * @param  array<string,mixed>       $gate       ANPA_Socios_Matricula_Gate::avaliar() result (needs 'trimestre').
	 * @param  array<int,array>          $trimestres ANPA_Socios_Trimestre_Repo::for_curso() rows (estado per trimester).
	 * @param  array<string,string>|null $datas      ANPA_Socios_Matricula_Gate::datas_de_fila() or null.
	 * @return array{efecto:string,trimestre:int,data_fin:string,texto:string}
	 */
	public static function avaliar( array $gate, array $trimestres, ?array $datas ): array {
		$tri    = (int) ( $gate['trimestre'] ?? 0 );
		$fila   = $trimestres[ $tri ] ?? array();
		$activo = is_array( $fila ) && ANPA_Socios_Trimestre_Estado::ACTIVO === (string) ( $fila['estado'] ?? '' );

		if ( ! $activo ) {
			return array(
				'efecto'    => self::INMEDIATA,
				'trimestre' => $tri,
				'data_fin'  => '',
				'texto'     => __( 'Como estamos en período de inscrición e os grupos aínda non están pechados, a baixa é efectiva de inmediato e non se pasará ningún cobro por esta actividade.', 'anpa-socios' ),
			);
		}

		$fin = '';
		if ( is_array( $datas ) && ANPA_Socios_Calendario::ten_datas_operativas( $datas ) ) {
			$limites = ANPA_Socios_Calendario::limites( $datas );
			$fin     = (string) ( $limites[ $tri ]['fin'] ?? '' );
		}
		$fin_label = self::data_label( $fin );

		return array(
			'efecto'    => self::FIN_TRIMESTRE,
			'trimestre' => $tri,
			'data_fin'  => $fin,
			'texto'     => sprintf(
				/* translators: 1: trimester number, 2: " (o DD/MM/YYYY)" or empty */
				__( 'Como o %1$dº trimestre xa comezou, a baixa é efectiva ao remate do trimestre en curso%2$s: a actividade e a cota correspondente mantéñense ata esa data e non se cobrará o trimestre seguinte.', 'anpa-socios' ),
				$tri,
				'' !== $fin_label ? sprintf( __( ' (o %s)', 'anpa-socios' ), $fin_label ) : ''
			),
		);
	}

	/**
	 * Shortcut: only the sentence for the email.
	 *
	 * @param  array<string,mixed>       $gate       Gate evaluation.
	 * @param  array<int,array>          $trimestres Trimester rows.
	 * @param  array<string,string>|null $datas      Course dates.
	 * @return string
	 */
	public static function texto( array $gate, array $trimestres, ?array $datas ): string {
		return self::avaliar( $gate, $trimestres, $datas )['texto'];
	}

	/**
	 * Y-m-d → DD/MM/YYYY ('' when not a date).
	 *
	 * @param  string $ymd Date.
	 * @return string
	 */
	private static function data_label( string $ymd ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return '';
		}
		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}
}
