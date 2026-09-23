<?php
/**
 * 1.50.0 (E5/E6): third group state «deshabilitado» + «Eliminar» button contract.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupo_Estado_Deshabilitado extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function payload( string $estado ): array {
		return array(
			'nome'           => 'Grupo A',
			'cursos'         => array( '2026/2027' ),
			'niveis_por_ano' => array( '2026/2027' => array( 1, 2 ) ),
			'horario'        => 'tarde',
			'franxa'         => '16:00-17:00',
			'dias'           => array( 'luns', 'mercores' ),
			'min_pupilos'    => 8,
			'max_pupilos'    => 15,
			'estado'         => $estado,
		);
	}

	public function test_series_normalizer_accepts_the_three_states_and_rejects_others(): void {
		foreach ( array( 'aberto', 'pechado', 'deshabilitado' ) as $estado ) {
			$out = ANPA_Socios_Grupo_Serie::normalize( $this->payload( $estado ) );
			$this->assertSame( $estado, $out['estado'] ?? null, "estado {$estado} debe aceptarse" );
		}
		$this->assertSame( array(), ANPA_Socios_Grupo_Serie::normalize( $this->payload( 'baixa' ) ) );
		$this->assertSame( array( 'aberto', 'pechado', 'deshabilitado' ), ANPA_Socios_Grupo_Serie::estados() );
		$this->assertSame( array( 'aberto', 'pechado', 'deshabilitado' ), ANPA_Socios_Admin_Payload::GRUPO_ESTADO );
	}

	public function test_only_deshabilitado_requires_an_empty_group(): void {
		$this->assertTrue( ANPA_Socios_Grupo_Serie::estado_requires_no_enrolments( 'deshabilitado' ) );
		$this->assertFalse( ANPA_Socios_Grupo_Serie::estado_requires_no_enrolments( 'pechado' ) );
		$this->assertFalse( ANPA_Socios_Grupo_Serie::estado_requires_no_enrolments( 'aberto' ) );
	}

	public function test_admin_handler_guards_deshabilitado_in_both_write_paths_and_returns_counts(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' );

		// Toggle endpoint and series PATCH both refuse when current enrolments exist, inside the transaction.
		$this->assertSame( 2, substr_count( $h, 'ANPA_Socios_Grupo_Serie::estado_requires_no_enrolments(' ) );
		$this->assertSame( 2, substr_count( $h, 'self::count_matriculas_vixentes_locked(' ) );
		$this->assertStringContainsString( "'anpa_admin_grupo_en_uso'", $h );
		$this->assertStringContainsString( "WHERE grupo_id = %d AND estado <> 'baixa' ORDER BY id FOR UPDATE", $h );

		// The listing carries the counts the UI needs for «Eliminar» and the select.
		$this->assertStringContainsString( "'matriculas_vixentes' =>", $h );
		$this->assertStringContainsString( "'matriculas_total'    =>", $h );
		$this->assertStringContainsString( "SUM(estado <> 'baixa') AS vixentes, COUNT(*) AS total", $h );

		// Moving a pupil still targets only open groups.
		$this->assertStringContainsString( "'aberto' !== (string) \$grupo['estado']", $h );
	}

	public function test_area_enrolment_rejects_disabled_groups_before_the_capacity_gate(): void {
		$r      = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$guard  = strpos( $r, "ANPA_Socios_Grupo_Serie::ESTADO_DESHABILITADO === (string) \$locked['estado']" );
		$gate   = strpos( $r, "\$full = ( 'pechado' === (string) \$locked['estado'] )" );
		$this->assertNotFalse( $guard );
		$this->assertNotFalse( $gate );
		$this->assertLessThan( $gate, $guard );
		$this->assertStringContainsString( "'anpa_extra_grupo_deshabilitado'", $r );
	}

	public function test_public_offer_and_timetable_only_use_open_groups(): void {
		// «pechado» and «deshabilitado» are both hidden because every consumer filters on aberto.
		$this->assertStringContainsString( "g.estado = 'aberto'", $this->src( 'includes/class-anpa-socios-extraescolares-page.php' ) );
		$this->assertStringContainsString( "AND estado = 'aberto' ORDER BY", $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' ) );
		$this->assertStringContainsString( "'aberto' !== ( \$g['estado'] ?? '' )", $this->src( 'includes/lib/class-anpa-socios-horario-builder.php' ) );
	}

	public function test_admin_ui_colours_rows_labels_states_and_offers_delete_only_for_empty_current_groups(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "['deshabilitado', 'Deshabilitado'", $js );
		$this->assertStringContainsString( "'anpa-row-grupo-' +", $js );
		$this->assertStringContainsString( "Number(grupo.matriculas_total || 0) === 0", $js );
		$this->assertStringContainsString( "anpaAdminFetch('grupo/' + grupo.id, { method: 'DELETE' })", $js );
		$this->assertStringContainsString( "o.disabled = true;", $js );
		$this->assertStringNotContainsString( "['aberto','pechado'].forEach", $js );

		$css = $this->src( 'assets/css/admin-management.css' );
		foreach ( array( 'aberto', 'pechado', 'deshabilitado' ) as $estado ) {
			$this->assertStringContainsString( ".anpa-row-grupo-{$estado} td", $css );
			$this->assertStringContainsString( ".anpa-grupo-estado-{$estado}", $css );
		}
	}

	public function test_schema_migration_1_40_0_widens_the_estado_enum_guarded(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "const DB_VERSION = '1.43.0'", $db );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.40.0', '<' ) && ! self::migrate_to_1_40_0()", $db );
		$this->assertStringContainsString( 'Migration halted at step 1.40.0', $db );
		$this->assertStringContainsString( 'private static function migrate_to_1_40_0(): bool', $db );
		$this->assertStringContainsString( "MODIFY COLUMN estado enum('aberto','pechado','deshabilitado') NOT NULL DEFAULT 'aberto'", $db );
		// Fresh installs get the widened enum straight from the CREATE statement too.
		$this->assertStringContainsString( "estado enum('aberto','pechado','deshabilitado') not null default 'aberto'", $db );
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.43.0' )", $this->src( 'anpa-socios.php' ) );
	}
}
