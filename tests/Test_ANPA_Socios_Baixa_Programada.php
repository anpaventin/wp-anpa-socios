<?php
/**
 * 1.91.0: baixa reported by the company — registered by the junta, effective
 * on its own in 24 h unless the family says it is a mistake. Pure rules of
 * ANPA_Socios_Baixa_Programada, the new templates, and source contracts for
 * the service, the endpoint, the cron and the cancel paths.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Baixa_Programada extends TestCase {

	private static function src( string $rel ): string {
		return (string) file_get_contents( __DIR__ . '/../' . $rel );
	}

	private static function method_body( string $src, string $signature ): string {
		$start = strpos( $src, $signature );
		self::assertNotFalse( $start, $signature );
		$next = strpos( $src, "\n\tpublic static function ", $start + strlen( $signature ) );
		return false === $next ? substr( $src, $start ) : substr( $src, $start, $next - $start );
	}

	public function test_effective_24_hours_later_in_utc(): void {
		$agora = gmmktime( 10, 30, 0, 10, 28, 2026 );
		$this->assertSame( 24, ANPA_Socios_Baixa_Programada::PRAZO_HORAS );
		$this->assertSame( '2026-10-29 10:30:00', ANPA_Socios_Baixa_Programada::efectiva_en( $agora ) );
	}

	public function test_only_a_requested_baixa_with_a_date_is_scheduled(): void {
		$this->assertTrue( ANPA_Socios_Baixa_Programada::e_programada( 'baixa_solicitada', '2026-10-29 10:30:00' ) );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::e_programada( 'baixa_solicitada', null ), 'a family request stays manual' );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::e_programada( 'baixa_solicitada', '' ) );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::e_programada( 'activo', '2026-10-29 10:30:00' ), 'a stale date never counts' );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::e_programada( 'baixa', '2026-10-29 10:30:00' ) );
	}

	public function test_due_only_once_the_moment_has_passed(): void {
		$momento = gmmktime( 10, 30, 0, 10, 29, 2026 );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::vencida( '2026-10-29 10:30:00', $momento - 1 ) );
		$this->assertTrue( ANPA_Socios_Baixa_Programada::vencida( '2026-10-29 10:30:00', $momento ) );
		$this->assertTrue( ANPA_Socios_Baixa_Programada::vencida( '2026-10-29 10:30:00', $momento + 3600 ) );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::vencida( null, $momento ) );
		$this->assertFalse( ANPA_Socios_Baixa_Programada::vencida( '', $momento ) );
	}

	public function test_date_text_is_in_the_site_time_zone(): void {
		$tz = new DateTimeZone( 'Europe/Madrid' );
		$this->assertSame( '29/10/2026 ás 11:30', ANPA_Socios_Baixa_Programada::texto_data( '2026-10-29 10:30:00', $tz ) );
		$this->assertSame( '15/07/2026 ás 12:00', ANPA_Socios_Baixa_Programada::texto_data( '2026-07-15 10:00:00', $tz ), 'summer time' );
		$this->assertSame( '', ANPA_Socios_Baixa_Programada::texto_data( '', $tz ) );
		$this->assertSame( '', ANPA_Socios_Baixa_Programada::texto_data( 'mañá', $tz ) );
	}

	public function test_new_templates_match_their_variables(): void {
		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		foreach ( array( 'baixa_extraescolar_programada', 'matricula_baixa_programada_aviso', 'matricula_baixa_anulada_aviso' ) as $id ) {
			$this->assertArrayHasKey( $id, $defaults, $id );
			$vars = ANPA_Socios_Email_Template_Store::get_variables( $id );
			$this->assertContains( 'association_name', $vars['subject'], "$id subject names the association" );
			$this->assertContains( 'contact_email', $vars['html'], "$id html gives a contact" );
			foreach ( array( 'subject', 'html', 'text' ) as $field ) {
				$this->assertSame( count( $vars[ $field ] ), substr_count( $defaults[ $id ][ $field ], '%s' ), "$id $field placeholders" );
			}
		}
		// The family is told when, and how to say it is a mistake.
		$familia = ANPA_Socios_Email_Template_Store::get_variables( 'baixa_extraescolar_programada' );
		foreach ( array( 'empresa', 'alumno', 'actividade', 'data_efectiva', 'login_url' ) as $v ) {
			$this->assertContains( $v, $familia['html'], $v );
		}
		$this->assertStringContainsString( 'erro', $defaults['baixa_extraescolar_programada']['html'] );
		$this->assertContains( 'data_efectiva', ANPA_Socios_Email_Template_Store::get_variables( 'matricula_baixa_programada_aviso' )['html'] );
		$this->assertSame( 'Aviso á empresa: baixa rexistrada', ANPA_Socios_Telegram::titulo_masivo( 'matricula_baixa_programada_aviso' ) );
		$this->assertSame( 'Aviso á empresa: baixa anulada', ANPA_Socios_Telegram::titulo_masivo( 'matricula_baixa_anulada_aviso' ) );
	}

	public function test_migration_adds_the_column_and_is_wired(): void {
		$db = self::src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.51.0', '<' ) && ! self::migrate_to_1_51_0()", $db );
		$this->assertStringContainsString( 'ADD COLUMN baixa_efectiva_en datetime NULL DEFAULT NULL AFTER baixa_en', $db );
		$this->assertStringContainsString( "self::tem_columna( \$mat, 'baixa_efectiva_en' )", $db );
	}

	public function test_registering_only_takes_an_active_enrolment_and_notifies_everyone(): void {
		$body = self::method_body( self::src( 'includes/class-anpa-socios-baixa-programada-service.php' ), 'public static function programar(' );
		$this->assertStringContainsString( "SET estado = 'baixa_solicitada', baixa_efectiva_en = %s, actualizado_en = %s WHERE id = %d AND estado = 'activo'", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Programada::AUDIT_PROGRAMADA', $body );
		$this->assertStringContainsString( 'wp_schedule_single_event(', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_baixa_extraescolar_programada( $detalle[\'emails\']', $body );
		$this->assertStringContainsString( "ANPA_Socios_Email::avisar_empresa( \$id, 'matricula_baixa_programada_aviso'", $body );
		$this->assertStringContainsString( "ANPA_Socios_Email::telegram_baixa_programada( 'programada' );", $body );

		$h = self::src( 'includes/class-anpa-socios-admin-baixas-handler.php' );
		$this->assertStringContainsString( "'callback'            => array( __CLASS__, 'programar_matricula' ),", $h );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Programada_Service::programar(', $h );
	}

	public function test_family_emails_copy_the_junta_and_company_notice_skips_the_canteen(): void {
		$email = self::src( 'includes/class-anpa-socios-email.php' );
		$this->assertStringContainsString( "array( 'Bcc: ' . self::junta_email() )", self::method_body( $email, 'private static function send_template_familia_xunta(' ) );
		$this->assertStringContainsString( "self::send_template_familia_xunta( \$emails, 'baixa_extraescolar_confirmada'", $email );
		$empresa = self::method_body( $email, 'public static function avisar_empresa(' );
		$this->assertStringContainsString( "ANPA_Socios_Aviso_Matricula::destinatarios( (string) \$row['empresa_email'], '' )", $empresa );
		$this->assertStringNotContainsString( 'e_grupo_creado', $empresa, 'the company raised it: always told' );
		// The offer to the next on the waiting list is copied to the junta too.
		$this->assertStringContainsString( "\$copia_xunta ? array( 'Bcc: ' . self::junta_email() ) : array()", $email );
		$this->assertStringContainsString( 'self::notify_offer( (int) $next[\'id\'], $copia_xunta );', self::src( 'includes/class-anpa-socios-extraescolar-offers.php' ) );
	}

	public function test_cron_makes_it_effective_like_the_confirm_button(): void {
		$s    = self::src( 'includes/class-anpa-socios-baixa-programada-service.php' );
		$cron = self::method_body( $s, 'public static function executar_vencidas(' );
		$this->assertStringContainsString( "estado = 'baixa_solicitada' AND baixa_efectiva_en IS NOT NULL AND baixa_efectiva_en <= %s", $cron );
		$this->assertStringContainsString( 'self::efectivar( (int) $id, \'system\', \'system\', ANPA_Socios_Baixa_Programada::AUDIT_EFECTIVA )', $cron );
		$ef = self::method_body( $s, 'public static function efectivar(' );
		$this->assertStringContainsString( "baixa_efectiva_en = NULL, actualizado_en = %s WHERE id = %d AND estado = 'baixa_solicitada'", $ef );
		$this->assertStringContainsString( 'ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat[\'grupo_id\'], (int) $mat[\'trimestre\'], 0, $programada );', $ef );
		$this->assertStringContainsString( "ANPA_Socios_Email::telegram_baixa_programada( 'efectiva', \$oferta );", $ef );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Programada_Service::efectivar(', self::src( 'includes/class-anpa-socios-admin-grupos-handler.php' ) );

		$main = self::src( 'anpa-socios.php' );
		$this->assertStringContainsString( "require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-baixa-programada-service.php';", $main );
		$this->assertStringContainsString( "add_action( ANPA_Socios_Baixa_Programada_Service::CRON_HOOK, array( 'ANPA_Socios_Baixa_Programada_Service', 'executar_vencidas' ) );", $main );
		$this->assertStringContainsString( "add_action( ANPA_Socios_Extraescolar_Offers::CRON_HOOK, array( 'ANPA_Socios_Baixa_Programada_Service', 'executar_vencidas' ), 5 );", $main );
	}

	public function test_cancel_paths_clear_the_date_and_tell_the_company(): void {
		$rest   = self::src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$cancel = self::method_body( $rest, 'public static function cancel_baixa(' );
		// Mid-course the enrolment window is closed: a reported baixa can still be called a mistake.
		$this->assertStringContainsString( "\$programada ? self::course_is_active( (string) ( \$mat['curso_escolar'] ?? '' ) ) : self::course_is_open(", $cancel );
		$this->assertStringContainsString( '$programada ? self::lock_open_course_mode( $curso ) : self::lock_open_course( $curso );', $cancel );
		$this->assertStringContainsString( "ANPA_Socios_Baixa_Programada_Service::anulada( (int) \$mat['id'], \$programada,", $cancel );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Programada_Service::limpar( $id );', self::method_body( $rest, 'public static function request_baixa(' ) );

		$reject = self::method_body( self::src( 'includes/class-anpa-socios-admin-baixas-handler.php' ), 'public static function reject_matricula(' );
		$this->assertStringContainsString( '$programada = ANPA_Socios_Baixa_Programada_Service::e_programada( $id );', $reject );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Programada_Service::anulada( $id, $programada,', $reject );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Programada_Service::limpar( $id );', self::src( 'includes/class-anpa-socios-admin-matriculas-handler.php' ) );

		$anulada = self::method_body( self::src( 'includes/class-anpa-socios-baixa-programada-service.php' ), 'public static function anulada(' );
		$this->assertStringContainsString( "ANPA_Socios_Email::avisar_empresa( \$id, 'matricula_baixa_anulada_aviso' );", $anulada );
		$this->assertStringContainsString( "ANPA_Socios_Email::telegram_baixa_programada( 'anulada' );", $anulada );
	}

	public function test_telegram_lines_carry_no_family_data(): void {
		$body = self::method_body( self::src( 'includes/class-anpa-socios-email.php' ), 'public static function telegram_baixa_programada(' );
		$this->assertSame( 3, substr_count( $body, 'self::telegram( ANPA_Socios_Telegram::TIPO_AVISO,' ) );
		$this->assertStringNotContainsString( '$alumno', $body );
		$this->assertStringNotContainsString( '$actividade', $body );
	}

	public function test_xestion_has_the_button_and_the_pending_mark(): void {
		$js = self::src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "anpaAdminFetch('matricula/' + m.id + '/baixa/programar', { method: 'POST' })", $js );
		$this->assertSame( 3, substr_count( $js, 'baixaEmpresaBtn(' ), 'helper + Matrículas listing + group pupils' );
		$this->assertStringContainsString( "if (m.programada) {", $js );
		$this->assertStringContainsString( "'baixa_programada_empresa'", self::src( 'includes/lib/class-anpa-socios-auditoria.php' ) );
	}
}
