<?php
/**
 * Pure-logic validation for Spanish fiscal identifiers (NIF/NIE).
 *
 * No WordPress dependency, no I/O, no global state. Unit-testable
 * with PHPUnit and a require_once-only bootstrap.
 *
 * IBAN validation (mod-97) is added by a later fase5 unit (PR-D) when
 * banking data is collected; this file currently owns NIF/NIE only.
 *
 * @since  1.6.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Validators for fiscal identifiers used in the membership form.
 *
 * Each validator returns the canonical (trimmed, upper-cased) value on
 * success, or null on failure. Callers translate null into a generic 400.
 *
 * @since 1.6.0
 */
final class ANPA_Socios_Sepa {

	/**
	 * Control letters for the mod-23 check, indexed by remainder.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	private const NIF_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	/**
	 * NIE leading-letter to digit substitution.
	 *
	 * @since 1.6.0
	 * @var array<string,string>
	 */
	private const NIE_PREFIX = array(
		'X' => '0',
		'Y' => '1',
		'Z' => '2',
	);

	/**
	 * Validates a Spanish NIF (DNI) or NIE.
	 *
	 * Rules (after trim + upper-case):
	 * - NIF: 8 digits + control letter (letter = NIF_LETTERS[number % 23]).
	 * - NIE: leading X/Y/Z (→ 0/1/2) + 7 digits + control letter, same mod-23.
	 *
	 * @since  1.6.0
	 * @param  string $value Raw input.
	 * @return string|null Canonical identifier if valid, null otherwise.
	 */
	public static function validar_nif_nie( string $value ): ?string {
		$v = strtoupper( trim( $value ) );
		if ( '' === $v ) {
			return null;
		}

		// NIF (DNI): 8 digits + 1 letter.
		if ( 1 === preg_match( '/^(\d{8})([A-Z])$/', $v, $m ) ) {
			$expected = self::NIF_LETTERS[ ( (int) $m[1] ) % 23 ];
			return ( $m[2] === $expected ) ? $v : null;
		}

		// NIE: X/Y/Z + 7 digits + 1 letter.
		if ( 1 === preg_match( '/^([XYZ])(\d{7})([A-Z])$/', $v, $m ) ) {
			$numeric  = (int) ( self::NIE_PREFIX[ $m[1] ] . $m[2] );
			$expected = self::NIF_LETTERS[ $numeric % 23 ];
			return ( $m[3] === $expected ) ? $v : null;
		}

		return null;
	}

	/**
	 * Validates an IBAN (length 15-34 + mod-97 == 1).
	 *
	 * Accepts spaces and lower-case; returns the canonical compact,
	 * upper-cased IBAN on success, or null on failure.
	 *
	 * @since  1.7.0
	 * @param  string $value Raw IBAN.
	 * @return string|null Canonical IBAN if valid, null otherwise.
	 */
	public static function validar_iban( string $value ): ?string {
		$iban = strtoupper( (string) preg_replace( '/\s+/', '', $value ) );

		if ( 1 !== preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return null;
		}

		// Move the first 4 chars to the end, then convert letters to numbers.
		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric    = '';
		$length     = strlen( $rearranged );
		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $rearranged[ $i ];
			if ( ctype_digit( $ch ) ) {
				$numeric .= $ch;
			} else {
				// A=10 ... Z=35.
				$numeric .= (string) ( ord( $ch ) - 55 );
			}
		}

		// mod-97 over the (possibly very long) numeric string, chunked.
		$remainder = 0;
		$len       = strlen( $numeric );
		for ( $i = 0; $i < $len; $i += 7 ) {
			$remainder = (int) ( ( $remainder . substr( $numeric, $i, 7 ) ) % 97 );
		}

		return ( 1 === $remainder ) ? $iban : null;
	}
}
