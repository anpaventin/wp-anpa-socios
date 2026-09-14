<?php
/**
 * 1.56.4: children added or edited by a family from the area must get a resolved nivel_id/aula_id
 * in fillos_cursos, exactly like the admin handler does since fase23 (D94).
 *
 * Production incident 2026-09-12..14: 13 children created via POST /fillos had nivel_id NULL, so the
 * group list was unfiltered (nivel 0) and every enrolment was refused with
 * «O curso do alumno/a non encaixa neste grupo».
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Area_Fillos_Nivel extends TestCase {

	private function src(): string {
		$path = dirname( __DIR__ ) . '/includes/class-anpa-socios-fillos-rest.php';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function method_body( string $src, string $signature ): string {
		$start = strpos( $src, $signature );
		$this->assertNotFalse( $start, $signature );
		$end = strpos( $src, "\n\t}\n", $start );
		$this->assertNotFalse( $end );
		return substr( $src, $start, $end - $start );
	}

	public function test_area_sync_delegates_to_the_shared_upsert_and_returns_bool(): void {
		$body = $this->method_body( $this->src(), 'private static function sync_current_course_assignment( int $fillo_id, string $curso, string $aula ): bool {' );
		$this->assertStringContainsString( 'return ANPA_Socios_DB::upsert_fillo_curso_assignment( $fillo_id, $curso_escolar, $curso, $aula );', $body );
		// The pre-1.56.4 version: a hand-written INSERT without nivel_id, gated behind re-validation.
		$this->assertStringNotContainsString( 'INSERT INTO', $body );
		$this->assertStringNotContainsString( 'curso_valido_db', $body );
		$this->assertStringNotContainsString( 'aula_valida_db', $body );
	}

	public function test_no_direct_write_to_fillos_cursos_outside_the_shared_upsert(): void {
		$src = $this->src();
		$this->assertStringNotContainsString( 'INSERT INTO', $src );
		$this->assertSame( 1, substr_count( $src, 'upsert_fillo_curso_assignment' ) );
	}

	public function test_create_fillo_is_atomic_and_captures_the_child_id_before_sync_and_audit(): void {
		$body = $this->method_body( $this->src(), 'public static function create_fillo( WP_REST_Request $request ) {' );
		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $body );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
		$this->assertStringContainsString( "query( 'COMMIT' )", $body );
		$capture = strpos( $body, '$fillo_id = (int) $wpdb->insert_id;' );
		$sync    = strpos( $body, '! self::sync_current_course_assignment( $fillo_id,' );
		$audit   = strpos( $body, "write_audit_actor( \$email, 'socio', 'fillo', (string) \$fillo_id, 'fillo_engadido' )" );
		$this->assertNotFalse( $capture );
		$this->assertNotFalse( $sync );
		$this->assertNotFalse( $audit, 'the audit must record the child id, not the last insert_id' );
		$this->assertLessThan( $sync, $capture );
		$this->assertLessThan( $audit, $sync );
		$this->assertStringNotContainsString( '(string) (int) $wpdb->insert_id', $body );
	}

	public function test_update_fillo_is_atomic_and_fails_closed_when_the_level_cannot_be_saved(): void {
		$body = $this->method_body( $this->src(), 'public static function update_fillo( WP_REST_Request $request ) {' );
		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $body );
		$this->assertStringContainsString( '! self::sync_current_course_assignment( $id, (string) $payload[\'curso\'], (string) $payload[\'aula\'] )', $body );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
		$this->assertStringContainsString( "query( 'COMMIT' )", $body );
	}

	public function test_enrolment_refusal_and_group_filter_still_depend_on_a_resolved_nivel(): void {
		// Documents WHY nivel_id matters: both checks live in the extraescolares REST.
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( 'if ( $nivel_fillo_id > 0 && ! in_array( $nivel_fillo_id, $grupo_niveis, true ) ) {', $rest );
		$this->assertStringContainsString( "if ( \$nivel_id <= 0 || ! in_array( \$nivel_id, ANPA_Socios_DB::get_niveis_for_grupo( \$grupo_id ), true ) ) {", $rest );
	}
}
