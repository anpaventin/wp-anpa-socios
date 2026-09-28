<?php
/**
 * 1.71.0 wiring: mid-course notices (approval with a place, accepted offer,
 * confirmed baixa) to the family, the company and the canteen; offers can be
 * accepted with the window closed; Lista Gmail explains each alta/baixa;
 * audit columns widened (schema 1.46.0); activity dates in Axustes → Xeral;
 * «Creado» and «Non acadaron o mínimo» on the public page; state + notice at
 * the top of the group form. Source-contract and pure tests.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Avisos_Metade_Curso extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function method_body( string $src, string $signature ): string {
		$start = strpos( $src, $signature );
		$this->assertNotFalse( $start, $signature );
		$end = strpos( $src, "\n\t/**", (int) $start + 1 );
		return substr( $src, (int) $start, false === $end ? null : (int) $end - (int) $start );
	}

	// ── Templates ──

	public function test_three_new_templates_follow_the_renderer_contract(): void {
		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		foreach ( array( 'matricula_alta_aviso', 'matricula_baixa_aviso', 'oferta_aceptada' ) as $id ) {
			$this->assertArrayHasKey( $id, $defaults );
			$vars = ANPA_Socios_Email_Template_Store::get_variables( $id );
			$this->assertContains( 'association_name', $vars['subject'] );
			$this->assertContains( 'contact_email', $vars['html'] );
			foreach ( array( 'subject', 'html', 'text' ) as $field ) {
				$this->assertSame( count( $vars[ $field ] ), substr_count( $defaults[ $id ][ $field ], '%s' ), "$id $field" );
			}
		}
		$alta = ANPA_Socios_Email_Template_Store::get_variables( 'matricula_alta_aviso' )['html'];
		foreach ( array( 'alumno', 'curso', 'actividade', 'grupo', 'proxenitor1', 'proxenitor2', 'opcions' ) as $v ) {
			$this->assertContains( $v, $alta );
		}
		$this->assertContains( 'efectos', ANPA_Socios_Email_Template_Store::get_variables( 'matricula_baixa_aviso' )['html'] );
		$this->assertSame( 'matricula_alta_aviso', ANPA_Socios_Aviso_Matricula::PLANTILLA_ALTA );
		$this->assertSame( 'matricula_baixa_aviso', ANPA_Socios_Aviso_Matricula::PLANTILLA_BAIXA );
		$this->assertSame( 'oferta_aceptada', ANPA_Socios_Aviso_Matricula::PLANTILLA_OFERTA_ACEPTADA );
	}

	// ── Sending ──

	public function test_company_and_canteen_get_one_bcc_message_only_mid_course(): void {
		$body = $this->method_body( $this->src( 'includes/class-anpa-socios-email.php' ), 'public static function avisar_empresa_comedor(' );
		$this->assertStringContainsString( 'ANPA_Socios_Alumnos_Export::row_panel_matricula( $matricula_id )', $body );
		$this->assertStringContainsString( "self::e_metade_de_curso( (string) \$row['curso_escolar'] )", $body );
		$this->assertStringContainsString( "ANPA_Socios_Aviso_Matricula::destinatarios( (string) \$row['empresa_email'], ANPA_Socios_Config::comedor_email() )", $body );
		// enviar_masivo = To the junta, the recipients in Bcc.
		$this->assertStringContainsString( 'self::enviar_masivo( $dest, $template_id,', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Aviso_Matricula::debe_avisar( ANPA_Socios_Matricula_Gate_Repo::para_curso( $curso ) )', $this->src( 'includes/class-anpa-socios-email.php' ) );
	}

	public function test_approval_with_a_place_notifies_but_not_from_the_trimester_activation(): void {
		$h    = $this->src( 'includes/class-anpa-socios-admin-matriculas-handler.php' );
		$this->assertStringContainsString( 'public static function aprobar( int $id, string $actor, string $actor_tipo, bool $avisar_empresa = true )', $h );
		$this->assertStringContainsString( "if ( \$avisar_empresa && ANPA_Socios_Matricula_Estado::ACTIVO === \$destino ) {\n\t\t\tANPA_Socios_Email::avisar_empresa_comedor( \$id, ANPA_Socios_Aviso_Matricula::PLANTILLA_ALTA );", $h );
		$t = $this->src( 'includes/class-anpa-socios-admin-trimestres-handler.php' );
		$this->assertStringContainsString( "(string) \$request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ), false );", $t );
	}

	public function test_confirmed_activity_baixa_notifies_company_and_canteen(): void {
		$body = $this->method_body( $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' ), 'public static function confirm_baixa(' );
		$this->assertStringContainsString( "ANPA_Socios_Email::avisar_empresa_comedor( \$id, ANPA_Socios_Aviso_Matricula::PLANTILLA_BAIXA, array( 'efectos' => \$efectos ) );", $body );
	}

	public function test_offer_can_be_accepted_mid_course_and_notifies_all_three(): void {
		$body = $this->method_body( $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' ), 'public static function accept_oferta(' );
		$this->assertStringContainsString( "self::course_is_active( (string) ( \$mat['curso_escolar'] ?? '' ) )", $body );
		$this->assertStringNotContainsString( 'self::course_is_open(', $body );
		$this->assertStringContainsString( '$course_error = self::lock_open_course_mode( $curso );', $body );
		$notice = strpos( $body, 'if ( ANPA_Socios_Email::e_metade_de_curso( $curso ) ) {' );
		$commit = strpos( $body, "query( 'COMMIT' )" );
		$this->assertNotFalse( $notice );
		$this->assertGreaterThan( (int) $commit, (int) $notice, 'emails only after the commit' );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_oferta_aceptada( $email,', $body );
		$this->assertStringContainsString( "ANPA_Socios_Email::avisar_empresa_comedor( (int) \$mat['id'], ANPA_Socios_Aviso_Matricula::PLANTILLA_ALTA );", $body );
	}

	public function test_single_enrolment_lookup_reuses_the_listing_sql(): void {
		$lib = $this->src( 'includes/lib/class-anpa-socios-alumnos-export.php' );
		$this->assertStringContainsString( 'public static function row_panel_matricula( int $matricula_id ): ?array', $lib );
		$this->assertSame( 2, substr_count( $lib, 'self::panel_select_sql(' ) );
		$this->assertStringContainsString( "COALESCE(e.email, '') AS empresa_email, COALESCE(g.curso_escolar, '') AS curso_escolar, ", $lib );
	}

	// ── Lista Gmail ──

	public function test_lista_gmail_explains_each_alta_and_baixa(): void {
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-contactos-google-handler.php';
		$h = 'ANPA_Socios_Admin_Contactos_Google_Handler';
		$this->assertSame( 'Alta nova', $h::motivo( 'alta', array( 'creado_en' => '2026-09-24 10:00:00', 'rol_familia' => 'principal' ), '2026-09-23 17:29:41' ) );
		$this->assertSame( 'Xa era socio/a: correo novo (cambiado ou recuperado dunha baixa) (2º proxenitor)', $h::motivo( 'alta', array( 'creado_en' => '2026-09-10 19:00:34', 'rol_familia' => 'secundario' ), '2026-09-23 17:29:41' ) );
		$this->assertSame( 'Este correo xa non está na web: cambiouse por outro ou eliminouse', $h::motivo( 'baixa', null, '2026-09-23 17:29:41' ) );
		$this->assertSame( 'Baixa confirmada', $h::motivo( 'baixa', array( 'estado' => 'baixa', 'rol_familia' => 'principal' ), '' ) );
		$this->assertSame( 'Xa non está activo/a (estado: pendiente_alta)', $h::motivo( 'baixa', array( 'estado' => 'pendiente_alta' ), '' ) );

		$src = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "list( \$altas, \$baixas ) = self::con_motivos( \$altas, \$baixas,", $src );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "['Apelidos', 'Nome', 'Email', 'Motivo']", $js );
	}

	// ── Schema 1.46.0 ──

	public function test_audit_columns_are_widened(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "const DB_VERSION = '1.46.0';", $db );
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.46.0' )", $this->src( 'anpa-socios.php' ) );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.46.0', '<' ) && ! self::migrate_to_1_46_0()", $db );
		$this->assertStringContainsString( 'MODIFY COLUMN accion varchar(40) NOT NULL, MODIFY COLUMN target_id varchar(190)', $db );
		$this->assertStringContainsString( "target_id varchar(190) not null default '',\n\t\t\taccion varchar(40) not null,", $db );
		$this->assertGreaterThanOrEqual( strlen( 'baixa_confirm_familia' ), 40 );
	}

	// ── Activity dates ──

	public function test_activity_dates_live_in_axustes_xeral(): void {
		$cfg = $this->src( 'includes/class-anpa-socios-config.php' );
		$this->assertStringContainsString( "const OPTION_DATA_INICIO_ACTIVIDADES = 'anpa_socios_data_inicio_actividades';", $cfg );
		$this->assertStringContainsString( "const OPTION_DATA_REMATE_ACTIVIDADES = 'anpa_socios_data_remate_actividades';", $cfg );
		$set = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'name="data_inicio_actividades" id="cfg-inicio-actividades" type="date"', $set );
		$this->assertStringContainsString( 'name="data_remate_actividades" id="cfg-remate-actividades" type="date"', $set );
		// Isolated handler: only the posted fields are touched, empty deletes.
		$this->assertStringContainsString( "if ( ! array_key_exists( \$field, \$_POST ) ) {", $set );
		$this->assertStringContainsString( "'data_invalida'", $set );
		$this->assertStringContainsString( "'datainicioactividades' => ANPA_Socios_Config::data_inicio_actividades()", $this->src( 'includes/class-anpa-socios-admin-management-page.php' ) );
		$this->assertStringContainsString( "inInicio.value = cfg.datainicioactividades || (anoInicio + '-10-01');", $this->src( 'assets/js/admin-management.js' ) );
	}

	// ── Public page ──

	public function test_public_page_shows_dates_created_badge_and_groups_below_minimum(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Oferta_Publica::aviso_datas( $inicio, $remate )', $page );
		$this->assertStringContainsString( 'anpa-extra-card-datas', $page );
		$this->assertStringContainsString( 'private static function non_acadados_html( string $curso ): string', $page );
		$this->assertSame( 2, substr_count( $page, 'self::grupos_creados_ids( $curso )' ) );
		// The below-minimum list never carries description, schedule or price.
		$body = $this->method_body( $page, 'private static function non_acadados_html(' );
		foreach ( array( 'descripcion', 'custo', 'franxa', 'dias' ) as $f ) {
			$this->assertStringNotContainsString( $f, $body );
		}

		$method = new ReflectionMethod( ANPA_Socios_Extraescolares_Page::class, 'schedule_detail_html' );
		$method->setAccessible( true );
		$html = (string) $method->invoke( null, array(
			'horarios_grupos' => '5|Luns|tarde|16:00-17:00|luns;;6|Martes|tarde|16:00-17:00|martes',
			'grupos_detail'   => json_encode( array(
				array( 'id' => 5, 'min_pupilos' => 8, 'max_pupilos' => 12, 'activos' => 9, 'espera' => 0, 'creado' => true ),
				array( 'id' => 6, 'min_pupilos' => 8, 'max_pupilos' => 12, 'activos' => 2, 'espera' => 0, 'creado' => false ),
			) ),
		) );
		$this->assertSame( 1, substr_count( $html, 'anpa-extra-grupo-creado' ) );
		$this->assertLessThan( strpos( $html, 'Martes' ), strpos( $html, 'Creado' ), 'the badge goes with the created group' );
		$this->assertSame( 1, substr_count( $html, 'Mínimo de 8 para crear grupo.' ), 'only the open group still shows its minimum' );
	}

	// ── Group form ──

	public function test_group_form_shows_state_and_notice_with_the_toggle(): void {
		$js    = $this->src( 'assets/js/admin-management.js' );
		$start = strpos( $js, 'function renderGrupoForm(' );
		$end   = strpos( $js, 'function renderGrupoMatriculas(' );
		$form  = substr( $js, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( "resumo.className = 'anpa-grupo-form-resumo';", $form );
		$this->assertStringContainsString( "'Estado: ' + grupoEstadoLabel(grupo.estado)", $form );
		$this->assertStringContainsString( "'Aviso: ' + grupoAvisoTexto(grupo)", $form );
		$this->assertStringContainsString( "anpaAdminFetch('grupo/' + grupo.id + '/aviso-comezo', { method: 'POST', body: { notificado: !grupo.notificado } })", $form );
		$this->assertStringContainsString( 'openGroupEditor(actividad.id, grupo.id, grupo.serie_uid);', $form );
		// Same label in the activity's group list.
		$this->assertStringContainsString( 'aviso.textContent = grupoAvisoTexto(grupo);', $js );
	}
}
