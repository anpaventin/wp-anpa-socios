<?php
/**
 * Per-activity course summary (1.69.0, «resumo por actividade», option A of
 * openspec/changes/2026-09-23-resumo-actividades).
 *
 * For every group of an activity: how many pupils were active at the end of
 * each trimester (or right now, for the running one), how many are waiting
 * now, how many left in each trimester, pending requests and free places.
 * Counts only — no pupil or family data.
 *
 * Trimester limits come from the course's operative dates (Axustes → Cursos);
 * when they are not configured the month model of the course year is used
 * (T1 until 31/12, T2 until 31/03, T3 until the course end).
 *
 * Pure PHP: no database, no WordPress.
 *
 * @since  1.69.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Resumo_Actividade {

	/** States that do not occupy a place. */
	const SEN_PRAZA = array( 'lista_espera', 'oferta', 'pendente_aprobacion' );

	/**
	 * Trimester limits (inclusive Y-m-d) for a course.
	 *
	 * @param  string               $curso Course «AAAA/AAAA+1».
	 * @param  array<string,string> $datas inicio, t1, t2, peche (Y-m-d or '').
	 * @return array<int,array{ini:string,fin:string}>
	 */
	public static function limites( string $curso, array $datas ): array {
		$ano   = (int) substr( $curso, 0, 4 );
		$valid = static function ( $v ): bool {
			$v = (string) $v;
			return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && '0000-00-00' !== $v;
		};
		$ini   = $valid( $datas['inicio'] ?? '' ) ? (string) $datas['inicio'] : sprintf( '%d-09-01', $ano );
		$t1    = $valid( $datas['t1'] ?? '' ) ? (string) $datas['t1'] : sprintf( '%d-12-31', $ano );
		$t2    = $valid( $datas['t2'] ?? '' ) ? (string) $datas['t2'] : sprintf( '%d-03-31', $ano + 1 );
		$peche = $valid( $datas['peche'] ?? '' ) ? (string) $datas['peche'] : sprintf( '%d-06-30', $ano + 1 );
		$next  = static function ( string $d ): string {
			$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
			return $dt instanceof DateTimeImmutable ? $dt->modify( '+1 day' )->format( 'Y-m-d' ) : $d;
		};
		return array(
			1 => array( 'ini' => $ini, 'fin' => $t1 ),
			2 => array( 'ini' => $next( $t1 ), 'fin' => $t2 ),
			3 => array( 'ini' => $next( $t2 ), 'fin' => $peche ),
		);
	}

	/**
	 * @param  array<int,array{ini:string,fin:string}> $limites From limites().
	 * @param  string                                  $hoxe    Y-m-d.
	 * @return int 1..3
	 */
	public static function trimestre_actual( array $limites, string $hoxe ): int {
		if ( $hoxe <= $limites[1]['fin'] ) {
			return 1;
		}
		return $hoxe <= $limites[2]['fin'] ? 2 : 3;
	}

	/**
	 * Aggregates the groups of one activity.
	 *
	 * @param  array<int,array<string,mixed>> $grupos     Group rows: id, nome, horario, franxa, dias, min_pupilos, max_pupilos, estado, notificado, aviso_comezo_trimestre, aviso_comezo_en.
	 * @param  array<int,array<string,mixed>> $matriculas Enrolment rows: grupo_id, estado, creado_en, baixa_en.
	 * @param  array<int,array{ini:string,fin:string}> $limites From limites().
	 * @param  string                         $hoxe       Y-m-d.
	 * @return array{trimestre_actual:int,grupos:array<int,array<string,mixed>>,totais:array<int,array{activos:int|null,baixas:int|null}>,pendentes:int}
	 */
	public static function agregar( array $grupos, array $matriculas, array $limites, string $hoxe ): array {
		$actual = self::trimestre_actual( $limites, $hoxe );
		$por_grupo = array();
		foreach ( $matriculas as $m ) {
			$por_grupo[ (int) ( $m['grupo_id'] ?? 0 ) ][] = $m;
		}
		$dia = static function ( $v ): string {
			return substr( (string) $v, 0, 10 );
		};

		$out_grupos = array();
		$totais     = array( 1 => array( 'activos' => 0, 'baixas' => 0 ), 2 => array( 'activos' => 0, 'baixas' => 0 ), 3 => array( 'activos' => 0, 'baixas' => 0 ) );
		$futuros    = array();
		$pendentes  = 0;
		foreach ( $grupos as $g ) {
			$gid  = (int) ( $g['id'] ?? 0 );
			$rows = $por_grupo[ $gid ] ?? array();
			$tris = array();
			$activos_agora = 0;
			foreach ( array( 1, 2, 3 ) as $t ) {
				$ini = $limites[ $t ]['ini'];
				$fin = $limites[ $t ]['fin'];
				if ( $ini > $hoxe ) {
					$tris[ $t ]  = array( 'estado' => 'futuro', 'activos' => null, 'espera' => null, 'baixas' => null );
					$futuros[ $t ] = true;
					continue;
				}
				$fin_eff = $fin < $hoxe ? $fin : $hoxe;
				$activos = 0;
				$espera  = 0;
				$baixas  = 0;
				foreach ( $rows as $m ) {
					$estado  = (string) ( $m['estado'] ?? '' );
					$creado  = $dia( $m['creado_en'] ?? '' );
					$baixa   = null !== ( $m['baixa_en'] ?? null ) && '' !== (string) $m['baixa_en'] ? $dia( $m['baixa_en'] ) : '';
					if ( '' !== $baixa && $baixa >= $ini && $baixa <= $fin_eff ) {
						++$baixas;
					}
					if ( in_array( $estado, self::SEN_PRAZA, true ) ) {
						if ( $t === $actual && in_array( $estado, array( 'lista_espera', 'oferta' ), true ) ) {
							++$espera;
						}
						continue;
					}
					if ( $creado > $fin_eff ) {
						continue;
					}
					if ( '' === $baixa ) {
						if ( 'baixa' === $estado ) {
							continue; // left without a date: never counted as active.
						}
						++$activos;
					} elseif ( $baixa > $fin_eff ) {
						++$activos;
					}
				}
				$tris[ $t ] = array( 'estado' => $t === $actual ? 'actual' : 'pasado', 'activos' => $activos, 'espera' => $t === $actual ? $espera : null, 'baixas' => $baixas );
				$totais[ $t ]['activos'] += $activos;
				$totais[ $t ]['baixas']  += $baixas;
				if ( $t === $actual ) {
					$activos_agora = $activos;
				}
			}
			$pend_g = 0;
			foreach ( $rows as $m ) {
				if ( 'pendente_aprobacion' === (string) ( $m['estado'] ?? '' ) ) {
					++$pend_g;
				}
			}
			$pendentes += $pend_g;
			$max = (int) ( $g['max_pupilos'] ?? 0 );
			$out_grupos[] = array(
				'id'                     => $gid,
				'nome'                   => (string) ( $g['nome'] ?? '' ),
				'horario'                => (string) ( $g['horario'] ?? '' ),
				'franxa'                 => (string) ( $g['franxa'] ?? '' ),
				'dias'                   => (string) ( $g['dias'] ?? '' ),
				'min_pupilos'            => (int) ( $g['min_pupilos'] ?? 0 ),
				'max_pupilos'            => $max,
				'estado'                 => (string) ( $g['estado'] ?? '' ),
				'trimestres'             => $tris,
				'pendentes'              => $pend_g,
				'prazas_libres'          => $max > 0 ? max( 0, $max - $activos_agora ) : null,
				'notificado'             => ! empty( $g['notificado'] ),
				'aviso_comezo_trimestre' => (int) ( $g['aviso_comezo_trimestre'] ?? 0 ),
				'aviso_comezo_en'        => (string) ( $g['aviso_comezo_en'] ?? '' ),
			);
		}
		foreach ( array_keys( $futuros ) as $t ) {
			$totais[ $t ] = array( 'activos' => null, 'baixas' => null );
		}
		return array( 'trimestre_actual' => $actual, 'grupos' => $out_grupos, 'totais' => $totais, 'pendentes' => $pendentes );
	}
}
