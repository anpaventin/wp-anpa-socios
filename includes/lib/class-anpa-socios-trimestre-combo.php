<?php
/**
 * Planner for the two combos of Xestión → Extraescolares → Matrículas (1.68.0):
 *
 *   «Trimestre activo»          1º | 2º | 3º | Curso pechado
 *   «Matrículas abertas para»   1º | 2º | 3º | Pechadas
 *
 * Works on the rows returned by ANPA_Socios_Trimestre_Repo::for_curso() and
 * emits the ordered list of single transitions the repo must apply, each one
 * legal under ANPA_Socios_Trimestre_Estado / ANPA_Socios_Ventana_Estado, so
 * the transition log stays truthful. Invariant introduced here: at most one
 * window open at a time.
 *
 * Pure PHP: no database, no WordPress besides __() for labels.
 *
 * @since  1.68.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Trimestre_Combo {

	/** Combo value meaning «every trimester closed, course closed». */
	const CURSO_PECHADO = 'pechado';

	/**
	 * Current position of both combos.
	 *
	 * @param  array<int,array{estado:string,ventana_estado:string,presente:bool}> $rows Repo rows.
	 * @return array{trimestre_activo:int|string,ventana_aberta:int,inicializado:bool}
	 */
	public static function resumo( array $rows ): array {
		$inicializado = true;
		$activo       = 0;
		$pechados     = 0;
		$ventana      = 0;
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$row = $rows[ $tri ] ?? array();
			if ( empty( $row['presente'] ) ) {
				$inicializado = false;
				continue;
			}
			$estado = (string) ( $row['estado'] ?? '' );
			if ( ANPA_Socios_Trimestre_Estado::ACTIVO === $estado ) {
				$activo = $tri; // the highest active one wins when data is inconsistent.
			} elseif ( ANPA_Socios_Trimestre_Estado::PECHADO === $estado ) {
				++$pechados;
			}
			if ( 0 === $ventana && ANPA_Socios_Ventana_Estado::ABERTA === (string) ( $row['ventana_estado'] ?? '' ) ) {
				$ventana = $tri;
			}
		}
		if ( ! $inicializado ) {
			return array( 'trimestre_activo' => '', 'ventana_aberta' => 0, 'inicializado' => false );
		}
		$trimestre_activo = $activo > 0 ? $activo : ( 3 === $pechados ? self::CURSO_PECHADO : '' );
		return array( 'trimestre_activo' => $trimestre_activo, 'ventana_aberta' => $ventana, 'inicializado' => true );
	}

	/**
	 * Transitions needed so that exactly `$destino` is active (or every
	 * trimester is closed). Earlier trimesters end «pechado», the target ends
	 * «activo», later ones are left alone unless they are active. A pending
	 * trimester that must end closed goes through «activo» first (the only
	 * legal path).
	 *
	 * @param  array<int,array{estado:string,ventana_estado:string,presente:bool}> $rows    Repo rows.
	 * @param  int|string                                                          $destino 1..3 or CURSO_PECHADO.
	 * @return array<int,array{0:int,1:string,2:string}>|null Ordered [tri, from, to]; null on invalid input.
	 */
	public static function plan_estado( array $rows, $destino ): ?array {
		$pechar_todo = self::CURSO_PECHADO === $destino;
		$tri_destino = $pechar_todo ? 0 : ( is_int( $destino ) || ctype_digit( (string) $destino ) ? (int) $destino : -1 );
		if ( ! $pechar_todo && ( $tri_destino < 1 || $tri_destino > 3 ) ) {
			return null;
		}
		$resumo = self::resumo( $rows );
		if ( ! $resumo['inicializado'] ) {
			return null;
		}

		$plan = array();
		// 1) A step back: close the LATER active trimester first, so «one active at
		//    a time» holds while the plan runs.
		if ( ! $pechar_todo ) {
			foreach ( array( 1, 2, 3 ) as $tri ) {
				if ( $tri > $tri_destino && ANPA_Socios_Trimestre_Estado::ACTIVO === (string) $rows[ $tri ]['estado'] ) {
					$plan[] = array( $tri, ANPA_Socios_Trimestre_Estado::ACTIVO, ANPA_Socios_Trimestre_Estado::PECHADO );
				}
			}
		}
		// 2) Close everything that must end closed (previous trimesters, or all). A
		//    pending one goes through «activo» first: the only legal path.
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$estado = (string) $rows[ $tri ]['estado'];
			if ( ! ( $pechar_todo || $tri < $tri_destino ) || ANPA_Socios_Trimestre_Estado::PECHADO === $estado ) {
				continue;
			}
			if ( ANPA_Socios_Trimestre_Estado::PENDENTE === $estado ) {
				$plan[] = array( $tri, ANPA_Socios_Trimestre_Estado::PENDENTE, ANPA_Socios_Trimestre_Estado::ACTIVO );
			}
			$plan[] = array( $tri, ANPA_Socios_Trimestre_Estado::ACTIVO, ANPA_Socios_Trimestre_Estado::PECHADO );
		}
		// 3) Activate the target.
		if ( ! $pechar_todo ) {
			$estado = (string) $rows[ $tri_destino ]['estado'];
			if ( ANPA_Socios_Trimestre_Estado::ACTIVO !== $estado ) {
				$plan[] = array( $tri_destino, $estado, ANPA_Socios_Trimestre_Estado::ACTIVO );
			}
		}
		return $plan;
	}

	/**
	 * Window transitions so that only `$destino` (0 = none) is open.
	 *
	 * @param  array<int,array{estado:string,ventana_estado:string,presente:bool}> $rows    Repo rows.
	 * @param  int                                                                 $destino 0..3.
	 * @return array<int,array{0:int,1:string,2:string}>|null
	 */
	public static function plan_ventana( array $rows, int $destino ): ?array {
		if ( $destino < 0 || $destino > 3 ) {
			return null;
		}
		$resumo = self::resumo( $rows );
		if ( ! $resumo['inicializado'] ) {
			return null;
		}
		$plan = array();
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$ventana = (string) $rows[ $tri ]['ventana_estado'];
			if ( $tri !== $destino && ANPA_Socios_Ventana_Estado::ABERTA === $ventana ) {
				$plan[] = array( $tri, ANPA_Socios_Ventana_Estado::ABERTA, ANPA_Socios_Ventana_Estado::PECHADA );
			}
		}
		if ( $destino > 0 && ANPA_Socios_Ventana_Estado::ABERTA !== (string) $rows[ $destino ]['ventana_estado'] ) {
			$plan[] = array( $destino, (string) $rows[ $destino ]['ventana_estado'], ANPA_Socios_Ventana_Estado::ABERTA );
		}
		return $plan;
	}

	/**
	 * @param  int|string $destino Combo value.
	 * @return string
	 */
	public static function etiqueta_destino( $destino ): string {
		if ( self::CURSO_PECHADO === $destino ) {
			return __( 'Curso pechado', 'anpa-socios' );
		}
		/* translators: %d: trimester number */
		return sprintf( __( '%dº trimestre', 'anpa-socios' ), (int) $destino );
	}

	/**
	 * @param  int $destino 0..3.
	 * @return string
	 */
	public static function etiqueta_ventana( int $destino ): string {
		if ( $destino < 1 ) {
			return __( 'Matrículas pechadas', 'anpa-socios' );
		}
		/* translators: %d: trimester number */
		return sprintf( __( 'Abertas para o %dº trimestre', 'anpa-socios' ), $destino );
	}
}
