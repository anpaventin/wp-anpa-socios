<?php
/**
 * Pure helpers for group ↔ level relations.
 *
 * Groups attach to one or more school levels. This helper normalises the
 * selected levels and checks membership without caring about classrooms.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Grupo_Niveis {
	public static function normalize( $value ): array {
		$tokens = self::tokenize( $value );
		$seen   = array();
		$out    = array();

		foreach ( $tokens as $token ) {
			$token = self::normalize_code( $token );
			if ( null === $token || isset( $seen[ $token ] ) ) {
				continue;
			}
			$seen[ $token ] = true;
			$out[] = $token;
		}

		sort( $out, SORT_NATURAL );

		return $out;
	}

	public static function fits( string $nivel_codigo, $grupo_niveis ): bool {
		$nivel_codigo = self::normalize_code( $nivel_codigo );
		if ( null === $nivel_codigo ) {
			return false;
		}

		return in_array( $nivel_codigo, self::normalize( $grupo_niveis ), true );
	}

	public static function is_valid( $grupo_niveis ): bool {
		return array() !== self::normalize( $grupo_niveis );
	}

	public static function serialize( $value ): string {
		return implode( ',', self::normalize( $value ) );
	}

	private static function tokenize( $value ): array {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value ) {
				return array();
			}
			if ( false !== strpos( $value, ',' ) ) {
				$tokens = array();
				foreach ( explode( ',', $value ) as $segment ) {
					$tokens = array_merge( $tokens, self::tokenize( $segment ) );
				}

				return $tokens;
			}
			if ( false !== strpos( $value, '-' ) ) {
				$parts = array_filter( array_map( 'trim', explode( '-', $value ) ), 'strlen' );
				// Only split numeric ranges like "1-2-3"; preserve
				// alphanumeric codes containing hyphens like "INF-3".
				if ( self::is_numeric_range( $parts ) ) {
					return array_values( $parts );
				}

				return array( $value );
			}

			return array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $value as $token ) {
			if ( is_array( $token ) ) {
				foreach ( $token as $nested ) {
					$tokens[] = $nested;
				}
				continue;
			}
			$tokens[] = $token;
		}

		return $tokens;
	}

	/**
	 * Whether all parts are purely numeric digits (single or multi-digit).
	 *
	 * @since  23.0.0
	 * @param  string[] $parts Array of trimmed token parts.
	 * @return bool
	 */
	private static function is_numeric_range( array $parts ): bool {
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^\d+$/', $part ) ) {
				return false;
			}
		}

		return true;
	}

	private static function normalize_code( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', trim( (string) $value ) );
		if ( null === $str ) {
			return null;
		}

		$str = strtoupper( $str );
		if ( '' === $str || strlen( $str ) > 30 ) {
			return null;
		}

		return $str;
	}
}
