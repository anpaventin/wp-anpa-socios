<?php
/**
 * 1.83.0: data retention — members' data deleted N months after the baixa
 * (default 9, date in the baixa email), audit log kept M months (default 12),
 * and a 2nd parent in baixa deleted before an alta creates them again.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-anpa-socios-email-template-migration.php';

final class Test_ANPA_Socios_Retencion extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	public function test_months_setting_is_bounded(): void {
		$m = array( 'ANPA_Socios_Retencion', 'meses' );
		$this->assertSame( 9, $m( '', 9 ) );
		$this->assertSame( 6, $m( '6', 9 ) );
		$this->assertSame( 24, $m( 24, 12 ) );
		$this->assertSame( 9, $m( '0', 9 ) );
		$this->assertSame( 9, $m( '121', 9 ) );
		$this->assertSame( 9, $m( '-3', 9 ) );
		$this->assertSame( 9, $m( 'nove', 9 ) );
		$this->assertSame( 9, ANPA_Socios_Retencion::MESES_BAIXA );
		$this->assertSame( 12, ANPA_Socios_Retencion::MESES_AUDITORIA );
	}

	public function test_adding_months_clamps_month_ends(): void {
		$s = array( 'ANPA_Socios_Retencion', 'sumar_meses' );
		$this->assertSame( '2027-06-30 10:00:00', $s( '2026-09-30 10:00:00', 9 ) );
		$this->assertSame( '2027-02-28 00:00:00', $s( '2026-05-31', 9 ), 'no spill into March' );
		$this->assertSame( '2028-02-29 08:15:00', $s( '2027-05-31 08:15:00', 9 ), 'leap year' );
		$this->assertSame( '2025-12-30 23:59:59', $s( '2026-09-30 23:59:59', -9 ) );
		$this->assertSame( '2026-02-28 00:00:00', $s( '2026-11-30', -9 ), 'backwards clamps too' );
		$this->assertSame( '2025-09-30 12:00:00', $s( '2026-09-30 12:00:00', -12 ) );
		$this->assertSame( '', $s( 'non é data', 9 ) );
	}

	public function test_whole_family_only_when_everyone_is_past_the_deadline(): void {
		$a     = array( 'ANPA_Socios_Retencion', 'alcance' );
		$corte = '2026-01-01 00:00:00';
		$vella = array( 'estado' => 'baixa', 'baixa_en' => '2025-12-01 10:00:00' );
		$this->assertSame( 'familia', $a( array( $vella, $vella ), $corte ) );
		$this->assertSame( 'persoa', $a( array( $vella, array( 'estado' => 'activo', 'baixa_en' => null ) ), $corte ), 'the other parent came back' );
		$this->assertSame( 'persoa', $a( array( $vella, array( 'estado' => 'baixa', 'baixa_en' => '2026-03-01 00:00:00' ) ), $corte ), 'the other one left later' );
		$this->assertSame( 'persoa', $a( array( $vella, array( 'estado' => 'baixa', 'baixa_en' => '' ) ), $corte ), 'no date yet: never' );
		$this->assertSame( 'persoa', $a( array(), $corte ) );
	}

	public function test_unedited_templates_follow_the_new_default_and_edited_ones_stay(): void {
		delete_option( 'anpa_socios_email_templates' );
		delete_option( ANPA_Socios_Email_Template_Migration::REFRESH_OPTION );
		ANPA_Socios_Email_Template_Migration::seed_if_needed();
		$stored = get_option( 'anpa_socios_email_templates' );
		$stored['baixa_socio_confirmada']['html'] = '<p>Texto da instalación antiga %s</p>';
		$stored['baixa_socio_rexeitada']['html']  = '<p>Editado pola xunta</p>';
		$stored['baixa_socio_rexeitada']['modified'] = '2026-09-01 10:00:00';
		update_option( 'anpa_socios_email_templates', $stored );

		$r      = ANPA_Socios_Email_Template_Migration::migrate();
		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertSame( 1, $r['refreshed'] );
		$this->assertStringContainsString( 'eliminaranse por completo', $stored['baixa_socio_confirmada']['html'] );
		$this->assertSame( '<p>Editado pola xunta</p>', $stored['baixa_socio_rexeitada']['html'] );
		$this->assertSame( 0, ANPA_Socios_Email_Template_Migration::migrate()['refreshed'], 'idempotent' );
		delete_option( 'anpa_socios_email_templates' );
	}

	public function test_baixa_email_says_when_the_data_is_deleted(): void {
		$d    = ANPA_Socios_Email_Template_Store::get_all_defaults()['baixa_socio_confirmada'];
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'baixa_socio_confirmada' );
		$this->assertSame( 'data_eliminacion', end( $vars['html'] ), 'appended last: customised templates keep their order' );
		$this->assertSame( 'data_eliminacion', end( $vars['text'] ) );
		foreach ( array( 'html', 'text' ) as $k ) {
			$this->assertSame( count( $vars[ $k ] ), substr_count( $d[ $k ], '%s' ), $k );
			$this->assertStringContainsString( 'sen posibilidade de recuperalos', $d[ $k ] );
		}
		$e = $this->src( 'includes/class-anpa-socios-email.php' );
		$this->assertStringContainsString( "'data_eliminacion' => \$eliminacion", $e );
		$this->assertStringContainsString( 'ANPA_Socios_Retencion_Service::data_eliminacion_texto()', $e );
	}

	public function test_schema_and_baixa_date(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "const DB_VERSION = '1.47.0';", $db );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.47.0', '<' ) && ! self::migrate_to_1_47_0()", $db );
		$m = $this->body( $db, 'migrate_to_1_47_0' );
		$this->assertStringContainsString( 'ADD COLUMN baixa_en datetime NULL DEFAULT NULL', $m );
		$this->assertStringContainsString( "SET baixa_en = %s WHERE estado = 'baixa' AND baixa_en IS NULL", $m, 'existing baixas start counting now' );
		$bf = $this->body( $this->src( 'includes/class-anpa-socios-baixa-familia.php' ), 'executar' );
		$this->assertStringContainsString( "SET baixa_en = %s WHERE estado = 'baixa' AND rol <> 'master' AND LOWER(email) IN (", $bf );
	}

	public function test_daily_job_is_wired_and_careful(): void {
		$p = $this->src( 'anpa-socios.php' );
		$this->assertStringContainsString( "register_activation_hook( __FILE__, array( 'ANPA_Socios_Retencion_Service', 'programar' ) );", $p );
		$this->assertStringContainsString( 'ANPA_Socios_Retencion_Service::programar();', $p );
		$this->assertStringContainsString( "register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Retencion_Service', 'desprogramar' ) );", $p );
		$this->assertStringContainsString( "add_action( ANPA_Socios_Retencion_Service::CRON_HOOK, array( 'ANPA_Socios_Retencion_Service', 'executar' ) );", $p );

		$s = $this->src( 'includes/class-anpa-socios-retencion-service.php' );
		$this->assertStringContainsString( "wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK )", $s );
		$pb = $this->body( $s, 'purgar_baixas' );
		$this->assertStringContainsString( "estado = 'baixa' AND rol <> 'master' AND baixa_en IS NOT NULL AND baixa_en <= %s", $pb );
		$this->assertStringContainsString( 'is_protected_admin', $pb );
		$this->assertStringContainsString( "'familia' === ANPA_Socios_Retencion::alcance(", $pb );
		$bfam = $this->body( $s, 'borrar_familia' );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Eliminar_Handler::load_family_context( $socio, true )', $bfam );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Eliminar_Handler::delete_family_context( $ctx )', $bfam );
		$this->assertStringContainsString( "'baixa' !== (string) \$m['estado']", $bfam, 'nobody came back meanwhile' );
		$this->assertStringContainsString( "WHERE id = %d AND estado = 'baixa' AND rol <> 'master'", $this->body( $s, 'borrar_persoa' ) );
		$pa = $this->body( $s, 'purgar_auditoria' );
		$this->assertStringContainsString( 'gmdate(', $pa, 'audit timestamps are UTC' );
		$this->assertStringContainsString( 'LIMIT 5000', $pa );
		$this->assertStringContainsString( 'meses_retencion_auditoria()', $pa );
		foreach ( array( 'load_family_context', 'validate_family_members', 'delete_family_context' ) as $fn ) {
			$this->assertStringContainsString( 'public static function ' . $fn . '(', $this->src( 'includes/class-anpa-socios-admin-eliminar-handler.php' ) );
		}
		preg_match_all( '/"(?:SELECT|UPDATE|DELETE)[^"]*"/', $s, $mm );
		foreach ( $mm[0] as $sql ) {
			$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $sql ), 'SQL must stay ASCII' );
		}
	}

	public function test_alta_deletes_a_second_parent_in_baixa_before_creating_them(): void {
		$a = $this->src( 'includes/class-anpa-socios-rest.php' );
		$purga  = strpos( $a, "ANPA_Socios_Retencion_Service::purgar_baixa_para_alta( (string) \$clean['parent2']['email'], \$familia_id )" );
		$upsert = strpos( $a, "self::upsert_socio( \$clean['parent2']['email'], \$clean['parent2'], \$familia_id, true, \$owner_estado )" );
		$this->assertNotFalse( $purga );
		$this->assertLessThan( (int) $upsert, (int) $purga, 'deleted before the insert' );
		$p = $this->body( $this->src( 'includes/class-anpa-socios-retencion-service.php' ), 'purgar_baixa_para_alta' );
		$this->assertStringContainsString( "WHERE email = %s AND estado = 'baixa' AND rol <> 'master' FOR UPDATE", $p, 'only a member in baixa, never an active one or an admin' );
		$this->assertStringContainsString( 'is_protected_admin', $p );
		$this->assertStringContainsString( 'if ( $familia_id > 0 && $familia === $familia_id ) {', $p, 'same family: only that person' );
		$this->assertStringContainsString( 'if ( $activos > 0 ) {', $p, 'another family with someone active: untouched' );
		$this->assertStringContainsString( 'self::borrar_familia( $row, false )', $p, 'another family all in baixa: whole family, inside the alta transaction' );
		$this->assertStringContainsString( "purgar_baixa_para_alta( (string) \$clean['parent2']['email'], \$familia_id )", $this->src( 'includes/class-anpa-socios-rest.php' ) );
	}

	public function test_family_deletion_never_takes_another_familys_children(): void {
		$e = $this->src( 'includes/class-anpa-socios-admin-eliminar-handler.php' );
		$this->assertStringContainsString( "') AND ( familia_id IS NULL OR familia_id = 0 ) )'", $e );
	}

	public function test_baixa_date_is_kept_in_step_everywhere(): void {
		$this->assertStringContainsString( "'baixa_en' => null", $this->src( 'includes/class-anpa-socios-area-rest.php' ) );
		$this->assertStringContainsString( "'baixa_en' => current_time( 'mysql' )", $this->src( 'includes/class-anpa-socios-admin-iban-import-handler.php' ) );
		$h = $this->src( 'includes/class-anpa-socios-admin-socios-handler.php' );
		$this->assertStringContainsString( "\$update_data['baixa_en'] = current_time( 'mysql' );", $h );
		$this->assertStringContainsString( "\$update_data['baixa_en'] = null;", $h );
		$this->assertStringContainsString( "SET baixa_en = NULL WHERE email = %s AND estado <> 'baixa'", $this->src( 'includes/class-anpa-socios-rest.php' ) );
		$m = $this->src( 'includes/class-anpa-socios-email-template-migration.php' );
		$this->assertStringContainsString( 'switch_to_locale( get_locale() )', $m, 'site language, not the admin profile one' );
		$this->assertStringContainsString( 'self::REFRESH_OPTION', $m, 'once per plugin version' );
	}

	public function test_settings_form_is_isolated(): void {
		$s = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( "add_action( 'admin_post_anpa_socios_save_retencion', array( __CLASS__, 'handle_save_retencion' ) );", $s );
		$h = $this->body( $s, 'handle_save_retencion' );
		$this->assertStringContainsString( "self::guard( 'anpa_socios_save_retencion' );", $h );
		$this->assertSame( 2, substr_count( $h, 'update_option(' ), 'saves only the two retention settings' );
		$this->assertSame( 'Borrado automático por prazo de conservación', ANPA_Socios_Auditoria::etiqueta( 'retencion_purga', 'retencion' ) );
	}
}
