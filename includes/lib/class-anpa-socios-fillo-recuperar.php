<?php
/**
 * Recovering a child in baixa (1.81.0): a family that comes back gets its
 * children's old records back (name, birth date, history) instead of typing
 * them again as new rows. Pure helpers; the glue lives in the area fillos REST
 * handler and in the alta handler.
 *
 * @since   1.81.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers for recovering children.
 *
 * @since 1.81.0
 */
final class ANPA_Socios_Fillo_Recuperar {

	/**
	 * Name comparison key: case-insensitive, with trimmed and single spaces.
	 *
	 * @param  string $nome     Given name.
	 * @param  string $apelidos Surnames.
	 * @return string
	 */
	public static function clave( string $nome, string $apelidos ): string {
		$s = trim( $nome ) . '|' . trim( $apelidos );
		$s = (string) preg_replace( '/\s+/u', ' ', $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * The child in baixa that the new data refers to: same name and surnames and,
	 * when both are known, the same birth date. The most recent row wins.
	 *
	 * @param  array<int,array<string,mixed>> $baixas         Rows (id, nome, apelidos, data_nacemento).
	 * @param  string                         $nome           New given name.
	 * @param  string                         $apelidos       New surnames.
	 * @param  string|null                    $data_nacemento New birth date (Y-m-d) or null.
	 * @return int Row id, or 0 when none matches.
	 */
	public static function coincidencia( array $baixas, string $nome, string $apelidos, ?string $data_nacemento ): int {
		$clave = self::clave( $nome, $apelidos );
		$mellor = 0;
		foreach ( $baixas as $r ) {
			if ( self::clave( (string) ( $r['nome'] ?? '' ), (string) ( $r['apelidos'] ?? '' ) ) !== $clave ) {
				continue;
			}
			$vella = (string) ( $r['data_nacemento'] ?? '' );
			if ( null !== $data_nacemento && '' !== $data_nacemento && '' !== $vella && '0000-00-00' !== $vella && $vella !== $data_nacemento ) {
				continue;
			}
			$mellor = max( $mellor, (int) ( $r['id'] ?? 0 ) );
		}
		return $mellor;
	}

	/**
	 * Level proposed for the current course from the birth date (same rule as
	 * the annual level promotion). 'fora' = older than the last level: the child
	 * has probably finished at the school, so no level is proposed.
	 *
	 * @param  string                         $data_nacemento Birth date (Y-m-d).
	 * @param  string                         $curso_escolar  Active school year (YYYY/YYYY+1).
	 * @param  array<int,array<string,mixed>> $niveis         Active levels (codigo, orde).
	 * @return array{curso:string,fora:bool}
	 */
	public static function curso_suxerido( string $data_nacemento, string $curso_escolar, array $niveis ): array {
		$idade = ANPA_Socios_Nivel_Promotion::age_for_course( $data_nacemento, $curso_escolar );
		if ( null === $idade ) {
			return array( 'curso' => '', 'fora' => false );
		}
		$t = ANPA_Socios_Nivel_Promotion::target_for_age( $idade, $niveis );
		if ( 'assigned' === $t['status'] ) {
			return array( 'curso' => (string) ( $t['level']['codigo'] ?? '' ), 'fora' => false );
		}
		return array( 'curso' => '', 'fora' => 'capped' === $t['status'] );
	}
}
