<?php
/**
 * 1.93.0: enrolments open all year (optional per-trimester mode), automatic
 * approval after N hours, the group's real state in every enrolment email, and
 * the lifecycle fixes found walking a whole course.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Inscricions_Todo_Curso extends TestCase {

	protected function setUp(): void {
		global $wp_options;
		unset(
			$wp_options[ ANPA_Socios_Config::OPTION_MODO_INSCRICION ],
			$wp_options[ ANPA_Socios_Config::OPTION_MATRICULA_AUTO ],
			$wp_options[ ANPA_Socios_Config::OPTION_MATRICULA_AUTO_HORAS ]
		);
	}

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function body( string $src, string $signature ): string {
		$start = strpos( $src, $signature );
		$this->assertNotFalse( $start, $signature );
		$end = strpos( $src, "\n\t}\n", (int) $start );
		return substr( $src, (int) $start, (int) $end - (int) $start );
	}

	// ── Configuration ──

	public function test_default_mode_is_open_all_year_without_automatic_approval(): void {
		$this->assertSame( ANPA_Socios_Config::MODO_TODO_CURSO, ANPA_Socios_Config::modo_inscricion() );
		$this->assertTrue( ANPA_Socios_Config::inscricions_todo_curso() );
		$this->assertFalse( ANPA_Socios_Config::matricula_aprobacion_automatica() );
		$this->assertSame( 24, ANPA_Socios_Config::matricula_aprobacion_horas() );
	}

	public function test_automatic_approval_only_in_the_all_year_mode(): void {
		update_option( ANPA_Socios_Config::OPTION_MATRICULA_AUTO, '1' );
		update_option( ANPA_Socios_Config::OPTION_MATRICULA_AUTO_HORAS, '48' );
		$this->assertTrue( ANPA_Socios_Config::matricula_aprobacion_automatica() );
		$this->assertSame( 48, ANPA_Socios_Config::matricula_aprobacion_horas() );
		update_option( ANPA_Socios_Config::OPTION_MODO_INSCRICION, ANPA_Socios_Config::MODO_TRIMESTRAL );
		$this->assertFalse( ANPA_Socios_Config::matricula_aprobacion_automatica(), 'per trimester: the junta decides' );
		update_option( ANPA_Socios_Config::OPTION_MODO_INSCRICION, 'calquera' );
		$this->assertSame( ANPA_Socios_Config::MODO_TODO_CURSO, ANPA_Socios_Config::modo_inscricion(), 'unknown value = default' );
	}

	public function test_hours_are_sanitised(): void {
		$this->assertSame( 24, ANPA_Socios_Config::sanitize_horas( '' ) );
		$this->assertSame( 24, ANPA_Socios_Config::sanitize_horas( '0' ) );
		$this->assertSame( 24, ANPA_Socios_Config::sanitize_horas( 'dous' ) );
		$this->assertSame( 1, ANPA_Socios_Config::sanitize_horas( '1' ) );
		$this->assertSame( 72, ANPA_Socios_Config::sanitize_horas( 72 ) );
		$this->assertSame( ANPA_Socios_Config::MATRICULA_AUTO_HORAS_MAX, ANPA_Socios_Config::sanitize_horas( '100000' ) );
	}

	// ── Group state sentence ──

	public function test_group_state_key(): void {
		$this->assertSame( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, ANPA_Socios_Estado_Grupo_Texto::clave( 'sen_minimo', false ) );
		$this->assertSame( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, ANPA_Socios_Estado_Grupo_Texto::clave( 'sen_minimo', true ) );
		$this->assertSame( ANPA_Socios_Estado_Grupo_Texto::SEN_CREAR, ANPA_Socios_Estado_Grupo_Texto::clave( 'aberto', false ) );
		$this->assertSame( ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA, ANPA_Socios_Estado_Grupo_Texto::clave( 'aberto', true ) );
	}

	public function test_family_sentence_says_the_group_is_not_formed(): void {
		$t = array( 'ANPA_Socios_Estado_Grupo_Texto', 'familia' );
		$sen_minimo = $t( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, ANPA_Socios_Estado_Grupo_Texto::PRAZA );
		$this->assertStringContainsString( 'non chega ao mínimo', $sen_minimo );
		$this->assertStringContainsString( 'non se cobra nada', $sen_minimo );
		$this->assertStringContainsString( 'A praza queda reservada', $sen_minimo );
		$this->assertStringNotContainsString( 'praza queda reservada', $t( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, ANPA_Socios_Estado_Grupo_Texto::ESPERA ) );
		$this->assertStringContainsString( 'aínda non está confirmado', $t( ANPA_Socios_Estado_Grupo_Texto::SEN_CREAR, ANPA_Socios_Estado_Grupo_Texto::PRAZA ) );
		$this->assertStringContainsString( 'aínda non está confirmado', $t( ANPA_Socios_Estado_Grupo_Texto::SEN_CREAR, ANPA_Socios_Estado_Grupo_Texto::PENDENTE ) );
		$this->assertStringContainsString( 'xa está en marcha', $t( ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA, ANPA_Socios_Estado_Grupo_Texto::PRAZA ) );
		$this->assertSame( '', $t( ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA, ANPA_Socios_Estado_Grupo_Texto::PENDENTE ) );
		// Families never get numbers.
		foreach ( array( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, ANPA_Socios_Estado_Grupo_Texto::SEN_CREAR, ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA ) as $c ) {
			foreach ( array( 'praza', 'espera', 'pendente', 'oferta' ) as $m ) {
				$this->assertDoesNotMatchRegularExpression( '/\d/', $t( $c, $m ), "$c $m" );
			}
		}
	}

	public function test_junta_label_has_the_numbers(): void {
		$x = ANPA_Socios_Estado_Grupo_Texto::xunta( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, 3, 6, 12, 0 );
		$this->assertSame( 'Sen mínimo: 3 con praza (mínimo 6, máximo 12), 0 en lista de espera', $x );
		$this->assertSame( 'En marcha: 10 con praza (sen máximo), 2 en lista de espera', ANPA_Socios_Estado_Grupo_Texto::xunta( ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA, 10, 0, 0, 2 ) );
	}

	// ── Baixa effects follow the group ──

	public function test_baixa_in_a_group_not_running_is_immediate_and_free_even_with_the_window_closed(): void {
		$gate = array( 'trimestre' => 2, 'abertas' => false, 'motivo' => 'ventana_pechada' );
		$rows = array( 2 => array( 'presente' => true, 'estado' => 'activo', 'ventana_estado' => 'pechada' ) );
		foreach ( array( ANPA_Socios_Estado_Grupo_Texto::SEN_MINIMO, ANPA_Socios_Estado_Grupo_Texto::SEN_CREAR ) as $clave ) {
			$r = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $rows, null, $clave );
			$this->assertSame( ANPA_Socios_Baixa_Extraescolar_Efectos::INMEDIATA, $r['efecto'], $clave );
			$this->assertStringContainsString( 'ningún cobro', $r['texto'] );
		}
		$r = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $rows, null, ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA );
		$this->assertSame( ANPA_Socios_Baixa_Extraescolar_Efectos::FIN_TRIMESTRE, $r['efecto'] );
	}

	public function test_a_baixa_at_the_end_of_the_trimester_keeps_its_place_until_then(): void {
		$s = $this->src( 'includes/class-anpa-socios-baixa-programada-service.php' );
		$this->assertStringContainsString( "\$baixa_en = \$avaliacion['data_fin'] . ' 23:59:59';", $s );
		$this->assertStringContainsString( '$oferta = $ao_final ? false : ANPA_Socios_Extraescolar_Offers::offer_next(', $s );
		$this->assertStringContainsString( "( estado = 'baixa' AND baixa_en > %s )", ANPA_Socios_Lista_Espera::SQL_OCUPAN );
	}

	// ── Enrolment and approval ──

	public function test_every_request_waits_for_approval_in_the_all_year_mode(): void {
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "if ( ANPA_Socios_Config::inscricions_todo_curso() ) {\n\t\t\t\$pendente = true;", $e );
		// A direct enrolment (free period of the per-trimester mode) is confirmed by email too.
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Matriculas_Handler::enviar_confirmacion( $mat_id, $grupo_id, $estado, (int) $posicion );', $e );
		// The area shows the pending notice.
		$this->assertStringContainsString( '$abertas = self::course_is_open( $curso ) && ! ANPA_Socios_Config::inscricions_todo_curso();', $e );
		$this->assertStringNotContainsString( 'O prazo de matrícula está pechado', $this->src( 'assets/js/area.js' ) );
	}

	public function test_family_baixas_need_only_an_active_course(): void {
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		foreach ( array( 'public static function request_baixa(', 'public static function cancel_baixa(' ) as $fn ) {
			$b = $this->body( $e, $fn );
			$this->assertStringContainsString( "if ( ! self::course_is_active( (string) ( \$mat['curso_escolar'] ?? '' ) ) ) {", $b, $fn );
			$this->assertStringNotContainsString( 'self::course_is_open(', $b, $fn );
			$this->assertStringContainsString( '$course_error = self::lock_open_course_mode( $curso );', $b, $fn );
		}
	}

	public function test_approval_respects_the_queue_the_course_and_the_unique_key(): void {
		$h = $this->body( $this->src( 'includes/class-anpa-socios-admin-matriculas-handler.php' ), 'public static function aprobar(' );
		$this->assertStringContainsString( "if ( ANPA_Socios_Curso_Activo::get() !== (string) \$grupo['curso_escolar'] ) {", $h );
		$this->assertStringContainsString( "ANPA_Socios_Lista_Espera::en_espera( (int) \$grupo['id'] ) > 0", $h );
		$this->assertStringContainsString( "\$trimestre = (int) \$mat['trimestre'];\n\t\t\t}", $h, 'keeps its own trimester on a collision' );
		$this->assertStringContainsString( '$correos = self::enviar_confirmacion( $id, (int) $grupo[\'id\'], $destino, (int) $posicion );', $h );
	}

	public function test_reused_rows_start_clean(): void {
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		foreach ( array( "'oferta_token'               => null,", "'oferta_aceptada_en'         => null,", "'baixa_efectiva_en'          => null," ) as $k ) {
			$this->assertStringContainsString( $k, $e );
		}
		$this->assertStringContainsString( 'SET creado_en = NOW() WHERE id = %d', $e );
		foreach ( array( 'includes/class-anpa-socios-admin-grupos-handler.php', 'includes/class-anpa-socios-baixa-familia.php', 'includes/class-anpa-socios-season-service.php' ) as $f ) {
			$this->assertStringContainsString( 'oferta_aceptada_en = NULL', $this->src( $f ), $f );
		}
	}

	public function test_automatic_approval_runs_hourly_with_the_same_code_as_the_button(): void {
		$a = $this->src( 'includes/class-anpa-socios-aprobacion-automatica.php' );
		$this->assertStringContainsString( 'if ( ! ANPA_Socios_Config::matricula_aprobacion_automatica() ) {', $a );
		$this->assertStringContainsString( 'm.creado_en <= NOW() - INTERVAL %d HOUR', $a );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Matriculas_Handler::aprobar( (int) $id, \'system\', \'system\', true );', $a );
		$p = $this->src( 'anpa-socios.php' );
		$this->assertStringContainsString( "includes/class-anpa-socios-aprobacion-automatica.php';", $p );
		$this->assertStringContainsString( "add_action( ANPA_Socios_Extraescolar_Offers::CRON_HOOK, array( 'ANPA_Socios_Aprobacion_Automatica', 'executar' ), 15 );", $p );
		// An accepted offer is approved at once.
		$this->assertStringContainsString( "if ( ANPA_Socios_Config::matricula_aprobacion_automatica() ) {\n\t\t\t\$auto = ANPA_Socios_Admin_Matriculas_Handler::aprobar( (int) \$mat['id'], 'system', 'system', true );", $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' ) );
		// The family email never mentions it; the junta notice does.
		$this->assertStringNotContainsString( 'automática', ANPA_Socios_Email_Template_Store::get_all_defaults()['matricula_pendente']['html'] );
		$this->assertStringContainsString( "'aprobacion_automatica' => self::texto_aprobacion_automatica(),", $this->src( 'includes/class-anpa-socios-email.php' ) );
	}

	// ── Offers ──

	public function test_offers_reach_both_parents_and_only_in_the_active_course(): void {
		$o = $this->src( 'includes/class-anpa-socios-extraescolar-offers.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Matriculas_Handler::detalle_para_correo( $matricula_id );', $this->body( $o, 'private static function notify_offer(' ) );
		$this->assertStringContainsString( "if ( ANPA_Socios_Curso_Activo::get() !== (string) \$grupo['curso_escolar'] ) {", $o );
		$tpl = ANPA_Socios_Email_Template_Renderer::render( 'oferta_extraescolar', array( 'association_name' => 'ANPA', 'actividade' => 'Xadrez', 'alumno' => 'Uxía', 'grupo' => 'A', 'dias_prazo' => '3', 'estado_grupo' => '', 'contact_email' => 'x@example.org' ) );
		$this->assertSame( 'Oferta de praza: Uxía en Xadrez — ANPA', $tpl['subject'] );
		$this->assertStringContainsString( 'praza para <strong>Uxía</strong> na actividade <strong>Xadrez</strong> (A)', $tpl['html'] );
	}

	// ── Course cycle ──

	public function test_every_way_of_closing_a_course_closes_its_enrolments(): void {
		$this->assertStringContainsString( 'self::pechar_matriculas_curso( $curso );', $this->src( 'includes/class-anpa-socios-season-service.php' ) );
		$this->assertStringContainsString( 'ANPA_Socios_Season_Service::pechar_matriculas_curso( (string) $vello );', $this->src( 'includes/class-anpa-socios-admin-cursos-handler.php' ) );
		$t = $this->src( 'includes/class-anpa-socios-admin-trimestres-handler.php' );
		$this->assertSame( 2, substr_count( $t, 'ANPA_Socios_Season_Service::pechar_matriculas_curso( $curso, $actor,' ), 'fin de curso + «Curso pechado»' );
		$this->assertStringNotContainsString( "['pechado', 'Curso pechado']", $this->src( 'assets/js/admin-management.js' ), 'closed only from step 5' );
	}

	public function test_start_of_course_is_one_email_with_the_two_dates(): void {
		$t = $this->src( 'includes/class-anpa-socios-admin-trimestres-handler.php' );
		$this->assertStringNotContainsString( "'/avisos/prazo-matriculas'", $t );
		$this->assertStringContainsString( "\$envio = self::masivo( \$request, 'inicio_curso', array(\n\t\t\t'data_peche'              => \$peche,", $t );
		$this->assertArrayNotHasKey( 'prazo_matriculas', ANPA_Socios_Email_Template_Store::get_all_defaults() );
		$this->assertStringContainsString( "post('avisos/comezo-curso', { curso: curso, data_peche: inPeche.value, data_inicio_actividades: inInicio.value }", $this->src( 'assets/js/admin-management.js' ) );
	}

	public function test_mode_route_and_window_rules(): void {
		$t = $this->src( 'includes/class-anpa-socios-admin-trimestres-handler.php' );
		$this->assertStringContainsString( "'/trimestres/modo'", $t );
		$this->assertStringContainsString( 'update_option( ANPA_Socios_Config::OPTION_MODO_INSCRICION, $modo );', $t );
		$this->assertStringContainsString( "'anpa_admin_trimestre_non_activo'", $t );
		$this->assertStringContainsString( "if ( \$destino > 1 && ANPA_Socios_Config::inscricions_todo_curso() ) {", $t );
		$this->assertStringContainsString( '$pendentes    = ( $pechar_curso || ANPA_Socios_Config::inscricions_todo_curso() ) ? array() : self::pendentes_ids( $curso );', $t );
		$this->assertStringContainsString( "WHERE m.estado IN ('activo', 'lista_espera', 'oferta', 'baixa_solicitada', 'pendente_aprobacion')", $this->src( 'includes/class-anpa-socios-nivel-promotion-service.php' ) );
	}

	// ── Admin enrolment through the API ──

	public function test_admin_enrolment_checks_group_and_capacity_and_asks_about_emails(): void {
		$b = $this->body( $this->src( 'includes/class-anpa-socios-admin-matriculas-handler.php' ), 'public static function create_matricula(' );
		$this->assertStringContainsString( "'anpa_admin_grupo_requirido'", $b );
		$this->assertStringContainsString( "if ( ! array_key_exists( 'notificar', \$body ) || ! is_bool( \$body['notificar'] ) ) {", $b );
		$this->assertStringContainsString( 'FOR UPDATE', $b );
		$this->assertStringContainsString( "(int) \$grupo['actividad_id'] !== \$act_id", $b );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get() !== $curso', $b );
		$this->assertStringContainsString( 'ANPA_Socios_Grupo_Serie::ESTADO_DESHABILITADO', $b );
		$this->assertStringContainsString( 'ANPA_Socios_DB::get_niveis_for_grupo( $grupo_id )', $b );
		$this->assertStringContainsString( "estado <> 'baixa'", $b );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Estado::destino_aprobacion(', $b );
		$this->assertStringContainsString( 'ANPA_Socios_Lista_Espera::en_espera( $grupo_id ) > 0', $b );
		// Emails only when the admin said so.
		$this->assertStringContainsString( "if ( \$notificar ) {
			\$correos = self::enviar_confirmacion(", $b );
		$this->assertStringNotContainsString( "'estado'               => (string) \$payload['estado']", $b );
	}

	// ── Listings ──

	public function test_listings_include_baixas_with_their_effective_date(): void {
		$this->assertContains( 'baixa_efectiva', ANPA_Socios_Alumnos_Export::columns_panel_empresa() );
		$this->assertContains( 'Baixa efectiva', ANPA_Socios_Listado_Empresa::cabeceira( false ) );
		$lib = $this->src( 'includes/lib/class-anpa-socios-alumnos-export.php' );
		// «Who attends now» keeps requested baixas and baixas dated at the end of the trimester.
		$this->assertStringContainsString( "( m.estado IN ('activo','baixa_solicitada') OR ( m.estado = 'baixa' AND m.baixa_en > %s ) )", $lib );
		$this->assertStringContainsString( 'm.baixa_en, m.baixa_efectiva_en, ', $lib );
		// The admin export: every state of the active course, baixas last.
		$this->assertStringNotContainsString( "WHERE m.estado = 'activo' ", $lib );
		$this->assertStringContainsString( "FIELD(m.estado, 'activo', 'baixa_solicitada', 'oferta', 'lista_espera', 'pendente_aprobacion', 'baixa')", $lib );
		$this->assertStringContainsString( "'baixa_efectiva' => (string) ( \$r['baixa_efectiva'] ?? '' ),", $this->src( 'includes/class-anpa-socios-empresa-rest.php' ) );
	}
}
