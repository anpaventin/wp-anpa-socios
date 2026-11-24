<?php
/**
 * 1.98.0: public activity cards like the socios occupancy cards, with
 * «Máis información» in a floating window.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Tarxetas_Oferta extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_card_keeps_every_field_and_moves_the_description(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		foreach ( array( "'Empresa:'", "'Descripción:'", "'Prezo:'", "'Datas:'", 'anpa-extra-card-datas', 'self::schedule_detail_html( $act )', 'self::price_label( $act[\'custo\'] ?? null )' ) as $needle ) {
			$this->assertStringContainsString( $needle, $page, $needle );
		}
		// The description lives inside «Máis información» (inline without JS).
		$this->assertMatchesRegularExpression( "/<details class=\"anpa-extra-mais\">.*?'Descripción:'.*?<\\/div><\\/details>/s", $page );
		$this->assertStringContainsString( "esc_html__( 'Máis información', 'anpa-socios' )", $page );
	}

	public function test_groups_show_state_and_an_occupancy_bar(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( 'private static function barra_prazas_html( array $group ): string', $page );
		$this->assertStringContainsString( 'self::group_prazas_html( $part[\'capacity\'] ) . self::barra_prazas_html( $part[\'capacity\'] )', $page );
		$this->assertStringContainsString( "__( 'Aberto', 'anpa-socios' )", $page );
		$m = new ReflectionMethod( 'ANPA_Socios_Extraescolares_Page', 'barra_prazas_html' );
		$m->setAccessible( true );
		$this->assertSame( '', $m->invoke( null, array( 'max_pupilos' => 0, 'activos' => 5 ) ) );
		$this->assertSame( '<div class="anpa-extra-barra anpa-extra-barra--completo" aria-hidden="true"><span style="width:100%"></span><i style="left:60%"></i></div>', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 15, 'min_pupilos' => 9 ) ) );
		// 1.99.0: orange below the minimum or «Sen grupo», green from the minimum or when created.
		$this->assertStringContainsString( 'anpa-extra-barra--baixo', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 5, 'min_pupilos' => 9 ) ) );
		$this->assertStringContainsString( 'anpa-extra-barra--baixo', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 12, 'min_pupilos' => 9, 'sen_minimo' => true ) ) );
		$this->assertStringStartsWith( '<div class="anpa-extra-barra" ', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 13, 'min_pupilos' => 9 ) ) );
		$this->assertStringStartsWith( '<div class="anpa-extra-barra" ', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 4, 'min_pupilos' => 9, 'creado' => true ) ) );
		// A created group no longer shows the minimum mark.
		$this->assertStringNotContainsString( '<i ', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 10, 'min_pupilos' => 9, 'creado' => true ) ) );
	}

	public function test_floating_window_script_is_loaded_with_the_offer(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( "wp_enqueue_script( 'anpa-extraescolares', plugins_url( 'assets/js/extraescolares.js', ANPA_SOCIOS_PLUGIN_FILE )", $page );
		$js = $this->src( 'assets/js/extraescolares.js' );
		$this->assertStringContainsString( "typeof window.HTMLDialogElement !== 'function'", $js, 'old browsers keep the inline details' );
		$this->assertStringContainsString( 'dialog.showModal();', $js );
		$this->assertStringContainsString( "ev.target.closest('.anpa-extra-mais > summary')", $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		$css = $this->src( 'assets/css/extraescolares.css' );
		$this->assertStringContainsString( '.anpa-extra-dialog::backdrop', $css );
	}

	public function test_public_label_says_sen_grupo(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		// 1.98.6/1.99.0: every state chip explains itself (hover, focus and screen readers).
		$m = new ReflectionMethod( 'ANPA_Socios_Extraescolares_Page', 'estado_chip' );
		$m->setAccessible( true );
		$html = $m->invoke( null, 'sen_minimo' );
		$this->assertStringContainsString( '>Sen grupo<', $html );
		$this->assertStringContainsString( 'tabindex="0"', $html );
		$this->assertStringContainsString( 'data-axuda="O grupo aínda non acadou o mínimo de inscricións para crearse.', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">: O grupo aínda non acadou o mínimo', $html );
		$this->assertStringNotContainsString( "esc_html__( 'Sen mínimo', 'anpa-socios' )", $page );
		foreach ( array( 'creado' => 'Grupo confirmado', 'aberto' => 'Admite inscricións', 'sen_inscricion' => 'Non precisa inscrición' ) as $estado => $axuda ) {
			$chip = $m->invoke( null, $estado );
			$this->assertStringContainsString( 'anpa-axuda" tabindex="0" data-axuda="' . $axuda, $chip, $estado );
		}
		$this->assertSame( '', $m->invoke( null, 'outro' ) );
	}

	public function test_timetable_entry_is_name_levels_places_and_state(): void {
		$m = new ReflectionMethod( 'ANPA_Socios_Extraescolares_Page', 'entrada_horario_html' );
		$m->setAccessible( true );
		$html = $m->invoke( null, array( 'nome' => 'Bloques de Construción (legoteca)', 'grupos' => array( '4º, 5º, 6º' ), 'activos' => 10, 'max' => 15, 'estado' => 'creado', 'ocupacion' => 'ok' ) );
		$this->assertStringStartsWith( '<span class="anpa-extra-act">Bloques de Construción (legoteca)</span> <span class="anpa-extra-grupos">(4º, 5º, 6º)</span> <span class="anpa-extra-horario-prazas anpa-extra-prazas-ok">10/15</span> <span class="anpa-extra-grupo-creado anpa-axuda"', $html );
		// No places nor minimum: no count and no chip.
		$this->assertSame( '<span class="anpa-extra-act">Patios</span> <span class="anpa-extra-grupos">(1º)</span>', $m->invoke( null, array( 'nome' => 'Patios', 'grupos' => array( '1º' ), 'activos' => 0, 'max' => 0, 'estado' => '' ) ) );
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertSame( 2, substr_count( $page, 'self::entrada_horario_html( $entry )' ), 'table and phone list' );
	}

	public function test_nivel_ocupacion(): void {
		$this->assertSame( 'completo', ANPA_Socios_Prazas::nivel_ocupacion( 15, 15, 9, true, false ) );
		$this->assertSame( 'baixo', ANPA_Socios_Prazas::nivel_ocupacion( 3, 15, 9, false, false ) );
		$this->assertSame( 'baixo', ANPA_Socios_Prazas::nivel_ocupacion( 10, 15, 9, false, true ) );
		$this->assertSame( 'ok', ANPA_Socios_Prazas::nivel_ocupacion( 9, 15, 9, false, false ) );
		$this->assertSame( 'ok', ANPA_Socios_Prazas::nivel_ocupacion( 3, 15, 9, true, false ) );
		$this->assertSame( 'ok', ANPA_Socios_Prazas::nivel_ocupacion( 3, 15, 0, false, false ) );
		$this->assertSame( 'anpa-extra-prazas-baixo', ANPA_Socios_Prazas::nivel_class( 'baixo' ) );
		$this->assertSame( 'anpa-extra-prazas-ok', ANPA_Socios_Prazas::nivel_class( '' ) );
		$this->assertFalse( ANPA_Socios_Prazas::ten_limites( 0, 0 ) );
		$this->assertTrue( ANPA_Socios_Prazas::ten_limites( 0, 5 ) );
	}

	public function test_area_chips_explain_themselves_and_bars_follow_the_rule(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "var COMPLETO = [__( 'Completo', 'anpa-socios' ), 'completo', __( 'Todas as prazas", $js );
		$this->assertStringContainsString( "chip.setAttribute('data-axuda', est[2]);", $js );
		$this->assertStringContainsString( "if (maximo > 0 || minimo > 0 || g.estado === 'non_acadado')", $js );
		$this->assertStringContainsString( "'anpa-cifras-barra anpa-cifras-barra--' + nivel", $js );
		$this->assertStringNotContainsString( 'pct >= 80', $js );
	}
}
