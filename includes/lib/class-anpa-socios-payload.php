<?php
/**
 * Pure-logic validation for the ANPA Socios plugin.
 *
 * No WordPress dependency, no I/O, no global state. Unit-testable
 * with PHPUnit and a require_once-only bootstrap.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Validation helpers for the crear-socio endpoint inputs.
 *
 * Each validator returns the trimmed input on success, or null
 * on failure. The REST handler checks for null and returns a
 * single generic 400 ("Datos inválidos") without per-field
 * detail.
 *
 * @since 1.0.0
 */
final class ANPA_Socios_Payload {

	/**
	 * Validates a `nome` field.
	 *
	 * Rules (after trim):
	 * - length in [1, 50]
	 * - no control characters (\x00-\x1F, \x7F)
	 *
	 * @since  1.0.0
	 * @param  string $nome Raw input.
	 * @return string|null Trimmed nome if valid, null otherwise.
	 */
	public static function validar_nome( string $nome ): ?string {
		return self::validar_string( $nome, 1, 50 );
	}

	/**
	 * Validates a `apelidos` field.
	 *
	 * Rules (after trim):
	 * - length in [1, 100]
	 * - no control characters (\x00-\x1F, \x7F)
	 *
	 * @since  1.0.0
	 * @param  string $apelidos Raw input.
	 * @return string|null Trimmed apelidos if valid, null otherwise.
	 */
	public static function validar_apelidos( string $apelidos ): ?string {
		return self::validar_string( $apelidos, 1, 100 );
	}

	/**
	 * Validates a contact phone number (Spain).
	 *
	 * Accepts an optional `+34`/`0034` prefix and spaces, dots or hyphens
	 * as separators. The canonical result is exactly 9 digits starting with
	 * 6, 7, 8 or 9 (mobiles and landlines).
	 *
	 * @since  1.6.0
	 * @param  string $telefono Raw input.
	 * @return string|null Canonical 9-digit phone if valid, null otherwise.
	 */
	public static function validar_telefono( string $telefono ): ?string {
		$digits = preg_replace( '/[\s.\-]/', '', trim( $telefono ) );
		$digits = (string) preg_replace( '/^(\+34|0034)/', '', (string) $digits );

		if ( 1 === preg_match( '/^[6-9]\d{8}$/', $digits ) ) {
			return $digits;
		}

		return null;
	}

	/**
	 * Internal: trim, length check, control-char check.
	 *
	 * @param string $value      Raw input.
	 * @param int    $min_length Inclusive minimum length after trim.
	 * @param int    $max_length Inclusive maximum length after trim.
	 * @return string|null Trimmed value if valid, null otherwise.
	 */
	private static function validar_string(
		string $value,
		int $min_length,
		int $max_length
	): ?string {
		$trimmed = trim( $value );
		$length  = strlen( $trimmed );

		if ( $length < $min_length || $length > $max_length ) {
			return null;
		}

		// Reject any control character (NUL through US, including DEL).
		if ( preg_match( '/[\x00-\x1F\x7F]/', $trimmed ) === 1 ) {
			return null;
		}

		return $trimmed;
	}
}
