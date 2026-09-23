<?php
/**
 * Enrolment (matrícula) states in one place (1.68.0).
 *
 *   activo               occupies a place in the group
 *   lista_espera         waiting for a place (posicion = order in the activity)
 *   oferta               a freed place was offered (3-day token)
 *   baixa_solicitada     the family asked to leave; the junta must confirm
 *   pendente_aprobacion  requested while the trimester window was CLOSED;
 *                        the junta approves (→ activo / lista_espera) or
 *                        rejects (→ baixa), or the next trimester activation
 *                        approves them all at once            (1.68.0)
 *   baixa                terminal
 *
 * Pure PHP: no database, no WordPress besides __() for labels.
 *
 * @since  1.68.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Matricula_Estado {

	const ACTIVO              = 'activo';
	const LISTA_ESPERA        = 'lista_espera';
	const OFERTA              = 'oferta';
	const BAIXA_SOLICITADA    = 'baixa_solicitada';
	const PENDENTE_APROBACION = 'pendente_aprobacion';
	const BAIXA               = 'baixa';

	/** Every state, in enum order. */
	const TODOS = array( self::ACTIVO, self::LISTA_ESPERA, self::OFERTA, self::BAIXA_SOLICITADA, self::PENDENTE_APROBACION, self::BAIXA );

	/** Everything but baixa: rows that still matter for the family and the junta. */
	const VIXENTES = array( self::ACTIVO, self::LISTA_ESPERA, self::OFERTA, self::BAIXA_SOLICITADA, self::PENDENTE_APROBACION );

	/** The pre-1.68.0 «vixentes» list, for the few readers that must ignore pending requests. */
	const VIXENTES_LEGADO = array( self::ACTIVO, self::LISTA_ESPERA, self::OFERTA, self::BAIXA_SOLICITADA );

	/**
	 * @param  string $estado Candidate.
	 * @return bool
	 */
	public static function valido( string $estado ): bool {
		return in_array( $estado, self::TODOS, true );
	}

	/**
	 * Quoted, comma-separated list for an SQL IN (...). ASCII only.
	 *
	 * @param  array<int,string> $estados States.
	 * @return string
	 */
	public static function sql_in( array $estados ): string {
		$out = array();
		foreach ( $estados as $e ) {
			if ( self::valido( (string) $e ) ) {
				$out[] = "'" . $e . "'";
			}
		}
		return implode( ',', $out );
	}

	/**
	 * Where an approved pending request lands: a place when the group is open
	 * and below its maximum, the waiting list otherwise.
	 *
	 * @param  string $estado_grupo aberto|pechado|deshabilitado.
	 * @param  int    $activos      Current active enrolments in the group.
	 * @param  int    $max_pupilos  Group maximum.
	 * @return string ACTIVO|LISTA_ESPERA
	 */
	public static function destino_aprobacion( string $estado_grupo, int $activos, int $max_pupilos ): string {
		if ( 'aberto' === $estado_grupo && $max_pupilos > 0 && $activos < $max_pupilos ) {
			return self::ACTIVO;
		}
		return self::LISTA_ESPERA;
	}

	/**
	 * A family may withdraw a request only while it is pending approval; every
	 * other state goes through the baixa flows.
	 *
	 * @param  string $estado Current state.
	 * @return bool
	 */
	public static function pode_retirar( string $estado ): bool {
		return self::PENDENTE_APROBACION === $estado;
	}

	/**
	 * @param  string $estado State.
	 * @return string Galician label (the raw state when unknown).
	 */
	public static function etiqueta( string $estado ): string {
		$labels = array(
			self::ACTIVO              => __( 'Activa', 'anpa-socios' ),
			self::LISTA_ESPERA        => __( 'Lista de espera', 'anpa-socios' ),
			self::OFERTA              => __( 'Oferta de praza', 'anpa-socios' ),
			self::BAIXA_SOLICITADA    => __( 'Baixa solicitada', 'anpa-socios' ),
			self::PENDENTE_APROBACION => __( 'Pendente de aprobación', 'anpa-socios' ),
			self::BAIXA               => __( 'Baixa', 'anpa-socios' ),
		);
		return $labels[ $estado ] ?? $estado;
	}
}
