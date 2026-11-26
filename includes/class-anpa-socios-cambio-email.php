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

	/** Life of the code sent to a new address, in seconds. */
	const CODIGO_TTL = 900;

	/** Wrong guesses allowed per code. */
	const CODIGO_INTENTOS = 5;

	/** Codes a member may ask for per hour. */
	const CODIGO_ENVIOS_HORA = 5;

	/**
	 * 1.102.0: a member changing their own email in the area must prove the new
	 * address. Sends a 6-digit code to it and keeps the pending change (hash only)
	 * for 15 minutes. The pending change belongs to the member, not to the
	 * address, so the code cannot be used to sign in or to join.
	 *
	 * @param  int    $socio_id Member asking for the change.
	 * @param  string $novo     New address, already validated.
	 * @return true|WP_Error
	 */
	public static function solicitar_codigo( int $socio_id, string $novo ) {
		$novo     = strtolower( trim( $novo ) );
		$chave_n  = 'anpa_cambio_email_n_' . $socio_id;
		$enviados = (int) get_transient( $chave_n );
		if ( $enviados >= self::CODIGO_ENVIOS_HORA ) {
			return new WP_Error( 'anpa_area_rate_limited', __( 'Demasiados intentos. Téntao de novo máis tarde.', 'anpa-socios' ), array( 'status' => 429 ) );
		}
		set_transient( $chave_n, $enviados + 1, HOUR_IN_SECONDS );

		$codigo = ANPA_Socios_Codigo_Generator::generate();
		set_transient(
			'anpa_cambio_email_' . $socio_id,
			array(
				'email'    => $novo,
				'hash'     => ANPA_Socios_Codigo_Generator::hash_code( $codigo ),
				'expira'   => ANPA_Socios_Codigo_Generator::expiry( time() ),
				'intentos' => 0,
			),
			self::CODIGO_TTL
		);
		if ( ! ANPA_Socios_Email::enviar_codigo( $novo, $codigo, 'verificacion' ) ) {
			delete_transient( 'anpa_cambio_email_' . $socio_id );
			return new WP_Error( 'anpa_area_email_send_failed', __( 'Non se puido enviar o código ao novo correo. Téntao de novo máis tarde.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Checks the code typed for a pending email change. The attempt is counted
	 * before the comparison, and the pending change is used up on success.
	 *
	 * @param  int    $socio_id Member asking for the change.
	 * @param  string $novo     New address in the request (must be the pending one).
	 * @param  string $codigo   Typed code.
	 * @return true|WP_Error
	 */
	public static function comprobar_codigo( int $socio_id, string $novo, string $codigo ) {
		$chave    = 'anpa_cambio_email_' . $socio_id;
		$pendente = get_transient( $chave );
		$caducado = new WP_Error( 'anpa_area_email_code_expired', __( 'O código caducou. Garda de novo para recibir outro.', 'anpa-socios' ), array( 'status' => 400, 'fields' => array( 'email_codigo' => __( 'Código caducado.', 'anpa-socios' ) ) ) );
		if ( ! self::pendente_vixente( $pendente, $novo, time() ) ) {
			delete_transient( $chave );
			return $caducado;
		}
		++$pendente['intentos'];
		set_transient( $chave, $pendente, max( 1, (int) $pendente['expira'] - time() ) );
		if ( ! ANPA_Socios_Codigo_Generator::verify( $codigo, (string) $pendente['hash'] ) ) {
			return new WP_Error( 'anpa_area_email_code_invalid', __( 'Código incorrecto', 'anpa-socios' ), array( 'status' => 400, 'fields' => array( 'email_codigo' => __( 'Código incorrecto.', 'anpa-socios' ) ) ) );
		}
		delete_transient( $chave );
		return true;
	}

	/**
	 * Whether a stored pending change can still take a guess for this address.
	 * Pure: no WordPress, no I/O.
	 *
	 * @param  mixed  $pendente Stored value (array with email, hash, expira, intentos).
	 * @param  string $novo     Address in the request.
	 * @param  int    $agora    Reference time.
	 * @return bool
	 */
	public static function pendente_vixente( $pendente, string $novo, int $agora ): bool {
		if ( ! is_array( $pendente ) || ! isset( $pendente['email'], $pendente['hash'], $pendente['expira'], $pendente['intentos'] ) ) {
			return false;
		}
		if ( strtolower( trim( $novo ) ) !== (string) $pendente['email'] ) {
			return false;
		}
		return (int) $pendente['expira'] > $agora && (int) $pendente['intentos'] < self::CODIGO_INTENTOS;
	}

	/**
	 * 1.103.0: what one parent changed in the other's data, for the email the
	 * other one receives. Pure. The NIF is shown masked (last four characters).
	 *
	 * @param  array<string,mixed> $antes   Row before (nome, apelidos, email, nif, telefono).
	 * @param  array<string,mixed> $despois Row after.
	 * @return array<int,array{campo:string,antes:string,despois:string}>
	 */
	public static function cambios( array $antes, array $despois ): array {
		$campos = array(
			'nome'     => 'Nome',
			'apelidos' => 'Apelidos',
			'nif'      => 'NIF/NIE',
			'telefono' => 'Teléfono',
			'email'    => 'Correo electrónico',
		);
		$lista  = array();
		foreach ( $campos as $chave => $etiqueta ) {
			$a = trim( (string) ( $antes[ $chave ] ?? '' ) );
			$d = trim( (string) ( $despois[ $chave ] ?? '' ) );
			if ( 'email' === $chave ? strtolower( $a ) === strtolower( $d ) : $a === $d ) {
				continue;
			}
			if ( 'nif' === $chave ) {
				$a = self::nif_oculto( $a );
				$d = self::nif_oculto( $d );
			}
			$lista[] = array( 'campo' => $etiqueta, 'antes' => $a, 'despois' => $d );
		}
		return $lista;
	}

	/**
	 * @param  string $nif NIF/NIE.
	 * @return string Only the last four characters visible ('' stays '').
	 */
	public static function nif_oculto( string $nif ): string {
		$n = strlen( $nif );
		return $n <= 4 ? $nif : str_repeat( '*', $n - 4 ) . substr( $nif, -4 );
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
