<?php
/**
 * Pre-season access guard (fase12).
 *
 * While the current course is in the pre-season (`pendente`) state, only
 * admin/master users may obtain a login/verification code. Everyone else is
 * blocked with a clear notice and receives no code.
 *
 * Implemented as a `rest_pre_dispatch` filter so it intercepts the login-code
 * routes (including anpa-verificacion's `anpa/v1/solicitar-codigo`) WITHOUT
 * coupling the plugins or touching the session-validation invariant in
 * ANPA_Socios_Area_REST::authenticate_area_session.
 *
 * @since  1.18.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Preseason_Guard {

	/**
	 * Code-issuing routes that are gated for non-admins during pre-season.
	 *
	 * Empresa login (`/anpa-socios/v1/empresa/solicitar-codigo`) is intentionally
	 * NOT gated: pre-season only restricts the socios area.
	 *
	 * @var string[]
	 */
	const GATED_ROUTES = array(
		'/anpa/v1/solicitar-codigo',
		'/anpa-socios/v1/solicitar-codigo-alta',
	);

	/**
	 * Registers the pre-dispatch filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_block' ), 10, 3 );
	}

	/**
	 * Short-circuits code issuance for non-admins during pre-season.
	 *
	 * @param  mixed           $result  Existing dispatch result (null by default).
	 * @param  WP_REST_Server  $server  REST server.
	 * @param  WP_REST_Request $request Incoming request.
	 * @return mixed  Unchanged $result to proceed, or WP_Error to block.
	 */
	public static function maybe_block( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}
		if ( 'POST' !== $request->get_method() ) {
			return $result;
		}
		if ( ! in_array( $request->get_route(), self::GATED_ROUTES, true ) ) {
			return $result;
		}

		$season   = ANPA_Socios_Season_Service::current_course_row();
		$estado   = (string) $season['estado'];
		$email    = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		$is_admin = self::email_is_admin( $email );

		if ( ANPA_Socios_Preseason_Gate::code_allowed( $estado, $is_admin ) ) {
			return $result;
		}

		$inicio = (string) $season['data_inicio'];
		$curso  = (string) $season['curso_escolar'];
		$msg    = sprintf(
			/* translators: 1: curso escolar, 2: data de inicio formatada */
			'O curso escolar %1$s aínda non comezou. A alta de socios e a matriculación nas actividades extraescolares abriranse automaticamente o %2$s. Mentres tanto, só o equipo administrador pode iniciar sesión.',
			$curso,
			self::format_date_gl( $inicio )
		);

		return new WP_Error(
			'anpa_preseason',
			$msg,
			array(
				'status'        => 403,
				'curso_escolar' => $curso,
				'data_inicio'   => $inicio,
			)
		);
	}

	/**
	 * Whether the email belongs to an admin/master (bypasses pre-season).
	 *
	 * Recognises the configured master email even before it is registered, so
	 * the junta can always bootstrap access.
	 *
	 * @param  string $email Lowercased email.
	 * @return bool
	 */
	private static function email_is_admin( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}
		if ( $email === ANPA_Socios_Config::master_email() ) {
			return true;
		}

		global $wpdb;
		$socios = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only role lookup for the pre-season gate.
		$rol = $wpdb->get_var( $wpdb->prepare( "SELECT rol FROM {$socios} WHERE email = %s", $email ) );

		return ANPA_Socios_Roles::es_master( $email, (string) $rol );
	}

	/**
	 * Formats a Y-m-d date as a friendly Galician date, e.g. "1 de setembro de 2026".
	 *
	 * @param  string $ymd Date in Y-m-d, possibly empty.
	 * @return string
	 */
	public static function format_date_gl( string $ymd ): string {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );
		if ( false === $dt ) {
			return $ymd;
		}
		$meses = array(
			1  => 'xaneiro',
			2  => 'febreiro',
			3  => 'marzo',
			4  => 'abril',
			5  => 'maio',
			6  => 'xuño',
			7  => 'xullo',
			8  => 'agosto',
			9  => 'setembro',
			10 => 'outubro',
			11 => 'novembro',
			12 => 'decembro',
		);
		$dia = (int) $dt->format( 'j' );
		$mes = $meses[ (int) $dt->format( 'n' ) ] ?? '';
		$ano = $dt->format( 'Y' );

		return sprintf( '%d de %s de %s', $dia, $mes, $ano );
	}
}
