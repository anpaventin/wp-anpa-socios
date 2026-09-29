<?php
/**
 * 1.81.0: a family that comes back recovers its children in baixa by itself
 * (member area «Recuperar», or re-entering them in the alta / «Engadir») instead
 * of creating duplicate rows.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fillo_Recuperar extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	public function test_name_key_ignores_case_and_spacing(): void {
		$k = array( 'ANPA_Socios_Fillo_Recuperar', 'clave' );
		$this->assertSame( $k( 'Uxía', 'Pérez  Souto' ), $k( ' uxía ', 'PÉREZ Souto' ) );
		$this->assertNotSame( $k( 'Uxía', 'Pérez' ), $k( 'Uxío', 'Pérez' ) );
	}

	public function test_match_needs_same_name_and_compatible_birth_date(): void {
		$c      = array( 'ANPA_Socios_Fillo_Recuperar', 'coincidencia' );
		$baixas = array(
			array( 'id' => 7, 'nome' => 'Ana', 'apelidos' => 'Exemplo Proba', 'data_nacemento' => '2018-03-01' ),
			array( 'id' => 9, 'nome' => 'Ana', 'apelidos' => 'Exemplo Proba', 'data_nacemento' => '' ),
			array( 'id' => 4, 'nome' => 'Brais', 'apelidos' => 'Exemplo Proba', 'data_nacemento' => '2016-05-05' ),
		);
		$this->assertSame( 9, $c( $baixas, 'ana', 'exemplo proba', null ), 'newest row wins' );
		$this->assertSame( 9, $c( $baixas, 'Ana', 'Exemplo Proba', '2019-01-01' ), 'a row without birth date still matches' );
		$this->assertSame( 4, $c( $baixas, 'Brais', 'Exemplo Proba', '2016-05-05' ) );
		$this->assertSame( 0, $c( $baixas, 'Brais', 'Exemplo Proba', '2017-05-05' ), 'another birth date = another child' );
		$this->assertSame( 0, $c( $baixas, 'Carme', 'Exemplo Proba', null ) );
		$this->assertSame( 0, $c( array(), 'Ana', 'Exemplo Proba', null ) );
	}

	public function test_level_is_proposed_from_age_like_the_annual_promotion(): void {
		$s      = array( 'ANPA_Socios_Fillo_Recuperar', 'curso_suxerido' );
		$niveis = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$niveis[] = array( 'codigo' => $i . 'º', 'orde' => 5 + $i );
		}
		// Born 2019 → 8 in 2027 → orde 8 = 3º.
		$this->assertSame( array( 'curso' => '3º', 'fora' => false ), $s( '2019-04-10', '2026/2027', $niveis ) );
		// Born 2012 → 15: older than 6º, probably finished at the school.
		$this->assertSame( array( 'curso' => '', 'fora' => true ), $s( '2012-04-10', '2026/2027', $niveis ) );
		$this->assertSame( array( 'curso' => '', 'fora' => false ), $s( '', '2026/2027', $niveis ), 'no birth date: nothing proposed' );
		$this->assertSame( array( 'curso' => '', 'fora' => false ), $s( '2019-04-10', '', $niveis ), 'no active course' );
	}

	public function test_area_routes_are_session_protected_and_family_scoped(): void {
		$r = $this->src( 'includes/class-anpa-socios-fillos-rest.php' );
		foreach ( array( "'/fillos/recuperables'", "'/fillo/(?P<id>\\d+)/recuperar'" ) as $route ) {
			$i = strpos( $r, $route );
			$this->assertNotFalse( $i, $route );
			$this->assertStringContainsString( "'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' )", substr( $r, (int) $i, 400 ) );
		}
		$this->assertStringContainsString( "WHERE familia_id = %d AND estado = 'baixa' ORDER BY id DESC", $this->body( $r, 'fillos_en_baixa' ) );
		$re = $this->body( $r, 'reactivar' );
		$this->assertStringContainsString( "array( 'id' => \$id, 'familia_id' => \$familia_id, 'estado' => 'baixa' )", $re, 'only a child in baixa of this family' );
		$this->assertStringContainsString( 'sync_current_course_assignment( $id,', $re, 'level resolved for the active course' );
		$this->assertStringContainsString( "'fillo_recuperado'", $re );
		$rec = $this->body( $r, 'recuperar_fillo' );
		$this->assertStringContainsString( 'validar_fillo_con_erros', $rec );
		$this->assertStringContainsString( 'check_duplicate_fillo', $rec );
		$this->assertStringContainsString( "'anpa_fillos_xa_activo'", $rec );
		// «Engadir fillo/a» with a name the family had in baixa recovers that row.
		$this->assertStringContainsString( 'return self::reactivar( $baixa_id, $familia_id, $payload, $email );', $this->body( $r, 'create_fillo' ) );
	}

	public function test_alta_recovers_children_of_the_same_family_only(): void {
		$a = $this->src( 'includes/class-anpa-socios-rest.php' );
		$this->assertStringContainsString( "WHERE ( familia_id = %d OR ( socio_email = %s AND ( familia_id IS NULL OR familia_id = 0 ) ) ) AND estado = 'baixa' ORDER BY id DESC", $a, 'never another family' );
		$this->assertStringContainsString( 'if ( 1 === $recuperados ) {', $a );
		$this->assertStringContainsString( 'if ( 0 === $fillo_id ) {', $a, 'nothing recovered: insert a new child' );
		$this->assertStringContainsString( "WHERE ( familia_id = %d OR socio_email = %s ) AND nome = %s AND apelidos = %s AND data_nacemento = %s AND estado = 'activo'", $a );
		$this->assertStringContainsString( 'ANPA_Socios_Fillo_Recuperar::coincidencia( $fillos_baixa,', $a );
		$this->assertStringContainsString( "array( 'id' => \$recuperar_id, 'estado' => 'baixa' )", $a );
		$this->assertStringContainsString( 'upsert_fillo_curso_assignment(' . "\n\t\t\t\t\$fillo_id,", $a );
		// Only parent 1 is verified: a 2nd parent in baixa is never brought back by someone else's alta
		// (they come back by verifying their own email).
		$this->assertStringNotContainsString( "AND familia_id = %d AND estado = 'baixa' AND rol <> 'master'", $a );
		preg_match_all( '/"(?:SELECT|UPDATE)[^"]*"/', $a, $m );
		foreach ( $m[0] as $sql ) {
			$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $sql ), 'SQL must stay ASCII' );
		}
	}

	public function test_area_js_and_reactivation_request(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "root.dataset.fillosUrl + '/recuperables'", $js );
		$this->assertStringContainsString( "encodeURIComponent(filloRecuperarId) + '/recuperar'", $js );
		$this->assertStringContainsString( 'Fillos/as de cursos anteriores', $js );
		$u = $this->src( 'assets/js/unified.js' );
		$this->assertStringContainsString( "apiPost(cfg.reactivarUrl, { email: email, _ts: tsR ? tsR.value : '', website: hpR ? hpR.value : '' })", $u, 'the antibot fields reach the server' );
		$this->assertSame( 'Fillo/a recuperado pola familia', ANPA_Socios_Auditoria::etiqueta( 'fillo_recuperado', 'fillo' ) );
	}
}
