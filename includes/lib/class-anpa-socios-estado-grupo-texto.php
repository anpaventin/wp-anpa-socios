<?php
/**
 * 1.93.0: the sentence that tells a family (or the junta) the real state of a
 * group in the enrolment emails. Families were told «ten praza» in a group
 * that was still «sen mínimo» or not created, with no hint it was not formed.
 *
 * Pure (no DB): ANPA_Socios_Email resolves the group and calls this.
 *
 * @package ANPA_Socios
 * @since   1.93.0
 */

declare(strict_types=1);

final class ANPA_Socios_Estado_Grupo_Texto {

	/** Group created (start notice sent or marked) and running. */
	const EN_MARCHA = 'en_marcha';
	/** Open but not created yet (waiting for the groups to be formed). */
	const SEN_CREAR = 'sen_crear';
	/** Not created and below its minimum: nobody is charged. */
	const SEN_MINIMO = 'sen_minimo';

	/** What the email is about. */
	const PRAZA    = 'praza';
	const ESPERA   = 'espera';
	const PENDENTE = 'pendente';
	const OFERTA   = 'oferta';

	/**
	 * @param  string $estado_grupo Group row estado (aberto, sen_minimo, pechado…).
	 * @param  bool   $creado       ANPA_Socios_Aviso_Matricula::grupo_creado().
	 * @return string One of the class constants.
	 */
	public static function clave( string $estado_grupo, bool $creado ): string {
		if ( 'sen_minimo' === $estado_grupo ) {
			return self::SEN_MINIMO;
		}
		return $creado ? self::EN_MARCHA : self::SEN_CREAR;
	}

	/**
	 * Sentence for the family email. Empty when there is nothing to add.
	 *
	 * @param  string $clave   clave().
	 * @param  string $momento PRAZA | ESPERA | PENDENTE | OFERTA.
	 * @return string
	 */
	public static function familia( string $clave, string $momento ): string {
		if ( self::SEN_MINIMO === $clave ) {
			$base = __( 'Tede en conta que o grupo aínda non chega ao mínimo de alumnado necesario, polo que de momento non comeza e non se cobra nada.', 'anpa-socios' );
			return $base . ' ' . ( self::PRAZA === $momento
				? __( 'A praza queda reservada: avisarémosvos se o grupo se forma ou se finalmente non se pode formar.', 'anpa-socios' )
				: __( 'Avisarémosvos se o grupo se forma ou se finalmente non se pode formar.', 'anpa-socios' ) );
		}
		if ( self::SEN_CREAR === $clave ) {
			return self::PRAZA === $momento
				? __( 'O grupo aínda non está confirmado: a praza queda reservada á espera de formar o grupo, e avisarémosvos por correo do comezo e do horario.', 'anpa-socios' )
				: __( 'O grupo aínda non está confirmado: fórmase cando hai alumnado suficiente, e avisarémosvos por correo do comezo e do horario.', 'anpa-socios' );
		}
		if ( self::PRAZA === $momento ) {
			return __( 'O grupo xa está en marcha, así que podedes incorporarvos ás clases. Avisamos tamén á empresa que imparte a actividade e ao persoal de comedor.', 'anpa-socios' );
		}
		return '';
	}

	/**
	 * Short label with the numbers, only for the junta.
	 *
	 * @param  string $clave   clave().
	 * @param  int    $ocupan  Places taken (ANPA_Socios_Lista_Espera::ocupadas()).
	 * @param  int    $minimo  min_pupilos (0 = none).
	 * @param  int    $maximo  max_pupilos (0 = no limit).
	 * @param  int    $espera  Rows in the waiting list.
	 * @return string e.g. «Sen mínimo: 3 con praza (mínimo 6, máximo 12), 0 en lista de espera».
	 */
	public static function xunta( string $clave, int $ocupan, int $minimo, int $maximo, int $espera ): string {
		$labels = array(
			self::EN_MARCHA  => __( 'En marcha', 'anpa-socios' ),
			self::SEN_CREAR  => __( 'Aínda non creado', 'anpa-socios' ),
			self::SEN_MINIMO => __( 'Sen mínimo', 'anpa-socios' ),
		);
		$limites = array();
		if ( $minimo > 0 ) {
			/* translators: %d: group minimum */
			$limites[] = sprintf( __( 'mínimo %d', 'anpa-socios' ), $minimo );
		}
		$limites[] = $maximo > 0
			/* translators: %d: group maximum */
			? sprintf( __( 'máximo %d', 'anpa-socios' ), $maximo )
			: __( 'sen máximo', 'anpa-socios' );
		return sprintf(
			/* translators: 1: state label, 2: places taken, 3: limits, 4: waiting list size */
			__( '%1$s: %2$d con praza (%3$s), %4$d en lista de espera', 'anpa-socios' ),
			$labels[ $clave ] ?? $labels[ self::SEN_CREAR ],
			$ocupan,
			implode( ', ', $limites ),
			$espera
		);
	}

	/**
	 * 1.93.1: when an offered place starts (sentence for the offer email).
	 *
	 * @param  int    $para_trimestre 0 = the place is free now; N = it frees at the end of the
	 *                                current trimester and starts with trimester N.
	 * @param  string $inicio         Y-m-d start of trimester N ('' when unknown).
	 * @return string
	 */
	public static function comezo_oferta( int $para_trimestre, string $inicio = '' ): string {
		if ( $para_trimestre < 1 ) {
			return __( 'Se a aceptades, a praza é desde agora: comeza inmediatamente.', 'anpa-socios' );
		}
		$data = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $inicio, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
		return sprintf(
			/* translators: 1: trimester number, 2: " (o DD/MM/AAAA)" or empty */
			__( 'A praza queda libre ao remate deste trimestre: se a aceptades, comezará a principios do %1$dº trimestre%2$s.', 'anpa-socios' ),
			$para_trimestre,
			'' !== $data ? sprintf( __( ' (o %s)', 'anpa-socios' ), $data ) : ''
		);
	}

	/**
	 * 1.93.1: confirmation of a place that starts with a later trimester.
	 *
	 * @param  int    $trimestre Trimester the place starts with.
	 * @param  string $inicio    Y-m-d first day of that trimester ('' when unknown).
	 * @return string
	 */
	public static function comezo_praza( int $trimestre, string $inicio = '' ): string {
		$data = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $inicio, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
		return sprintf(
			/* translators: 1: trimester number, 2: " (o DD/MM/AAAA)" or empty */
			__( 'A praza é desde o %1$dº trimestre: comezaredes a principios dese trimestre%2$s. Avisamos tamén á empresa que imparte a actividade e ao persoal de comedor.', 'anpa-socios' ),
			$trimestre,
			'' !== $data ? sprintf( __( ' (o %s)', 'anpa-socios' ), $data ) : ''
		);
	}
}
