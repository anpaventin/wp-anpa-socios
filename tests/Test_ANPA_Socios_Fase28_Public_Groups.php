<?php
/**
 * Fase 28/30 public activity cards: one block per annual group.
 */
use PHPUnit\Framework\TestCase;

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text ): string { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string { return (string) json_encode( $value ); }
}

require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-extraescolares-page.php';

class Test_ANPA_Socios_Fase28_Public_Groups extends TestCase {
	private string $source;

	public function setUp(): void {
		parent::setUp();
		$this->source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-extraescolares-page.php' );
	}

	public function test_public_read_model_loads_level_labels_by_group_id(): void {
		$this->assertStringContainsString( 'tabela_grupos_niveis', $this->source );
		$this->assertStringContainsString( 'tabela_niveis', $this->source );
		$this->assertStringContainsString( 'GROUP_CONCAT(DISTINCT n.etiqueta ORDER BY n.orde', $this->source );
		$this->assertStringContainsString( "'niveis'", $this->source );
	}

	public function test_renderer_keeps_levels_and_capacity_scoped_to_each_group(): void {
		$activity = array(
			'horarios_grupos' => '10|Iniciación|tarde|16:00-17:00|luns;;11|Avanzado|tarde|17:00-18:00|martes',
			'grupos_detail'   => wp_json_encode(
				array(
					array( 'id' => 10, 'niveis' => '1º, 2º', 'activos' => 3, 'max_pupilos' => 10, 'min_pupilos' => 5 ),
					array( 'id' => 11, 'niveis' => '5º, 6º', 'activos' => 8, 'max_pupilos' => 12, 'min_pupilos' => 8 ),
				)
			),
		);
		$method = new ReflectionMethod( 'ANPA_Socios_Extraescolares_Page', 'schedule_detail_html' );
		$method->setAccessible( true );
		$html = (string) $method->invoke( null, $activity );

		$this->assertSame( 2, substr_count( $html, 'anpa-extra-horario-grupo' ) );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'Cursos: 1º, 2º', $text );
		$this->assertStringContainsString( 'Cursos: 5º, 6º', $text );
		$this->assertStringContainsString( '3/10', $text );
		$this->assertStringContainsString( '8/12', $text );
	}
}
