<?php
/**
 * 1.60.0: trimester + enrolment-window notice next to the enrolment listings
 * (company/canteen panel, Xestión → Matrículas) and after CSV downloads.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Aviso_Listado_Matriculas extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function curso( string $estado = 'activo' ): array {
		return array(
			'curso_escolar'      => '2026/2027',
			'estado'             => $estado,
			'matriculas_abertas' => '0',
			'data_inicio'        => '2026-09-01',
			't1_peche_operativo' => '2026-12-22',
			't2_peche_operativo' => '2027-03-19',
			'data_peche'         => '2027-06-20',
		);
	}

	private function trimestres( string $t1 = 'pechada', string $t2 = 'pechada', string $t3 = 'pechada', bool $presente = true ): array {
		return array(
			1 => array( 'estado' => 'activo', 'ventana_estado' => $t1, 'presente' => $presente ),
			2 => array( 'estado' => 'pendente', 'ventana_estado' => $t2, 'presente' => $presente ),
			3 => array( 'estado' => 'pendente', 'ventana_estado' => $t3, 'presente' => $presente ),
		);
	}

	public function test_open_window_says_the_list_may_change(): void {
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'aberta' ), '2026-09-11' ) );
		$this->assertSame( 'abertas', $aviso['estado'] );
		$this->assertSame( 1, $aviso['trimestre'] );
		$this->assertSame( '1º trimestre · Matrículas ABERTAS', $aviso['titulo'] );
		$this->assertStringContainsString( 'pode variar', $aviso['texto'] );
		$this->assertStringContainsString( 'altas e baixas', $aviso['texto'] );
	}

	public function test_closed_window_says_the_list_is_stable(): void {
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'pechada', 'pechada' ), '2027-02-01' ) );
		$this->assertSame( 'pechadas', $aviso['estado'] );
		$this->assertSame( 2, $aviso['trimestre'] );
		$this->assertSame( '2º trimestre · Matrículas PECHADAS', $aviso['titulo'] );
		$this->assertStringContainsString( 'estable', $aviso['texto'] );
	}

	public function test_other_closed_reasons_explain_why(): void {
		// Trimester rows missing → closed, with the gate label as explanation.
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), array(), '2027-05-01' ) );
		$this->assertSame( 'pechadas', $aviso['estado'] );
		$this->assertSame( 3, $aviso['trimestre'] );
		$this->assertStringContainsString( 'non está inicializado', $aviso['texto'] );

		// Course not active.
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( ANPA_Socios_Matricula_Gate::avaliar( $this->curso( 'pechado' ), $this->trimestres( 'aberta' ), '2026-09-11' ) );
		$this->assertSame( 'pechadas', $aviso['estado'] );
		$this->assertStringContainsString( 'non está activo', $aviso['texto'] );

		// No course at all: fails closed and never claims a trimester.
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( ANPA_Socios_Matricula_Gate::avaliar( null, array() ) );
		$this->assertSame( 'pechadas', $aviso['estado'] );
		$this->assertSame( 0, $aviso['trimestre'] );
		$this->assertSame( 'Trimestre sen determinar · Matrículas PECHADAS', $aviso['titulo'] );
		$this->assertStringContainsString( 'sen configurar', $aviso['texto'] );
	}

	public function test_open_flag_alone_never_opens_the_notice(): void {
		// A forged gate must not open the notice unless motivo AND abertas agree (single source of truth).
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( array( 'abertas' => true, 'trimestre' => 1, 'motivo' => 'ventana_pechada' ) );
		$this->assertSame( 'pechadas', $aviso['estado'] );
		$aviso = ANPA_Socios_Matricula_Gate::aviso_listado( array( 'abertas' => false, 'trimestre' => 1, 'motivo' => 'abertas' ) );
		$this->assertSame( 'pechadas', $aviso['estado'] );
	}

	public function test_endpoints_expose_the_notice(): void {
		$empresa = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "\$out['matriculas']    = ANPA_Socios_Matricula_Gate::aviso_listado(", $empresa );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Gate_Repo::para_curso( (string) $curso )', $empresa );

		$cursos = $this->src( 'includes/class-anpa-socios-admin-cursos-handler.php' );
		$this->assertStringContainsString( "\$row['trimestre']", $cursos );
		$this->assertStringContainsString( "\$row['matriculas_aviso']    = ANPA_Socios_Matricula_Gate::aviso_listado( \$gate );", $cursos );
	}

	public function test_panel_shows_the_notice_above_the_downloads_and_warns_after_download_only_when_open(): void {
		$tpl    = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$aviso  = strpos( $tpl, 'data-empresa-aviso-matriculas' );
		$export = strpos( $tpl, 'data-action="empresa-export" data-ambito="activos"' );
		$this->assertNotFalse( $aviso );
		$this->assertNotFalse( $export );
		$this->assertLessThan( $export, $aviso, 'the notice must sit above the download buttons' );

		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'renderEmpresaAviso(profile.matriculas)', $js );
		$this->assertStringContainsString( "anpa-empresa-aviso--abertas' : 'anpa-empresa-aviso--pechadas'", $js );
		$this->assertStringContainsString( "if (empresaAvisoMatriculas && empresaAvisoMatriculas.estado === 'abertas')", $js );
		$this->assertStringContainsString( 'pode cambiar por altas e baixas', $js );

		$css = $this->src( 'assets/css/area.css' );
		$this->assertStringContainsString( '.anpa-empresa-aviso--abertas', $css );
		$this->assertStringContainsString( '.anpa-empresa-aviso--pechadas', $css );
		$this->assertStringContainsString( '.anpa-area-notice[data-type="warning"]', $css );
	}

	public function test_admin_matriculas_header_and_csv_download_use_the_notice(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( 'anpa-mgmt-aviso-matriculas--abertas', $js );
		$this->assertStringContainsString( 'anpa-mgmt-aviso-matriculas--pechadas', $js );
		$this->assertStringContainsString( 'anpa-mgmt-aviso-matriculas--outro', $js );
		$this->assertStringContainsString( "addCsvExportBtn(bar, 'matriculas', matRows, MAT_COLS, avisoDescarga)", $js );
		$this->assertStringContainsString( "if (activeAviso && activeAviso.estado === 'abertas')", $js );
		$this->assertStringContainsString( 'function exportServerCsv(entity, filename, onDone)', $js );
		$this->assertStringNotContainsString( "'Curso activo: ' + active.curso_escolar + ' · Matrículas ' + (active.matriculas_abertas", $js, 'the old plain status line is replaced by the coloured notice' );

		$css = $this->src( 'assets/css/admin-management.css' );
		$this->assertStringContainsString( '.anpa-mgmt-aviso-matriculas--abertas', $css );
		$this->assertStringContainsString( '.anpa-mgmt-message[data-type="warning"]', $css );
	}

	public function test_admin_matriculas_rows_mark_baixas_in_red(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "if (row.estado === 'baixa') { tr.classList.add('anpa-row-matricula-baixa'); }", $js );
		$this->assertStringContainsString( "else if (row.estado === 'baixa_solicitada') { tr.classList.add('anpa-row-baixa-pending'); }", $js );
		$this->assertStringNotContainsString( 'buildTable(paged, MAT_COLS, matSt.sort, renderMat, null)', $js );

		$css = $this->src( 'assets/css/admin-management.css' );
		$this->assertMatchesRegularExpression( '/\.anpa-mgmt-table \.anpa-row-matricula-baixa td \{[^}]*background: #fdecea;[^}]*color: #7a271a;/s', $css );
	}
}
