<?php
/**
 * Pure family-id resolution helper.
 *
 * No WordPress/wpdb dependency — this class contains only the COALESCE
 * logic used by both the backfill migration and the runtime write paths.
 *
 * @since  1.21.0
 * @package ANPA_Socios
 */

/**
 * Pure helpers for family grouping logic.
 *
 * @since 1.21.0
 */
class ANPA_Socios_Familia {

	/**
	 * Resolves the effective familia_id for a socio.
	 *
	 * Business rule: if the socio has a non-zero familia_id, use it.
	 * Otherwise fall back to the socio's own id (single-parent or
	 * pre-linkage state). This mirrors the DB backfill SQL:
	 * COALESCE(NULLIF(familia_id, 0), id).
	 *
	 * @since  1.21.0
	 * @param  int|null $familia_id The socios.familia_id value (nullable).
	 * @param  int      $socio_id   The socios.id value (always > 0).
	 * @return int Resolved familia_id (always > 0).
	 */
	public static function resolve_familia_id( ?int $familia_id, int $socio_id ): int {
		if ( null !== $familia_id && $familia_id > 0 ) {
			return $familia_id;
		}

		return $socio_id;
	}

	/**
	 * Resolves the effective familia_id from an area session profile array.
	 *
	 * The profile is the associative array stashed by permission_area_session
	 * (keys: id, email, familia_id, …). Returns the resolved familia_id using
	 * the same COALESCE logic, or 0 when the profile is missing the required
	 * fields (defensive guard — should never happen post-migration).
	 *
	 * @since  1.21.0
	 * @param  array<string,string> $profile Area session profile.
	 * @return int Resolved familia_id (> 0 on success, 0 on missing data).
	 */
	public static function resolve_from_profile( array $profile ): int {
		$socio_id   = isset( $profile['id'] ) ? (int) $profile['id'] : 0;
		$familia_id = isset( $profile['familia_id'] ) && '' !== $profile['familia_id']
			? (int) $profile['familia_id']
			: null;

		if ( $socio_id <= 0 ) {
			return 0;
		}

		return self::resolve_familia_id( $familia_id, $socio_id );
	}
}
