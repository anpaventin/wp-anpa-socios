<?php
/**
 * Contract tests for export/import CSV round-trip headers (fase22).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-anpa-socios-admin-export-handler.php';

final class Test_ANPA_Socios_Csv_Roundtrip_Contracts extends TestCase {

	/**
	 * @dataProvider entities
	 */
	public function test_export_columns_equal_import_headers( string $entity ): void {
		$reflection = new ReflectionClass( ANPA_Socios_Admin_Export_Handler::class );
		$export     = $reflection->getConstant( 'ENTITY_COLUMNS' );

		$this->assertSame(
			ANPA_Socios_Csv_Import::ENTITY_HEADERS[ $entity ],
			$export[ $entity ],
			"CSV contract drift for {$entity}"
		);
	}

	public function test_actividades_export_contract_excludes_legacy_capacity_columns(): void {
		$export = self::export_columns();

		foreach ( array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ) as $field ) {
			$this->assertNotContains( $field, $export['actividades'], "legacy actividades field {$field} must not be exported" );
		}
	}

	/**
	 * @dataProvider roundtrip_rows
	 */
	public function test_fictitious_export_row_parses_and_analyzes( string $entity, array $row ): void {
		$export_cols = self::export_columns();
		// Use export columns when available, otherwise fall back to import headers.
		$headers = isset( $export_cols[ $entity ] )
			? $export_cols[ $entity ]
			: ANPA_Socios_Csv_Import::ENTITY_HEADERS[ $entity ];
		$values  = array_map( static function ( string $header ) use ( $row ): string {
			return str_replace( '"', '""', (string) ( $row[ $header ] ?? '' ) );
		}, $headers );
		$csv = implode( ',', $headers ) . "\n\"" . implode( '\",\"', $values ) . "\"\n";

		$parsed = ANPA_Socios_Csv_Import::parse( $csv );
		$result = ANPA_Socios_Csv_Import::analyze( $entity, $parsed );

		$this->assertCount( 1, $parsed, $entity );
		$this->assertNotEmpty( $result['to_insert'], $entity );
	}

	public static function roundtrip_rows(): array {
		return array(
			'socios' => array( 'socios', array( 'id_familia' => 'F001', 'rol_familia' => 'principal', 'email' => 'familia@example.com', 'nome' => 'Noa', 'apelidos' => 'Exemplo', 'nif' => '00000000T', 'telefono' => '600000000', 'estado' => 'activo' ) ),
			'fillos' => array( 'fillos', array( 'proxenitor_email' => 'familia@example.com', 'nome' => 'Lúa', 'apelidos' => 'Exemplo', 'data_nacemento' => '2018-03-15', 'curso' => '1', 'aula' => 'A', 'curso_escolar' => '2026/2027', 'image_consent' => '1', 'estado' => 'activo' ) ),
			'empresas' => array( 'empresas', array( 'nome' => 'Empresa Exemplo', 'email' => 'empresa@example.com', 'responsable' => 'Persoa Exemplo', 'telefono' => '600000001', 'url_web' => 'https://example.com', 'estado' => 'activo' ) ),
			'actividades' => array( 'actividades', array( 'empresa_email' => 'empresa@example.com', 'nome' => 'Actividade Exemplo', 'icono' => '🎒', 'descripcion' => 'Descrición', 'custo' => '20', 'estado' => 'activo' ) ),
			'matriculas' => array( 'matriculas', array( 'proxenitor_email' => 'familia@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'Exemplo', 'empresa_email' => 'empresa@example.com', 'actividade_nome' => 'Actividade Exemplo', 'curso_escolar' => '2026/2027', 'grupo_curso_range' => '1-2-3', 'grupo_franxa' => '16:00-17:00', 'grupo_dias' => 'luns', 'trimestre' => '1', 'posicion' => '', 'comedor' => '1', 'tarde' => '0', 'observaciones' => '', 'estado' => 'activo' ) ),
			'grupos' => array( 'grupos', array( 'actividade_nome' => 'Actividade Exemplo', 'empresa_email' => 'empresa@example.com', 'curso_escolar' => '2026/2027', 'grupo_nome' => 'Grupo A', 'serie_uid' => '11111111-1111-4111-8111-111111111111', 'niveis_codigos' => '1,2,3', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns', 'min_pupilos' => '8', 'max_pupilos' => '15', 'estado' => 'aberto' ) ),
		);
	}

	private static function export_columns(): array {
		$reflection = new ReflectionClass( ANPA_Socios_Admin_Export_Handler::class );

		return $reflection->getConstant( 'ENTITY_COLUMNS' );
	}

	/**
	 * Activities are reusable entities: their natural key never contains a
	 * school year and must not read the retired annual-offer table.
	 */
	public function test_actividades_existing_keys_use_only_the_base_entity(): void {
		require_once __DIR__ . '/../includes/class-anpa-socios-admin-import-handler.php';
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-import-handler.php' );
		$method = self::method_source( $source, 'build_existing_keys' );
		$case   = self::case_block( $method, 'actividades' );

		$this->assertStringNotContainsString( 'actividades_cursos', $case );
		$this->assertStringNotContainsString( 'curso_escolar', $case );
		$this->assertStringContainsString( 'SELECT a.nome, e.email AS empresa_email', $case );
	}

	/**
	 * Modern matrículas carry grupo_id and may have activitad_id = 0, so the
	 * export MUST resolve the activity through the group as fallback or the
	 * CSV ships empty empresa_email/actividade_nome, which the importer then
	 * rejects as invalid (broken round-trip, fase22 S8 E2E).
	 */
	public function test_matriculas_export_resolves_activity_via_group_fallback(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-export-handler.php' );
		$start  = strpos( $source, "'matriculas' === \$entity" );
		self::assertNotFalse( $start, 'matriculas export branch not found' );
		$sql = substr( $source, $start, strpos( $source, 'ORDER BY', $start ) - $start );

		$this->assertStringContainsString( 'COALESCE(NULLIF(m.activitad_id, 0), g.actividad_id)', $sql, 'matriculas export must resolve the activity from the group when activitad_id is 0' );
	}

	/**
	 * The matriculas dedup keys suffer the same legacy assumption: an INNER
	 * JOIN on m.activitad_id silently drops group-based matrículas from the
	 * existing-keys set, so a reimport would duplicate them.
	 */
	public function test_matriculas_existing_keys_resolve_activity_via_group_fallback(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-import-handler.php' );
		$method = self::method_source( $source, 'build_existing_keys' );
		$case   = self::case_block( $method, 'matriculas' );

		$this->assertStringContainsString( 'COALESCE(NULLIF(m.activitad_id, 0), g.actividad_id)', $case, 'matriculas dedup keys must resolve the activity from the group when activitad_id is 0' );
		$this->assertStringNotContainsString( 'a.id = m.activitad_id', $case, 'matriculas dedup keys must not require the legacy direct activity id' );
	}

	private static function method_source( string $source, string $method ): string {
		$start = strpos( $source, "function {$method}(" );
		self::assertNotFalse( $start, "{$method} not found" );
		$end = strpos( $source, "\n	}", $start );

		return substr( $source, $start, $end - $start );
	}

	private static function case_block( string $method, string $entity ): string {
		$start = strpos( $method, "case '{$entity}':" );
		self::assertNotFalse( $start, "case {$entity} not found" );
		$end = strpos( $method, 'break;', $start );

		return substr( $method, $start, $end - $start );
	}

	public function entities(): array {
		return array(
			'socios'      => array( 'socios' ),
			'fillos'      => array( 'fillos' ),
			'empresas'    => array( 'empresas' ),
			'actividades' => array( 'actividades' ),
			'matriculas'  => array( 'matriculas' ),
			'grupos'      => array( 'grupos' ),
		);
	}
}
