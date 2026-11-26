<?php
/**
 * Collaborators network (1.104.0): REST routes.
 *
 * - Shops sign in with email + code (own codes and sessions) and manage their
 *   own offers. The shop is always the one of the session, never of the request.
 * - A member (area session) asks for a card code for their family.
 * - Anyone can check a card code: the answer is only valid / not valid.
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

	/** Wrong guesses allowed per login code. */
	const CODIGO_INTENTOS = 5;

	/** Login codes one address may be sent per hour, whatever the IP. */
	const CODIGOS_EMAIL_HORA = 10;

	/** Card checks one IP may make in ten minutes. */
	const VERIFICACIONS_IP = 30;

	/**
	 * Routes. Nothing is registered while the module is off.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		if ( ! ANPA_Socios_Colab_DB::activo() ) {
			return;
		}
		$publico = '__return_true';
		$tenda   = array( __CLASS__, 'permission_comercio' );
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
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_me' ),
			'permission_callback' => $tenda,
		) );
		register_rest_route( self::NS, '/colab/me/session', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'handle_sair' ),
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
		register_rest_route( self::NS, '/colab/verificar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_verificar' ),
			'permission_callback' => $publico,
			'args'                => array( 'codigo' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( self::NS, '/area/carne', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_carne' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
		) );
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
	 * GET /colab/me — the shop and its offers.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_me( WP_REST_Request $request ) {
		$c = (array) $request->get_param( '_anpa_colab_comercio' );

		return new WP_REST_Response( self::panel( $c ), 200 );
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
	 * offer of the signed-in shop. It always ends pending.
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

		return new WP_REST_Response( self::panel( $c ), 200 );
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

		return new WP_REST_Response( self::panel( $c ), 200 );
	}

	/**
	 * POST /area/carne — a card code for the family of the signed-in member.
	 * Only its keyed digest is stored; the family's older codes are dropped.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_carne( WP_REST_Request $request ) {
		global $wpdb;
		$p       = (array) $request->get_param( '_anpa_area_profile' );
		$familia = (int) ( ! empty( $p['familia_id'] ) ? $p['familia_id'] : ( $p['id'] ?? 0 ) );
		if ( $familia <= 0 || ! ANPA_Socios_Colab_Service::familia_de_alta( $familia ) ) {
			return new WP_Error( 'anpa_colab_sen_carne', __( 'Non se puido xerar o carné.', 'anpa-socios' ), array( 'status' => 403 ) );
		}
		$t      = ANPA_Socios_Colab_DB::carnes();
		$codigo = ANPA_Socios_Colab::xerar_codigo();
		$expira = time() + ANPA_Socios_Colab::CARNE_TTL;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->delete( $t, array( 'familia_id' => $familia ), array( '%d' ) );
		$ok = $wpdb->insert(
			$t,
			array( 'codigo_digest' => self::digest( $codigo ), 'familia_id' => $familia, 'expira_ts' => $expira, 'creado_ts' => time() ),
			array( '%s', '%d', '%d', '%d' )
		);
		// phpcs:enable
		if ( false === $ok ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$paxina = ANPA_Socios_Colab_Page::url_verificar();

		return new WP_REST_Response(
			array(
				'codigo'     => ANPA_Socios_Colab::formatar_codigo( $codigo ),
				'expires_in' => ANPA_Socios_Colab::CARNE_TTL,
				// The code goes after «#»: browsers never send that part to a server.
				'url'        => '' !== $paxina ? $paxina . '#c=' . $codigo : '',
				'curso'      => (string) ANPA_Socios_Curso_Activo::get(),
			),
			200
		);
	}

	/**
	 * POST /colab/verificar — is this card code of a family that is a member
	 * right now? Answers valid / not valid and nothing about who it is.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_verificar( WP_REST_Request $request ) {
		global $wpdb;
		if ( ! self::limite_ip( 'anpa_colab_ver_ip_', self::VERIFICACIONS_IP, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'anpa_colab_limite', __( 'Demasiadas comprobacións. Agarda uns minutos.', 'anpa-socios' ), array( 'status' => 429 ) );
		}
		$valido = false;
		$codigo = ANPA_Socios_Colab::normalizar_codigo( $request->get_param( 'codigo' ) );
		if ( '' !== $codigo ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT familia_id, expira_ts FROM ' . ANPA_Socios_Colab_DB::carnes() . ' WHERE codigo_digest = %s LIMIT 1', self::digest( $codigo ) ), ARRAY_A );
			$valido = is_array( $row ) && (int) $row['expira_ts'] >= time() && ANPA_Socios_Colab_Service::familia_de_alta( (int) $row['familia_id'] );
		}

		return new WP_REST_Response(
			array(
				'valido'        => $valido,
				'curso'         => $valido ? (string) ANPA_Socios_Curso_Activo::get() : '',
				'comprobado_en' => current_time( 'H:i' ),
			),
			200
		);
	}

	/**
	 * What the shop panel shows: the shop's own data and its offers.
	 *
	 * @param  array<string,mixed> $c Shop row.
	 * @return array<string,mixed>
	 */
	private static function panel( array $c ): array {
		$ofertas = array();
		foreach ( ANPA_Socios_Colab_Service::ofertas_de( (int) ( $c['id'] ?? 0 ) ) as $o ) {
			$ofertas[] = array(
				'id'           => (int) $o['id'],
				'titulo'       => (string) $o['titulo'],
				'desconto'     => (string) $o['desconto'],
				'descricion'   => (string) $o['descricion'],
				'condicions'   => (string) $o['condicions'],
				'valida_dende' => (string) $o['valida_dende'],
				'valida_ata'   => (string) $o['valida_ata'],
				'estado'       => (string) $o['estado'],
				'estado_texto' => ANPA_Socios_Colab::etiqueta_estado( (string) $o['estado'] ),
				'motivo'       => (string) $o['motivo'],
			);
		}

		return array(
			'comercio'         => array(
				'nome'        => (string) ( $c['nome'] ?? '' ),
				'email'       => (string) ( $c['email'] ?? '' ),
				'responsable' => (string) ( $c['responsable'] ?? '' ),
				'telefono'    => (string) ( $c['telefono'] ?? '' ),
				'url_web'     => (string) ( $c['url_web'] ?? '' ),
				'enderezo'    => (string) ( $c['enderezo'] ?? '' ),
			),
			'ofertas'          => $ofertas,
			'horas_aprobacion' => ANPA_Socios_Colab_DB::horas_aprobacion(),
			'max_ofertas'      => ANPA_Socios_Colab::MAX_OFERTAS,
		);
	}

	/**
	 * Keyed digest of a card code (the code itself is never stored).
	 *
	 * @param  string $codigo Card code, stored form.
	 * @return string
	 */
	private static function digest( string $codigo ): string {
		return hash_hmac( 'sha256', $codigo, wp_salt( 'auth' ) . '|anpa_colab_carne' );
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
	 * @param  string $prefix  Transient prefix.
	 * @param  int    $max     Requests allowed in the window.
	 * @param  int    $segundos Window in seconds.
	 * @return bool True when the request may go on.
	 */
	private static function limite_ip( string $prefix, int $max, int $segundos ): bool {
		$agora = time();
		$chave = $prefix . md5( self::ip() );
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
