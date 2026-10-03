<?php
/**
 * 1.87.0: per-activity dates, price 0 = «Gratuíta», groups without minimum or
 * maximum (0 = no limit), activities that need no enrolment, and the
 * timetable mark for «sen mínimo» groups.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Actividade_Datas_Gratuita extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	private function act( array $extra = array() ): array {
		return $extra + array( 'empresa_id' => 3, 'nome' => 'Xadrez', 'descripcion' => 'Proba', 'custo' => '0', 'estado' => 'activo' );
	}

	public function test_activity_own_dates_and_no_enrolment_flag(): void {
		$p = ANPA_Socios_Admin_Payload::validar_actividad( $this->act() );
		$this->assertSame( 0, $p['datas_propias'] );
		$this->assertNull( $p['data_inicio'] );
		$this->assertSame( 0, $p['sen_inscricion'] );

		$p = ANPA_Socios_Admin_Payload::validar_actividad( $this->act( array( 'datas_propias' => true, 'data_inicio' => '2026-11-02', 'data_remate' => '2027-03-31', 'sen_inscricion' => true ) ) );
		$this->assertSame( array( 1, '2026-11-02', '2027-03-31', 1 ), array( $p['datas_propias'], $p['data_inicio'], $p['data_remate'], $p['sen_inscricion'] ) );
		// Dates are ignored when the box is not checked.
		$p = ANPA_Socios_Admin_Payload::validar_actividad( $this->act( array( 'data_inicio' => '2026-11-02' ) ) );
		$this->assertNull( $p['data_inicio'] );

		$d = array( 'ANPA_Socios_Admin_Payload', 'diagnosticar_actividad' );
		$this->assertSame( 'datas_required', $d( $this->act( array( 'datas_propias' => true ) ) ) );
		$this->assertSame( 'datas_invalid', $d( $this->act( array( 'datas_propias' => true, 'data_inicio' => '31/12/2026' ) ) ) );
		$this->assertSame( 'datas_orde', $d( $this->act( array( 'datas_propias' => true, 'data_inicio' => '2027-03-31', 'data_remate' => '2026-11-02' ) ) ) );
		$this->assertNull( $d( $this->act( array( 'datas_propias' => true, 'data_remate' => '2027-03-31' ) ) ), 'only one date is enough' );
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_actividad( $this->act( array( 'datas_propias' => true ) ) ) );

		$db = $this->body( $this->src( 'includes/class-anpa-socios-db.php' ), 'migrate_to_1_49_0' );
		foreach ( array( "'datas_propias'  => 'tinyint(1) NOT NULL DEFAULT 0'", "'data_inicio'    => 'date NULL DEFAULT NULL'", "'sen_inscricion' => 'tinyint(1) NOT NULL DEFAULT 0'" ) as $col ) {
			$this->assertStringContainsString( $col, $db );
		}
		$h = $this->src( 'includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringContainsString( "'sen_inscricion' => (int) ( \$payload['sen_inscricion'] ?? 0 ),", $this->body( $h, 'base_payload' ) );
		$this->assertStringContainsString( "array( '%d', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%d' )", $h );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( 'datas_propias: datasChk.checked, data_inicio: iniInput.value, data_remate: remInput.value,', $js );
		$this->assertStringContainsString( "sen_inscricion: String(row.sen_inscricion || '0') === '1',", $js, 'the Activar/Desactivar toggle keeps them' );
	}

	public function test_public_page_dates_free_and_no_enrolment(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( "\$curto_act = ! empty( \$act['datas_propias'] )", $page );
		$m = new ReflectionMethod( 'ANPA_Socios_Extraescolares_Page', 'price_label' );
		$m->setAccessible( true );
		$this->assertSame( 'Gratuíta', $m->invoke( null, '0.00' ) );
		$this->assertSame( 'Gratuíta', $m->invoke( null, null ) );
		$this->assertStringContainsString( "\$creado         = \$sen_inscricion || (", $page );
		$this->assertStringContainsString( "'Non precisa inscrición.'", $page );
		$this->assertStringContainsString( "AND a.sen_inscricion = 0", $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' ), 'not offered in the area' );
		$this->assertStringContainsString( "'anpa_extra_sen_inscricion'", $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' ) );
		$this->assertStringContainsString( "'anpa_admin_sen_inscricion'", $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' ), 'no group notices' );
	}

	public function test_zero_means_no_limit(): void {
		$base = array( 'actividad_id' => 1, 'nome' => 'G', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => array( 'luns' ), 'cursos' => array( '2026/2027' ), 'niveis_por_ano' => array( '2026/2027' => array( 1 ) ) );
		$this->assertNotSame( array(), ANPA_Socios_Grupo_Serie::normalize( $base + array( 'min_pupilos' => 0, 'max_pupilos' => 0 ) ), 'no minimum, no maximum' );
		$this->assertNotSame( array(), ANPA_Socios_Grupo_Serie::normalize( $base + array( 'min_pupilos' => 4, 'max_pupilos' => 0 ) ), 'minimum without maximum' );
		$this->assertSame( array(), ANPA_Socios_Grupo_Serie::normalize( $base + array( 'min_pupilos' => 8, 'max_pupilos' => 5 ) ) );
		$this->assertSame( array(), ANPA_Socios_Grupo_Serie::normalize( $base + array( 'min_pupilos' => -1, 'max_pupilos' => 5 ) ) );
		$this->assertSame( 'activo', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'aberto', 99, 0 ) );
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "( (int) \$locked['max_pupilos'] > 0 && \$activos >= (int) \$locked['max_pupilos'] )", $e );
		$this->assertStringContainsString( "'cheo'        => (int) \$g['max_pupilos'] > 0 &&", $e );
		$this->assertStringContainsString( "(int) \$grupo['max_pupilos'] > 0 && max( count( \$active_rows )", $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' ) );
		$this->assertStringContainsString( "esc_html__( 'sen límite', 'anpa-socios' )", $this->src( 'includes/class-anpa-socios-extraescolares-page.php' ) );
	}

	public function test_timetable_marks_sen_minimo_groups(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Xadrez', 'grupo_nome' => 'G1', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns', 'max_pupilos' => 10, 'activos' => 3, 'estado' => 'sen_minimo' ),
			array( 'nome' => 'Ioga', 'grupo_nome' => 'G2', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns', 'max_pupilos' => 10, 'activos' => 9, 'estado' => 'aberto' ),
		) );
		$entries = $grid[0]['dias']['luns'];
		$this->assertTrue( $entries[1]['sen_minimo'] );
		$this->assertFalse( $entries[0]['sen_minimo'] );
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( "' class=\"anpa-extra-lista-sen-minimo\"'", $page );
		$this->assertStringContainsString( 'g.max_pupilos, g.estado', $page );
		$this->assertStringContainsString( '.anpa-extra-lista li.anpa-extra-lista-sen-minimo', $this->src( 'assets/css/extraescolares.css' ) );
	}
}
