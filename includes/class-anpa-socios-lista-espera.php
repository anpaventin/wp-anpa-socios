<?php
/**
 * Waiting list of a group (1.85.0): places taken (an offer and an accepted
 * offer waiting for the junta keep the place), positions numbered PER GROUP
 * and trimester (1..N, waiting rows only), and the «end of the list» move used
 * when a family lets an offer expire or turns it down.
 *
 * @since   1.85.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Group waiting list.
 *
 * @since 1.85.0
 */
final class ANPA_Socios_Lista_Espera {

	/**
	 * SQL condition (ASCII) for the rows that hold a place in the group: active,
	 * an outstanding offer, and an accepted offer waiting for the junta.
	 */
	const SQL_OCUPAN = "( estado IN ('activo','oferta','baixa_solicitada') OR ( estado = 'pendente_aprobacion' AND oferta_aceptada_en IS NOT NULL ) )";

	/**
	 * A family that let an offer go is not offered a place again for this long
	 * (the end of its last offer is kept in oferta_expira of its waiting row).
	 */
	const DESCANSO_DIAS = 3;

	/**
	 * Places taken in a group (every trimester, like the public offer).
	 *
	 * @param  int $grupo_id Group id.
	 * @param  int $excluir  Matrícula not to count (e.g. the one being moved).
	 * @return int
	 */
	public static function ocupadas( int $grupo_id, int $excluir = 0 ): int {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant condition.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$mat_t} WHERE grupo_id = %d AND id <> %d AND " . self::SQL_OCUPAN, $grupo_id, $excluir ) );
	}

	/**
	 * Waiting rows of a group (any trimester).
	 *
	 * @param  int $grupo_id Group id.
	 * @return int
	 */
	public static function en_espera( int $grupo_id ): int {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$mat_t} WHERE grupo_id = %d AND estado = 'lista_espera'", $grupo_id ) );
	}

	/**
	 * Next position at the end of a group's waiting list.
	 *
	 * @param  int $grupo_id  Group id.
	 * @param  int $trimestre Trimester.
	 * @return int
	 */
	public static function seguinte_posicion( int $grupo_id, int $trimestre ): int {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only.
		return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(posicion) FROM {$mat_t} WHERE grupo_id = %d AND trimestre = %d AND estado = 'lista_espera'", $grupo_id, $trimestre ) );
	}

	/**
	 * Renumbers a group's waiting list to 1..N keeping its order (posicion, id).
	 *
	 * @param  int $grupo_id  Group id.
	 * @param  int $trimestre Trimester.
	 * @return void
	 */
	public static function renumerar( int $grupo_id, int $trimestre ): void {
		ANPA_Socios_Extraescolar_Offers::renumber_group( $grupo_id, $trimestre );
	}

	/**
	 * An offer the family let expire or turned down: back to the waiting list,
	 * at the END of it.
	 *
	 * @param  int $matricula_id Matrícula in «oferta».
	 * @return array{grupo_id:int,trimestre:int}|null Null when it was no longer an offer.
	 */
	public static function ao_final( int $matricula_id ): ?array {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, grupo_id, trimestre FROM {$mat_t} WHERE id = %d AND estado = 'oferta'", $matricula_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$g   = (int) $row['grupo_id'];
		$t   = (int) $row['trimestre'];
		$pos = self::seguinte_posicion( $g, $t );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded state change.
		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE {$mat_t} SET estado = 'lista_espera', posicion = %d, oferta_token = NULL, oferta_expira = %s, actualizado_en = %s WHERE id = %d AND estado = 'oferta'",
			$pos,
			gmdate( 'Y-m-d H:i:s' ),
			current_time( 'mysql' ),
			$matricula_id
		) );
		if ( 1 !== (int) $n ) {
			return null;
		}
		self::renumerar( $g, $t );
		return array( 'grupo_id' => $g, 'trimestre' => $t );
	}
}
