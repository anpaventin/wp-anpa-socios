<?php
/**
 * Campaign state value object + explicit state machine (fase35).
 *
 * A campaign groups one send to many recipients. Its state is SEPARATE from the
 * per-recipient communication state (ANPA_Socios_Comunicacion_Estado).
 *
 * Allowed transitions:
 *   pendente  → procesando | cancelada
 *   procesando → pausada | rematada | cancelada
 *   pausada   → procesando | cancelada
 * Terminal: rematada, cancelada.
 *
 * ASCII identifiers on purpose (no "ñ") for portable class/DB names; the Galician
 * label "campaña" lives only in the UI. No WordPress dependency.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Campana_Estado {

	const PENDENTE   = 'pendente';
	const PROCESANDO = 'procesando';
	const PAUSADA    = 'pausada';
	const REMATADA   = 'rematada';
	const CANCELADA  = 'cancelada';

	/**
	 * @return string[]
	 */
	public static function validos(): array {
		return array( self::PENDENTE, self::PROCESANDO, self::PAUSADA, self::REMATADA, self::CANCELADA );
	}

	/**
	 * @param string $estado Candidate.
	 * @return bool
	 */
	public static function valido( string $estado ): bool {
		return in_array( $estado, self::validos(), true );
	}

	/**
	 * @param string $estado Candidate.
	 * @return bool Whether the state is terminal (no further transitions).
	 */
	public static function terminal( string $estado ): bool {
		return in_array( $estado, array( self::REMATADA, self::CANCELADA ), true );
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
			self::PENDENTE   => array( self::PROCESANDO, self::CANCELADA ),
			self::PROCESANDO => array( self::PAUSADA, self::REMATADA, self::CANCELADA ),
			self::PAUSADA    => array( self::PROCESANDO, self::CANCELADA ),
			self::REMATADA   => array(),
			self::CANCELADA  => array(),
		);
		return in_array( $a, $permitidas[ $de ] ?? array(), true );
	}
}
