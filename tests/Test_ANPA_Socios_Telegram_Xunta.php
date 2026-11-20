<?php
/**
 * 1.90.0: Telegram copy of the emails to the junta (Axustes → Xeral →
 * Configuración). Pure rules and formatting, plus source contracts for the
 * hooks in ANPA_Socios_Email and the settings form.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-telegram.php';

final class Test_ANPA_Socios_Telegram_Xunta extends TestCase {

	private static function src( string $rel ): string {
		return (string) file_get_contents( __DIR__ . '/../' . $rel );
	}

	public function test_token_validation_and_mask(): void {
		$token = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';
		$this->assertTrue( ANPA_Socios_Telegram::token_valido( $token ) );
		$this->assertTrue( ANPA_Socios_Telegram::token_valido( '  ' . $token . "\n" ) );
		$this->assertFalse( ANPA_Socios_Telegram::token_valido( '' ) );
		$this->assertFalse( ANPA_Socios_Telegram::token_valido( 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw' ) );
		$this->assertFalse( ANPA_Socios_Telegram::token_valido( '123456789:curto' ) );
		$this->assertFalse( ANPA_Socios_Telegram::token_valido( '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/getMe' ) );

		$this->assertSame( '123456789:…Dsaw', ANPA_Socios_Telegram::token_mascarado( $token ) );
		$this->assertStringNotContainsString( 'AAHdqTcv', ANPA_Socios_Telegram::token_mascarado( $token ) );
		$this->assertSame( '', ANPA_Socios_Telegram::token_mascarado( '' ) );
	}

	public function test_chat_id_accepts_numbers_and_public_channels_only(): void {
		$this->assertSame( '-1001234567890', ANPA_Socios_Telegram::chat_id( ' -1001234567890 ' ) );
		$this->assertSame( '987654321', ANPA_Socios_Telegram::chat_id( '987654321' ) );
		$this->assertSame( '@anpa_avisos', ANPA_Socios_Telegram::chat_id( '@anpa_avisos' ) );
		$this->assertSame( '', ANPA_Socios_Telegram::chat_id( '' ) );
		$this->assertSame( '', ANPA_Socios_Telegram::chat_id( '@ab' ) );
		$this->assertSame( '', ANPA_Socios_Telegram::chat_id( 'https://t.me/anpa' ) );
		$this->assertSame( '', ANPA_Socios_Telegram::chat_id( '12 34' ) );
	}

	public function test_api_url(): void {
		$this->assertSame( 'https://api.telegram.org/bot1:abc/sendMessage', ANPA_Socios_Telegram::api_url( ' 1:abc ', 'sendMessage' ) );
	}

	public function test_text_has_association_title_line_and_link(): void {
		$texto = ANPA_Socios_Telegram::texto( 'ANPA Exemplo', 'Solicitude de baixa de socio/a', 'Revísaa en Aprobacións.', 'https://exemplo.org/wp-admin/admin.php?page=x' );
		$this->assertSame( "📬 ANPA Exemplo · Solicitude de baixa de socio/a\nRevísaa en Aprobacións.\nhttps://exemplo.org/wp-admin/admin.php?page=x", $texto );
		$this->assertSame( '📬 Título', ANPA_Socios_Telegram::texto( '', 'Título' ) );
		$this->assertLessThanOrEqual( ANPA_Socios_Telegram::MAX_TEXTO, mb_strlen( ANPA_Socios_Telegram::texto( 'A', 'B', str_repeat( 'x', 5000 ) ) ) );
	}

	public function test_mass_summary_names_the_template_never_the_subject_or_addresses(): void {
		$linea = ANPA_Socios_Telegram::resumo_masivo( 'matricula_alta_aviso', array( 'lotes' => 1, 'enviados' => 1, 'fallidos' => 0 ) );
		$this->assertSame( '«Aviso á empresa/comedor: alta nun grupo» enviado en copia oculta a 1 enderezos.', $linea );
		$linea = ANPA_Socios_Telegram::resumo_masivo( 'prazo_matriculas', array( 'lotes' => 3, 'enviados' => 100, 'fallidos' => 20 ) );
		$this->assertSame( '«Prazo de matrículas» enviado en copia oculta a 100 enderezos. Non se puido enviar a 20.', $linea );
		$this->assertSame( 'plantilla_nova', ANPA_Socios_Telegram::titulo_masivo( 'plantilla_nova' ) );
	}

	public function test_every_junta_notice_is_routed_by_its_catalogue_id(): void {
		$email = self::src( 'includes/class-anpa-socios-email.php' );
		$this->assertStringNotContainsString( 'TIPO_AVISO', $email );
		$this->assertStringNotContainsString( 'TIPO_OUTRO', $email );
		$ids = array( 'ALTA_SOCIO', 'REACTIVACION_SOCIO', 'BAIXA_SOCIO', 'BAIXA_EXTRAESCOLAR', 'RESUMO_SEMANAL', 'INICIO_CURSO' );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/self::telegram\(\s*ANPA_Socios_Avisos_Xunta::' . $id . ',/', $email, $id );
		}
		$this->assertSame( 3, substr_count( $email, 'self::telegram( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA,' ), 'baixa comunicada pola empresa: rexistrada, efectiva, anulada' );
		$this->assertSame( 2, substr_count( $email, 'self::telegram( $aviso_id,' ), 'nova inscrición e oferta aceptada' );
		// Every email to the junta inbox asks its notice first (the fixed ones excepted).
		foreach ( array( 'ALTA_SOCIO', 'REACTIVACION_SOCIO', 'BAIXA_SOCIO', 'BAIXA_EXTRAESCOLAR', 'RESUMO_SEMANAL' ) as $id ) {
			$this->assertStringContainsString( '$ok = self::correo_xunta( ANPA_Socios_Avisos_Xunta::' . $id . ' ) && self::send_', $email, $id );
		}
		$this->assertSame( 2, substr_count( $email, 'self::correo_xunta( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA )' ), 'copia oculta á xunta da baixa programada e efectiva' );
		$this->assertStringContainsString( 'ANPA_Socios_Telegram::resumo_masivo( $template_id, $resumo )', $email );
		$this->assertStringContainsString( 'self::telegram( ANPA_Socios_Avisos_Xunta::id_masivo( $template_id ),', $email );
		// The mass summary goes once per send, after the batch loop.
		$this->assertMatchesRegularExpression( '/foreach \( \$lotes as \$lote \) \{.*?\n\t\t\}\n\t\t\$resumo = ANPA_Socios_Envio_Masivo::resumo\( \$resultados \);\n.*?self::telegram\(/s', $email );
		// The Telegram helper swallows every error.
		$this->assertMatchesRegularExpression( '/private static function telegram\(.*?try \{\s*ANPA_Socios_Telegram_Xunta::avisar\(.*?catch \( \\\\Throwable \$e \)/s', $email );
	}

	public function test_pending_enrolments_reach_the_junta(): void {
		// 1.92.0 bug: a request pending approval and an accepted offer never told the junta.
		$rest = self::src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertMatchesRegularExpression( '/enviar_matricula_pendente\( \$to, \$alumno, \$actividade, \$grupo_lbl, \$frase \);\n\t\t\t\}\n.*?ANPA_Socios_Email::enviar_aviso_matricula_pendente_xunta\( \$email, \$alumno, \$actividade, \$grupo_lbl, false, \$grupo_id \);/s', $rest );
		$this->assertMatchesRegularExpression( '/\'oferta_aceptada\' \);.*?ANPA_Socios_Email::enviar_aviso_matricula_pendente_xunta\(\s*self::current_email\( \$request \),.*?true,\s*\$grupo_id\s*\);\s*return new WP_REST_Response/s', $rest );
		$email = self::src( 'includes/class-anpa-socios-email.php' );
		$this->assertStringContainsString( '$de_oferta ? \'oferta_aceptada_xunta\' : \'matricula_pendente_xunta\'', $email );
	}

	public function test_telegram_never_gets_family_data(): void {
		$email = self::src( 'includes/class-anpa-socios-email.php' );
		preg_match_all( '/self::telegram\((.*?)\);\n/s', $email, $m );
		$this->assertNotEmpty( $m[1] );
		foreach ( $m[1] as $args ) {
			foreach ( array( '$email_socio', '$nome', '$apelidos', '$alumno', '$subject', "content['subject']", '$lote' ) as $dato ) {
				$this->assertStringNotContainsString( $dato, $args );
			}
		}
	}

	public function test_settings_form_never_prints_the_token_and_validates_input(): void {
		$s = self::src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'name="telegram_bot_token" id="cfg-telegram-token" type="password" class="regular-text" value=""', $s );
		$this->assertStringContainsString( 'name="telegram_chat_id"', $s );
		$this->assertStringNotContainsString( 'name="telegram_modo"', $s, '1.92.0: replaced by the notices table' );
		$this->assertStringContainsString( 'name="avisos_xunta[%1$s][telegram]"', $s );
		$this->assertStringContainsString( 'name="avisos_xunta[%1$s][correo]"', $s );
		$this->assertStringContainsString( 'ANPA_Socios_Avisos_Xunta::desde_formulario( $raw )', $s );
		$this->assertStringContainsString( "add_action( 'admin_post_anpa_socios_telegram_proba', array( __CLASS__, 'handle_telegram_proba' ) );", $s );
		$this->assertStringContainsString( "self::guard( 'anpa_socios_telegram_proba' );", $s );
		$this->assertStringContainsString( 'ANPA_Socios_Telegram::token_valido( $token )', $s );
		$this->assertStringContainsString( "update_option( ANPA_Socios_Config::OPTION_TELEGRAM_TOKEN, \$token, false );", $s, 'not autoloaded' );
		$this->assertStringContainsString( 'ANPA_Socios_Telegram::chat_id( $raw )', $s );
	}

	public function test_token_resolution_reuses_the_site_bot(): void {
		$cfg = self::src( 'includes/class-anpa-socios-config.php' );
		$this->assertStringContainsString( "const OPTION_TELEGRAM_TOKEN = 'anpa_socios_telegram_bot_token';", $cfg );
		$this->assertStringContainsString( "const TELEGRAM_TOKEN_CONSTANTS = array( 'ANPA_SOCIOS_TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_TOKEN' );", $cfg );
		$this->assertStringContainsString( "apply_filters( 'anpa_socios_telegram_bot_token', '' )", $cfg );
		$sender = self::src( 'includes/class-anpa-socios-telegram-xunta.php' );
		$this->assertStringNotContainsString( "'blocking'", $sender, '1.92.0: wait for the answer (at shutdown) so a failure is recorded' );
		$this->assertStringContainsString( "add_action( 'shutdown', array( __CLASS__, 'baleirar_cola' ), 100 );", $sender );
		$this->assertStringContainsString( 'ANPA_Socios_Config::aviso_xunta( $aviso_id, ANPA_Socios_Avisos_Xunta::TELEGRAM )', $sender );
		$this->assertStringNotContainsString( 'get_error_message', $sender, 'the transport error may quote the token URL' );
		$main = self::src( 'anpa-socios.php' );
		$this->assertStringContainsString( "includes/lib/class-anpa-socios-telegram.php'", $main );
		$this->assertStringContainsString( "includes/lib/class-anpa-socios-avisos-xunta.php'", $main );
		$this->assertStringContainsString( "includes/class-anpa-socios-telegram-xunta.php'", $main );
	}
}
