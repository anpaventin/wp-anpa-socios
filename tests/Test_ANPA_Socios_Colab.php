<?php
/**
 * 1.104.0: collaborators network — pure rules and the module's isolation.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-colab.php';

final class Test_ANPA_Socios_Colab extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_offer_is_cleaned_and_checked(): void {
		$r = ANPA_Socios_Colab::limpar_oferta( array(
			'titulo'       => "  <b>Desconto</b> en   libros\n ",
			'desconto'     => '10 %',
			'descricion'   => "Válido en toda a tenda.\r\n\r\n\r\n<script>alert(1)</script>Agás promocións.",
			'condicions'   => '',
			'valida_dende' => '2026-12-01',
			'valida_ata'   => '2027-06-30',
		) );
		$this->assertSame( array(), $r['erros'] );
		$this->assertSame( 'Desconto en libros', $r['datos']['titulo'] );
		$this->assertStringNotContainsString( '<', $r['datos']['descricion'] );
		$this->assertStringContainsString( "tenda.\n\nalert(1)Agás", $r['datos']['descricion'], 'tags go, at most one blank line stays' );
	}

	public function test_offer_errors(): void {
		$r = ANPA_Socios_Colab::limpar_oferta( array( 'titulo' => 'abc', 'desconto' => '', 'descricion' => 'curta', 'valida_dende' => '2027-02-30', 'valida_ata' => '2026-01-01' ) );
		$this->assertSame( array( 'titulo', 'desconto', 'descricion', 'valida_dende' ), array_keys( $r['erros'] ) );
		$r = ANPA_Socios_Colab::limpar_oferta( array( 'titulo' => 'Título bo', 'desconto' => '5 %', 'descricion' => 'Descrición suficiente', 'valida_dende' => '2027-03-01', 'valida_ata' => '2027-02-01' ) );
		$this->assertSame( array( 'valida_ata' ), array_keys( $r['erros'] ) );
		$longo = ANPA_Socios_Colab::limpar_oferta( array( 'titulo' => str_repeat( 'a', 200 ), 'desconto' => str_repeat( 'b', 200 ), 'descricion' => str_repeat( 'c', 2000 ) ) );
		$this->assertSame( 80, strlen( $longo['datos']['titulo'] ) );
		$this->assertSame( 60, strlen( $longo['datos']['desconto'] ) );
		$this->assertSame( 600, strlen( $longo['datos']['descricion'] ) );
	}

	public function test_shop_is_cleaned_and_checked(): void {
		$r = ANPA_Socios_Colab::limpar_comercio( array( 'nome' => ' Libraría  Ventín ', 'email' => 'Tenda@Example.COM', 'url_web' => 'https://example.com/tenda' ) );
		$this->assertSame( array(), $r['erros'] );
		$this->assertSame( 'Libraría Ventín', $r['datos']['nome'] );
		$this->assertSame( 'tenda@example.com', $r['datos']['email'] );
		foreach ( array( 'javascript:alert(1)', 'www.example.com', 'ftp://example.com' ) as $web ) {
			$m = ANPA_Socios_Colab::limpar_comercio( array( 'nome' => 'Tenda', 'email' => 'a@example.com', 'url_web' => $web ) );
			$this->assertArrayHasKey( 'url_web', $m['erros'], $web );
		}
		$m = ANPA_Socios_Colab::limpar_comercio( array( 'nome' => '', 'email' => 'non-e-correo' ) );
		$this->assertSame( array( 'nome', 'email' ), array_keys( $m['erros'] ) );
	}

	public function test_offer_approves_itself_after_the_configured_hours(): void {
		$enviada = 1_000_000;
		$this->assertFalse( ANPA_Socios_Colab::aprobacion_vencida( $enviada, 24, $enviada + 24 * 3600 - 1 ) );
		$this->assertTrue( ANPA_Socios_Colab::aprobacion_vencida( $enviada, 24, $enviada + 24 * 3600 ) );
		$this->assertFalse( ANPA_Socios_Colab::aprobacion_vencida( $enviada, 0, $enviada + 999999 ), '0 hours = only by hand' );
		$this->assertFalse( ANPA_Socios_Colab::aprobacion_vencida( 0, 24, $enviada ) );
		$this->assertSame( 24, ANPA_Socios_Colab::HORAS_APROBACION );
	}

	public function test_offer_validity_dates(): void {
		$this->assertTrue( ANPA_Socios_Colab::vixente( '', '', '2026-11-27' ) );
		$this->assertTrue( ANPA_Socios_Colab::vixente( '2026-11-27', '2026-11-27', '2026-11-27' ) );
		$this->assertFalse( ANPA_Socios_Colab::vixente( '2026-12-01', '', '2026-11-27' ) );
		$this->assertFalse( ANPA_Socios_Colab::vixente( '', '2026-11-26', '2026-11-27' ) );
		$this->assertTrue( ANPA_Socios_Colab::vixente( '0000-00-00', '0000-00-00', '2026-11-27' ) );
	}

	public function test_card_code_shape_and_normalisation(): void {
		$vistos = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$c = ANPA_Socios_Colab::xerar_codigo();
			$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{8}$/', $c );
			$vistos[ $c ] = true;
		}
		$this->assertGreaterThan( 195, count( $vistos ), 'codes are random' );
		$this->assertSame( 32, strlen( ANPA_Socios_Colab::ALFABETO ) );
		$this->assertSame( 'AB12-CD34', ANPA_Socios_Colab::formatar_codigo( 'AB12CD34' ) );
		$this->assertSame( 'AB12CD34', ANPA_Socios_Colab::normalizar_codigo( ' ab12-cd34 ' ) );
		$this->assertSame( '0011ABCD', ANPA_Socios_Colab::normalizar_codigo( 'oOiL-abcd' ), 'O reads as 0, I and L as 1' );
		foreach ( array( '', 'AB12CD3', 'AB12CD345', 'AB12CDU4', 'AB12CD3!', array( 'x' ) ) as $malo ) {
			$this->assertSame( '', ANPA_Socios_Colab::normalizar_codigo( $malo ) );
		}
	}

	public function test_module_is_isolated_from_companies_and_members(): void {
		$files = array( 'includes/lib/class-anpa-socios-colab.php', 'includes/class-anpa-socios-colab-db.php', 'includes/class-anpa-socios-colab-service.php', 'includes/class-anpa-socios-colab-rest.php', 'includes/class-anpa-socios-colab-page.php', 'includes/class-anpa-socios-colab-admin.php' );
		$todo  = '';
		foreach ( $files as $f ) {
			$s = $this->src( $f );
			$this->assertStringNotContainsString( 'tabela_empresas', $s, "$f never touches the activity companies" );
			$this->assertStringNotContainsString( 'anpa_codigos_verificacion', $s, "$f has its own login codes" );
			$this->assertStringNotContainsString( 'tabela_sesions(', $s, "$f has its own sessions" );
			$todo .= $s;
		}
		$this->assertSame( 1, substr_count( $todo, 'tabela_socios()' ), 'one single read of the members table' );
		$svc = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$this->assertStringContainsString( "'SELECT COUNT(*) FROM ' . ANPA_Socios_DB::tabela_socios()", $svc, 'and it only counts' );
	}

	public function test_card_check_reveals_only_valid_or_not(): void {
		$r    = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$from = (int) strpos( $r, 'public static function handle_verificar' );
		$body = substr( $r, $from, (int) strpos( $r, 'private static function panel' ) - $from );
		foreach ( array( 'nome', 'email', 'apelidos', 'nif', 'telefono' ) as $dato ) {
			$this->assertStringNotContainsString( "'" . $dato . "'", $body, $dato );
		}
		$this->assertStringContainsString( "'valido'        => \$valido,", $body );
		$this->assertStringContainsString( 'self::limite_ip(', $body, 'limited per IP' );
		$this->assertStringContainsString( 'hash_hmac(', $r, 'only a keyed digest of the card code is stored' );
		$this->assertStringContainsString( "\$paxina . '#c=' . \$codigo", $r, 'the code travels after #, never in a query string' );
	}

	public function test_shop_can_only_touch_its_own_offers_and_everything_is_gated(): void {
		$r = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::gardar_oferta( (int) \$c['id'], \$r['datos'], (int) \$request->get_param( 'id' ) )", $r, 'shop id from the session' );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::retirar_oferta( (int) \$c['id'],", $r );
		$this->assertStringContainsString( 'if ( ! ANPA_Socios_Colab_DB::activo() ) {', $r, 'no routes while the module is off' );
		$this->assertStringContainsString( 'AND usado = 0 AND intentos < %d', $r, 'login guesses counted atomically' );
		$s = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$this->assertStringContainsString( "array( 'id' => \$id, 'comercio_id' => \$comercio_id )", $s, 'writes scoped to the shop' );
		$this->assertStringContainsString( "WHERE id = %d AND estado = %s\", \$estado, \$motivo, time(), \$por, \$agora, \$id, ANPA_Socios_Colab::PENDENTE", $s, 'only a pending offer is approved or rejected, in one statement' );
		$a = $this->src( 'includes/class-anpa-socios-colab-admin.php' );
		$this->assertSame( 4, substr_count( $a, 'self::gardar( ' ), 'every admin handler checks capability and nonce' );
		$this->assertSame( 4, substr_count( $a, "add_action( 'admin_post_anpa_colab_" ) );
	}

	public function test_plugin_wires_the_module(): void {
		$p = $this->src( 'anpa-socios.php' );
		$this->assertStringContainsString( "array( 'ANPA_Socios_Colab_Service', 'aprobar_vencidas' )", $p );
		$this->assertStringContainsString( "array( 'ANPA_Socios_Colab_REST', 'register_routes' )", $p );
		$n = $this->src( 'includes/lib/class-anpa-socios-non-cachear.php' );
		$this->assertStringContainsString( "'anpa_colaboradores_acceso',", $n );
		$this->assertStringContainsString( "'anpa_colaboradores_verificar',", $n );
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/js/vendor/qrcode-generator.js' );
		$this->assertStringContainsString( 'Licensed under the MIT license', $this->src( 'assets/js/vendor/qrcode-generator.js' ) );
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "root.dataset.carneUrl", $js );
	}
}
