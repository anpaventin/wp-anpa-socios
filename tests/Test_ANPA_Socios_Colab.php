<?php
/**
 * 1.104.0: collaborators network — pure rules and the module's isolation.
 * 1.105.0 (v2): permanent family card, shop key, till, uses per family,
 * shop data proposals, images.
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
			'imaxe_id'     => 99,
		) );
		$this->assertSame( array(), $r['erros'] );
		$this->assertSame( 'Desconto en libros', $r['datos']['titulo'] );
		$this->assertStringNotContainsString( '<', $r['datos']['descricion'] );
		$this->assertStringContainsString( "tenda.\n\nalert(1)Agás", $r['datos']['descricion'], 'tags go, at most one blank line stays' );
		$this->assertSame( 0, $r['datos']['acumulable'], 'not combinable unless the shop says so' );
		$this->assertSame( 1, $r['datos']['usos_familia'], 'one use per family by default' );
		$this->assertArrayNotHasKey( 'imaxe_id', $r['datos'], 'the image never comes from the JSON' );
	}

	public function test_offer_errors(): void {
		$r = ANPA_Socios_Colab::limpar_oferta( array( 'titulo' => 'abc', 'desconto' => '', 'descricion' => 'curta', 'valida_dende' => '2027-02-30', 'valida_ata' => '2026-01-01' ) );
		$this->assertSame( array( 'titulo', 'desconto', 'descricion', 'valida_dende' ), array_keys( $r['erros'] ) );
		$r = ANPA_Socios_Colab::limpar_oferta( array( 'titulo' => 'Título bo', 'desconto' => '5 %', 'descricion' => 'Descrición suficiente', 'valida_dende' => '2027-03-01', 'valida_ata' => '2027-02-01' ) );
		$this->assertSame( array( 'valida_ata' ), array_keys( $r['erros'] ) );
		$longo = ANPA_Socios_Colab::limpar_oferta( array( 'titulo' => str_repeat( 'a', 200 ), 'desconto' => str_repeat( 'b', 200 ), 'descricion' => str_repeat( 'c', 2000 ) ) );
		$this->assertSame( 80, strlen( $longo['datos']['titulo'] ) );
		$this->assertSame( 60, strlen( $longo['datos']['desconto'] ) );
		$this->assertSame( 300, strlen( $longo['datos']['descricion'] ), 'a short description now' );
		$this->assertSame( 300, ANPA_Socios_Colab::MAX_DESCRICION );
	}

	/** 1.105.0 v2: combinable and uses per family. */
	public function test_offer_uses_per_family_and_combinable(): void {
		$base = array( 'titulo' => 'Título bo', 'desconto' => '5 %', 'descricion' => 'Descrición suficiente' );
		foreach ( array( array( 0, 0 ), array( 1, 1 ), array( '5', 5 ), array( 99, 99 ), array( ' 10 ', 10 ), array( '', 1 ), array( null, 1 ) ) as $c ) {
			$r = ANPA_Socios_Colab::limpar_oferta( $base + array( 'usos_familia' => $c[0] ) );
			$this->assertSame( array(), $r['erros'], var_export( $c[0], true ) );
			$this->assertSame( $c[1], $r['datos']['usos_familia'], var_export( $c[0], true ) );
		}
		foreach ( array( 100, -1, '1.5', 'moitos', true, array( 2 ) ) as $malo ) {
			$r = ANPA_Socios_Colab::limpar_oferta( $base + array( 'usos_familia' => $malo ) );
			$this->assertSame( array( 'usos_familia' ), array_keys( $r['erros'] ), var_export( $malo, true ) );
		}
		foreach ( array( true, 1, '1', 'true', 'si' ) as $si ) {
			$this->assertSame( 1, ANPA_Socios_Colab::limpar_oferta( $base + array( 'acumulable' => $si ) )['datos']['acumulable'] );
		}
		foreach ( array( false, 0, '0', '', 'non', array( 1 ) ) as $non ) {
			$this->assertSame( 0, ANPA_Socios_Colab::limpar_oferta( $base + array( 'acumulable' => $non ) )['datos']['acumulable'] );
		}
		$this->assertNull( ANPA_Socios_Colab::restan( 0, 50 ), 'unlimited' );
		$this->assertSame( 1, ANPA_Socios_Colab::restan( 1, 0 ) );
		$this->assertSame( 0, ANPA_Socios_Colab::restan( 1, 1 ) );
		$this->assertSame( 0, ANPA_Socios_Colab::restan( 3, 7 ), 'never negative' );
		$this->assertSame( 2, ANPA_Socios_Colab::restan( 5, 3 ) );
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

	/** 1.105.0 v2: what a shop proposes about itself never carries the login email. */
	public function test_shop_profile_proposal_is_cleaned(): void {
		$r = ANPA_Socios_Colab::limpar_perfil( array( 'nome' => ' Froitería <b>Ana</b> ', 'email' => 'outro@example.com', 'url_web' => 'https://example.com', 'descricion' => "Liña 1\n\n\n\nLiña 2" ) );
		$this->assertSame( array(), $r['erros'] );
		$this->assertSame( ANPA_Socios_Colab::campos_perfil(), array_keys( $r['datos'] ) );
		$this->assertArrayNotHasKey( 'email', $r['datos'] );
		$this->assertSame( 'Froitería Ana', $r['datos']['nome'] );
		$this->assertSame( "Liña 1\n\nLiña 2", $r['datos']['descricion'] );
		$m = ANPA_Socios_Colab::limpar_perfil( array( 'nome' => 'x', 'url_web' => 'javascript:alert(1)' ) );
		$this->assertSame( array( 'nome', 'url_web' ), array_keys( $m['erros'] ) );
		$this->assertNotContains( 'email', ANPA_Socios_Colab::campos_perfil() );
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

	/** 1.105.0 v2: codes derived from a secret, same input = same code. */
	public function test_family_code_and_shop_key_derivation(): void {
		$seg = 'segredo-de-proba';
		$f   = ANPA_Socios_Colab::codigo_familia( 42, 1, $seg );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{12}$/', $f, '60 bits in Crockford base32' );
		$this->assertSame( $f, ANPA_Socios_Colab::codigo_familia( 42, 1, $seg ), 'shown again at any time' );
		$this->assertNotSame( $f, ANPA_Socios_Colab::codigo_familia( 42, 2, $seg ), 'renewing changes it' );
		$this->assertNotSame( $f, ANPA_Socios_Colab::codigo_familia( 43, 1, $seg ) );
		$this->assertNotSame( $f, ANPA_Socios_Colab::codigo_familia( 42, 1, 'outro' ), 'useless without the secret' );
		$k = ANPA_Socios_Colab::clave_comercio( 42, 1, $seg );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $k, '130 bits' );
		$this->assertNotSame( substr( $k, 0, 12 ), $f, 'family and shop codes never coincide' );
		$this->assertSame( 32, strlen( ANPA_Socios_Colab::ALFABETO ) );
		// Known vector: the first character is the first 5 bits of HMAC-SHA256("familia|1|1", "k").
		$bin = hash_hmac( 'sha256', 'familia|1|1', 'k', true );
		$this->assertSame( ANPA_Socios_Colab::ALFABETO[ ord( $bin[0] ) >> 3 ], ANPA_Socios_Colab::codigo_familia( 1, 1, 'k' )[0] );
		$vistos = array();
		for ( $i = 1; $i <= 300; $i++ ) {
			$vistos[ ANPA_Socios_Colab::codigo_familia( $i, 1, $seg ) ] = true;
		}
		$this->assertCount( 300, $vistos );
		$this->assertSame( 64, strlen( ANPA_Socios_Colab::digest( $f, $seg ) ) );
		$this->assertNotSame( ANPA_Socios_Colab::digest( $f, $seg . '|a' ), ANPA_Socios_Colab::digest( $f, $seg . '|b' ) );
	}

	public function test_code_normalisation_and_format(): void {
		$this->assertSame( 'AB12-CD34-EF56', ANPA_Socios_Colab::formatar_codigo( 'AB12CD34EF56' ) );
		$this->assertSame( 'AB12CD34EF56', ANPA_Socios_Colab::normalizar_codigo( ' ab12-cd34-ef56 ' ) );
		$this->assertSame( '0011ABCDEF56', ANPA_Socios_Colab::normalizar_codigo( 'oOiL-abcd-ef56' ), 'O reads as 0, I and L as 1' );
		foreach ( array( '', 'AB12CD34EF5', 'AB12CD34EF567', 'AB12CD34EFU6', 'AB12CD34EF5!', array( 'x' ), str_repeat( 'A', 500 ) ) as $malo ) {
			$this->assertSame( '', ANPA_Socios_Colab::normalizar_codigo( $malo ) );
		}
		$k = ANPA_Socios_Colab::clave_comercio( 7, 3, 's' );
		$this->assertSame( $k, ANPA_Socios_Colab::normalizar_codigo( strtolower( ANPA_Socios_Colab::formatar_codigo( $k ) ), ANPA_Socios_Colab::CLAVE_LONXITUDE ) );
		$this->assertSame( '', ANPA_Socios_Colab::normalizar_codigo( $k ), 'a shop key is not a family code' );
	}

	/** 1.105.0 v2: when an approved offer goes out (fallback rule). */
	public function test_next_batch_moment(): void {
		$tz = new DateTimeZone( 'Europe/Madrid' );
		$ts = static function ( string $d ) use ( $tz ): int {
			return ( new DateTimeImmutable( $d, $tz ) )->getTimestamp();
		};
		$this->assertSame( $ts( '2026-12-03 16:00' ), ANPA_Socios_Colab::proximo_lote( $ts( '2026-11-27 10:00' ), 4, 16, 0, $tz ), 'Friday → next Thursday' );
		$this->assertSame( $ts( '2026-12-03 16:00' ), ANPA_Socios_Colab::proximo_lote( $ts( '2026-12-03 15:59' ), 4, 16, 0, $tz ) );
		$this->assertSame( $ts( '2026-12-03 16:00' ), ANPA_Socios_Colab::proximo_lote( $ts( '2026-12-03 16:00' ), 4, 16, 0, $tz ) );
		$this->assertSame( $ts( '2026-12-10 16:00' ), ANPA_Socios_Colab::proximo_lote( $ts( '2026-12-03 16:01' ), 4, 16, 0, $tz ) );
		$this->assertSame( $ts( '2027-04-01 16:00' ), ANPA_Socios_Colab::proximo_lote( $ts( '2027-03-26 12:00' ), 4, 16, 0, $tz ), 'across the summer time change' );
		$this->assertSame( $ts( '2026-11-30 09:30' ), ANPA_Socios_Colab::proximo_lote( $ts( '2026-11-27 10:00' ), 1, 9, 30, $tz ) );
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
		$this->assertSame( 2, substr_count( $todo, 'tabela_socios()' ), 'two reads of the members table: membership and the card names' );
		$svc = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$this->assertStringContainsString( "'SELECT COUNT(*) FROM ' . ANPA_Socios_DB::tabela_socios()", $svc, 'membership only counts' );
		$this->assertStringContainsString( "'SELECT nome, apelidos FROM ' . ANPA_Socios_DB::tabela_socios()", $svc, 'the card only reads names' );
		$this->assertStringContainsString( "tabela_socios() . \" WHERE COALESCE(NULLIF(familia_id, 0), id) = %d AND estado = 'activo' AND rol <> 'master' ORDER BY", $svc, 'only the family itself' );
		$r = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$this->assertSame( 1, substr_count( $r, 'nomes_familia(' ), 'the names only go to the family card' );
		$this->assertGreaterThan( strpos( $r, 'private static function resposta_carne' ), strpos( $r, 'nomes_familia(' ) );
		$this->assertLessThan( strpos( $r, 'private static function panel' ), strpos( $r, 'nomes_familia(' ) );
	}

	/** 1.105.0 v2: the till learns «valid family» and its offers, nothing else. */
	public function test_till_never_gets_family_data(): void {
		$r    = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$this->assertStringContainsString( "array( 'comercio' => (string) \$tenda['nome'] )", $r, 'GET /colab/caixa: only the shop name' );
		$from = (int) strpos( $r, 'public static function handle_caixa_familia(' );
		$body = substr( $r, $from, (int) strpos( $r, 'public static function handle_carne' ) - $from );
		$this->assertGreaterThan( 1000, strlen( $body ) );
		foreach ( array( 'nome', 'email', 'apelidos', 'nif', 'telefono', 'adultos', 'familia_id', 'curso' ) as $dato ) {
			$this->assertStringNotContainsString( "'" . $dato . "'", $body, $dato );
		}
		$this->assertStringNotContainsString( 'nomes_familia', $body );
		$this->assertStringContainsString( "'valida'  => \$familia > 0,", $body );
		$this->assertStringContainsString( "\$familia > 0 ? ANPA_Socios_Colab_Service::ofertas_caixa( (int) \$tenda['id'], \$familia ) : array()", $body, 'not valid = no offers' );

		$s    = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$from = (int) strpos( $s, 'public static function ofertas_caixa' );
		$fn   = substr( $s, $from, (int) strpos( $s, 'public static function aplicar_uso' ) - $from );
		$this->assertSame( 10, substr_count( $fn, "\t\t\t\t'" ), 'ten keys per offer' );
		foreach ( array( 'familia_id', 'nome', 'email' ) as $k ) {
			$this->assertStringNotContainsString( "'" . $k . "'", $fn, $k );
		}
		$this->assertStringContainsString( 'WHERE o.comercio_id = %d AND o.estado = %s AND o.publicada_ts > 0', $fn, 'only this shop, approved and published' );

		// Till auth: the key in a header, the same 401 for any bad key, limits.
		$this->assertStringContainsString( "const CAIXA_HEADER = 'X-Anpa-Colab-Caixa';", $r );
		$this->assertSame( 1, substr_count( $r, "'anpa_colab_caixa_clave'" ), 'one single answer for a wrong key' );
		$this->assertStringContainsString( "self::limite_ip( 'anpa_colab_caixa_ip_', self::CAIXA_IP, 10 * MINUTE_IN_SECONDS )", $r );
		$this->assertStringContainsString( "self::limite( 'anpa_colab_caixa_c_' . \$comercio['id'], self::CAIXA_COMERCIO, 10 * MINUTE_IN_SECONDS )", $r );
		$this->assertStringContainsString( "const CAIXA_COMERCIO = 120;", $r );
		$this->assertStringContainsString( "const CAIXA_IP = 60;", $r );
		$this->assertStringContainsString( "hash_equals( (string) \$row['qr_digest'], \$d )", $s, 'constant-time comparison after the digest lookup' );
		$this->assertStringContainsString( "'activo' !== (string) \$row['estado']", $s, 'a deactivated shop key is useless' );
		$this->assertStringNotContainsString( "'/colab/verificar'", $r, 'no anonymous check any more' );
		$this->assertStringNotContainsString( 'CARNE_TTL', $r );
	}

	/** 1.105.0 v2: the uses limit is enforced in one statement. */
	public function test_uses_limit_in_one_statement(): void {
		$s    = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$from = (int) strpos( $s, 'public static function aplicar_uso' );
		$fn   = substr( $s, $from, (int) strpos( $s, 'public static function desfacer_uso' ) - $from );
		$this->assertSame( 1, substr_count( $fn, 'INSERT INTO {$u}' ) );
		$this->assertStringContainsString( '( SELECT COUNT(*) AS usados FROM {$u} WHERE oferta_id = %d AND familia_id = %d AND desfeito_ts = 0 ) k', $fn );
		$this->assertStringContainsString( 'AND ( o.usos_familia = 0 OR k.usados < o.usos_familia )', $fn );
		$this->assertStringContainsString( 'SELECT GET_LOCK( %s, 5 )', $fn );
		$this->assertStringContainsString( 'SELECT RELEASE_LOCK( %s )', $fn );
		$this->assertStringNotContainsString( '$wpdb->insert(', $fn, 'no separate check-then-insert' );
		$this->assertStringContainsString( "'anpa_colab_sen_usos'", $fn );
		$this->assertStringContainsString( 'AND comercio_id = %d AND familia_id = %d AND desfeito_ts = 0 AND creado_ts >= %d', $s, 'undo: same shop, same family, in time' );
		$this->assertSame( 600, ANPA_Socios_Colab::DESFACER_SEGUNDOS );
		$this->assertStringContainsString( 'WHERE comercio_id = %d AND desfeito_ts = 0 GROUP BY oferta_id', $s, 'undone uses do not count' );
	}

	/** 1.105.0 v2: uploads are always re-encoded and limited. */
	public function test_uploads_are_reencoded(): void {
		$s    = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$from = (int) strpos( $s, 'public static function recibir_imaxe' );
		$fn   = substr( $s, $from, (int) strpos( $s, 'public static function imaxe_url' ) - $from );
		foreach ( array( 'is_uploaded_file(', 'wp_check_filetype_and_ext(', 'getimagesize(', 'wp_get_image_editor(', '->resize( self::LADO_IMAXE, self::LADO_IMAXE, false )', '$ed->save(', "'WP_Image_Editor_GD'", 'self::SUBIDAS_HORA', 'self::MAX_BYTES_IMAXE' ) as $paso ) {
			$this->assertStringContainsString( $paso, $fn, $paso );
		}
		$this->assertStringContainsString( "(string) \$saved['path'],\n\t\t\t0,", $fn, 'only the re-encoded file becomes the attachment, with no parent' );
		$this->assertStringContainsString( "\$tipos = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );", $fn );
		foreach ( array( 'svg', 'gif' ) as $non ) {
			$this->assertStringNotContainsString( $non, strtolower( $fn ), $non );
		}
		$this->assertStringContainsString( 'const MAX_BYTES_IMAXE = 2097152;', $s );
		$this->assertStringContainsString( 'const SUBIDAS_HORA = 10;', $s );
		$this->assertStringContainsString( 'const LADO_IMAXE = 800;', $s );
		$this->assertStringContainsString( "'' === (string) get_post_meta( \$id, self::META_COMERCIO, true )", $s, "only the module's own uploads are ever deleted" );
		$r = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$this->assertStringContainsString( "\$files['logo'] ?? null", $r );
		$this->assertStringContainsString( "\$files['imaxe'] ?? null", $r );
	}

	/** 1.105.0 v2: codes never travel in a query string. */
	public function test_codes_never_in_query_strings(): void {
		$r = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$this->assertStringContainsString( "\$paxina . '#f=' . \$carne['codigo']", $r, 'the family code travels after #' );
		$this->assertStringContainsString( "\$caixa . '#k=' . \$clave", $r, 'the shop key travels after #' );
		$this->assertStringContainsString( '$request->get_header( self::CAIXA_HEADER )', $r );
		$this->assertStringContainsString( "self::corpo( \$request, 'codigo' )", $r );
		$this->assertStringNotContainsString( 'get_query_params', $r );
		$this->assertStringNotContainsString( "get_param( 'codigo' )", substr( $r, (int) strpos( $r, 'public static function handle_caixa(' ) ) );
		foreach ( array( "'/colab/caixa/familia'", "'/colab/caixa/aplicar'", "'/colab/caixa/desfacer'" ) as $ruta ) {
			$from = (int) strpos( $r, $ruta );
			$this->assertStringContainsString( 'WP_REST_Server::CREATABLE', substr( $r, $from, 120 ), $ruta );
		}
		$this->assertStringContainsString( "\$result->header( 'Cache-Control', 'no-store, private' );", $r, 'all answers no-store' );
		$p = $this->src( 'includes/class-anpa-socios-colab-page.php' );
		$this->assertStringContainsString( '<div id="anpa-colab-caixa"', $p );
		$this->assertStringContainsString( "add_shortcode( self::SC_VERIFICAR, array( __CLASS__, 'render_caixa' ) );", $p, 'the 1.104.0 page renders the till' );
		$this->assertStringContainsString( "'anpa-colab-caixa', 'assets/js/colab-caixa.js'", $p );
	}

	public function test_shop_can_only_touch_its_own_offers_and_everything_is_gated(): void {
		$r = $this->src( 'includes/class-anpa-socios-colab-rest.php' );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::gardar_oferta( (int) \$c['id'], \$r['datos'], (int) \$request->get_param( 'id' ) )", $r, 'shop id from the session' );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::retirar_oferta( (int) \$c['id'],", $r );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::imaxe_oferta( (int) \$c['id'],", $r );
		$this->assertStringContainsString( 'if ( ! ANPA_Socios_Colab_DB::activo() ) {', $r, 'no routes while the module is off' );
		$this->assertStringContainsString( 'AND usado = 0 AND intentos < %d', $r, 'login guesses counted atomically' );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::usos( (int) \$c['id'] )", $r, 'a shop sees only its own counts' );
		$s = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$this->assertStringContainsString( "array( 'id' => \$id, 'comercio_id' => \$comercio_id )", $s, 'writes scoped to the shop' );
		$this->assertStringContainsString( "WHERE id = %d AND estado = %s\", \$estado, \$motivo, time(), \$por, \$agora, \$id, ANPA_Socios_Colab::PENDENTE", $s, 'only a pending offer is approved or rejected, in one statement' );
		$a = $this->src( 'includes/class-anpa-socios-colab-admin.php' );
		$this->assertSame( 6, substr_count( $a, 'self::gardar( ' ), 'every admin handler checks capability and nonce' );
		$this->assertSame( 6, substr_count( $a, "add_action( 'admin_post_anpa_colab_" ) );
		$this->assertStringContainsString( "ANPA_Socios_Colab_Service::editar_oferta_xunta( \$id, \$r['datos'], self::actor() )", $a, 'the junta edits through the same cleaning as a shop' );
		$p = $this->src( 'includes/class-anpa-socios-colab-publicacion.php' );
		$this->assertStringContainsString( "if ( 'especial' !== \$modo && \$novas <= 0 ) {", $p, 'a week without new offers publishes and emails nothing' );
		$this->assertStringNotContainsString( 'name="sempre"', $p, 'no setting brings the empty weekly post back' );
		$this->assertSame( 3, substr_count( $p, 'self::gardia( self::ACCION_' ), 'settings, pending and special publication are gated' );
		$this->assertSame( 6, substr_count( $a, 'public static function handle_' ) );
		$this->assertStringContainsString( "do_action( 'anpa_colab_admin_seccions', \$post );", $a );
		$this->assertStringContainsString( "array_intersect_key( (array) \$in, array( 'nome' => 1, 'email' => 1 ) )", $a, 'the junta only sets name and login email' );
	}

	/** 1.105.0 (second audit + v2): moderation holds, and the wipe knows the module. */
	public function test_moderation_cannot_be_walked_around(): void {
		$s = $this->src( 'includes/class-anpa-socios-colab-service.php' );
		$this->assertStringContainsString( "( ANPA_Socios_Colab::REXEITADA === \$estado || 1 === (int) \$fila['so_manual'] ) ? 1 : 0", $s, 'a rejected offer sent again waits for a person' );
		$this->assertStringContainsString( 'o.estado = %s AND o.so_manual = 0 AND c.estado', $s, 'and the hourly approval skips it' );
		$this->assertStringContainsString( "resolta_por NOT IN ( '', 'comercio', 'auto' ) AND resolta_ts >= %d", $s, 'new offers of a shop the junta turned down wait too' );
		$this->assertStringContainsString( "\$wpdb->prepare( ' AND enviada_ts = %d', \$vista )", $s, 'the junta resolves the version it saw' );
		$this->assertStringContainsString( "array( 'id' => \$id, 'comercio_id' => \$comercio_id, 'estado' => \$estado )", $s, 'an edit never revives an offer withdrawn meanwhile' );
		$this->assertStringContainsString( 'ANPA_Socios_Colab::GARDADOS_HORA', $s, 'saves per hour are limited' );
		$this->assertSame( 20, ANPA_Socios_Colab::GARDADOS_HORA );
		$this->assertStringContainsString( "if ( \$publica ) {
			self::limpar_cache();", $s, 'the page cache is dropped only when the public list changes' );
		$this->assertStringContainsString( "'publicada_ts'   => 0,", $s, 'an edited offer goes out again in a batch' );
		// Shop data proposals: the same rules.
		$this->assertStringContainsString( "array( 'id' => \$id, 'proposta_ts' => \$version )", $s, 'a proposal is resolved only from the version read' );
		$this->assertStringContainsString( 'if ( $vista > 0 && $vista !== $version ) {', $s );
		$this->assertStringContainsString( "'proposta_manual' => 1,", $s, 'a rejected proposal waits for a person next time' );
		$this->assertStringContainsString( "WHERE proposta_ts > 0 AND proposta_manual = 0 AND estado = 'activo' AND proposta_ts <= %d", $s );
		$this->assertStringContainsString( "max( time(), (int) \$c['proposta_ts'] + 1 )", $s, 'every proposal version has its own stamp' );
		$this->assertStringContainsString( "'UPDATE ' . ANPA_Socios_Colab_DB::ofertas() . ' SET publicada_ts = %d WHERE estado = %s AND publicada_ts = 0'", $s, 'the batch releases approved offers' );
		$this->assertStringContainsString( "o.estado = %s AND o.publicada_ts > 0 AND c.estado = 'activo'", $s, 'the public list shows only published offers' );
		$a = $this->src( 'includes/class-anpa-socios-colab-admin.php' );
		$this->assertSame( 2, substr_count( $a, 'name="enviada" value="' ) );
		$this->assertStringContainsString( 'self::actor(), $motivo, $vista )', $a );
		$this->assertStringContainsString( "'aprobar' === \$accion, self::actor(), \$motivo, \$vista )", $a );

		$b = $this->src( 'includes/class-anpa-socios-backup.php' );
		$this->assertStringContainsString( "array( 'comercios', 'ofertas', 'sesions', 'codigos', 'carnes', 'usos', 'familias' )", $b, 'the wipe drops the module tables' );
		$this->assertStringContainsString( 'ANPA_Socios_Colab_DB::OPTION_ACTIVO,', $b );
		$db = $this->src( 'includes/class-anpa-socios-colab-db.php' );
		foreach ( array( 'comercios', 'ofertas', 'sesions', 'codigos', 'carnes', 'usos', 'familias' ) as $t ) {
			$this->assertStringContainsString( "\$wpdb->prefix . 'anpa_colab_" . $t . "'", $db, $t );
		}
		$this->assertSame( 7, substr_count( $db, "return \$wpdb->prefix . 'anpa_colab_" ), 'no module table the wipe does not know' );
	}

	/** 1.105.0 v2: schema of the uses and the family cards. */
	public function test_schema_keeps_no_secret_and_no_personal_data(): void {
		$db = $this->src( 'includes/class-anpa-socios-colab-db.php' );
		$this->assertStringContainsString( "const DB_VERSION     = '1.2.0';", $db );
		$cut = static function ( string $from ) use ( $db ): string {
			$i = (int) strpos( $db, $from );
			return substr( $db, $i, (int) strpos( $db, ') {$cc};', $i ) - $i );
		};
		$usos = $cut( "dbDelta( 'CREATE TABLE ' . self::usos()" );
		$this->assertNotSame( '', $usos );
		foreach ( array( 'email', 'nome', 'codigo', 'carne', ' ip ' ) as $col ) {
			$this->assertStringNotContainsString( $col, $usos, "the uses table has no $col" );
		}
		$this->assertStringContainsString( 'key oferta_familia (oferta_id,familia_id)', $usos );
		$this->assertStringContainsString( 'desfeito_ts int(10) unsigned not null default 0', $usos );
		$fam = $cut( "dbDelta( 'CREATE TABLE ' . self::familias()" );
		$this->assertStringContainsString( 'unique key familia_id (familia_id)', $fam );
		$this->assertStringContainsString( 'unique key codigo_digest (codigo_digest)', $fam );
		foreach ( array( 'email', 'nome', 'codigo varchar', 'codigo char' ) as $col ) {
			$this->assertStringNotContainsString( $col, $fam, "no $col in the family cards" );
		}
		$com = $cut( "dbDelta( 'CREATE TABLE ' . self::comercios()" );
		foreach ( array( 'logo_id', 'cubertos', 'proposta longtext null', 'proposta_ts', 'proposta_manual', 'proposta_motivo', 'qr_version', 'qr_digest char(64)', 'key qr_digest (qr_digest)' ) as $col ) {
			$this->assertStringContainsString( $col, $com, $col );
		}
		$this->assertStringNotContainsString( 'clave', $com, 'the shop key itself is never stored' );
		$of = $cut( "dbDelta( 'CREATE TABLE ' . self::ofertas()" );
		foreach ( array( 'acumulable tinyint(1)', 'usos_familia smallint(5) unsigned not null default 1', 'imaxe_id', 'publicada_ts' ) as $col ) {
			$this->assertStringContainsString( $col, $of, $col );
		}
	}

	/** 1.105.0 (second audit): the other parent's new address proves itself. */
	public function test_other_parent_email_needs_a_code(): void {
		$r = $this->src( 'includes/class-anpa-socios-area-rest.php' );
		$this->assertStringContainsString( "ANPA_Socios_Cambio_Email::solicitar_codigo( (int) \$profile['id'], \$p2_novo, 'p2_' )", $r );
		$this->assertStringContainsString( "ANPA_Socios_Cambio_Email::comprobar_codigo( (int) \$profile['id'], \$p2_novo, \$p2_codigo, 'p2_' )", $r );
		$this->assertStringContainsString( "\$p2_input['email'] = \$p2_vello;", $r, 'without a code the address stays as it was' );
		$this->assertLessThan( strpos( $r, '$p2_result = self::save_segundo_proxenitor(' ), strpos( $r, "\$p2_input['email'] = \$p2_vello;" ), 'decided before anything is written' );
		$this->assertStringContainsString( "'proxenitor2_engadido'", $r, 'a second adult added from the area is audited' );
		$this->assertSame( 2, substr_count( $r, '( familia_id = %d OR id = %d )' ), 'the first adult is found even without a family id' );
		$c = $this->src( 'includes/class-anpa-socios-cambio-email.php' );
		$this->assertStringContainsString( "'anpa_cambio_email_' . \$ambito . \$socio_id", $c, 'own and other-parent pending changes never mix' );
		$this->assertStringContainsString( "\$ambito   = 'p2_' === \$ambito ? 'p2_' : '';", $c );
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'payload.p2_email_codigo = p2Codigo;', $js );
		$this->assertStringContainsString( 'data-p2-email-codigo', $this->src( 'includes/class-anpa-socios-area-page.php' ) );
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
		$js = glob( dirname( __DIR__ ) . '/assets/js/colab-*.js' );
		$js = array_merge( is_array( $js ) ? $js : array(), array( dirname( __DIR__ ) . '/assets/js/area.js' ) );
		foreach ( $js as $f ) {
			$this->assertStringNotContainsString( 'innerHTML', (string) file_get_contents( $f ), basename( $f ) . ' builds nodes, never innerHTML' );
		}
	}
}
