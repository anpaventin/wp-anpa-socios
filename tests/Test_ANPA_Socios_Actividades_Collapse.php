<?php
/**
 * Tests for PR-ES9 task 82: collapsing (actividad, curso_escolar) rows into
 * one row per activity with an aggregated `cursos_ofertados` list.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Actividades_Collapse extends TestCase {

	/**
	 * @testdox An activity with 3 annual rows collapses into exactly ONE row (not 3)
	 */
	public function test_collapses_to_one_row_per_activity(): void {
		$base = array(
			$this->base_row( 1, 'Xadrez' ),
		);
		$acy = array(
			$this->acy_row( 1, '2024/2025' ),
			$this->acy_row( 1, '2025/2026' ),
			$this->acy_row( 1, '2026/2027' ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2025/2026', array(), array() );

		$this->assertCount( 1, $rows, 'One activity with 3 annual rows must yield exactly 1 row' );
	}

	/**
	 * @testdox cursos_ofertados is chronologically sorted ASC and deduplicated
	 */
	public function test_cursos_ofertados_sorted_and_deduplicated(): void {
		$base = array( $this->base_row( 1, 'Xadrez' ) );
		// Pass rows out of order and with a duplicate to prove the helper
		// does not just trust caller ordering blindly for dedup purposes,
		// while still documenting that the SQL layer is expected to ORDER
		// BY curso_escolar ASC before calling collapse().
		$acy = array(
			$this->acy_row( 1, '2024/2025' ),
			$this->acy_row( 1, '2025/2026' ),
			$this->acy_row( 1, '2025/2026' ), // duplicate row (defensive)
			$this->acy_row( 1, '2026/2027' ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, null, array(), array() );

		$this->assertSame(
			array( '2024/2025', '2025/2026', '2026/2027' ),
			$rows[0]['cursos_ofertados']
		);
	}

	/**
	 * @testdox Prefers the row for the currently active curso escolar as the source row
	 */
	public function test_prefers_active_curso_as_source(): void {
		$base = array( $this->base_row( 1, 'Xadrez' ) );
		$acy  = array(
			$this->acy_row( 1, '2024/2025', array( 'franxa' => 'tarde-antiga' ) ),
			$this->acy_row( 1, '2025/2026', array( 'franxa' => 'tarde-activa' ) ),
			$this->acy_row( 1, '2026/2027', array( 'franxa' => 'tarde-futura' ) ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2025/2026', array(), array() );

		$this->assertSame( '2025/2026', $rows[0]['curso_escolar'] );
		$this->assertSame( 'tarde-activa', $rows[0]['franxa'] );
	}

	/**
	 * @testdox Falls back to the most recent offered year when the activity does not offer the active curso
	 */
	public function test_falls_back_to_most_recent_year_when_active_not_offered(): void {
		$base = array( $this->base_row( 1, 'Xadrez' ) );
		$acy  = array(
			$this->acy_row( 1, '2023/2024', array( 'franxa' => 'antiga' ) ),
			$this->acy_row( 1, '2024/2025', array( 'franxa' => 'recente' ) ),
		);

		// Active curso is 2026/2027, which this activity does NOT offer.
		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2026/2027', array(), array() );

		$this->assertSame( '2024/2025', $rows[0]['curso_escolar'], 'Must pick the most recent offered year' );
		$this->assertSame( 'recente', $rows[0]['franxa'] );
		// cursos_ofertados must still list both years regardless of source pick.
		$this->assertSame( array( '2023/2024', '2024/2025' ), $rows[0]['cursos_ofertados'] );
	}

	/**
	 * @testdox Falls back to the legacy base actividades row when there is no annual row at all
	 */
	public function test_falls_back_to_legacy_base_row_when_no_annual_rows(): void {
		$base = array( $this->base_row( 1, 'Legado', array( 'curso_escolar' => '2022/2023', 'franxa' => 'legacy-franxa' ) ) );

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, array(), '2025/2026', array(), array() );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2022/2023', $rows[0]['curso_escolar'] );
		$this->assertSame( 'legacy-franxa', $rows[0]['franxa'] );
		$this->assertSame( array(), $rows[0]['cursos_ofertados'], 'No annual rows means cursos_ofertados = [] (not null)' );
	}

	/**
	 * @testdox An activity without any actividades_cursos row still appears, with cursos_ofertados = []
	 */
	public function test_activity_without_years_still_appears_with_empty_array(): void {
		$base = array( $this->base_row( 1, 'Sen anos' ) );

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, array(), null, array(), array() );

		$this->assertCount( 1, $rows );
		$this->assertIsArray( $rows[0]['cursos_ofertados'] );
		$this->assertSame( array(), $rows[0]['cursos_ofertados'] );
	}

	/**
	 * @testdox prazas_ocupadas/prazas_espera are scoped to the chosen source curso escolar
	 */
	public function test_prazas_scoped_to_source_curso(): void {
		$base = array( $this->base_row( 1, 'Robótica' ) );
		$acy  = array(
			$this->acy_row( 1, '2024/2025' ),
			$this->acy_row( 1, '2025/2026' ),
		);
		// Counts for BOTH years exist; only the active year's counts must be used.
		$scoped_counts = array(
			array( 'actividad_id' => 1, 'curso_escolar' => '2024/2025', 'estado' => 'activo', 'total' => 9 ),
			array( 'actividad_id' => 1, 'curso_escolar' => '2025/2026', 'estado' => 'activo', 'total' => 3 ),
			array( 'actividad_id' => 1, 'curso_escolar' => '2025/2026', 'estado' => 'lista_espera', 'total' => 2 ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2025/2026', $scoped_counts, array() );

		$this->assertSame( 3, $rows[0]['prazas_ocupadas'] );
		$this->assertSame( 2, $rows[0]['prazas_espera'] );
	}

	/**
	 * @testdox prazas fall back to unscoped legacy totals when there is no annual row
	 */
	public function test_prazas_use_legacy_totals_when_no_annual_row(): void {
		$base          = array( $this->base_row( 1, 'Legado' ) );
		$legacy_totals = array(
			array( 'actividad_id' => 1, 'estado' => 'activo', 'total' => 7 ),
			array( 'actividad_id' => 1, 'estado' => 'lista_espera', 'total' => 1 ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, array(), null, array(), $legacy_totals );

		$this->assertSame( 7, $rows[0]['prazas_ocupadas'] );
		$this->assertSame( 1, $rows[0]['prazas_espera'] );
	}

	/**
	 * @testdox nivel_min_id/nivel_max_id are exposed from the source annual row
	 */
	public function test_exposes_nivel_min_max_from_source_row(): void {
		$base = array( $this->base_row( 1, 'Xadrez' ) );
		$acy  = array(
			$this->acy_row( 1, '2025/2026', array( 'nivel_min_id' => 5, 'nivel_max_id' => 9 ) ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2025/2026', array(), array() );

		$this->assertSame( 5, $rows[0]['nivel_min_id'] );
		$this->assertSame( 9, $rows[0]['nivel_max_id'] );
	}

	/**
	 * @testdox nivel_min_id/nivel_max_id are null when not configured for the source year
	 */
	public function test_nivel_min_max_null_when_not_configured(): void {
		$base = array( $this->base_row( 1, 'Xadrez' ) );
		$acy  = array(
			$this->acy_row( 1, '2025/2026' ), // no nivel_min_id/nivel_max_id override.
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2025/2026', array(), array() );

		$this->assertNull( $rows[0]['nivel_min_id'] );
		$this->assertNull( $rows[0]['nivel_max_id'] );
	}

	/**
	 * @testdox nivel_min_id/nivel_max_id are null (never inherited from curso_min/curso_max) when there is no annual row
	 */
	public function test_nivel_min_max_null_for_legacy_activity_without_annual_row(): void {
		$base = array( $this->base_row( 1, 'Legado', array( 'curso_min' => 1, 'curso_max' => 6 ) ) );

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, array(), null, array(), array() );

		$this->assertNull( $rows[0]['nivel_min_id'] );
		$this->assertNull( $rows[0]['nivel_max_id'] );
	}

	/**
	 * @testdox Different years of the same activity can carry different nivel_min_id/nivel_max_id (independent per year)
	 */
	public function test_nivel_min_max_independent_per_year(): void {
		$base = array( $this->base_row( 1, 'Xadrez' ) );
		$acy  = array(
			$this->acy_row( 1, '2024/2025', array( 'nivel_min_id' => 1, 'nivel_max_id' => 3 ) ),
			$this->acy_row( 1, '2025/2026', array( 'nivel_min_id' => 4, 'nivel_max_id' => 6 ) ),
		);

		$rows_2024 = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2024/2025', array(), array() );
		$rows_2025 = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2025/2026', array(), array() );

		$this->assertSame( 1, $rows_2024[0]['nivel_min_id'] );
		$this->assertSame( 3, $rows_2024[0]['nivel_max_id'] );
		$this->assertSame( 4, $rows_2025[0]['nivel_min_id'] );
		$this->assertSame( 6, $rows_2025[0]['nivel_max_id'] );
	}

	/**
	 * @testdox Multiple activities are each collapsed independently
	 */
	public function test_multiple_activities_collapse_independently(): void {
		$base = array(
			$this->base_row( 1, 'Xadrez' ),
			$this->base_row( 2, 'Robótica' ),
		);
		$acy = array(
			$this->acy_row( 1, '2025/2026' ),
			$this->acy_row( 1, '2026/2027' ),
			$this->acy_row( 2, '2026/2027' ),
		);

		$rows = ANPA_Socios_Actividades_Collapse::collapse( $base, $acy, '2026/2027', array(), array() );

		$this->assertCount( 2, $rows );
		$by_id = array();
		foreach ( $rows as $r ) { $by_id[ $r['id'] ] = $r; }
		$this->assertSame( array( '2025/2026', '2026/2027' ), $by_id[1]['cursos_ofertados'] );
		$this->assertSame( array( '2026/2027' ), $by_id[2]['cursos_ofertados'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// Fixtures
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function base_row( int $id, string $nome, array $overrides = array() ): array {
		return array_merge( array(
			'id'            => $id,
			'empresa_id'    => 1,
			'nome'          => $nome,
			'icono'         => '🎒',
			'descripcion'   => 'Descrición de proba',
			'curso_escolar' => '2024/2025',
			'franxa'        => 'tarde',
			'horarios'      => 'tarde',
			'grupos'        => '1-2-3',
			'dias'          => 'luns,martes',
			'min_pupilos'   => 10,
			'max_pupilos'   => 15,
			'custo'         => 20.0,
			'estado'        => 'activo',
			'curso_min'     => null,
			'curso_max'     => null,
		), $overrides );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function acy_row( int $actividad_id, string $curso_escolar, array $overrides = array() ): array {
		return array_merge( array(
			'actividad_id'  => $actividad_id,
			'curso_escolar' => $curso_escolar,
			'franxa'        => 'tarde',
			'horarios'      => 'tarde',
			'grupos'        => '1-2-3',
			'dias'          => 'luns,martes',
			'min_pupilos'   => 10,
			'max_pupilos'   => 15,
			'custo'         => 20.0,
			'estado'        => 'activo',
		), $overrides );
	}
}
