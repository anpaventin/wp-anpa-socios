<?php
/**
 * Pure validation helpers for a multi-year series of activity groups.
 *
 * @since  1.42.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Grupo_Serie {

	private const NOME_MAX_LEN = 80;
	private const HORARIOS = array( 'manha', 'tarde' );
	private const ESTADOS = array( 'aberto', 'pechado' );

	/**
	 * Normalizes a group-series payload. Returns an empty array on any error.
	 *
	 * @param array<string,mixed> $input Raw payload.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $input ): array {
		$nome = preg_replace( '/\s+/u', ' ', trim( (string) ( $input['nome'] ?? '' ) ) );
		if ( ! is_string( $nome ) || '' === $nome ) {
			return array();
		}
		$nome = mb_substr( $nome, 0, self::NOME_MAX_LEN );

		if ( ! isset( $input['cursos'] ) || ! is_array( $input['cursos'] ) ) {
			return array();
		}
		$cursos = array_values( array_unique( array_map( 'strval', $input['cursos'] ) ) );
		if ( array() === $cursos ) {
			return array();
		}
		foreach ( $cursos as $curso ) {
			if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
				return array();
			}
		}

		$raw_niveis = $input['niveis_por_ano'] ?? null;
		if ( ! is_array( $raw_niveis ) || array_diff( array_keys( $raw_niveis ), $cursos ) ) {
			return array();
		}
		$niveis_por_ano = array();
		foreach ( $cursos as $curso ) {
			if ( ! isset( $raw_niveis[ $curso ] ) || ! is_array( $raw_niveis[ $curso ] ) ) {
				return array();
			}
			$niveis = array_values( array_unique( array_filter( array_map( 'intval', $raw_niveis[ $curso ] ), static function ( int $id ): bool {
				return $id > 0;
			} ) ) );
			if ( array() === $niveis ) {
				return array();
			}
			$niveis_por_ano[ $curso ] = $niveis;
		}

		$horario = (string) ( $input['horario'] ?? '' );
		if ( ! in_array( $horario, self::HORARIOS, true ) ) {
			return array();
		}
		$franxa = ANPA_Socios_Actividade_Options::normalize_franxa( $input['franxa'] ?? null );
		if ( null === $franxa ) {
			return array();
		}
		$dias = ANPA_Socios_Actividade_Options::normalize( $input['dias'] ?? null, ANPA_Socios_Actividade_Options::DIAS );
		if ( array() === $dias ) {
			return array();
		}

		$min = isset( $input['min_pupilos'] ) ? (int) $input['min_pupilos'] : 0;
		$max = isset( $input['max_pupilos'] ) ? (int) $input['max_pupilos'] : 0;
		if ( $min < 1 || $max < $min ) {
			return array();
		}
		$estado = (string) ( $input['estado'] ?? 'aberto' );
		if ( ! in_array( $estado, self::ESTADOS, true ) ) {
			return array();
		}

		return array(
			'nome'           => $nome,
			'cursos'         => $cursos,
			'niveis_por_ano' => $niveis_por_ano,
			'horario'        => $horario,
			'franxa'         => $franxa,
			'dias'           => implode( ',', $dias ),
			'min_pupilos'    => $min,
			'max_pupilos'    => $max,
			'estado'         => $estado,
		);
	}

	public static function horario_label( string $horario ): string {
		if ( 'manha' === $horario ) {
			return 'Mañá (comedor)';
		}
		return 'tarde' === $horario ? 'Tarde' : '';
	}

	/** @param mixed $horario */
	public static function is_valid_horario( $horario ): bool {
		return is_string( $horario ) && in_array( $horario, self::HORARIOS, true );
	}
}
