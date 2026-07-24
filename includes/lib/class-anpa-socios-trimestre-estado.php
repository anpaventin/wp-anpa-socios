<?php
/**
 * Centralized trimester state value object + explicit state machine (fase34).
 *
 * The trimester (lectivo) state is SEPARATE from the application-window state
 * (ANPA_Socios_Ventana_Estado) and from group/matrícula states (fase39).
 *
 * Allowed transitions:
 *   pendente → activo → pechado
 *   pechado  → activo   (explicit re-open; admin-confirmed, audited)
 *
 * No WordPress dependency.
 *
 * @since  1.38.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Trimestre_Estado {

	const PENDENTE = 'pendente';
	const ACTIVO   = 'activo';
	const PECHADO  = 'pechado';

	/**
	 * @return string[]
	 */
	public static function validos(): array {
		return array( self::PENDENTE, self::ACTIVO, self::PECHADO );
	}

	/**
	 * @param string $estado Candidate.
	 * @return bool
	 */
	public static function valido( string $estado ): bool {
		return in_array( $estado, self::validos(), true );
	}

	/**
	 * Whether a transition from → to is permitted.
	 *
	 * @param string $de Current state.
	 * @param string $a  Target state.
	 * @return bool
	 */
	public static function pode_transicionar( string $de, string $a ): bool {
		if ( ! self::valido( $de ) || ! self::valido( $a ) ) {
			return false;
		}
		$permitidas = array(
			self::PENDENTE => array( self::ACTIVO ),
			self::ACTIVO   => array( self::PECHADO ),
			self::PECHADO  => array( self::ACTIVO ),
		);
		return in_array( $a, $permitidas[ $de ] ?? array(), true );
	}
}
