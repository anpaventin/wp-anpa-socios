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
		$this->assertStringContainsString( "esc_html__( 'Aberto', 'anpa-socios' )", $page );
		$m = new ReflectionMethod( 'ANPA_Socios_Extraescolares_Page', 'barra_prazas_html' );
		$m->setAccessible( true );
		$this->assertSame( '', $m->invoke( null, array( 'max_pupilos' => 0, 'activos' => 5 ) ) );
		$this->assertSame( '<div class="anpa-extra-barra anpa-extra-barra--completo" aria-hidden="true"><span style="width:100%"></span><i style="left:60%"></i></div>', $m->invoke( null, array( 'max_pupilos' => 15, 'activos' => 15, 'min_pupilos' => 9 ) ) );
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
		// 1.98.3: timetable (table + phone list) and cards say «Sen grupo», never «Sen mínimo».
		$this->assertSame( 3, substr_count( $page, "esc_html__( 'Sen grupo', 'anpa-socios' )" ) );
		$this->assertStringNotContainsString( "esc_html__( 'Sen mínimo', 'anpa-socios' )", $page );
	}
}
