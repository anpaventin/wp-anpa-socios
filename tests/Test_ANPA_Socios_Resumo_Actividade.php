<?php
/**
 * 1.69.0: per-activity course summary (option A) and the per-group notice
 * state toggle from the activity form.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Resumo_Actividade extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_limits_follow_operative_dates_or_fall_back_to_the_month_model(): void {
		$l = ANPA_Socios_Resumo_Actividade::limites( '2026/2027', array( 'inicio' => '2026-09-01', 't1' => '2026-12-22', 't2' => '2027-03-19', 'peche' => '2027-06-20' ) );
		$this->assertSame( array( 'ini' => '2026-09-01', 'fin' => '2026-12-22' ), $l[1] );
		$this->assertSame( array( 'ini' => '2026-12-23', 'fin' => '2027-03-19' ), $l[2] );
		$this->assertSame( array( 'ini' => '2027-03-20', 'fin' => '2027-06-20' ), $l[3] );
		$l = ANPA_Socios_Resumo_Actividade::limites( '2026/2027', array( 'inicio' => '', 't1' => '0000-00-00', 't2' => '', 'peche' => '' ) );
		$this->assertSame( array( 'ini' => '2026-09-01', 'fin' => '2026-12-31' ), $l[1] );
		$this->assertSame( array( 'ini' => '2027-01-01', 'fin' => '2027-03-31' ), $l[2] );
		$this->assertSame( array( 'ini' => '2027-04-01', 'fin' => '2027-06-30' ), $l[3] );
		$this->assertSame( 1, ANPA_Socios_Resumo_Actividade::trimestre_actual( $l, '2026-11-02' ) );
		$this->assertSame( 2, ANPA_Socios_Resumo_Actividade::trimestre_actual( $l, '2027-01-01' ) );
		$this->assertSame( 3, ANPA_Socios_Resumo_Actividade::trimestre_actual( $l, '2027-05-05' ) );
	}

	public function test_aggregates_active_waiting_and_leaving_per_trimester(): void {
		$limites = ANPA_Socios_Resumo_Actividade::limites( '2026/2027', array( 'inicio' => '2026-09-01', 't1' => '2026-12-22', 't2' => '2027-03-19', 'peche' => '2027-06-20' ) );
		$grupos  = array( array( 'id' => 1, 'nome' => 'Luns', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns', 'min_pupilos' => 4, 'max_pupilos' => 6, 'estado' => 'aberto', 'notificado' => true, 'aviso_comezo_trimestre' => 2, 'aviso_comezo_en' => '2027-01-10 09:00:00' ) );
		$mats    = array(
			array( 'grupo_id' => 1, 'estado' => 'activo', 'creado_en' => '2026-09-20 10:00:00', 'baixa_en' => null ),                 // all year
			array( 'grupo_id' => 1, 'estado' => 'activo', 'creado_en' => '2026-09-21 10:00:00', 'baixa_en' => null ),
			array( 'grupo_id' => 1, 'estado' => 'baixa', 'creado_en' => '2026-09-22 10:00:00', 'baixa_en' => '2026-12-22 12:00:00' ), // left at the end of T1 → active in T1, gone in T2
			array( 'grupo_id' => 1, 'estado' => 'baixa', 'creado_en' => '2026-09-23 10:00:00', 'baixa_en' => '2027-02-01 12:00:00' ), // left in T2
			array( 'grupo_id' => 1, 'estado' => 'activo', 'creado_en' => '2027-01-05 10:00:00', 'baixa_en' => null ),                 // joined in T2
			array( 'grupo_id' => 1, 'estado' => 'lista_espera', 'creado_en' => '2027-01-06 10:00:00', 'baixa_en' => null ),
			array( 'grupo_id' => 1, 'estado' => 'pendente_aprobacion', 'creado_en' => '2027-02-06 10:00:00', 'baixa_en' => null ),
			array( 'grupo_id' => 1, 'estado' => 'baixa', 'creado_en' => '2026-10-01 10:00:00', 'baixa_en' => null ),                  // left without a date: never active
			array( 'grupo_id' => 99, 'estado' => 'activo', 'creado_en' => '2026-09-01 10:00:00', 'baixa_en' => null ),                // another group
		);
		$r = ANPA_Socios_Resumo_Actividade::agregar( $grupos, $mats, $limites, '2027-02-15' );
		$this->assertSame( 2, $r['trimestre_actual'] );
		$g = $r['grupos'][0];
		// T1 (past): the two all-year pupils + the one who left on 22/12 (still there at the close) → 3 active; the
		// 22/12 baixa is dated inside T1 → 1 baixa. Nobody waits in a past trimester (unknown → null).
		$this->assertSame( array( 'estado' => 'pasado', 'activos' => 3, 'espera' => null, 'baixas' => 1 ), $g['trimestres'][1] );
		// T2 (current, up to today 15/02): two all-year + the January joiner = 3 active; the February leaver is a
		// baixa of T2 and no longer active; one on the waiting list now.
		$this->assertSame( array( 'estado' => 'actual', 'activos' => 3, 'espera' => 1, 'baixas' => 1 ), $g['trimestres'][2] );
		$this->assertSame( array( 'estado' => 'futuro', 'activos' => null, 'espera' => null, 'baixas' => null ), $g['trimestres'][3] );
		$this->assertSame( 1, $g['pendentes'] );
		$this->assertSame( 3, $g['prazas_libres'], '6 places − 3 active now' );
		$this->assertTrue( $g['notificado'] );
		$this->assertSame( 2, $g['aviso_comezo_trimestre'] );
		$this->assertSame( array( 'activos' => 3, 'baixas' => 1 ), $r['totais'][1] );
		$this->assertSame( array( 'activos' => 3, 'baixas' => 1 ), $r['totais'][2] );
		$this->assertSame( array( 'activos' => null, 'baixas' => null ), $r['totais'][3] );
		$this->assertSame( 1, $r['pendentes'] );
	}

	public function test_group_without_maximum_has_no_free_places_figure(): void {
		$limites = ANPA_Socios_Resumo_Actividade::limites( '2026/2027', array() );
		$r = ANPA_Socios_Resumo_Actividade::agregar( array( array( 'id' => 5, 'nome' => 'X', 'max_pupilos' => 0 ) ), array(), $limites, '2026-10-10' );
		$this->assertNull( $r['grupos'][0]['prazas_libres'] );
		$this->assertSame( 0, $r['grupos'][0]['trimestres'][1]['activos'] );
		$this->assertFalse( $r['grupos'][0]['notificado'] );
	}

	// ── Glue contracts ──────────────────────────────────────────────────

	public function test_resumo_endpoint_is_read_only_master_gated_and_carries_no_pii(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringContainsString( "'/actividad/(?P<id>\\d+)/resumo'", $h );
		$this->assertStringContainsString( "'callback'            => array( __CLASS__, 'resumo' )", $h );
		$start = strpos( $h, 'public static function resumo(' );
		$end   = strpos( $h, 'public static function list_actividades(', $start + 1 );
		$body  = substr( $h, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( 'ANPA_Socios_Resumo_Actividade::limites(', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Resumo_Actividade::agregar(', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Grupos_Handler::ciclo_ventana(', $body );
		$this->assertStringContainsString( 'SELECT grupo_id, estado, creado_en, baixa_en FROM', $body );
		$this->assertStringNotContainsString( 'tabela_fillos', $body );
		$this->assertStringNotContainsString( 'tabela_socios', $body );
		$this->assertStringNotContainsString( '$wpdb->update', $body );
		$this->assertStringNotContainsString( '$wpdb->insert', $body );
	}

	public function test_notice_state_can_be_toggled_from_the_activity_form(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->assertStringContainsString( "'/grupo/(?P<id>\\d+)/aviso-comezo'", $h );
		$this->assertStringContainsString( 'public static function set_aviso_comezo(', $h );
		$this->assertStringContainsString( "'aviso_comezo_ciclo' => null, 'aviso_comezo_trimestre' => null, 'aviso_comezo_en' => null", $h );
		// The activity's group list exposes the notice state.
		$start = strpos( $h, 'public static function list_grupos(' );
		$end   = strpos( $h, "\n\t/**", $start + 1 );
		$body  = substr( $h, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( 'aviso_comezo_ciclo, aviso_comezo_trimestre, aviso_comezo_en', $body );
		$this->assertStringContainsString( "'notificado'", $body );

		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'Grupo', 'Horario', 'Franxa', 'Días', 'Niveis', 'Min', 'Max', 'Estado', 'Aviso', ''", $js );
		$this->assertStringContainsString( "anpaAdminFetch('grupo/' + grupo.id + '/aviso-comezo', { method: 'POST', body: { notificado: !grupo.notificado } })", $js );
		$this->assertStringContainsString( 'Marcar sen notificar', $js );
		$this->assertStringContainsString( 'Marcar notificado (sen correo)', $js );
		// Resumo per activity: a toggle row under the activity in the listing.
		$this->assertStringContainsString( "anpaAdminFetch('actividad/' + row.id + '/resumo')", $js );
		$this->assertStringContainsString( 'anpa-resumo-row', $js );
		$this->assertStringContainsString( "['Grupo', 'Mín/Máx', '1\\u00BA trimestre', '2\\u00BA trimestre', '3\\u00BA trimestre', 'Prazas libres', 'Aviso']", $js );
		$css = $this->src( 'assets/css/admin-management.css' );
		$this->assertStringContainsString( '.anpa-resumo-row', $css );
		$this->assertStringContainsString( '.anpa-grupo-aviso--si', $css );
	}

	public function test_lib_is_wired(): void {
		foreach ( array( 'anpa-socios.php', 'tests/bootstrap.php' ) as $rel ) {
			$this->assertStringContainsString( 'class-anpa-socios-resumo-actividade.php', $this->src( $rel ), $rel );
		}
	}
}
