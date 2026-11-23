<?php
/**
 * 1.95.0: «A ANPA en cifras» — [anpa_cifras] public block and the socios panel.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Cifras extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_claves_keeps_known_keys_in_order_and_falls_back(): void {
		$this->assertSame( array( 'prazas', 'familias' ), ANPA_Socios_Cifras::claves( ' Prazas, familias,familias, xxx' ) );
		$this->assertSame( ANPA_Socios_Cifras::PUBLICAS_DEFECTO, ANPA_Socios_Cifras::claves( '' ) );
		$this->assertSame( ANPA_Socios_Cifras::PUBLICAS_DEFECTO, ANPA_Socios_Cifras::claves( 'nada,raro' ) );
	}

	public function test_small_public_numbers_are_hidden(): void {
		$this->assertSame( '0', ANPA_Socios_Cifras::valor_publico( 0 ) );
		$this->assertSame( 'menos de 5', ANPA_Socios_Cifras::valor_publico( 1 ) );
		$this->assertSame( 'menos de 5', ANPA_Socios_Cifras::valor_publico( 4 ) );
		$this->assertSame( '5', ANPA_Socios_Cifras::valor_publico( 5 ) );
		$this->assertSame( '1.234', ANPA_Socios_Cifras::valor_publico( 1234 ) );
		$this->assertSame( '0', ANPA_Socios_Cifras::valor_publico( -3 ) );
	}

	public function test_items_public_and_socios(): void {
		$datos = array( 'familias' => 312, 'alumnado' => 3, 'actividades' => 0, 'prazas' => 1 );
		$pub   = ANPA_Socios_Cifras::items( $datos, array( 'familias', 'alumnado', 'actividades', 'prazas', 'empresas' ), true );
		// «0 actividades» is not advertised; missing keys are skipped.
		$this->assertSame( array( 'familias', 'alumnado', 'prazas' ), array_column( $pub, 'clave' ) );
		$this->assertSame( '312', $pub[0]['valor'] );
		$this->assertSame( 'familias socias', $pub[0]['etiqueta'] );
		$this->assertSame( 'menos de 5', $pub[1]['valor'] );
		$this->assertSame( 'grupo con prazas libres', $pub[2]['etiqueta'] );

		// The socios panel shows exact numbers, zeros included.
		$soc = ANPA_Socios_Cifras::items( $datos, array( 'alumnado', 'actividades' ), false );
		$this->assertSame( array( '3', '0' ), array_column( $soc, 'valor' ) );
	}

	public function test_every_key_has_a_label(): void {
		foreach ( ANPA_Socios_Cifras::CLAVES as $k ) {
			$this->assertNotSame( '', ANPA_Socios_Cifras::etiqueta( $k, 2 ), $k );
		}
		$this->assertSame( '', ANPA_Socios_Cifras::etiqueta( 'raro', 2 ) );
	}

	public function test_prazas_summary(): void {
		$s = ANPA_Socios_Cifras::prazas( array(
			array( 'max_pupilos' => 12, 'activos' => 9 ),
			array( 'max_pupilos' => 10, 'activos' => 10 ),
			array( 'max_pupilos' => 8, 'activos' => 11 ),
			array( 'max_pupilos' => 0, 'activos' => 30 ),
			'ruido',
		) );
		$this->assertSame( array( 'grupos_con_prazas' => 2, 'prazas_libres' => 3, 'sen_limite' => 1, 'completos' => 2 ), $s );
		$this->assertSame( 0, ANPA_Socios_Cifras::prazas( array() )['grupos_con_prazas'] );
	}

	public function test_altas_count_from_july_of_the_course(): void {
		$this->assertSame( '2026-07-01', ANPA_Socios_Cifras::inicio_altas( '2026/2027' ) );
		$this->assertSame( '', ANPA_Socios_Cifras::inicio_altas( '' ) );
		$this->assertSame( '', ANPA_Socios_Cifras::inicio_altas( '2026-2027' ) );
	}

	public function test_families_are_counted_by_the_first_parent_only(): void {
		$src = $this->src( 'includes/class-anpa-socios-cifras-page.php' );
		$this->assertStringContainsString( "SELECT COUNT(*) FROM {\$soc_t} WHERE estado = 'activo' AND rol <> 'master' AND rol_familia = 'principal'", $src );
		$this->assertStringNotContainsString( 'COUNT(DISTINCT email)', $src );
		$this->assertStringNotContainsString( 'COUNT(DISTINCT s.email)', $src );
	}

	public function test_figures_follow_the_public_offer_and_are_cached(): void {
		$src = $this->src( 'includes/class-anpa-socios-cifras-page.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Extraescolares_Page::grupos_publicos_ids( $curso )', $src );
		$this->assertStringContainsString( 'set_transient( $key, $datos, self::CACHE_TTL )', $src );
		$this->assertStringContainsString( "m.estado IN ('activo','baixa_solicitada')", $src );
		$extra = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( 'public static function grupos_publicos_ids( string $curso ): array', $extra );
	}

	public function test_socios_endpoint_needs_an_area_session(): void {
		$src = $this->src( 'includes/class-anpa-socios-cifras-page.php' );
		$this->assertMatchesRegularExpression( "#'/area/cifras'.*?'permission_callback'\s*=>\s*array\(\s*'ANPA_Socios_Area_REST',\s*'permission_area_session'\s*\)#s", $src );
		$this->assertStringContainsString( "add_shortcode( 'anpa_cifras'", $src );
	}

	public function test_plugin_wires_the_block_and_the_panel(): void {
		$main = $this->src( 'anpa-socios.php' );
		$this->assertStringContainsString( 'includes/lib/class-anpa-socios-cifras.php', $main );
		$this->assertStringContainsString( 'includes/class-anpa-socios-cifras-page.php', $main );
		$this->assertStringContainsString( 'ANPA_Socios_Cifras_Page::register();', $main );

		$area = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'data-cifras-url=', $area );
		$this->assertStringContainsString( 'data-panel-cifras-section hidden', $area );

		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'function renderCifras()', $js );
		$this->assertStringContainsString( "'X-Anpa-Area-Token': areaToken", $js );
		// Values go in with textContent, never innerHTML.
		$this->assertDoesNotMatchRegularExpression( '/function renderCifras\(\)[\s\S]*?innerHTML[\s\S]*?function navigateArea/', $js );

		$this->assertFileExists( dirname( __DIR__ ) . '/assets/css/cifras.css' );
	}

	public function test_tiles_override_theme_list_padding_and_keep_icons(): void {
		$css = $this->src( 'assets/css/cifras.css' );
		// 1.96.2: theme rules give every content <li> a left padding and a dot; the tiles neutralise both.
		$this->assertMatchesRegularExpression( '/\.anpa-cifras ul\.anpa-cifras-lista > li\.anpa-cifras-item \{[^}]*padding: 20px 12px 16px !important;/s', $css );
		$this->assertStringContainsString( '.anpa-cifras ul.anpa-cifras-lista > li.anpa-cifras-item::before', $css );
		foreach ( array( 'familias', 'alumnado', 'extraescolares', 'actividades', 'prazas', 'altas', 'baixas', 'completos', 'espera' ) as $k ) {
			$this->assertStringContainsString( '.anpa-cifras-item--' . $k . ' { --anpa-cifras-icon: url("data:image/svg+xml,', $css, $k );
		}
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "'anpa-cifras-lista' + (mini ? ' anpa-cifras-lista--mini' : '')", $js );
		$this->assertStringContainsString( "el('div', 'anpa-cifras-ocupacion')", $js );
	}
}
