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
			'actividades' => array( 'actividades', array( 'empresa_email' => 'empresa@example.com', 'nome' => 'Actividade Exemplo', 'descripcion' => 'Descrición', 'curso_escolar' => '2026/2027', 'min_pupilos' => '5', 'max_pupilos' => '15', 'curso_min' => '1', 'curso_max' => '6', 'nivel_min_codigo' => '1', 'nivel_max_codigo' => '3', 'custo' => '20', 'estado' => 'activo' ) ),
			'matriculas' => array( 'matriculas', array( 'proxenitor_email' => 'familia@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'Exemplo', 'empresa_email' => 'empresa@example.com', 'actividade_nome' => 'Actividade Exemplo', 'curso_escolar' => '2026/2027', 'grupo_curso_range' => '1-2-3', 'grupo_franxa' => '16:00-17:00', 'grupo_dias' => 'luns', 'trimestre' => '1', 'posicion' => '', 'comedor' => '1', 'tarde' => '0', 'observaciones' => '', 'estado' => 'activo' ) ),
			'grupos' => array( 'grupos', array( 'actividade_nome' => 'Actividade Exemplo', 'empresa_email' => 'empresa@example.com', 'curso_escolar' => '2026/2027', 'niveis_codigos' => '1,2,3', 'franxa' => '16:00-17:00', 'dias' => 'luns', 'min_pupilos' => '8', 'max_pupilos' => '15', 'estado' => 'activo', 'grupo_curso_range' => '1-2-3' ) ),
		);
	}

	private static function export_columns(): array {
		$reflection = new ReflectionClass( ANPA_Socios_Admin_Export_Handler::class );

		return $reflection->getConstant( 'ENTITY_COLUMNS' );
	}

	public function entities(): array {
		return array(
			'socios'      => array( 'socios' ),
			'fillos'      => array( 'fillos' ),
			'empresas'    => array( 'empresas' ),
			'actividades' => array( 'actividades' ),
			'matriculas'  => array( 'matriculas' ),
		);
	}
}
