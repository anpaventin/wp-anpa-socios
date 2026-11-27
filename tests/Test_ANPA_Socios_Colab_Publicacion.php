<?php
/**
 * 1.105.0: weekly publication of the collaborators' offers — seasonal
 * calendar, weekly schedule, post content and the admin handlers' guards.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-colab-calendario.php';
require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-colab-publicacion.php';

final class Test_ANPA_Socios_Colab_Publicacion extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function madrid(): DateTimeZone {
		return new DateTimeZone( 'Europe/Madrid' );
	}

	private function ts( string $local ): int {
		return ( new DateTimeImmutable( $local, $this->madrid() ) )->getTimestamp();
	}

	private function local( int $ts ): string {
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->madrid() )->format( 'Y-m-d H:i' );
	}

	public function test_easter_matches_known_dates(): void {
		$known = array(
			2024 => '2024-03-31',
			2025 => '2025-04-20',
			2026 => '2026-04-05',
			2027 => '2027-03-28',
			2028 => '2028-04-16',
			2029 => '2029-04-01',
			2030 => '2030-04-21',
			2031 => '2031-04-13',
			2032 => '2032-03-28',
			2033 => '2033-04-17',
			2034 => '2034-04-09',
			2035 => '2035-03-25',
		);
		foreach ( $known as $ano => $data ) {
			$this->assertSame( $data, ANPA_Socios_Colab_Calendario::pascua( $ano ), (string) $ano );
		}
	}

	public function test_each_festivity_wins_the_thursday_before_it(): void {
		$utc = new DateTimeZone( 'UTC' );
		for ( $ano = 2027; $ano <= 2035; $ano++ ) {
			$xanelas = ANPA_Socios_Colab_Calendario::xanelas( $ano );
			foreach ( $xanelas as $x ) {
				$fin = new DateTimeImmutable( $x['ata'], $utc );
				$dif = ( (int) $fin->format( 'N' ) - 4 + 7 ) % 7;
				$xov = $fin->modify( '-' . $dif . ' days' )->format( 'Y-m-d' );
				$this->assertGreaterThanOrEqual( $x['dende'], $xov, $x['clave'] . ' ' . $ano . ': every window holds a Thursday' );
				$got = ANPA_Socios_Colab_Calendario::clave( $xov );
				if ( $got === $x['clave'] ) {
					continue;
				}
				// Only a festivity that comes even sooner may take that Thursday.
				$outra = null;
				foreach ( array_merge( ANPA_Socios_Colab_Calendario::xanelas( $ano - 1 ), $xanelas ) as $y ) {
					if ( $y['clave'] === $got && $xov >= $y['dende'] && $xov <= $y['ata'] ) {
						$outra = $y;
					}
				}
				$this->assertNotNull( $outra, $x['clave'] . ' ' . $ano . ' lost ' . $xov . ' to ' . $got );
				$this->assertLessThan( $x['ata'], $outra['ata'], $x['clave'] . ' ' . $ano );
			}
		}
		// Spot checks.
		$this->assertSame( 'entroido', ANPA_Socios_Colab_Calendario::clave( '2027-02-04' ) );
		$this->assertSame( 'pascua', ANPA_Socios_Colab_Calendario::clave( '2027-03-25' ) );
		$this->assertSame( 'letras-galegas', ANPA_Socios_Colab_Calendario::clave( '2029-05-17' ) );
		$this->assertSame( 'samain', ANPA_Socios_Colab_Calendario::clave( '2030-10-31' ) );
		$this->assertSame( 'aninovo-reis', ANPA_Socios_Colab_Calendario::clave( '2027-12-30' ) );
		$this->assertSame( 'aninovo-reis', ANPA_Socios_Colab_Calendario::clave( '2028-01-06' ) );
		$this->assertSame( 'xaneiro', ANPA_Socios_Colab_Calendario::clave( '2028-01-07' ) );
		$this->assertSame( 'nadal', ANPA_Socios_Colab_Calendario::clave( '2031-12-25' ) );
		$this->assertSame( 'dia-de-galicia', ANPA_Socios_Colab_Calendario::clave( '2032-07-22' ) );
	}

	public function test_every_month_has_a_fallback_that_is_reached(): void {
		$claves = ANPA_Socios_Colab_Calendario::claves();
		$vistos = array();
		$d      = new DateTimeImmutable( '2027-01-01', new DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < 365; $i++ ) {
			$vistos[ ANPA_Socios_Colab_Calendario::clave( $d->modify( "+{$i} days" )->format( 'Y-m-d' ) ) ] = true;
		}
		foreach ( ANPA_Socios_Colab_Calendario::MESES as $n => $mes ) {
			$this->assertArrayHasKey( $mes, $claves, (string) $n );
			$this->assertArrayHasKey( $mes, $vistos, $mes . ' is reached on some day of the year' );
		}
		foreach ( array_keys( $claves ) as $k ) {
			$this->assertArrayHasKey( $k, $vistos, $k . ' is reached in 2027' );
		}
	}

	public function test_every_key_has_variants_texts_and_images(): void {
		$claves = ANPA_Socios_Colab_Calendario::claves();
		$textos = ANPA_Socios_Colab_Calendario::textos();
		$this->assertSame( array_keys( $claves ), array_keys( $textos ) );
		$this->assertGreaterThanOrEqual( 28, count( $claves ) );
		$dir = dirname( __DIR__ ) . '/' . ANPA_Socios_Colab_Calendario::DIR_IMAXES;
		foreach ( $textos as $k => $vs ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $k );
			$this->assertGreaterThanOrEqual( 2, count( $vs ), $k );
			$this->assertLessThanOrEqual( 3, count( $vs ), $k );
			$this->assertFileExists( $dir . $k . '.svg' );
			$this->assertFileExists( $dir . $k . '.png' );
			$png = getimagesize( $dir . $k . '.png' );
			$this->assertSame( array( 1200, 630 ), array( $png[0], $png[1] ), $k );
			$titulos = array();
			foreach ( $vs as $v ) {
				$this->assertLessThanOrEqual( 70, mb_strlen( $v[0] ), $v[0] );
				$this->assertGreaterThan( 10, mb_strlen( $v[0] ) );
				$this->assertGreaterThan( 60, mb_strlen( $v[1] ), $k );
				foreach ( $v as $txt ) {
					$this->assertDoesNotMatchRegularExpression( '/\b(19|20)\d{2}\b/', $txt, 'no year: ' . $txt );
					$this->assertDoesNotMatchRegularExpression( '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', $txt, 'no emoji: ' . $txt );
					$this->assertDoesNotMatchRegularExpression( '/[€$]|\d+\s?%/u', $txt, 'no prices: ' . $txt );
					$this->assertSame( trim( $txt ), $txt );
				}
				$titulos[] = $v[0];
			}
			$this->assertSame( count( $titulos ), count( array_unique( $titulos ) ), $k );
		}
	}

	public function test_svgs_are_small_and_text_free(): void {
		foreach ( glob( dirname( __DIR__ ) . '/' . ANPA_Socios_Colab_Calendario::DIR_IMAXES . '*.svg' ) as $f ) {
			$s = (string) file_get_contents( $f );
			$this->assertLessThan( 12000, strlen( $s ), basename( $f ) );
			$this->assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630"', $s );
			foreach ( array( '<text', '<image', '<script', 'href=', '<foreignObject' ) as $prohibido ) {
				$this->assertStringNotContainsString( $prohibido, $s, basename( $f ) );
			}
		}
	}

	public function test_post_text_comes_from_the_calendar_and_varies_weekly(): void {
		$p = ANPA_Socios_Colab_Calendario::para( '2027-08-05' );
		$this->assertSame( 'agosto', $p['clave'] );
		$this->assertSame( 'assets/img/colab/agosto.png', $p['imaxe'] );
		$q = ANPA_Socios_Colab_Calendario::para( '2027-08-12' );
		$this->assertSame( 'agosto', $q['clave'] );
		$this->assertNotSame( $p['titulo'], $q['titulo'], 'consecutive weeks differ' );
		$this->assertSame( $p, ANPA_Socios_Colab_Calendario::para( '2027-08-05' ), 'deterministic' );
		$this->assertSame( 'nadal', ANPA_Socios_Colab_Calendario::para( '2027-12-23' )['clave'] );
	}

	public function test_next_batch_across_dst_and_when_the_hour_passed(): void {
		$tz = $this->madrid();
		// Plain week (CET).
		$n = ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-22 10:00' ), 4, 16, 0, $tz );
		$this->assertSame( '2027-03-25 16:00', $this->local( $n ) );
		$this->assertSame( 15, (int) gmdate( 'G', $n ) );
		// Same Thursday, hour not yet reached / exactly / passed.
		$this->assertSame( '2027-03-25 16:00', $this->local( ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-25 15:59' ), 4, 16, 0, $tz ) ) );
		$this->assertSame( '2027-04-01 16:00', $this->local( ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-25 16:00' ), 4, 16, 0, $tz ) ) );
		$this->assertSame( '2027-04-01 16:00', $this->local( ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-25 16:30' ), 4, 16, 0, $tz ) ) );
		// Spring forward (28 March 2027): still 16:00 local, now 14:00 UTC.
		$n = ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-26 09:00' ), 4, 16, 0, $tz );
		$this->assertSame( '2027-04-01 16:00', $this->local( $n ) );
		$this->assertSame( 14, (int) gmdate( 'G', $n ) );
		// Fall back (31 October 2027).
		$n = ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-10-29 09:00' ), 4, 16, 0, $tz );
		$this->assertSame( '2027-11-04 16:00', $this->local( $n ) );
		$this->assertSame( 15, (int) gmdate( 'G', $n ) );
		// Other weekday and minutes; Sunday batch seen from a Sunday evening.
		$this->assertSame( '2027-03-29 09:30', $this->local( ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-26 09:00' ), 1, 9, 30, $tz ) ) );
		$this->assertSame( '2027-04-04 08:00', $this->local( ANPA_Socios_Colab_Calendario::seguinte_saida( $this->ts( '2027-03-28 20:00' ), 7, 8, 0, $tz ) ) );
		// Last moment.
		$this->assertSame( '2027-03-25 16:00', $this->local( ANPA_Socios_Colab_Calendario::ultima_saida( $this->ts( '2027-03-31 12:00' ), 4, 16, 0, $tz ) ) );
		$this->assertSame( '2027-04-01 16:00', $this->local( ANPA_Socios_Colab_Calendario::ultima_saida( $this->ts( '2027-04-01 16:00' ), 4, 16, 0, $tz ) ) );
		$this->assertSame( 'o xoves 1 ás 16:00', ANPA_Socios_Colab_Calendario::texto_saida( $this->ts( '2027-04-01 16:00' ), $tz ) );
	}

	public function test_batch_fires_once_per_week_on_hourly_ticks(): void {
		$tz    = $this->madrid();
		$agora = $this->ts( '2027-03-15 00:05' );
		$feito = ANPA_Socios_Colab_Calendario::ultima_saida( $agora, 4, 16, 0, $tz ); // primed like a new site.
		$fired = array();
		for ( $t = $agora; $t < $this->ts( '2027-11-15 00:00' ); $t += 3600 ) {
			$toca = ANPA_Socios_Colab_Calendario::lote_pendente( $t, $feito, 4, 16, 0, $tz );
			if ( $toca > 0 ) {
				$feito   = $toca; // recorded before the work.
				$fired[] = $this->local( $t );
				$this->assertSame( 0, ANPA_Socios_Colab_Calendario::lote_pendente( $t, $feito, 4, 16, 0, $tz ), 'never twice' );
				$this->assertSame( 0, ANPA_Socios_Colab_Calendario::lote_pendente( $t + 1800, $feito, 4, 16, 0, $tz ) );
			}
		}
		$this->assertSame( '2027-03-18 16:05', $fired[0] );
		$this->assertContains( '2027-04-01 16:05', $fired, 'after spring forward' );
		$this->assertContains( '2027-11-04 16:05', $fired, 'after fall back' );
		$semanas = array();
		foreach ( $fired as $f ) {
			$this->assertStringEndsWith( '16:05', $f );
			$this->assertSame( '4', ( new DateTimeImmutable( $f, $tz ) )->format( 'N' ) );
			$semanas[] = ( new DateTimeImmutable( $f, $tz ) )->format( 'o-W' );
		}
		$this->assertSame( count( $semanas ), count( array_unique( $semanas ) ) );
		$this->assertCount( 35, $fired );

		// A site down for days runs the missed batch only once.
		$feito = $this->ts( '2027-03-18 16:00' );
		$this->assertSame( $this->ts( '2027-04-01 16:00' ), ANPA_Socios_Colab_Calendario::lote_pendente( $this->ts( '2027-04-03 10:00' ), $feito, 4, 16, 0, $tz ) );
		// Moving the weekday to the next day does not publish twice in two days.
		$this->assertSame( 0, ANPA_Socios_Colab_Calendario::lote_pendente( $this->ts( '2027-03-19 17:00' ), $feito, 5, 16, 0, $tz ) );
	}

	public function test_words_for_validity_and_uses(): void {
		$this->assertSame( 'Sen data de fin (ata que o establecemento a retire)', ANPA_Socios_Colab_Calendario::vixencia( '', 2027 ) );
		$this->assertSame( 'Sen data de fin (ata que o establecemento a retire)', ANPA_Socios_Colab_Calendario::vixencia( '0000-00-00', 2027 ) );
		$this->assertSame( 'Válida ata o 30 de xuño', ANPA_Socios_Colab_Calendario::vixencia( '2027-06-30', 2027 ) );
		$this->assertSame( 'Válida ata o 6 de xaneiro de 2028', ANPA_Socios_Colab_Calendario::vixencia( '2028-01-06', 2027 ) );
		$this->assertSame( 'Un uso por familia', ANPA_Socios_Colab_Calendario::usos_texto( 1 ) );
		$this->assertSame( '3 usos por familia', ANPA_Socios_Colab_Calendario::usos_texto( 3 ) );
		$this->assertSame( 'Sen límite de usos por familia', ANPA_Socios_Colab_Calendario::usos_texto( 0 ) );
	}

	public function test_post_content_is_escaped_and_ends_with_telegram(): void {
		$grupos = array(
			array(
				'comercio' => array( 'nome' => 'Tenda <b>A</b>', 'url_web' => 'javascript:alert(1)', 'enderezo' => 'Rúa 1', 'logo_url' => 'https://example.com/logo.png' ),
				'ofertas'  => array(
					array( 'titulo' => 'Desconto "xoves"', 'desconto' => '10 %', 'descricion' => "Liña 1\nLiña 2", 'condicions' => '', 'valida_ata' => '', 'acumulable' => 1, 'usos_familia' => 0, 'imaxe_url' => '' ),
					array( 'titulo' => 'Outra', 'desconto' => '2x1', 'descricion' => '<script>x</script>', 'condicions' => 'Só os sábados', 'valida_ata' => '2027-06-30', 'imaxe_url' => 'data:image/png;base64,AAAA' ),
				),
			),
			array(
				'comercio' => array( 'nome' => 'Libraría', 'url_web' => 'https://libraria.example/' ),
				'ofertas'  => array( array( 'titulo' => 'Libros', 'desconto' => '5 %' ) ),
			),
		);
		$cal  = array( 'clave' => 'xuno', 'titulo' => 'T', 'intro' => 'Intro & máis' );
		$html = ANPA_Socios_Colab_Publicacion::contido_html( $grupos, $cal, 2027, 'https://t.me/canle', false, '' );
		$this->assertStringStartsWith( '<p>Intro &amp; máis</p>', $html );
		$this->assertStringContainsString( 'Tenda &lt;b&gt;A&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'data:image', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'href="https://libraria.example/"', $html );
		$this->assertStringContainsString( 'src="https://example.com/logo.png"', $html, 'offer without image shows the logo' );
		$this->assertStringContainsString( 'Liña 1<br>', $html );
		$this->assertStringContainsString( 'Acumulable con outras ofertas', $html );
		$this->assertStringContainsString( 'Non acumulable con outras ofertas', $html );
		$this->assertStringContainsString( 'Sen límite de usos por familia', $html );
		$this->assertStringContainsString( 'Un uso por familia', $html, 'default 1 use' );
		$this->assertStringContainsString( 'Válida ata o 30 de xuño', $html );
		$this->assertStringContainsString( 'para non perder ningunha oferta', $html );
		$this->assertStringEndsWith( '<a href="https://t.me/canle" rel="noopener">https://t.me/canle</a></p>', $html );
		$this->assertStringNotContainsString( 'O meu carné de socio/a', $html, 'the opt-out line is only in the email' );

		$sen = ANPA_Socios_Colab_Publicacion::contido_html( $grupos, $cal, 2027, '', true, 'https://example.com/area/' );
		$this->assertStringNotContainsString( 'Telegram', $sen, 'no link sentence without a channel' );
		$this->assertStringContainsString( '«O meu carné de socio/a»', $sen );
		$this->assertStringContainsString( 'href="https://example.com/area/"', $sen );
	}

	public function test_url_and_time_cleaning(): void {
		$this->assertSame( 'https://t.me/x', ANPA_Socios_Colab_Publicacion::url_segura( ' https://t.me/x ' ) );
		foreach ( array( 'javascript:alert(1)', 'ftp://x.example', '//x.example', 'https://', 'http://a b', 'data:text/html,x' ) as $mal ) {
			$this->assertSame( '', ANPA_Socios_Colab_Publicacion::url_segura( $mal ), $mal );
		}
		$this->assertSame( '09:05', ANPA_Socios_Colab_Publicacion::limpar_hora( '9:05' ) );
		$this->assertSame( '', ANPA_Socios_Colab_Publicacion::limpar_hora( '24:00' ) );
		$this->assertSame( '', ANPA_Socios_Colab_Publicacion::limpar_hora( '16:0' ) );
	}

	public function test_handlers_check_capability_and_nonce(): void {
		$s = $this->src( 'includes/class-anpa-socios-colab-publicacion.php' );
		$this->assertStringContainsString( "const CAP = 'manage_options';", $s );
		$this->assertMatchesRegularExpression( '/function gardia\( string \$accion \): void \{\s*if \( ! current_user_can\( self::CAP \) \) \{\s*wp_die\([^\n]+\s*\}\s*check_admin_referer\( \$accion \);/', $s );
		$this->assertMatchesRegularExpression( '/function handle_axustes\(\): void \{\s*self::gardia\( self::ACCION_AXUSTES \);/', $s );
		$this->assertMatchesRegularExpression( '/function handle_publicar_agora\(\): void \{\s*self::gardia\( self::ACCION_AGORA \);/', $s );
		$this->assertStringContainsString( 'wp_nonce_field( self::ACCION_AXUSTES );', $s );
		$this->assertStringContainsString( 'wp_nonce_field( self::ACCION_AGORA );', $s );
		$this->assertStringContainsString( "admin_url( 'admin.php?page=anpa-socios-colab' )", $s );
		$this->assertStringContainsString( "add_action( 'anpa_colab_admin_seccions'", $s );
		$this->assertStringNotContainsString( 'innerHTML', $this->src( 'assets/js/colab-publicacion-admin.js' ) );
	}

	public function test_post_never_goes_to_novas_and_batch_is_recorded_first(): void {
		$s = $this->src( 'includes/class-anpa-socios-colab-publicacion.php' );
		$this->assertStringContainsString( "const SLUG_PROHIBIDO = 'novas';", $s );
		$this->assertStringContainsString( "const TERMO_SLUG = 'ofertas-socios';", $s );
		$this->assertStringContainsString( "'post_category' => array( \$termo ),", $s );
		$this->assertStringContainsString( 'wp_set_post_categories( $post, array( $termo ), false );', $s );
		$this->assertStringNotContainsString( 'default_category', $s );
		$this->assertMatchesRegularExpression( '/if \( \$termo <= 0 \) \{\s*\$res\[\'motivo\'\] = \'sen_categoria\';\s*return/', $s, 'no category, no post (WordPress would use the default one)' );
		$this->assertMatchesRegularExpression( '/\$t instanceof WP_Term && ! self::e_novas\( \$t \)/', $s );
		$this->assertMatchesRegularExpression( '/self::e_novas\( \$t \) \) \{\s*\$termo = 0;/', $s, 'the settings never store Novas' );

		$ini  = strpos( $s, 'function executar_se_toca' );
		$fin  = strpos( $s, 'function publicar_agora' );
		$body = substr( $s, $ini, $fin - $ini );
		$rec  = strpos( $body, 'update_option( self::OPT_FEITO, $toca, false );' );
		$run  = strpos( $body, 'self::publicar( $agora, \'semanal\' );' );
		$this->assertNotFalse( $rec );
		$this->assertNotFalse( $run );
		$this->assertLessThan( $run, $rec, 'the batch is recorded before the work' );
		$this->assertStringContainsString( "method_exists( 'ANPA_Socios_Colab_Service', 'sacar_aprobadas' )", $s );
		// The weekly email skips the families that turned it off.
		$this->assertStringContainsString( "'anpa_colab_familias'", $s );
		$this->assertStringContainsString( 'f.sen_correo = %d', $s );
		$this->assertStringContainsString( 'SHOW TABLES LIKE %s', $s );
	}
}
