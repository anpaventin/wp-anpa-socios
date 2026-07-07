<?php
/**
 * Pure flow decision helpers for the unified socio-area flow.
 *
 * Decides which step the UI should show next (area, alta, inactivo,
 * empresa) based on the flags pre-fetched by the integration layer.
 * The class never touches the database; the REST handler does the
 * lookups and passes the result here.
 *
 * This separation keeps the decision testable with PHPUnit (no WordPress
 * bootstrap) and prevents accidental information leaks: the integration
 * layer can pass only two boolean flags and the helper still produces a
 * correct, privacy-preserving decision.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Decides the next UI step for the socio area flow.
 *
 * @since 1.2.0
 */
final class ANPA_Socios_Flow {

	/**
	 * Allowed flag keys for the flow decision.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const FLAG_KEYS = array( 'socio', 'empresa', 'socio_baixa' );

	/**
	 * Allowed flag values for the flow decision.
	 *
	 * `solicitada` is only meaningful for the `socio_baixa` key (a pending
	 * baixa request on an otherwise-active socio).
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const FLAG_VALUES = array( 'activo', 'pendiente_alta', 'pendente_aprobacion', 'baixa', 'inactivo', 'solicitada' );

	/**
	 * Computes the next step in the unified socio flow.
	 *
	 * Decision rules (in order):
	 *  1. Active socio -> `area`.
	 *  2. `pendiente_alta` socio -> `alta` (resume).
	 *  3. Active empresa -> `empresa`.
	 *  4. baixa socio -> `inactivo`.
	 *  5. Anything else (no row, inactive empresa, unknown state) ->
	 *     `alta` so the UI can show the generic "se o email é válido
	 *     recibirás un código" message and not leak account state.
	 *
	 * @since  1.2.0
	 * @param  array<string,string> $flags Associative array with optional
	 *                                     keys `socio` and `empresa` and
	 *                                     values from FLAG_VALUES.
	 * @return string
	 */
	public static function next( array $flags ): string {
		$flags = self::sanitise_flags( $flags );

		$socio = isset( $flags['socio'] ) ? $flags['socio'] : null;

		if ( 'activo' === $socio ) {
			// An active socio with a pending baixa request gets a dedicated
			// step so the UI can offer to cancel it (cancellation itself still
			// requires authentication via the area login).
			if ( isset( $flags['socio_baixa'] ) && 'solicitada' === $flags['socio_baixa'] ) {
				return 'baixa_pendente';
			}
			return 'area';
		}
		if ( 'pendiente_alta' === $socio ) {
			return 'alta';
		}
		if ( 'pendente_aprobacion' === $socio ) {
			// The alta is complete but the socio still needs master approval
			// before they can log in. A dedicated step lets the UI explain the
			// wait without offering to re-submit the alta or leaking state.
			return 'pendente_aprobacion';
		}
		if ( 'baixa' === $socio ) {
			return 'inactivo';
		}

		if ( isset( $flags['empresa'] ) ) {
			if ( 'activo' === $flags['empresa'] ) {
				return 'empresa';
			}
			// Inactive or unknown empresa state: no leak, treat as alta.
		}

		return 'alta';
	}

	/**
	 * Validates a flags array keeping only known keys and values.
	 *
	 * @since  1.2.0
	 * @param  array<string,string> $flags Raw flags array.
	 * @return array<string,string>
	 */
	public static function sanitise_flags( array $flags ): array {
		$clean = array();
		foreach ( $flags as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				continue;
			}
			if ( ! in_array( $key, self::FLAG_KEYS, true ) ) {
				continue;
			}
			if ( ! in_array( $value, self::FLAG_VALUES, true ) ) {
				continue;
			}
			$clean[ $key ] = $value;
		}

		return $clean;
	}
}
