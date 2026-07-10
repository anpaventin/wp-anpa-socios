<?php
/**
 * Pure-logic normalization helpers for ANPA Socios data.
 *
 * Centralises the canonical form for every free-text field the
 * plugin accepts so validators, REST handlers, JS, and the
 * retroactive migrator all produce the same output.
 *
 * No WordPress dependency, no I/O, no global state. Unit-testable
 * with PHPUnit and a require_once-only bootstrap.
 *
 * @since  1.20.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Canonical form helpers.
 *
 * Each method is pure: same input → same output, no side effects.
 *
 * @since 1.20.0
 */
final class ANPA_Socios_Normalize {

	/**
	 * Particles that stay lower-case inside multi-word names EXCEPT
	 * when they appear at the very first position of the string.
	 *
	 * Covers Galician/Portuguese/Spanish/Italian particles that
	 * never capitalise mid-string.
	 *
	 * @since 1.20.0
	 * @var string[]
	 */
	const TITLE_CASE_PARTICLES = array(
		'de', 'del', 'la', 'las', 'el', 'los',
		'y', 'e',
		'da', 'do', 'dos', 'das',
		'i', 'van', 'von',
	);

	/**
	 * Title case: first letter of every word upper, rest lower.
	 *
	 * Handles UTF-8 (mb_*), particle preservation, hyphens,
	 * apostrophes, multiple spaces, mixed case.
	 *
	 * Examples:
	 *  - "maría JOSÉ"        → "María José"
	 *  - "RUIZ DE LA PRADA"  → "Ruiz de la Prada"
	 *  - "MARÍA-JOSÉ"        → "María-José"
	 *  - "O'RIANXO"          → "O'Ryanxo"
	 *  - "  maría   josé  "  → "María José"
	 *
	 * @since  1.20.0
	 * @param  string $value Raw input.
	 * @return string Canonical title case.
	 */
	public static function title_case( string $value ): string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( '' === $value ) {
			return '';
		}

		$titled = mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' );

		$tokens = preg_split( '/(\s+|-|’|‘|\')/u', $titled, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $tokens ) ) {
			return $titled;
		}

		$is_first_word = true;
		$result        = '';
		foreach ( $tokens as $piece ) {
			if ( '' === $piece ) {
				continue;
			}
			if ( preg_match( '/^(\s+|-|’|‘|\')$/u', $piece ) === 1 ) {
				$result .= $piece;
				continue;
			}
			$lower = mb_strtolower( $piece, 'UTF-8' );
			if ( ! $is_first_word && in_array( $lower, self::TITLE_CASE_PARTICLES, true ) ) {
				$result .= $lower;
			} else {
				$first = mb_strtoupper( mb_substr( $lower, 0, 1, 'UTF-8' ), 'UTF-8' );
				$rest  = mb_substr( $lower, 1, null, 'UTF-8' );
				$result .= $first . $rest;
			}
			$is_first_word = false;
		}

		return $result;
	}

	/**
	 * Canonical email: trim + lower-case + FILTER_VALIDATE_EMAIL.
	 *
	 * @since  1.20.0
	 * @param  string $value Raw input.
	 * @return string|null   Canonical email or null if invalid.
	 */
	public static function email( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( '' === $value || strlen( $value ) > 190 ) {
			return null;
		}
		return ( false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ) ? $value : null;
	}

	/**
	 * Canonical Spanish phone: 9 digits, no country prefix.
	 *
	 * Accepts "+34", "0034", spaces, dots, dashes, parentheses.
	 * Returns null if the result is not 9 valid Spanish digits
	 * (starting with 6/7/8/9).
	 *
	 * @since  1.20.0
	 * @param  string $value Raw input.
	 * @return string|null   9-digit phone or null.
	 */
	public static function telefono( string $value ): ?string {
		$digits = (string) preg_replace( '/[\s.\-()]/', '', trim( $value ) );
		$digits = (string) preg_replace( '/^(\+34|0034)/', '', $digits );
		if ( 1 === preg_match( '/^[6-9]\d{8}$/', $digits ) ) {
			return $digits;
		}
		return null;
	}

	/**
	 * Canonical NIF/NIE: upper-case, no spaces, validated.
	 *
	 * Delegates letter-validation to ANPA_Socios_Sepa::validar_nif_nie.
	 * Returns null if the input is not a valid Spanish identifier.
	 *
	 * @since  1.20.0
	 * @param  string $value Raw input.
	 * @return string|null   Canonical NIF/NIE or null.
	 */
	public static function nif( string $value ): ?string {
		$v = strtoupper( (string) preg_replace( '/\s+/', '', trim( $value ) ) );
		if ( '' === $v ) {
			return null;
		}
		// Re-uses the canonical mod-23 validator.
		return ANPA_Socios_Sepa::validar_nif_nie( $v );
	}

	/**
	 * Canonical IBAN: upper-case, all whitespace removed.
	 *
	 * Does NOT validate checksum — only produces the canonical form
	 * (uppercase, no spaces) for consistent storage and comparison.
	 * Empty input stays empty.
	 *
	 * Examples:
	 *  - "es91 2100 0418 4502 0005 1332" → "ES9121000418450200051332"
	 *  - "  GB29 NWBK 6016 1331 9268 19 " → "GB29NWBK60161331926819"
	 *  - "" → ""
	 *
	 * @since  1.34.0
	 * @param  string $value Raw IBAN input.
	 * @return string Canonical IBAN (uppercase, no whitespace).
	 */
	public static function iban( string $value ): string {
		$v = preg_replace( '/\s+/', '', trim( $value ) );
		if ( '' === $v || null === $v ) {
			return '';
		}

		return strtoupper( $v );
	}

	/**
	 * Canonical school year: normalizes to YYYY/YYYY format.
	 *
	 * Accepts two 4-digit years separated by `/`, `-`, or spaces
	 * (e.g. "2025-2026", "2025 / 2026", "2025/2026"). The second
	 * year must be exactly first+1. Returns null if the input cannot
	 * be parsed into two consecutive 4-digit years.
	 *
	 * Examples:
	 *  - "2025-2026"    → "2025/2026"
	 *  - "2025 / 2026"  → "2025/2026"
	 *  - "2025/2026"    → "2025/2026"
	 *  - "2025 2026"    → "2025/2026"
	 *  - "2025-2027"    → null (not consecutive)
	 *  - "abc"          → null
	 *  - ""             → null
	 *
	 * @since  1.34.0
	 * @param  string $value Raw school year input.
	 * @return string|null Canonical "YYYY/YYYY" or null on failure.
	 */
	public static function curso_escolar( string $value ): ?string {
		$v = trim( $value );
		if ( '' === $v ) {
			return null;
		}

		// Split on separators: /, -, or whitespace (possibly surrounded by spaces).
		$parts = preg_split( '/\s*[\/\-]\s*|\s+/', $v );
		if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
			return null;
		}

		$year1 = $parts[0];
		$year2 = $parts[1];

		// Both must be exactly 4 digits.
		if ( 1 !== preg_match( '/^\d{4}$/', $year1 ) || 1 !== preg_match( '/^\d{4}$/', $year2 ) ) {
			return null;
		}

		$y1 = (int) $year1;
		$y2 = (int) $year2;

		// Second year must be exactly first + 1.
		if ( $y2 !== $y1 + 1 ) {
			return null;
		}

		return $year1 . '/' . $year2;
	}
}
