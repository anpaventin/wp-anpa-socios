<?php
/**
 * Changing a member's email (1.84.0), shared by «Cambiar correo» in Xestión,
 * the 2nd parent's email (Xestión and member area) and the member's own email
 * in the area: the same checks everywhere, and the rest of the data follows.
 *
 * @since   1.84.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member email change.
 *
 * @since 1.84.0
 */
final class ANPA_Socios_Cambio_Email {

	/**
	 * The new email, normalised, or why it cannot be used.
	 *
	 * @param  string $novo     Typed email.
	 * @param  int    $socio_id Member whose email changes (0 = a new row).
	 * @param  string $campo    Form field for the error ('email', 'p2_email'…).
	 * @return string|WP_Error
	 */
	public static function validar( string $novo, int $socio_id, string $campo = 'email' ) {
		global $wpdb;
		$email = ANPA_Socios_Normalize::email( $novo );
		if ( null === $email || strlen( $email ) > 100 ) {
			return new WP_Error( 'anpa_email_invalido', __( 'O correo non é válido.', 'anpa-socios' ), array( 'status' => 400, 'fields' => array( $campo => __( 'O correo non é válido.', 'anpa-socios' ) ) ) );
		}
		if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return new WP_Error( 'anpa_email_xunta', __( 'Ese é o correo da xunta directiva: non pode ser o dun socio/a.', 'anpa-socios' ), array( 'status' => 409, 'fields' => array( $campo => __( 'É o correo da xunta.', 'anpa-socios' ) ) ) );
		}
		$reservado = ANPA_Socios_Email_Ownership::conflito_para_socio( $email, $campo );
		if ( null !== $reservado ) {
			return $reservado;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uniqueness check.
		$outro = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ANPA_Socios_DB::tabela_socios() . ' WHERE email = %s AND id <> %d LIMIT 1', $email, $socio_id ) );
		if ( null !== $outro ) {
			return new WP_Error( 'anpa_area_email_taken', __( 'Ese email xa está rexistrado por outro socio/a', 'anpa-socios' ), array( 'status' => 409, 'fields' => array( $campo => __( 'Xa o usa outro socio/a.', 'anpa-socios' ) ) ) );
		}
		return $email;
	}

	/**
	 * After the socio row got its new email: the children it added follow it,
	 * and the sessions and login codes of the old address are removed (whoever
	 * uses the new address signs in again with a code sent to it).
	 *
	 * Only the children of THIS family move (an old address may still sit on
	 * another family's rows); $sen_familia also moves old rows without a family
	 * (only from Xestión).
	 *
	 * @param  string $vello       Old email.
	 * @param  string $novo        New email.
	 * @param  int    $familia_id  Family of the member.
	 * @param  bool   $sen_familia Also rows with no family id.
	 * @return bool
	 */
	public static function aplicar( string $vello, string $novo, int $familia_id, bool $sen_familia = false ): bool {
		global $wpdb;
		$vello = strtolower( trim( $vello ) );
		$novo  = strtolower( trim( $novo ) );
		if ( '' === $vello || $vello === $novo ) {
			return true;
		}
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$extra = $sen_familia ? ' OR familia_id IS NULL OR familia_id = 0' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL fragment.
		$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$fil_t} SET socio_email = %s WHERE socio_email = %s AND ( familia_id = %d{$extra} )", $novo, $vello, $familia_id ) );
		$ok = false !== $wpdb->delete( ANPA_Socios_DB::tabela_sesions(), array( 'email' => $vello ), array( '%s' ) ) && $ok;
		$ok = false !== $wpdb->delete( $wpdb->prefix . 'anpa_codigos_verificacion', array( 'email' => $vello ), array( '%s' ) ) && $ok;
		return $ok;
	}
}
