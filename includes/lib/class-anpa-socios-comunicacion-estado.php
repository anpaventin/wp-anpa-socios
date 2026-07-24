<?php
/**
 * Per-recipient communication state value object + state machine (fase35).
 *
 * One record per recipient in a campaign. State is SEPARATE from the campaign
 * state. Reflects the transport lifecycle; "aceptado" means wp_mail() returned
 * true (accepted by the local mail system), NOT delivered.
 *
 * Allowed transitions:
 *   pendente   → procesando | cancelado
 *   procesando → aceptado | fallido | pendente (orphan-lock recovery)
 *   fallido    → pendente (retry) | fallido_definitivo
 * Terminal: aceptado, fallido_definitivo, cancelado.
 *
 * No WordPress dependency.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Comunicacion_Estado {

	const PENDENTE           = 'pendente';
	const PROCESANDO         = 'procesando';
	const ACEPTADO           = 'aceptado';
	const FALLIDO            = 'fallido';
	const FALLIDO_DEFINITIVO = 'fallido_definitivo';
	const CANCELADO          = 'cancelado';

	/**
	 * @return string[]
	 */
	public static function validos(): array {
		return array( self::PENDENTE, self::PROCESANDO, self::ACEPTADO, self::FALLIDO, self::FALLIDO_DEFINITIVO, self::CANCELADO );
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
	 * @return bool Whether the state is terminal.
	 */
	public static function terminal( string $estado ): bool {
		return in_array( $estado, array( self::ACEPTADO, self::FALLIDO_DEFINITIVO, self::CANCELADO ), true );
	}

	/**
	 * @param string $estado Candidate.
	 * @return bool Whether this recipient may still be processed by a batch.
	 */
	public static function reintentable( string $estado ): bool {
		return in_array( $estado, array( self::PENDENTE, self::FALLIDO ), true );
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
			self::PENDENTE           => array( self::PROCESANDO, self::CANCELADO ),
			self::PROCESANDO         => array( self::ACEPTADO, self::FALLIDO, self::PENDENTE ),
			self::FALLIDO            => array( self::PENDENTE, self::FALLIDO_DEFINITIVO ),
			self::ACEPTADO           => array(),
			self::FALLIDO_DEFINITIVO => array(),
			self::CANCELADO          => array(),
		);
		return in_array( $a, $permitidas[ $de ] ?? array(), true );
	}
}
