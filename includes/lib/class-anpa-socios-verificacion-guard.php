<?php
/**
 * Pure decision helper for the absorbed verification module (fase13b).
 *
 * Decides whether anpa-socios should register the `anpa/v1` verification routes,
 * given whether the legacy `anpa-verificacion` plugin is active. Kept pure so it
 * is unit-testable without WordPress.
 *
 * @since  1.26.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Should-register decision for the verification routes.
 *
 * @since 1.26.0
 */
final class ANPA_Socios_Verificacion_Guard {

	/**
	 * Register the routes here only when the legacy plugin is NOT active, so
	 * the `anpa/v1` routes are never registered twice.
	 *
	 * @since  1.26.0
	 * @param  bool $legacy_active Whether the legacy anpa-verificacion is active.
	 * @return bool
	 */
	public static function should_register( bool $legacy_active ): bool {
		return ! $legacy_active;
	}
}
