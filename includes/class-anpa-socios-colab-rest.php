<?php
/**
 * Collaborators network (1.104.0): REST routes.
 *
 * - Shops sign in with email + code (own codes and sessions), propose their
 *   own data and logo and manage their own offers. The shop is always the one
 *   of the session, never of the request.
 * - The till (caixa, 1.105.0 v2) works with the shop's key in a header and
 *   the family's card code: it learns «valid family» and that family's
 *   remaining offers at this shop, nothing else.
 * - A member (area session) gets the family's permanent card.
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST surface of the collaborators module.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab_REST {

	const NS = 'anpa-socios/v1';

	/** Shop session header. */
	const TOKEN_HEADER = 'X-Anpa-Colab-Token';

	/** Till header: the shop key from its QR (1.105.0 v2). */
	const CAIXA_HEADER = 'X-Anpa-Colab-Caixa';

	/** Wrong guesses allowed per login code. */
	const CODIGO_INTENTOS = 5;

	/** Login codes one address may be sent per hour, whatever the IP. */
	const CODIGOS_EMAIL_HORA = 10;

	/** Till requests one shop may make in ten minutes. */
	const CAIXA_COMERCIO = 120;

	/** Till requests one IP may make in ten minutes. */
	const CAIXA_IP = 60;

	/**
	 * Routes. Nothing is registered while the module is off.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		if ( ! ANPA_Socios_Colab_DB::activo() ) {
			return;
		}
		ANPA_Socios_Colab_DB::instalar(); // No-op unless the schema is older.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'non_gardar' ), 10, 3 );
		$publico = '__return_true';
		$tenda   = array( __CLASS__, 'permission_comercio' );
		$caixa   = array( __CLASS__, 'permission_caixa' );
		$area    = array( 'ANPA_Socios_Area_REST', 'permission_area_session' );
		$texto   = array( 'type' => 'string', 'required' => false );

		register_rest_route( self::NS, '/colab/solicitar-codigo', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_solicitar_codigo' ),
			'permission_callback' => $publico,
			'args'                => array(
				'email'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'website' => $texto,
				'_ts'     => $texto,
			),
		) );
		register_rest_route( self::NS, '/colab/entrar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_entrar' ),
			'permission_callback' => $publico,
			'args'                => array(
				'email'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'codigo' => array( 'type' => 'string', 'required' => true ),
			),
		) );
		register_rest_route( self::NS, '/colab/me', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_me' ),
				'permission_callback' => $tenda,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'handle_propor' ),
				'permission_callback' => $tenda,
			),
		) );
		register_rest_route( self::NS, '/colab/me/session', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'handle_sair' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/me/logo', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_logo' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/me/proposta', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'handle_retirar_proposta' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/me/qr', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_renovar_qr' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/ofertas', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_gardar_oferta' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/ofertas/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'handle_gardar_oferta' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/ofertas/(?P<id>\d+)/retirar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_retirar_oferta' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/ofertas/(?P<id>\d+)/imaxe', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_imaxe_oferta' ),
				'permission_callback' => $tenda,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_quitar_imaxe_oferta' ),
				'permission_callback' => $tenda,
			),
		) );

		// Till (point of sale). Codes only in the header and in POST bodies.
		register_rest_route( self::NS, '/colab/caixa', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_caixa' ),
			'permission_callback' => $caixa,
		) );
		register_rest_route( self::NS, '/colab/caixa/familia', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_caixa_familia' ),
			'permission_callback' => $caixa,
		) );
		register_rest_route( self::NS, '/colab/caixa/aplicar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_caixa_aplicar' ),
			'permission_callback' => $caixa,
		) );
		register_rest_route( self::NS, '/colab/caixa/desfacer', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_caixa_desfacer' ),
			'permission_callback' => $caixa,
		) );

		// Member area: the family's own card.
		register_rest_route( self::NS, '/area/carne', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_carne' ),
			'permission_callback' => $area,
		) );
		register_rest_route( self::NS, '/area/carne/renovar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_renovar_carne' ),
			'permission_callback' => $area,
		) );
		register_rest_route( self::NS, '/area/carne/ofertas', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_ofertas_carne' ),
			'permission_callback' => $area,
		) );
		register_rest_route( self::NS, '/area/carne/correo', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'handle_correo_carne' ),
			'permission_callback' => $area,
		) );
	}

	/**
	 * Every answer of the module's routes (errors included) is no-store.
	 *
	 * @param  WP_HTTP_Response $result  Response.
	 * @param  WP_REST_Server   $server  Server.
	 * @param  WP_REST_Request  $request Request.
	 * @return WP_HTTP_Response
	 */
	public static function non_gardar( $result, $server, $request ) {
		unset( $server );
		$ruta = $request instanceof WP_REST_Request ? (string) $request->get_route() : '';
		if ( $result instanceof WP_HTTP_Response && ( 0 === strpos( $ruta, '/' . self::NS . '/colab/' ) || 0 === strpos( $ruta, '/' . self::NS . '/area/carne' ) ) ) {
			$result->header( 'Cache-Control', 'no-store, private' );
		}

		return $result;
	}

	/**
	 * Shop session gate (canonical authenticator, shop context).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_comercio( WP_REST_Request $request ) {
		$auth = ANPA_Socios_Area_REST::authenticate_area_session(
			$request,
			'anpa_colab_me_rl_',
			array(
				'table'        => ANPA_Socios_Colab_DB::sesions(),
				'token_header' => self::TOKEN_HEADER,
				'principal'    => array( 'ANPA_Socios_Colab_Service', 'comercio_activo' ),
			)
		);
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$request->set_param( '_anpa_colab_session_id', $auth['session_id'] );
		$request->set_param( '_anpa_colab_comercio', $auth['profile'] );

		return true;
	}

	/**
	 * Till gate: the shop key in the header, of an ACTIVE shop. Limited per IP
	 * and per shop; any bad key gets the same 401.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_caixa( WP_REST_Request $request ) {
		$limite = new WP_Error( 'anpa_colab_limite', __( 'Demasiadas peticións. Agarda uns minutos.', 'anpa-socios' ), array( 'status' => 429 ) );
		if ( ! self::limite_ip( 'anpa_colab_caixa_ip_', self::CAIXA_IP, 10 * MINUTE_IN_SECONDS ) ) {
			return $limite;
		}
		$clave    = ANPA_Socios_Colab::normalizar_codigo( (string) $request->get_header( self::CAIXA_HEADER ), ANPA_Socios_Colab::CLAVE_LONXITUDE );
		$comercio = ANPA_Socios_Colab_Service::comercio_por_clave( $clave );
		if ( null === $comercio ) {
			return new WP_Error( 'anpa_colab_caixa_clave', __( 'Este dispositivo non ten a clave dun comercio activo. Escanea de novo o QR do teu comercio.', 'anpa-socios' ), array( 'status' => 401 ) );
		}
		if ( ! self::limite( 'anpa_colab_caixa_c_' . $comercio['id'], self::CAIXA_COMERCIO, 10 * MINUTE_IN_SECONDS ) ) {
			return $limite;
		}
		$request->set_param( '_anpa_colab_caixa', $comercio );

		return true;
	}

	/**
	 * POST /colab/solicitar-codigo — a login code for an ACTIVE shop. Always the
	 * same answer, whether or not the address is a shop.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_solicitar_codigo( WP_REST_Request $request ) {
		global $wpdb;
		$xenerica = new WP_REST_Response( array( 'success' => true, 'message' => __( 'Se o correo é dun comercio colaborador, recibirás un código en breve.', 'anpa-socios' ) ), 200 );
		$email    = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$ts       = $request->get_param( '_ts' );
		if ( ! is_email( $email ) || ! ANPA_Socios_Antibot::passes( (string) $request->get_param( 'website' ), is_numeric( $ts ) ? (int) $ts : null, time() ) ) {
			return $xenerica;
		}
		if ( ! self::limite_ip( 'anpa_colab_cod_ip_', 10, HOUR_IN_SECONDS ) ) {
			return $xenerica;
		}
		$t = ANPA_Socios_Colab_DB::codigos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$recentes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE email = %s AND creado_ts >= %d", $email, time() - HOUR_IN_SECONDS ) );
		$comercio = ANPA_Socios_Colab_Service::comercio_activo( $email );
		if ( null === $comercio || $recentes >= self::CODIGOS_EMAIL_HORA ) {
			ANPA_Socios_Codigo_Generator::hash_code( ANPA_Socios_Codigo_Generator::generate() ); // Same work on every path.
			return $xenerica;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET usado = 1 WHERE email = %s AND usado = 0", $email ) );
		$codigo = ANPA_Socios_Codigo_Generator::generate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$ok = $wpdb->insert(
			$t,
			array(
				'email'       => $email,
				'codigo_hash' => ANPA_Socios_Codigo_Generator::hash_code( $codigo ),
				'expira_ts'   => ANPA_Socios_Codigo_Generator::expiry( time() ),
				'intentos'    => 0,
				'usado'       => 0,
				'ip'          => self::ip(),
				'creado_ts'   => time(),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
		);
		if ( false !== $ok ) {
			ANPA_Socios_Email::enviar_codigo( $email, $codigo, 'verificacion' );
		}

		return $xenerica;
	}

	/**
	 * POST /colab/entrar — checks the code and opens the shop session. The
	 * guess is counted before it is checked, in one statement.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_entrar( WP_REST_Request $request ) {
		global $wpdb;
		$erro   = new WP_Error( 'anpa_colab_codigo', __( 'Código incorrecto ou caducado. Pide un novo.', 'anpa-socios' ), array( 'status' => 400 ) );
		$email  = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$codigo = (string) preg_replace( '/\D/', '', (string) $request->get_param( 'codigo' ) );
		if ( ! self::limite_ip( 'anpa_colab_ent_ip_', 20, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'anpa_colab_limite', __( 'Demasiados intentos. Téntao de novo máis tarde.', 'anpa-socios' ), array( 'status' => 429 ) );
		}
		if ( ! is_email( $email ) || 6 !== strlen( $codigo ) ) {
			return $erro;
		}
		$t = ANPA_Socios_Colab_DB::codigos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, codigo_hash, expira_ts FROM {$t} WHERE email = %s AND usado = 0 ORDER BY id DESC LIMIT 1", $email ), ARRAY_A );
		if ( ! is_array( $row ) || (int) $row['expira_ts'] < time() ) {
			ANPA_Socios_Codigo_Generator::hash_code( $codigo );
			return $erro;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$contado = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET intentos = intentos + 1 WHERE id = %d AND usado = 0 AND intentos < %d", (int) $row['id'], self::CODIGO_INTENTOS ) );
		if ( 1 !== (int) $contado || ! ANPA_Socios_Codigo_Generator::verify( $codigo, (string) $row['codigo_hash'] ) ) {
			return $erro;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$usado = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET usado = 1 WHERE id = %d AND usado = 0", (int) $row['id'] ) );
		if ( 1 !== (int) $usado || null === ANPA_Socios_Colab_Service::comercio_activo( $email ) ) {
			return $erro;
		}
		$sesion = ANPA_Socios_Area_REST::issue_session( ANPA_Socios_Colab_DB::sesions(), $email );
		if ( is_wp_error( $sesion ) ) {
			return $sesion;
		}

		return new WP_REST_Response( array( 'session_token' => $sesion['session_token'], 'expires_in' => $sesion['expires_in'] ), 200 );
	}

	/**
	 * GET /colab/me — the shop, its proposal, its till QR and its offers.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_me( WP_REST_Request $request ) {
		return new WP_REST_Response( self::panel( self::id_comercio( $request ) ), 200 );
	}

	/**
	 * PUT /colab/me — the shop proposes its data (never the login email).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_propor( WP_REST_Request $request ) {
		$id   = self::id_comercio( $request );
		$body = $request->get_json_params();
		$r    = ANPA_Socios_Colab::limpar_perfil( is_array( $body ) ? $body : array() );
		if ( array() !== $r['erros'] ) {
			return new WP_Error( 'anpa_colab_datos_invalidos', __( 'Revisa os campos marcados.', 'anpa-socios' ), array( 'status' => 400, 'fields' => $r['erros'] ) );
		}
		$ok = ANPA_Socios_Colab_Service::propor_datos( $id, $r['datos'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		return new WP_REST_Response( self::panel( $id ), 200 );
	}

	/**
	 * POST /colab/me/logo — multipart field `logo`: the proposal's new logo.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_logo( WP_REST_Request $request ) {
		$id    = self::id_comercio( $request );
		$c     = ANPA_Socios_Colab_Service::comercio( $id );
		$files = $request->get_file_params();
		$att   = ANPA_Socios_Colab_Service::recibir_imaxe( $id, $files['logo'] ?? null, (string) ( $c['nome'] ?? '' ) );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$ok = ANPA_Socios_Colab_Service::propor_logo( $id, $att );
		if ( is_wp_error( $ok ) ) {
			wp_delete_attachment( $att, true ); // Just uploaded and not used.
			return $ok;
		}

		return new WP_REST_Response( self::panel( $id ), 200 );
	}

	/**
	 * DELETE /colab/me/proposta — the shop withdraws its proposal.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_retirar_proposta( WP_REST_Request $request ) {
		$id = self::id_comercio( $request );
		if ( ! ANPA_Socios_Colab_Service::retirar_proposta( $id ) ) {
			return new WP_Error( 'anpa_colab_sen_proposta', __( 'Non hai datos pendentes que retirar.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( self::panel( $id ), 200 );
	}

	/**
	 * POST /colab/me/qr — a new till QR (the old one stops working).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_renovar_qr( WP_REST_Request $request ) {
		$id = self::id_comercio( $request );
		if ( ! ANPA_Socios_Colab_Service::renovar_qr( $id ) ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$c = (array) $request->get_param( '_anpa_colab_comercio' );
		ANPA_Socios_Admin_Shared::write_audit_actor( strtolower( (string) ( $c['email'] ?? '' ) ), 'comercio', 'comercio_colab', (string) $id, 'qr_comercio_renovado' );

		return new WP_REST_Response( self::panel( $id ), 200 );
	}

	/**
	 * DELETE /colab/me/session — signs the shop out.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_sair( WP_REST_Request $request ) {
		ANPA_Socios_Area_REST::delete_session_row( ANPA_Socios_Colab_DB::sesions(), (int) $request->get_param( '_anpa_colab_session_id' ) );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * POST /colab/ofertas and PUT /colab/ofertas/<id> — creates or edits an
	 * offer of the signed-in shop. It always ends pending. The image is never
	 * taken from here (see /imaxe).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_gardar_oferta( WP_REST_Request $request ) {
		$c    = (array) $request->get_param( '_anpa_colab_comercio' );
		$body = $request->get_json_params();
		$r    = ANPA_Socios_Colab::limpar_oferta( is_array( $body ) ? $body : array() );
		if ( array() !== $r['erros'] ) {
			return new WP_Error( 'anpa_colab_oferta_invalida', __( 'Revisa os campos marcados.', 'anpa-socios' ), array( 'status' => 400, 'fields' => $r['erros'] ) );
		}
		$id = ANPA_Socios_Colab_Service::gardar_oferta( (int) $c['id'], $r['datos'], (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$panel           = self::panel( (int) $c['id'] );
		$panel['gardada'] = (int) $id;

		return new WP_REST_Response( $panel, 200 );
	}

	/**
	 * POST /colab/ofertas/<id>/imaxe — multipart field `imaxe`.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_imaxe_oferta( WP_REST_Request $request ) {
		$c      = (array) $request->get_param( '_anpa_colab_comercio' );
		$id     = (int) $c['id'];
		$oferta = (int) $request->get_param( 'id' );
		if ( ! self::oferta_propia( $id, $oferta ) ) {
			return new WP_Error( 'anpa_colab_non_atopada', __( 'Non se atopou a oferta.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$files = $request->get_file_params();
		$att   = ANPA_Socios_Colab_Service::recibir_imaxe( $id, $files['imaxe'] ?? null, (string) ( $c['nome'] ?? '' ) );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$ok = ANPA_Socios_Colab_Service::imaxe_oferta( $id, $oferta, $att );
		if ( is_wp_error( $ok ) ) {
			wp_delete_attachment( $att, true ); // Just uploaded and not used.
			return $ok;
		}

		return new WP_REST_Response( self::panel( $id ), 200 );
	}

	/**
	 * DELETE /colab/ofertas/<id>/imaxe — the offer shows the shop logo again.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_quitar_imaxe_oferta( WP_REST_Request $request ) {
		$c  = (array) $request->get_param( '_anpa_colab_comercio' );
		$ok = ANPA_Socios_Colab_Service::imaxe_oferta( (int) $c['id'], (int) $request->get_param( 'id' ), 0 );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		return new WP_REST_Response( self::panel( (int) $c['id'] ), 200 );
	}

	/**
	 * POST /colab/ofertas/<id>/retirar — the shop withdraws its own offer.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_retirar_oferta( WP_REST_Request $request ) {
		$c = (array) $request->get_param( '_anpa_colab_comercio' );
		if ( ! ANPA_Socios_Colab_Service::retirar_oferta( (int) $c['id'], (int) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'anpa_colab_non_atopada', __( 'Non se atopou a oferta.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( self::panel( (int) $c['id'] ), 200 );
	}

	/**
	 * GET /colab/caixa — which shop this device is the till of.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_caixa( WP_REST_Request $request ) {
		$tenda = (array) $request->get_param( '_anpa_colab_caixa' );

		return new WP_REST_Response( array( 'comercio' => (string) $tenda['nome'] ), 200 );
	}

	/**
	 * POST /colab/caixa/familia {codigo} — valid member family, and its
	 * remaining offers at THIS shop. Nothing else about the family.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_caixa_familia( WP_REST_Request $request ) {
		$tenda   = (array) $request->get_param( '_anpa_colab_caixa' );
		$familia = self::familia_valida( $request );

		return new WP_REST_Response(
			array(
				'valida'  => $familia > 0,
				'ofertas' => $familia > 0 ? ANPA_Socios_Colab_Service::ofertas_caixa( (int) $tenda['id'], $familia ) : array(),
			),
			200
		);
	}

	/**
	 * POST /colab/caixa/aplicar {codigo, oferta_id} — subtracts one use.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_caixa_aplicar( WP_REST_Request $request ) {
		$tenda   = (array) $request->get_param( '_anpa_colab_caixa' );
		$familia = self::familia_valida( $request );
		if ( $familia <= 0 ) {
			return self::erro_familia();
		}
		$uso = ANPA_Socios_Colab_Service::aplicar_uso( (int) $tenda['id'], $familia, (int) self::corpo( $request, 'oferta_id' ) );
		if ( is_wp_error( $uso ) ) {
			return $uso;
		}

		return new WP_REST_Response(
			array(
				'aplicada' => true,
				'uso_id'   => $uso,
				'ofertas'  => ANPA_Socios_Colab_Service::ofertas_caixa( (int) $tenda['id'], $familia ),
			),
			200
		);
	}

	/**
	 * POST /colab/caixa/desfacer {codigo, uso_id} — undoes a use this shop
	 * applied to this family in the last 10 minutes.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_caixa_desfacer( WP_REST_Request $request ) {
		$tenda   = (array) $request->get_param( '_anpa_colab_caixa' );
		$familia = self::familia_valida( $request );
		if ( $familia <= 0 ) {
			return self::erro_familia();
		}
		if ( ! ANPA_Socios_Colab_Service::desfacer_uso( (int) $tenda['id'], $familia, (int) self::corpo( $request, 'uso_id' ) ) ) {
			return new WP_Error( 'anpa_colab_non_desfacer', __( 'Xa non se pode desfacer este uso (pasaron máis de 10 minutos ou xa se desfixo).', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response(
			array(
				'desfeito' => true,
				'ofertas'  => ANPA_Socios_Colab_Service::ofertas_caixa( (int) $tenda['id'], $familia ),
			),
			200
		);
	}

	/**
	 * GET /area/carne — the family's permanent card.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_carne( WP_REST_Request $request ) {
		$familia = self::familia_area( $request );
		if ( $familia <= 0 ) {
			return self::erro_sen_carne();
		}

		return self::resposta_carne( $familia );
	}

	/**
	 * POST /area/carne/renovar — a new card (the old QR stops working).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_renovar_carne( WP_REST_Request $request ) {
		$familia = self::familia_area( $request );
		if ( $familia <= 0 || ! ANPA_Socios_Colab_Service::renovar_carne( $familia ) ) {
			return self::erro_sen_carne();
		}
		$p = (array) $request->get_param( '_anpa_area_profile' );
		ANPA_Socios_Admin_Shared::write_audit_actor( strtolower( (string) ( $p['email'] ?? '' ) ), 'socio', 'familia', (string) $familia, 'carne_renovado' );

		return self::resposta_carne( $familia );
	}

	/**
	 * GET /area/carne/ofertas — every published offer in force with the
	 * family's remaining uses.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_ofertas_carne( WP_REST_Request $request ) {
		$familia = self::familia_area( $request );
		if ( $familia <= 0 ) {
			return self::erro_sen_carne();
		}

		return new WP_REST_Response( array( 'ofertas' => ANPA_Socios_Colab_Service::ofertas_familia( $familia ) ), 200 );
	}

	/**
	 * PUT /area/carne/correo {recibir} — the weekly offers email on or off.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_correo_carne( WP_REST_Request $request ) {
		$familia = self::familia_area( $request );
		if ( $familia <= 0 ) {
			return self::erro_sen_carne();
		}
		$recibir = ANPA_Socios_Colab::si( self::corpo( $request, 'recibir' ) );
		if ( ! ANPA_Socios_Colab_Service::recibir_correo( $familia, $recibir ) ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'recibir_correo' => $recibir ), 200 );
	}

	/**
	 * The card payload. The adults' names are the family's own data, only for
	 * its own card; the code goes after «#» (never sent to a server by a browser).
	 *
	 * @param  int $familia Family id (member).
	 * @return WP_REST_Response|WP_Error
	 */
	private static function resposta_carne( int $familia ) {
		$carne = ANPA_Socios_Colab_Service::carne( $familia );
		if ( null === $carne ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$paxina = ANPA_Socios_Colab_Page::url_caixa();

		return new WP_REST_Response(
			array(
				'codigo'         => ANPA_Socios_Colab::formatar_codigo( $carne['codigo'] ),
				'url'            => '' !== $paxina ? $paxina . '#f=' . $carne['codigo'] : '',
				'asociacion'     => (string) ANPA_Socios_Config::association_name(),
				'logo_url'       => ANPA_Socios_Colab_Service::logo_carne_url(),
				'adultos'        => ANPA_Socios_Colab_Service::nomes_familia( $familia ),
				'curso'          => (string) ANPA_Socios_Curso_Activo::get(),
				'recibir_correo' => $carne['recibir_correo'],
			),
			200
		);
	}

	/**
	 * What the shop panel shows: the shop's own data, its proposal, its till
	 * QR and its offers.
	 *
	 * @param  int $id Shop id (from the session).
	 * @return array<string,mixed>
	 */
	private static function panel( int $id ): array {
		$c = ANPA_Socios_Colab_Service::comercio( $id );
		if ( null === $c ) {
			return array();
		}
		ANPA_Socios_Colab_Service::asegurar_qr( $c );
		$ofertas = array();
		$usos    = ANPA_Socios_Colab_Service::usos( (int) $c['id'] );
		$lote    = ANPA_Socios_Colab_Service::texto_lote( ANPA_Socios_Colab_Service::seguinte_lote( time() ) );
		foreach ( ANPA_Socios_Colab_Service::ofertas_de( (int) $c['id'] ) as $o ) {
			$publicada = ANPA_Socios_Colab::APROBADA === (string) $o['estado'] && (int) $o['publicada_ts'] > 0;
			$ofertas[] = array(
				'id'           => (int) $o['id'],
				'titulo'       => (string) $o['titulo'],
				'desconto'     => (string) $o['desconto'],
				'descricion'   => (string) $o['descricion'],
				'condicions'   => (string) $o['condicions'],
				'valida_dende' => (string) $o['valida_dende'],
				'valida_ata'   => (string) $o['valida_ata'],
				'acumulable'   => 1 === (int) $o['acumulable'],
				'usos_familia' => (int) $o['usos_familia'],
				'imaxe_url'    => ANPA_Socios_Colab_Service::imaxe_url( (int) $o['imaxe_id'] ),
				'estado'       => (string) $o['estado'],
				'estado_texto' => ANPA_Socios_Colab::etiqueta_estado( (string) $o['estado'], $publicada ),
				'publicada'    => $publicada,
				'sae_o'        => ( ANPA_Socios_Colab::APROBADA === (string) $o['estado'] && ! $publicada ) ? $lote : '',
				'motivo'       => (string) $o['motivo'],
				'so_manual'    => 1 === (int) ( $o['so_manual'] ?? 0 ),
				'usos'         => (int) ( $usos[ (int) $o['id'] ]['total'] ?? 0 ),
				'usos_mes'     => (int) ( $usos[ (int) $o['id'] ]['mes'] ?? 0 ),
			);
		}
		$p        = ANPA_Socios_Colab_Service::proposta( $c );
		$proposta = null;
		if ( null !== $p ) {
			$proposta = array(
				'estado'     => (int) $c['proposta_ts'] > 0 ? ANPA_Socios_Colab::PENDENTE : ANPA_Socios_Colab::REXEITADA,
				'campos'     => $p['campos'],
				'logo_url'   => ANPA_Socios_Colab_Service::imaxe_url( $p['logo_id'] ),
				'enviada_ts' => $p['enviada_ts'],
				'manual'     => 1 === (int) $c['proposta_manual'],
				'motivo'     => (string) $c['proposta_motivo'],
			);
		}
		$caixa = ANPA_Socios_Colab_Page::url_caixa();
		$clave = ANPA_Socios_Colab_Service::clave_comercio( $c );

		return array(
			'comercio'         => array(
				'nome'        => (string) $c['nome'],
				'email'       => (string) $c['email'],
				'responsable' => (string) $c['responsable'],
				'telefono'    => (string) $c['telefono'],
				'url_web'     => (string) $c['url_web'],
				'enderezo'    => (string) $c['enderezo'],
				'descricion'  => (string) $c['descricion'],
				'logo_url'    => ANPA_Socios_Colab_Service::imaxe_url( (int) $c['logo_id'] ),
				'cubertos'    => 1 === (int) $c['cubertos'],
			),
			'proposta'         => $proposta,
			'caixa'            => array(
				'url'   => $caixa,
				'clave' => $clave,
				'qr'    => '' !== $caixa ? $caixa . '#k=' . $clave : '',
			),
			'ofertas'          => $ofertas,
			'horas_aprobacion' => ANPA_Socios_Colab_DB::horas_aprobacion(),
			'max_ofertas'      => ANPA_Socios_Colab::MAX_OFERTAS,
			'max_descricion'   => ANPA_Socios_Colab::MAX_DESCRICION,
			'seguinte_envio'   => $lote,
		);
	}

	/**
	 * @param  WP_REST_Request $request Request.
	 * @return int Shop id of the session.
	 */
	private static function id_comercio( WP_REST_Request $request ): int {
		$c = (array) $request->get_param( '_anpa_colab_comercio' );

		return (int) ( $c['id'] ?? 0 );
	}

	/**
	 * Whether an offer belongs to the shop and is not withdrawn (checked before an upload).
	 *
	 * @param  int $comercio_id Shop id.
	 * @param  int $id          Offer id.
	 * @return bool
	 */
	private static function oferta_propia( int $comercio_id, int $id ): bool {
		foreach ( ANPA_Socios_Colab_Service::ofertas_de( $comercio_id ) as $o ) {
			if ( (int) $o['id'] === $id ) {
				return ANPA_Socios_Colab::RETIRADA !== (string) $o['estado'];
			}
		}

		return false;
	}

	/**
	 * A field of the JSON body (or form body). Never from the query string.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @param  string          $k       Field.
	 * @return mixed
	 */
	private static function corpo( WP_REST_Request $request, string $k ) {
		$j = $request->get_json_params();
		if ( is_array( $j ) && array_key_exists( $k, $j ) ) {
			return $j[ $k ];
		}
		$b = $request->get_body_params();

		return is_array( $b ) && array_key_exists( $k, $b ) ? $b[ $k ] : null;
	}

	/**
	 * Family of the card code in the body, if it is a member now (0 otherwise).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return int
	 */
	private static function familia_valida( WP_REST_Request $request ): int {
		$codigo  = ANPA_Socios_Colab::normalizar_codigo( self::corpo( $request, 'codigo' ) );
		$familia = ANPA_Socios_Colab_Service::familia_por_codigo( $codigo );

		return ( $familia > 0 && ANPA_Socios_Colab_Service::familia_de_alta( $familia ) ) ? $familia : 0;
	}

	/**
	 * Family of the signed-in member, if it is a member now (0 otherwise).
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return int
	 */
	private static function familia_area( WP_REST_Request $request ): int {
		$p       = (array) $request->get_param( '_anpa_area_profile' );
		$familia = (int) ( ! empty( $p['familia_id'] ) ? $p['familia_id'] : ( $p['id'] ?? 0 ) );

		return ( $familia > 0 && ANPA_Socios_Colab_Service::familia_de_alta( $familia ) ) ? $familia : 0;
	}

	/**
	 * @return WP_Error The code is not of a member family.
	 */
	private static function erro_familia(): WP_Error {
		return new WP_Error( 'anpa_colab_familia_non_valida', __( 'Este carné non é dunha familia socia.', 'anpa-socios' ), array( 'status' => 403 ) );
	}

	/**
	 * @return WP_Error No card for this member.
	 */
	private static function erro_sen_carne(): WP_Error {
		return new WP_Error( 'anpa_colab_sen_carne', __( 'Non se puido xerar o carné.', 'anpa-socios' ), array( 'status' => 403 ) );
	}

	/**
	 * @return string REMOTE_ADDR only (no proxy headers).
	 */
	private static function ip(): string {
		return empty( $_SERVER['REMOTE_ADDR'] ) ? '' : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Transient-backed limit per IP.
	 *
	 * @param  string $prefix   Transient prefix.
	 * @param  int    $max      Requests allowed in the window.
	 * @param  int    $segundos Window in seconds.
	 * @return bool True when the request may go on.
	 */
	private static function limite_ip( string $prefix, int $max, int $segundos ): bool {
		return self::limite( $prefix . md5( self::ip() ), $max, $segundos );
	}

	/**
	 * Transient-backed sliding-window limit.
	 *
	 * @param  string $chave    Transient key.
	 * @param  int    $max      Requests allowed in the window.
	 * @param  int    $segundos Window in seconds.
	 * @return bool True when the request may go on.
	 */
	private static function limite( string $chave, int $max, int $segundos ): bool {
		$agora = time();
		$hist  = get_transient( $chave );
		$hist  = is_array( $hist ) ? array_map( 'intval', $hist ) : array();
		if ( ! ANPA_Socios_Rate_Limiter::permitir( $hist, $max, $segundos, $agora ) ) {
			return false;
		}
		$hist[] = $agora;
		set_transient( $chave, array_values( array_filter( $hist, static function ( $ts ) use ( $agora, $segundos ) {
			return $ts >= $agora - $segundos;
		} ) ), $segundos );

		return true;
	}
}
