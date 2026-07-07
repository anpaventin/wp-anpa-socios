<?php
/**
 * Pure role and state helpers for the ANPA Socios plugin.
 *
 * No WordPress dependency, no I/O. Unit-testable with PHPUnit.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Validates socio roles and the master email constant.
 *
 * @since 1.2.0
 */
final class ANPA_Socios_Roles {

	/**
	 * Email of the master (directiva) socio.
	 *
	 * Used by permission callbacks that require admin access. The constant
	 * is duplicated here as a fallback so the helper is testable without
	 * WordPress; the runtime value in production is the same string
	 * (see `ANPA_Socios_Roles::master_email()`).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const MASTER_EMAIL = 'xunta.directiva@anpaventin.es';

	/**
	 * Allowed socio roles.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const ROLES = array( 'socio', 'master' );

	/**
	 * Allowed socio states (subset used by permission checks).
	 *
	 * `pendiente_alta` is a transitional state for users who requested a
	 * code but have not yet completed the signup form. `baixa` covers
	 * both retired and explicitly removed.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const ESTADOS_VALIDOS = array( 'activo', 'pendiente_alta' );

	/**
	 * Master email used at runtime.
	 *
	 * Falls back to the constant so the class stays testable without WP.
	 *
	 * @since  1.2.0
	 * @return string
	 */
	public static function master_email(): string {
		return self::MASTER_EMAIL;
	}

	/**
	 * Checks whether a socio with the given email and rol qualifies as admin.
	 *
	 * Returns true when the rol is exactly 'master' (data-driven, any email).
	 * The email is still required non-empty as a defensive guard.
	 * The optional $master_email parameter is kept for signature compatibility
	 * but is no longer used in the positive path.
	 *
	 * @since  1.2.0
	 * @param  string      $email        Socio email.
	 * @param  string      $rol          Stored rol in wp_anpa_socios.
	 * @param  string|null $master_email  Unused (kept for compatibility).
	 * @return bool
	 */
	public static function es_master( string $email, string $rol, ?string $master_email = null ): bool {
		if ( '' === $email || '' === $rol ) {
			return false;
		}

		return 'master' === $rol;
	}

	/**
	 * Checks whether the given email belongs to the protected root admin.
	 *
	 * The root admin can never be demoted or removed. This guard is pure
	 * (no WP, no I/O) and is the single source of truth for root protection.
	 * Case-insensitive comparison against the provided master email or the
	 * default MASTER_EMAIL constant when null.
	 *
	 * @since  1.4.0
	 * @since  1.4.1 Added optional $master_email parameter for configurability.
	 * @param  string      $email        Email to check.
	 * @param  string|null $master_email Override master email (null = MASTER_EMAIL constant).
	 * @return bool
	 */
	public static function is_protected_admin( string $email, ?string $master_email = null ): bool {
		if ( '' === trim( $email ) ) {
			return false;
		}

		$master = $master_email ?? self::MASTER_EMAIL;

		return strtolower( trim( $email ) ) === strtolower( trim( $master ) );
	}

	/**
	 * Validates a stored rol value.
	 *
	 * @since  1.2.0
	 * @param  string $rol Stored rol.
	 * @return bool
	 */
	public static function rol_valido( string $rol ): bool {
		return in_array( $rol, self::ROLES, true );
	}

	/**
	 * Validates a stored estado value.
	 *
	 * Only returns true for states that grant area access: `activo` and
	 * `pendiente_alta` (the latter so a user can resume an incomplete
	 * signup after re-requesting a code). `baixa` and unknown values
	 * return false.
	 *
	 * @since  1.2.0
	 * @param  string $estado Stored estado.
	 * @return bool
	 */
	public static function estado_socio_valido( string $estado ): bool {
		return in_array( $estado, self::ESTADOS_VALIDOS, true );
	}
}
