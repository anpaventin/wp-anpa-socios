<?php
/**
 * One email, one role: an address used by a socio/a can never be a company
 * address and vice versa (1.55.0).
 *
 * The login flow decides "socio" or "empresa" from the email alone, so sharing
 * an address would silently hide one of the two panels. Every write path that
 * assigns an email (public alta, area profile/second parent, admin socios and
 * empresas, CSV imports) asks this class first.
 *
 * @since  1.55.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Ownership {

	const MSG_EMAIL_DE_EMPRESA = 'Ese correo pertence a unha empresa de actividades. Un mesmo correo non pode usarse para un socio/a e para unha empresa.';
	const MSG_EMAIL_DE_SOCIO   = 'Ese correo xa pertence a un socio/a. Un mesmo correo non pode usarse para un socio/a e para unha empresa.';
	const MSG_EMAIL_DE_COMEDOR = 'Ese correo é o da conta do comedor escolar (Axustes). Un mesmo correo non pode usarse para o comedor e para un socio/a ou unha empresa.';

	/** Lower-cases and trims for the UNIQUE comparisons (both tables store lower-case). */
	public static function normalizar( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/** @return int|null Socio id (any estado) owning the email, or null. */
	public static function socio_por_email( string $email ): ?int {
		$email = self::normalizar( $email );
		if ( '' === $email ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ownership lookup.
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ANPA_Socios_DB::tabela_socios() . ' WHERE email = %s LIMIT 1', $email ) );
		return null === $id ? null : (int) $id;
	}

	/** @return int|null Empresa id (any estado) owning the email, or null. */
	public static function empresa_por_email( string $email ): ?int {
		$email = self::normalizar( $email );
		if ( '' === $email ) {
			return null;
		}
		// 1.56.0: the canteen account (Axustes) behaves as a company for login; id 0 marks it.
		if ( ANPA_Socios_Config::is_comedor_email( $email ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ownership lookup.
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ANPA_Socios_DB::tabela_empresas() . ' WHERE email = %s LIMIT 1', $email ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Guard for company writes: the email must not belong to any socio/a.
	 *
	 * @return WP_Error|null 409 when reserved.
	 */
	public static function conflito_para_empresa( string $email ): ?WP_Error {
		if ( ANPA_Socios_Config::is_comedor_email( $email ) ) {
			return new WP_Error( 'anpa_email_reservado_comedor', __( self::MSG_EMAIL_DE_COMEDOR, 'anpa-socios' ), array( 'status' => 409, 'fields' => array( 'email' => __( self::MSG_EMAIL_DE_COMEDOR, 'anpa-socios' ) ) ) );
		}
		if ( null === self::socio_por_email( $email ) ) {
			return null;
		}
		return new WP_Error( 'anpa_email_reservado_socio', __( self::MSG_EMAIL_DE_SOCIO, 'anpa-socios' ), array( 'status' => 409, 'fields' => array( 'email' => __( self::MSG_EMAIL_DE_SOCIO, 'anpa-socios' ) ) ) );
	}

	/**
	 * Guard for socio writes: the email must not belong to any company.
	 *
	 * @param  string $email Email being assigned to a socio/a.
	 * @param  string $field Field key reported in `fields` (p1_email, p2_email, email…).
	 * @return WP_Error|null 409 when reserved.
	 */
	public static function conflito_para_socio( string $email, string $field = 'email' ): ?WP_Error {
		if ( null === self::empresa_por_email( $email ) ) {
			return null;
		}
		$msg = ANPA_Socios_Config::is_comedor_email( $email ) ? self::MSG_EMAIL_DE_COMEDOR : self::MSG_EMAIL_DE_EMPRESA;
		return new WP_Error( 'anpa_email_reservado_empresa', __( $msg, 'anpa-socios' ), array( 'status' => 409, 'fields' => array( $field => __( $msg, 'anpa-socios' ) ) ) );
	}
}
