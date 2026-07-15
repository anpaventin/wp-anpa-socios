<?php
/**
 * Pure helpers for curricular groups (fase24).
 *
 * A "grupo curricular" is a per-school-year template that groups one or more
 * niveis and defines a morning (`franxa_manha`) and/or afternoon
 * (`franxa_tarde`) time slot for that set of niveis. Activity yearly offers
 * pick an exclusive horario (morning XOR afternoon) and one or more curricular
 * groups; the effective time slot is resolved from the chosen group + horario.
 *
 * This class holds only the pure, WordPress-independent logic: snapshot
 * normalization, horario validation and effective-franxa resolution.
 * Persistence lives in the DB layer / admin handler (PR-GC2/PR-GC3).
 *
 * @since  1.41.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure normalization/validation for curricular groups.
 *
 * @since 1.41.0
 */
final class ANPA_Socios_Grupos_Curriculares {

	/**
	 * Maximum label length for a curricular group.
	 *
	 * @since 1.41.0
	 * @var int
	 */
	private const MAX_ETIQUETA_LEN = 60;

	/**
	 * Canonical horario tokens (exclusive: morning XOR afternoon).
	 *
	 * @since 1.41.0
	 * @var string[]
	 */
	public const HORARIOS = array( 'manha', 'tarde' );

	/**
	 * Normalizes a single curricular-group snapshot.
	 *
	 * Returns an empty array when the snapshot is invalid: no valid label, no
	 * niveis, or no valid franxa in either slot. A valid group MUST have a
	 * non-empty label, at least one nivel and at least one valid franxa
	 * (morning or afternoon).
	 *
	 * @since  1.41.0
	 * @param  array<string,mixed> $input Raw snapshot.
	 * @return array<string,mixed> Normalized group, or empty array when invalid.
	 */
	public static function normalize_snapshot( array $input ): array {
		$etiqueta = self::normalize_label( $input['etiqueta'] ?? ( $input['label'] ?? null ) );
		if ( null === $etiqueta ) {
			return array();
		}

		$niveis = ANPA_Socios_Grupo_Niveis::normalize( $input['niveis'] ?? array() );
		if ( array() === $niveis ) {
			return array();
		}

		$franxa_manha = self::normalize_optional_franxa( $input['franxa_manha'] ?? null );
		$franxa_tarde = self::normalize_optional_franxa( $input['franxa_tarde'] ?? null );
		if ( '' === $franxa_manha && '' === $franxa_tarde ) {
			return array();
		}

		return array(
			'etiqueta'     => $etiqueta,
			'orde'         => self::normalize_order( $input['orde'] ?? ( $input['order'] ?? null ) ),
			'niveis'       => $niveis,
			'franxa_manha' => $franxa_manha,
			'franxa_tarde' => $franxa_tarde,
		);
	}

	/**
	 * Whether the given value is a valid exclusive horario token.
	 *
	 * Exactly one of the two canonical tokens ('manha' or 'tarde'). An array,
	 * empty string, or unknown token is rejected — the horario is never a
	 * multi-value set (unlike the legacy ANPA_Socios_Actividade_Options::HORARIOS).
	 *
	 * @since  1.41.0
	 * @param  mixed $value Candidate horario.
	 * @return bool
	 */
	public static function is_valid_horario( $value ): bool {
		return is_string( $value ) && in_array( $value, self::HORARIOS, true );
	}

	/**
	 * Resolves the effective franxa of a normalized group for a given horario.
	 *
	 * @since  1.41.0
	 * @param  array<string,mixed> $grupo   Normalized group (see normalize_snapshot()).
	 * @param  string              $horario 'manha' or 'tarde'.
	 * @return string|null The 'HH:MM-HH:MM' franxa, or null when the group has
	 *                     no slot for that horario (or the horario is invalid).
	 */
	public static function franxa_efectiva( array $grupo, string $horario ): ?string {
		if ( ! self::is_valid_horario( $horario ) ) {
			return null;
		}

		$key    = 'manha' === $horario ? 'franxa_manha' : 'franxa_tarde';
		$franxa = isset( $grupo[ $key ] ) ? (string) $grupo[ $key ] : '';

		return '' === $franxa ? null : $franxa;
	}

	/**
	 * Whether a normalized group can be offered under the given horario.
	 *
	 * A group is selectable for a yearly offer only when it has a valid franxa
	 * for that offer's horario.
	 *
	 * @since  1.41.0
	 * @param  array<string,mixed> $grupo   Normalized group.
	 * @param  string              $horario 'manha' or 'tarde'.
	 * @return bool
	 */
	public static function offerable_under( array $grupo, string $horario ): bool {
		return null !== self::franxa_efectiva( $grupo, $horario );
	}

	/**
	 * Normalizes an optional franxa value to 'HH:MM-HH:MM' or '' (absent).
	 *
	 * @since  1.41.0
	 * @param  mixed $value Raw franxa.
	 * @return string Canonical franxa, or '' when absent/invalid.
	 */
	private static function normalize_optional_franxa( $value ): string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return '';
		}

		$franxa = ANPA_Socios_Actividade_Options::normalize_franxa( $value );

		return null === $franxa ? '' : $franxa;
	}

	/**
	 * Normalizes a label: trims control chars, enforces max length.
	 *
	 * @since  1.41.0
	 * @param  mixed $value Raw label.
	 * @return string|null Normalized label, or null when empty/too long.
	 */
	private static function normalize_label( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', trim( (string) $value ) );
		if ( null === $str || '' === $str || strlen( $str ) > self::MAX_ETIQUETA_LEN ) {
			return null;
		}

		return $str;
	}

	/**
	 * Normalizes an orde value to a positive int, defaulting to 10.
	 *
	 * @since  1.41.0
	 * @param  mixed $value Raw order.
	 * @return int
	 */
	private static function normalize_order( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) {
			$orde = (int) trim( $value );
			if ( $orde > 0 ) {
				return $orde;
			}
		}

		return 10;
	}
}
