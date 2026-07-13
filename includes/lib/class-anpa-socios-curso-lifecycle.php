<?php
/**
 * Pure policy for course lifecycle transitions.
 *
 * @since  1.38.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
	// Pure helper: direct loading is allowed by the PHPUnit bootstrap.
}

final class ANPA_Socios_Curso_Lifecycle {

	/**
	 * Builds a deterministic transition plan.
	 *
	 * @param string      $target        Target school year.
	 * @param string      $estado        Requested estado (pendente|activo|pechado).
	 * @param bool        $open          Requested enrolment gate.
	 * @param string|null $active        Currently active school year, if any.
	 * @param bool        $replace_active Whether replacing another active course was confirmed.
	 * @return array{allowed:bool,error:?string,deactivate:?string,target_estado:string,target_open:bool}
	 */
	public static function plan( string $target, string $estado, bool $open, ?string $active, bool $replace_active ): array {
		if ( ! in_array( $estado, array( 'pendente', 'activo', 'pechado' ), true ) ) {
			return self::denied( 'invalid_state', $estado );
		}

		if ( 'pendente' === $estado && $open ) {
			return self::denied( 'inactive_course_cannot_open', $estado );
		}

		$other_active = null !== $active && '' !== $active && $active !== $target;
		if ( 'activo' === $estado && $other_active && ! $replace_active ) {
			return self::denied( 'active_course_conflict', $estado );
		}

		return array(
			'allowed'       => true,
			'error'         => null,
			'deactivate'    => ( 'activo' === $estado && $other_active ) ? $active : null,
			'target_estado' => $estado,
			'target_open'   => 'activo' === $estado ? $open : false,
		);
	}

	/**
	 * Describes the safe automatic rollover. The cron may close/create courses,
	 * but it must never activate one or open enrolments without an admin choice.
	 *
	 * @param  string $current Current school year.
	 * @return array{current_estado:string,current_matriculas_abertas:bool,next_curso:string,next_estado:string,next_matriculas_abertas:bool}
	 */
	public static function season_rollover( string $current ): array {
		return array(
			'current_estado'              => 'pechado',
			'current_matriculas_abertas' => false,
			'next_curso'                  => ANPA_Socios_Curso_Escolar::next( $current ),
			'next_estado'                 => 'pendente',
			'next_matriculas_abertas'     => false,
		);
	}

	private static function denied( string $error, string $estado ): array {
		return array(
			'allowed'       => false,
			'error'         => $error,
			'deactivate'    => null,
			'target_estado' => $estado,
			'target_open'   => false,
		);
	}
}
