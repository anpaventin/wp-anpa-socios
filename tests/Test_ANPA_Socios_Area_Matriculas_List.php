<?php
/**
 * 1.56.1: the area must read the family's enrolments from the { matriculas } envelope, and the canteen
 * account (id 0) must be allowed to export.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Area_Matriculas_List extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_area_js_unwraps_the_matriculas_envelope_everywhere(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'function matriculasList(mats)', $js );
		$this->assertStringContainsString( 'return mats && Array.isArray(mats.matriculas) ? mats.matriculas : [];', $js );
		$this->assertSame( 2, substr_count( $js, 'renderMatriculas(' ) - substr_count( $js, 'function renderMatriculas(' ), 'two call sites' );
		$this->assertSame( 2, substr_count( $js, 'matriculasList(mats))' ), 'both call sites use the helper' );
		$this->assertStringNotContainsString( 'Array.isArray(mats) ? mats : []', $js );
		// Server contract this relies on.
		$rest = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "'matriculas'          => \$data,", $rest );
	}

	/**
	 * 1.56.3: the «Curso/Aula» label is built in PHP. Behaviour of the 1.56.2 SQL, minus its bug.
	 *
	 * @dataProvider provide_curso_completo
	 */
	public function test_curso_completo_label( ?string $curso, ?string $aula, string $expected ): void {
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-shared.php';
		$this->assertSame( $expected, ANPA_Socios_Admin_Shared::curso_completo( $curso, $aula ) );
	}

	/** @return array<string,array{0:?string,1:?string,2:string}> */
	public function provide_curso_completo(): array {
		return array(
			'canonical level + letter'      => array( '3º', 'D', '3ºD' ),
			'legacy bare number + letter'   => array( '3', 'D', '3ºD' ),
			'no letter yet (1.54.0)'        => array( '3º', '', '3º' ),
			'NULL letter'                   => array( '3º', null, '3º' ),
			'degree sign variant U+00B0'    => array( '3°', 'A', '3ºA' ),
			'doubled sign from old data'    => array( '3ºº', 'B', '3ºB' ),
			'whitespace is trimmed'         => array( ' 1º ', ' C ', '1ºC' ),
			'no level at all'               => array( null, 'D', '' ),
			'empty level'                   => array( '', '', '' ),
		);
	}

	/**
	 * 1.56.3 regression guard: both admin lists were empty («datos non válidos») because the 1.56.2 SQL was
	 * not ASCII ('º') and contained an inner FROM (TRIM(TRAILING … FROM …)). A non-ASCII query makes wpdb
	 * run strip_invalid_text_from_query(), whose get_table_from_query() took «COALESCE» as the table.
	 * Contract: every SELECT literal in these two handlers stays pure ASCII, and no SQL uses TRIM(TRAILING.
	 */
	public function test_admin_list_sql_is_pure_ascii_and_uses_the_php_label(): void {
		foreach ( array( 'includes/class-anpa-socios-admin-grupos-handler.php', 'includes/class-anpa-socios-admin-matriculas-handler.php' ) as $file ) {
			$src = $this->src( $file );
			$this->assertStringNotContainsString( 'TRIM(TRAILING', $src, $file );
			$this->assertStringNotContainsString( "'º'", $src, $file );
			$this->assertStringNotContainsString( 'AS curso_completo', $src, $file );
			$this->assertStringContainsString( "\$row['curso_completo'] = ANPA_Socios_Admin_Shared::curso_completo( \$row['curso'] ?? null, \$row['aula'] ?? null );", $src, $file );
			// Every double-quoted SQL literal starting with SELECT must be ASCII only.
			$this->assertGreaterThan( 0, preg_match_all( '/"\s*SELECT\b.*?"/s', $src, $m ), $file . ': no SELECT literals found' );
			foreach ( $m[0] as $sql ) {
				$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $sql ), $file . ': non-ASCII SQL literal: ' . substr( $sql, 0, 120 ) );
			}
		}
		// No other PHP builder appends a degree sign to a course value.
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $php ) {
			if ( false !== strpos( $php, 'admin-shared' ) || false !== strpos( $php, 'class-anpa-socios-db.php' ) || false !== strpos( $php, 'class-anpa-socios-backup.php' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( "'º'", (string) file_get_contents( $php ), basename( $php ) );
		}
	}

	/**
	 * Reproduces WordPress' table parser (wp-includes/class-wpdb.php get_table_from_query(), WP 6.x–7.1)
	 * on the two SQL shapes, so the mechanism behind 1.56.2 stays documented and the fixed shape is provably safe.
	 */
	public function test_wpdb_table_parser_reproduction(): void {
		$parse = static function ( string $query ): string {
			$query = rtrim( $query, ';/-#' );
			$query = ltrim( $query, "\r\n\t (" );
			$query = (string) preg_replace( '/\((?!\s*select)[^(]*?\)/is', '()', $query );
			if ( preg_match( '/^\s*(?:SELECT.*?\s+FROM)\s+((?:[0-9a-zA-Z$_.`-]|[\xC2-\xDF][\x80-\xBF])+)/is', $query, $maybe ) ) {
				return str_replace( '`', '', $maybe[1] );
			}
			return '';
		};
		$broken = "SELECT m.id, CONCAT(TRIM(TRAILING 'º' FROM COALESCE(fc.curso, f.curso, '')), 'º', COALESCE(fc.aula, f.aula, '')) AS curso_completo FROM wp_anpa_matriculas m INNER JOIN wp_anpa_fillos f ON f.id = m.fillo_id";
		$this->assertSame( 'COALESCE', $parse( $broken ), '1.56.2 shape: wpdb resolves the wrong table' );
		$fixed = "SELECT m.id, COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula FROM wp_anpa_matriculas m INNER JOIN wp_anpa_fillos f ON f.id = m.fillo_id";
		$this->assertSame( 'wp_anpa_matriculas', $parse( $fixed ) );
	}

	public function test_canteen_account_may_export_despite_id_zero(): void {
		$rest = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "if ( \$empresa_id <= 0 && ! self::is_comedor_profile( \$profile ) ) {", $rest );
	}
}
