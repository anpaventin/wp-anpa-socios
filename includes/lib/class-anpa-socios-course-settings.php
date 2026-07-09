<?php
/**
 * Pure helpers for course settings submitted from wp-admin.
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Normalizes course lifecycle/admin settings values outside WordPress glue.
 *
 * @since 1.32.0
 */
final class ANPA_Socios_Course_Settings {

	/**
	 * Reads the optional matriculas_abertas field from a submitted form.
	 *
	 * A missing key means "do not update the gate". Present falsey values mean
	 * "close enrolments". This lets course-date forms preserve the gate while
	 * the dedicated enrolment form can explicitly write true/false.
	 *
	 * @since  1.32.0
	 * @param  array<string,mixed> $post Submitted form values.
	 * @return bool|null
	 */
	public static function matriculas_abertas_from_post( array $post ): ?bool {
		if ( ! array_key_exists( 'matriculas_abertas', $post ) ) {
			return null;
		}

		$value = $post['matriculas_abertas'];
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		return in_array( $value, array( true, 1, '1', 'true', 'on', 'yes', 'si', 'sí' ), true );
	}

	/**
	 * Resolves the enrolment gate for display.
	 *
	 * New, not-yet-persisted courses default to open, matching existing inserts.
	 *
	 * @since  1.32.0
	 * @param  array<string,mixed>|null $row Stored course row, or null for new.
	 * @return bool
	 */
	public static function matriculas_abertas_for_display( ?array $row ): bool {
		if ( null === $row || ! array_key_exists( 'matriculas_abertas', $row ) ) {
			return true;
		}

		return (bool) (int) $row['matriculas_abertas'];
	}
}
