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
	private const HORARIOS = array( 'maña', 'manha', 'tarde' );
	/**
	 * Group states (1.50.0):
	 *  - aberto: visible on the public page/timetable, offered in the area, enrolment active while seats remain.
	 *  - pechado: hidden from the public offer and the area; existing enrolments continue; a new one goes to the waitlist.
	 *  - deshabilitado: hidden everywhere and accepts NO enrolments; only allowed when the group has no current enrolments.
	 *    Kept for history and later reuse instead of deleting the row.
	 */
	public const ESTADO_ABERTO        = 'aberto';
	public const ESTADO_PECHADO       = 'pechado';
	public const ESTADO_DESHABILITADO = 'deshabilitado';
	private const ESTADOS = array( self::ESTADO_ABERTO, self::ESTADO_PECHADO, self::ESTADO_DESHABILITADO );

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

		$min = isset( $input['min_pupilos'] ) ? (int) $input['min_pupilos'] : 10;
		$max = isset( $input['max_pupilos'] ) ? (int) $input['max_pupilos'] : 15;
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

	/** @return string[] */
	public static function estados(): array {
		return self::ESTADOS;
	}

	/** True when the state may only be set on a group without current (non-baixa) enrolments. */
	public static function estado_requires_no_enrolments( string $estado ): bool {
		return self::ESTADO_DESHABILITADO === $estado;
	}

	public static function estado_label( string $estado ): string {
		$labels = array(
			self::ESTADO_ABERTO        => __( 'Aberto', 'anpa-socios' ),
			self::ESTADO_PECHADO       => __( 'Pechado', 'anpa-socios' ),
			self::ESTADO_DESHABILITADO => __( 'Deshabilitado', 'anpa-socios' ),
		);
		return $labels[ $estado ] ?? $estado;
	}

	public static function horario_label( string $horario ): string {
		if ( 'maña' === $horario ) {
			return 'Mañá';
		}
		if ( 'manha' === $horario ) {
			return 'Comedor';
		}
		return 'tarde' === $horario ? 'Tarde' : '';
	}

	/** @param mixed $horario */
	public static function is_valid_horario( $horario ): bool {
		return is_string( $horario ) && in_array( $horario, self::HORARIOS, true );
	}
}
