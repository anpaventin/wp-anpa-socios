<?php
/**
 * Centralized application-window state value object (fase34).
 *
 * The "ventá de solicitudes" (window to file baixas / waitlist / new requests
 * for the next trimester) is a SEPARATE concept from the trimester lectivo
 * state and from group state. Two states only:
 *   pechada ↔ aberta   (admin-confirmed, audited)
 *
 * No WordPress dependency.
 *
 * @since  1.38.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Ventana_Estado {

	const PECHADA = 'pechada';
	const ABERTA  = 'aberta';

	/**
	 * @return string[]
	 */
	public static function validos(): array {
		return array( self::PECHADA, self::ABERTA );
	}

	/**
	 * @param string $estado Candidate.
	 * @return bool
	 */
	public static function valido( string $estado ): bool {
		return in_array( $estado, self::validos(), true );
	}

	/**
	 * Whether a transition from → to is permitted (both directions allowed).
	 *
	 * @param string $de Current state.
	 * @param string $a  Target state.
	 * @return bool
	 */
	public static function pode_transicionar( string $de, string $a ): bool {
		return self::valido( $de ) && self::valido( $a ) && $de !== $a;
	}
}
