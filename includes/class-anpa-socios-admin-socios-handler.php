<?php
/**
 * Admin REST handler for the socio domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/socio*` endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Socios_Handler {

	/**
	 * Registers socio admin routes.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socios', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_socios' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socio/(?P<email>[^/]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_socio' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_socio' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socio/(?P<email>[^/]+)/baixa/confirm', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'confirm_baixa' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socios/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'socios_stats' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/config', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_config' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/socios
	 *
	 * @since  1.3.0
	 * @return WP_REST_Response
	 */
	public static function list_socios(): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT email, nome, apelidos, telefono, nif, estado, rol, baixa_estado, baixa_solicitada_en, creado_en, actualizado_en FROM {$wpdb->prefix}anpa_socios ORDER BY email ASC",
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * GET /admin/socio/<email>
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_socio( WP_REST_Request $request ) {
		global $wpdb;

		// rawurldecode guards against environments where the percent-encoded
		// "@" (%40) in the path segment reaches us undecoded. Safety does not
		// rely on the decode being a no-op: whatever it yields must still pass
		// sanitise_email() (strtolower + trim + FILTER_VALIDATE_EMAIL) and is
		// only ever used in a prepared statement (%s).
		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', 'Email inválido', array( 'status' => 400 ) );
		}

		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT email, nome, apelidos, telefono, nif, estado, rol, baixa_estado, baixa_solicitada_en, creado_en, actualizado_en FROM {$wpdb->prefix}anpa_socios WHERE email = %s",
				$email
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'anpa_admin_socio_not_found', 'Socio non atopado', array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * PATCH /admin/socio/<email>
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_socio( WP_REST_Request $request ) {
		global $wpdb;

		// rawurldecode: see get_socio() — tolerate an undecoded %40 in the path.
		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', 'Email inválido', array( 'status' => 400 ) );
		}

		$body  = ANPA_Socios_Admin_Shared::json_body( $request );

		$nome     = ANPA_Socios_Admin_Payload::sanitise_optional_string( $body['nome'] ?? null, 50 );
		$apelidos = ANPA_Socios_Admin_Payload::sanitise_optional_string( $body['apelidos'] ?? null, 100 );
		$telefono = isset( $body['telefono'] ) ? (string) $body['telefono'] : null;
		$nif      = isset( $body['nif'] ) ? (string) $body['nif'] : null;
		$estado   = isset( $body['estado'] ) ? (string) $body['estado'] : null;
		$rol      = isset( $body['rol'] ) ? (string) $body['rol'] : null;

		if ( null === $nome || null === $apelidos ) {
			return new WP_Error( 'anpa_admin_invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}
		if ( null !== $estado && ! in_array( $estado, array( 'activo', 'pendiente_alta', 'pendente_aprobacion', 'baixa' ), true ) ) {
			return new WP_Error( 'anpa_admin_invalid', 'Estado inválido', array( 'status' => 400 ) );
		}
		if ( null !== $rol && ! ANPA_Socios_Roles::rol_valido( $rol ) ) {
			return new WP_Error( 'anpa_admin_invalid', 'Rol inválido', array( 'status' => 400 ) );
		}

		// Initial master lock: the configured master account is fully immutable
		// from the admin surface (no nome/apelidos/estado/rol change, no baixa).
		$is_root = ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() );
		if ( $is_root ) {
			return new WP_Error( 'anpa_admin_protected_root',
				'Usuario master inicial — non é posible modificar os seus datos nin o seu estado',
				array( 'status' => 403 ) );
		}

		// True PATCH semantics: omitted estado/rol keep their CURRENT stored
		// value rather than resetting to defaults. Resetting to 'socio'/'activo'
		// on a name-only edit would silently demote co-admins and reactivate
		// socios who are in baixa — an integrity hazard. Fetch the current row
		// to resolve effective values (and to 404 cleanly on a missing socio).
		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT estado, rol FROM {$wpdb->prefix}anpa_socios WHERE email = %s",
				$email
			),
			ARRAY_A
		);
		if ( ! is_array( $current ) ) {
			return new WP_Error( 'anpa_admin_socio_not_found', 'Socio non atopado', array( 'status' => 404 ) );
		}

		$final_estado = ( null === $estado ) ? (string) $current['estado'] : $estado;
		$final_rol    = ( null === $rol ) ? (string) $current['rol'] : $rol;

		// Lockout guard: a master in 'baixa' cannot receive login codes
		// (solicitar-codigo serves only active socios), so it would lock the
		// admin out. Require demoting to socio first. (Root already returned above.)
		if ( 'baixa' === $final_estado && 'master' === $final_rol ) {
			return new WP_Error(
				'anpa_admin_master_no_baixa',
				'Non se pode poñer en baixa a un administrador. Cambia primeiro o seu rol a socio.',
				array( 'status' => 409 )
			);
		}

		// Build update data dynamically: only include telefono/nif if the admin
		// explicitly sent them (so empty-string clears a previously stored value).
		$update_data = array(
			'nome'           => $nome,
			'apelidos'       => $apelidos,
			'estado'         => $final_estado,
			'rol'            => $final_rol,
			'actualizado_en' => current_time( 'mysql' ),
		);
		$update_types = array( '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $telefono ) {
			$update_data['telefono'] = $telefono;
			$update_types[]          = '%s';
		}
		if ( null !== $nif ) {
			$update_data['nif'] = $nif;
			$update_types[]     = '%s';
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_socios',
			$update_data,
			array( 'email' => $email ),
			$update_types,
			array( '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'socio', $email, 'update' );

		return self::get_socio( $request );
	}

	/**
	 * POST /admin/socio/<email>/baixa/confirm — master-only.
	 *
	 * Confirms a pending member baixa: sets estado='baixa' (the baixa is only
	 * effective after this action) and clears the pending request flag. The
	 * protected root admin can never be given baixa.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm_baixa( WP_REST_Request $request ) {
		global $wpdb;

		// rawurldecode: see get_socio() — tolerate an undecoded %40 in the path.
		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', 'Email inválido', array( 'status' => 400 ) );
		}

		// Protected root guard: the root admin can never be given baixa.
		if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return new WP_Error( 'anpa_admin_protected_root',
				'O administrador raíz non pode ser dado de baixa',
				array( 'status' => 403 ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_socios',
			array(
				'estado'         => 'baixa',
				'baixa_estado'   => 'none',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'email'        => $email,
				'baixa_estado' => 'solicitada',
			),
			array( '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', 'Erro interno', array( 'status' => 500 ) );
		}
		if ( 0 === $updated ) {
			// No row matched the pending-request precondition: refuse rather than
			// silently giving baixa to a socio who never requested it.
			return new WP_Error(
				'anpa_admin_no_baixa_request',
				'Este socio/a non ten unha solicitude de baixa pendente',
				array( 'status' => 409 )
			);
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'socio', $email, 'baixa_confirm' );

		return self::get_socio( $request );
	}

	/**
	 * GET /admin/socios/stats — count socios with empty telefono/nif.
	 *
	 * @since  1.21.0
	 * @return WP_REST_Response
	 */
	public static function socios_stats(): WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'anpa_socios';

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$activos   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE estado = 'activo'" );
		$sem_tel   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (telefono IS NULL OR telefono = '') AND estado = 'activo'" );
		$sem_nif   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (nif IS NULL OR nif = '') AND estado = 'activo'" );
		$sem_ambos = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (telefono IS NULL OR telefono = '') AND (nif IS NULL OR nif = '') AND estado = 'activo'" );

		return new WP_REST_Response( array(
			'total_socios'     => $total,
			'activos'          => $activos,
			'sem_telefono'     => $sem_tel,
			'sem_nif'          => $sem_nif,
			'sem_ambos'        => $sem_ambos,
		), 200 );
	}

	/**
	 * GET /admin/config — system configuration for the admin UI.
	 *
	 * Returns cursos, aulas and other dynamic values so the frontend
	 * never hardcodes them. All values come from the payload validation
	 * constants so there is a single source of truth.
	 *
	 * @since  1.21.0
	 * @return WP_REST_Response
	 */
	public static function get_config(): WP_REST_Response {
		return new WP_REST_Response( array(
			'cursos'   => ANPA_Socios_Admin_Payload::CURSO_VALIDOS,
			'aulas'    => ANPA_Socios_Admin_Payload::GRUPO_VALIDOS,
			'defaults' => array(
				'provincia' => 'A Coruña',
				'poboacion' => 'Ames',
			),
		), 200 );
	}
}
