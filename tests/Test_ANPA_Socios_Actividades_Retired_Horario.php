<?php
/**
 * Regression contract: 1.31.0 retires actividades_cursos.horario, so the
 * activity admin write/read path must never reference that removed column.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Actividades_Retired_Horario extends TestCase {
	private string $handler_file;

	protected function setUp(): void {
		$this->handler_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php';
	}

	public function test_yearly_activity_payload_does_not_write_retired_horario_column(): void {
		$source = file_get_contents( $this->handler_file );
		$body   = $this->method_body( $source, 'year_payload' );

		$this->assertStringNotContainsString( "'horario'", $body );
	}

	public function test_activity_response_does_not_read_retired_horario_column(): void {
		$source = file_get_contents( $this->handler_file );
		$body   = $this->method_body( $source, 'get_row' );

		$this->assertStringNotContainsString( 'ac.horario AS horario', $body );
	}

	private function method_body( string $source, string $name ): string {
		$start = strpos( $source, 'function ' . $name . '(' );
		$this->assertNotFalse( $start, 'Method not found: ' . $name );
		$open = strpos( $source, '{', $start );
		$this->assertNotFalse( $open );
		$depth = 0;
		for ( $i = $open, $length = strlen( $source ); $i < $length; $i++ ) {
			if ( '{' === $source[ $i ] ) {
				$depth++;
			} elseif ( '}' === $source[ $i ] && 0 === --$depth ) {
				return substr( $source, $open, $i - $open + 1 );
			}
		}
		$this->fail( 'Method body did not close: ' . $name );
	}
}
