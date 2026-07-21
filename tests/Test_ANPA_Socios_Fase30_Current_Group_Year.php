<?php
/**
 * Fase30 contracts: annual groups are the only source of activity offers.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fase30_Current_Group_Year extends TestCase {

	private string $source;

	protected function setUp(): void {
		$this->source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php' );
	}

	private function method( string $start, string $end ): string {
		$from = strpos( $this->source, $start );
		$to   = strpos( $this->source, $end, $from + strlen( $start ) );
		$this->assertNotFalse( $from );
		$this->assertNotFalse( $to );
		return substr( $this->source, $from, $to - $from );
	}

	public function test_group_validation_forces_the_server_active_year_without_activity_offer_lookup(): void {
		$method = $this->method( 'private static function validate_series_payload', 'private static function persist_series' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $method );
		$this->assertStringNotContainsString( 'tabela_actividades_cursos', $method );
		$this->assertStringContainsString( "\$body['cursos'] = array( \$curso_activo )", $method );
		$this->assertStringContainsString( 'niveis_belong_to_curso', $method );
	}

	public function test_persist_updates_or_inserts_only_the_active_row_and_never_deletes_history(): void {
		$method = $this->method( 'private static function persist_series', 'public static function confirm_baixa' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $method );
		$this->assertStringContainsString( 'serie_uid = %s ORDER BY id FOR UPDATE', $method );
		$this->assertStringNotContainsString( '$removed', $method );
		$this->assertStringNotContainsString( 'array_diff(', $method );
		$this->assertStringNotContainsString( '$wpdb->delete( $table', $method );
		$this->assertStringContainsString( "\$payload['niveis_por_ano'][ \$curso_activo ]", $method );
	}

	public function test_state_toggle_targets_only_the_current_annual_row(): void {
		$method = $this->method( 'public static function set_estado', '// ──────────────────────────────────────────────' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $method );
		$this->assertStringContainsString( 'curso_escolar = %s', $method );
		$this->assertStringContainsString( "array( 'id' => \$current_id )", $method );
		$this->assertStringNotContainsString( "array( 'actividad_id' => (int) \$current['actividad_id'], 'serie_uid' => \$uid )", $method );
	}

	public function test_delete_rejects_historical_rows_and_deletes_only_the_requested_current_row(): void {
		$method = $this->method( 'public static function delete_grupo', 'public static function set_estado' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $method );
		$this->assertStringContainsString( "'anpa_admin_grupo_historico_readonly'", $method );
		$this->assertStringContainsString( 'WHERE grupo_id = %d ORDER BY id FOR UPDATE', $method );
		$this->assertStringNotContainsString( 'serie_uid = %s ORDER BY id FOR UPDATE', $method );
		$this->assertStringNotContainsString( 'WHERE grupo_id IN', $method );
	}

	public function test_list_exposes_current_levels_and_read_only_previous_courses(): void {
		$method = $this->method( 'public static function list_grupos', 'public static function create_grupo' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $method );
		$this->assertStringContainsString( "'cursos_anteriores'", $method );
		$this->assertStringContainsString( "'nivel_ids'", $method );
		$this->assertStringContainsString( "'ten_grupo_actual'", $method );
	}
}
