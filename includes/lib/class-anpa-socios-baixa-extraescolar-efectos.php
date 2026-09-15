<?php
/**
 * When does a confirmed activity baixa take effect, and is anything charged?
 *
 * Rule agreed with the junta (1.62.0, corrected in 1.63.0 after a wrong email
 * went out on 2026-09-15):
 *  - While the enrolment WINDOW of the running trimester is OPEN (Axustes →
 *    Cursos → Estado dos trimestres, «ventá aberta»): enrolments are still
 *    changing and the classes are not confirmed, so the baixa is effective
 *    immediately and nothing is charged. This holds in every trimester.
 *  - Once that window is CLOSED (the lists went to the companies, classes are
 *    running): the baixa is effective at the END of the running trimester; the
 *    activity and its fee stand until then and the next trimester is not
 *    charged.
 *
 * The trimester row's `estado` («activo») only says which trimester is the
 * current one — it never meant "classes started", which is what 1.62.x used.
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
	 * @param  array<string,mixed>       $gate       ANPA_Socios_Matricula_Gate::avaliar() result ('trimestre', 'abertas').
	 * @param  array<int,array>          $trimestres ANPA_Socios_Trimestre_Repo::for_curso() rows (ventana_estado per trimester).
	 * @param  array<string,string>|null $datas      ANPA_Socios_Matricula_Gate::datas_de_fila() or null.
	 * @return array{efecto:string,trimestre:int,data_fin:string,texto:string}
	 */
	public static function avaliar( array $gate, array $trimestres, ?array $datas ): array {
		$tri  = (int) ( $gate['trimestre'] ?? 0 );
		$fila = $trimestres[ $tri ] ?? array();

		// Open window = the gate says enrolments are open, or the row itself says
		// «aberta». Anything unknown (no course, no rows) also counts as not
		// closed: never announce a charge the junta cannot back.
		$ventana_aberta = ! empty( $gate['abertas'] )
			|| ( is_array( $fila ) && ANPA_Socios_Ventana_Estado::ABERTA === (string) ( $fila['ventana_estado'] ?? '' ) );
		$ventana_pechada = is_array( $fila ) && ! empty( $fila['presente'] )
			&& ANPA_Socios_Ventana_Estado::PECHADA === (string) ( $fila['ventana_estado'] ?? '' );

		if ( $ventana_aberta || ! $ventana_pechada ) {
			$texto = $tri > 0
				/* translators: %d: trimester number */
				? sprintf( __( 'Como a ventá de inscrición do %dº trimestre segue aberta e as clases aínda non están confirmadas, a baixa é efectiva desde este momento e non se pasará ningún cobro por esta actividade.', 'anpa-socios' ), $tri )
				: __( 'Como o período de inscrición segue aberto e as clases aínda non están confirmadas, a baixa é efectiva desde este momento e non se pasará ningún cobro por esta actividade.', 'anpa-socios' );
			return array(
				'efecto'    => self::INMEDIATA,
				'trimestre' => $tri,
				'data_fin'  => '',
				'texto'     => $texto,
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
				__( 'Como a inscrición do %1$dº trimestre xa está pechada e as clases en marcha, a baixa é efectiva ao remate do trimestre en curso%2$s: a actividade e a cota correspondente mantéñense ata esa data e non se cobrará o trimestre seguinte.', 'anpa-socios' ),
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
