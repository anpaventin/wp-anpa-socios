<?php
/**
 * 1.51.0 (E3): single enrolment rule — pure evaluation + source contracts.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Matricula_Gate extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function curso( string $estado = 'activo' ): array {
		return array(
			'curso_escolar'      => '2026/2027',
			'estado'             => $estado,
			'matriculas_abertas' => '0', // legacy flag must be irrelevant
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

	public function test_open_only_when_course_active_and_current_window_open(): void {
		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'aberta' ), '2026-09-11' );
		$this->assertTrue( $g['abertas'] );
		$this->assertSame( 1, $g['trimestre'] );
		$this->assertSame( 'abertas', $g['motivo'] );

		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'pechada' ), '2026-09-11' );
		$this->assertFalse( $g['abertas'] );
		$this->assertSame( 'ventana_pechada', $g['motivo'] );
	}

	public function test_trimester_is_derived_from_operative_dates(): void {
		// 2027-02-01 is after t1 (2026-12-22) and on/before t2 → T2.
		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'aberta', 'pechada' ), '2027-02-01' );
		$this->assertSame( 2, $g['trimestre'] );
		$this->assertFalse( $g['abertas'], 'T1 window open must not open T2' );

		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'pechada', 'aberta' ), '2027-02-01' );
		$this->assertTrue( $g['abertas'] );

		// After t2 → T3.
		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'pechada', 'pechada', 'aberta' ), '2027-05-10' );
		$this->assertSame( 3, $g['trimestre'] );
		$this->assertTrue( $g['abertas'] );
	}

	public function test_fails_closed_without_active_course_or_trimester_rows(): void {
		$g = ANPA_Socios_Matricula_Gate::avaliar( null, $this->trimestres( 'aberta' ), '2026-09-11' );
		$this->assertFalse( $g['abertas'] );
		$this->assertSame( 'sen_curso', $g['motivo'] );

		foreach ( array( 'pendente', 'pechado' ) as $estado ) {
			$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso( $estado ), $this->trimestres( 'aberta' ), '2026-09-11' );
			$this->assertFalse( $g['abertas'], $estado );
			$this->assertSame( 'curso_non_activo', $g['motivo'] );
		}

		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'aberta', 'aberta', 'aberta', false ), '2026-09-11' );
		$this->assertFalse( $g['abertas'] );
		$this->assertSame( 'trimestre_sen_configurar', $g['motivo'] );

		$g = ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), array(), '2026-09-11' );
		$this->assertFalse( $g['abertas'] );
	}

	public function test_legacy_flag_never_decides(): void {
		$row = $this->curso();
		$row['matriculas_abertas'] = '1';
		$g = ANPA_Socios_Matricula_Gate::avaliar( $row, $this->trimestres( 'pechada' ), '2026-09-11' );
		$this->assertFalse( $g['abertas'] );
	}

	public function test_zero_dates_are_treated_as_missing_and_fall_back_to_month_model(): void {
		$row = $this->curso();
		$row['t1_peche_operativo'] = '0000-00-00';
		$row['t2_peche_operativo'] = '';
		$datas = ANPA_Socios_Matricula_Gate::datas_de_fila( $row );
		$this->assertSame( '', $datas['t1'] );
		$this->assertSame( '', $datas['t2'] );
		// Month model: February → T2.
		$g = ANPA_Socios_Matricula_Gate::avaliar( $row, $this->trimestres( 'pechada', 'aberta' ), '2027-02-15' );
		$this->assertSame( 2, $g['trimestre'] );
		$this->assertTrue( $g['abertas'] );
	}

	public function test_labels_name_the_state_and_trimester(): void {
		$open   = ANPA_Socios_Matricula_Gate::etiqueta( ANPA_Socios_Matricula_Gate::avaliar( $this->curso(), $this->trimestres( 'aberta' ), '2026-09-11' ) );
		$closed = ANPA_Socios_Matricula_Gate::etiqueta( ANPA_Socios_Matricula_Gate::avaliar( $this->curso( 'pechado' ), array(), '2026-09-11' ) );
		$this->assertStringContainsString( 'ABERTAS', $open );
		$this->assertStringContainsString( '1º trimestre', $open );
		$this->assertStringContainsString( 'PECHADAS', $closed );
	}

	public function test_area_rest_gates_use_the_rule_and_lock_the_window_row(): void {
		$r = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Gate_Repo::avaliar_fila( $row )', $r );
		$this->assertStringContainsString( "WHERE curso_escolar = %s AND trimestre = %d FOR UPDATE", $r );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Gate::avaliar( $row, array( $trimestre =>', $r );
		$this->assertStringNotContainsString( "1 === (int) \$row['matriculas_abertas']", $r );
		$this->assertStringNotContainsString( "1 !== (int) ( \$row['matriculas_abertas'] ?? 0 )", $r );
	}

	public function test_admin_never_takes_the_flag_from_forms_or_bodies(): void {
		$settings = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringNotContainsString( 'name="matriculas_abertas"', $settings );
		$this->assertStringNotContainsString( "'matriculas_abertas' => \$open", $settings );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso )', $settings );
		$this->assertStringContainsString( "__( 'Abrir matrículas (ventá)', 'anpa-socios' )", $settings );

		$cursos = $this->src( 'includes/class-anpa-socios-admin-cursos-handler.php' );
		$this->assertStringNotContainsString( "\$open    = ! empty( \$body['matriculas_abertas'] );", $cursos );
		$this->assertStringContainsString( "\$plan['target_open'] = (bool) ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( \$curso );", $cursos );
		$this->assertStringContainsString( "'matriculas_etiqueta'", $cursos );
	}

	public function test_migration_1_41_0_keeps_open_courses_open_and_is_wired(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "const DB_VERSION = '1.42.0'", $db );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.41.0', '<' ) && ! self::migrate_to_1_41_0()", $db );
		$this->assertStringContainsString( 'Migration halted at step 1.41.0', $db );
		$this->assertStringContainsString( 'private static function migrate_to_1_41_0(): bool', $db );
		$this->assertStringContainsString( "ANPA_Socios_Trimestre_Repo::ORIXE_MIGRACION, 'sistema' );", $db );
		$this->assertStringContainsString( "ANPA_Socios_Ventana_Estado::ABERTA, 'sistema', ANPA_Socios_Trimestre_Repo::ORIXE_MIGRACION, 'e3-1.41.0'", $db );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );', $db );
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.42.0' )", $this->src( 'anpa-socios.php' ) );
	}
}
