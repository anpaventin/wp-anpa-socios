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

	public function test_mode_defaults_to_only_the_web_notices(): void {
		$this->assertSame( ANPA_Socios_Telegram::MODO_AVISOS, ANPA_Socios_Telegram::modo( '' ) );
		$this->assertSame( ANPA_Socios_Telegram::MODO_AVISOS, ANPA_Socios_Telegram::modo( 'calquera' ) );
		$this->assertSame( ANPA_Socios_Telegram::MODO_AVISOS, ANPA_Socios_Telegram::modo( null ) );
		$this->assertSame( ANPA_Socios_Telegram::MODO_TODO, ANPA_Socios_Telegram::modo( ' todo ' ) );
		$this->assertSame( array( 'avisos', 'todo' ), array_keys( ANPA_Socios_Telegram::modos() ) );
	}

	public function test_notices_always_go_and_the_rest_only_in_mode_todo(): void {
		$this->assertTrue( ANPA_Socios_Telegram::debe_notificar( 'avisos', ANPA_Socios_Telegram::TIPO_AVISO ) );
		$this->assertTrue( ANPA_Socios_Telegram::debe_notificar( 'todo', ANPA_Socios_Telegram::TIPO_AVISO ) );
		$this->assertFalse( ANPA_Socios_Telegram::debe_notificar( 'avisos', ANPA_Socios_Telegram::TIPO_OUTRO ) );
		$this->assertTrue( ANPA_Socios_Telegram::debe_notificar( 'todo', ANPA_Socios_Telegram::TIPO_OUTRO ) );
		$this->assertFalse( ANPA_Socios_Telegram::debe_notificar( 'todo', 'descoñecido' ) );
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

	public function test_every_junta_email_is_copied_to_telegram(): void {
		$email = self::src( 'includes/class-anpa-socios-email.php' );
		$this->assertSame( 7, substr_count( $email, 'self::telegram( ANPA_Socios_Telegram::TIPO_AVISO,' ), 'baixa socio, reactivación, baixa extraescolar, alta pendente e (1.91.0) baixa comunicada pola empresa: rexistrada, efectiva, anulada' );
		$this->assertStringContainsString( "__( 'Revisión semanal de Xestión', 'anpa-socios' )", $email );
		$this->assertStringContainsString( "self::telegram( ANPA_Socios_Telegram::TIPO_OUTRO, __( 'Correo de inicio de curso', 'anpa-socios' )", $email );
		$this->assertStringContainsString( 'ANPA_Socios_Telegram::resumo_masivo( $template_id, $resumo )', $email );
		// The mass summary goes once per send, after the batch loop.
		$this->assertMatchesRegularExpression( '/foreach \( \$lotes as \$lote \) \{.*?\n\t\t\}\n\t\t\$resumo = ANPA_Socios_Envio_Masivo::resumo\( \$resultados \);\n.*?self::telegram\(/s', $email );
		// The Telegram helper swallows every error.
		$this->assertMatchesRegularExpression( '/private static function telegram\(.*?try \{\s*ANPA_Socios_Telegram_Xunta::avisar\(.*?catch \( \\\\Throwable \$e \)/s', $email );
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
		$this->assertStringContainsString( 'name="telegram_modo"', $s );
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
		$this->assertStringContainsString( "'blocking' => \$esperar,", $sender, 'notices are fire-and-forget' );
		$this->assertStringNotContainsString( 'get_error_message', $sender, 'the transport error may quote the token URL' );
		$main = self::src( 'anpa-socios.php' );
		$this->assertStringContainsString( "includes/lib/class-anpa-socios-telegram.php'", $main );
		$this->assertStringContainsString( "includes/class-anpa-socios-telegram-xunta.php'", $main );
	}
}
