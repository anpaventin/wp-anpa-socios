<?php
/**
 * Integration suite pinning email headers per emitter (fase36, PR-36s3).
 *
 * For each of the nine emitters:
 *   - exact recipient
 *   - exact From and Reply-To
 *   - ABSENCE of Cc and Bcc
 *   - Content-Type: text/html via the wp_mail_content_type filter
 *   - no CRLF in any header value (header injection prevention)
 *   - no real addresses anywhere
 *
 * Uses `pre_wp_mail` to capture; nothing is ever sent.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Headers extends TestCase {

	private const ASSOCIATION = 'ANPA Exemplo';
	private const MASTER      = 'xunta@example.org';
	private const CONTACT     = 'contacto@example.org';
	private const SIGNATURE   = "A Xunta Directiva\nANPA Exemplo";

	/** @var array<int,array<string,mixed>> */
	private array $sent = array();

	/** @var callable|null */
	private $transport = null;

	/** @var string|null Content type captured from the filter. */
	private ?string $content_type = null;

	private static function has_wordpress(): bool {
		return defined( 'ANPA_SOCIOS_IT_DB' ) && function_exists( 'update_option' );
	}

	private function require_wordpress(): void {
		if ( ! self::has_wordpress() ) {
			$this->markTestSkipped( 'Header tests need WordPress (run phpunit-integration.xml).' );
		}
	}

	protected function setUp(): void {
		if ( ! self::has_wordpress() ) {
			return;
		}

		update_option( ANPA_Socios_Config::OPTION_ASSOCIATION, self::ASSOCIATION );
		update_option( ANPA_Socios_Config::OPTION, self::MASTER );
		update_option( ANPA_Socios_Config::OPTION_CONTACT_EMAIL, self::CONTACT );
		update_option( ANPA_Socios_Config::OPTION_SIGNATURE, self::SIGNATURE );

		$this->sent         = array();
		$this->content_type = null;

		$this->transport = function ( $short_circuit, $atts ) {
			unset( $short_circuit );
			$this->sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->transport, 10, 2 );

		// Capture the content type from the filter WordPress calls.
		add_filter( 'wp_mail_content_type', function ( string $type ): string {
			$this->content_type = $type;
			return $type;
		}, 99 );
	}

	protected function tearDown(): void {
		if ( null !== $this->transport ) {
			remove_filter( 'pre_wp_mail', $this->transport, 10 );
			$this->transport = null;
		}
		remove_all_filters( 'wp_mail_content_type' );
	}

	/**
	 * Asserts common header properties on the last captured email.
	 */
	private function assert_headers( string $expected_to, int $index = 0 ): void {
		$this->assertArrayHasKey( $index, $this->sent, 'no email captured' );
		$mail = $this->sent[ $index ];

		// Exact recipient.
		$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
		$this->assertSame( $expected_to, $to, 'recipient mismatch' );

		// Headers as array.
		$headers = is_array( $mail['headers'] ) ? $mail['headers'] : array( (string) $mail['headers'] );
		$joined  = implode( "\n", $headers );

		// From and Reply-To present.
		$this->assertStringContainsString( 'From: ' . self::ASSOCIATION . ' <' . self::MASTER . '>', $joined );
		$this->assertStringContainsString( 'Reply-To: ' . self::ASSOCIATION . ' <' . self::MASTER . '>', $joined );

		// Absence of Cc and Bcc.
		$this->assertStringNotContainsString( 'Cc:', $joined );
		$this->assertStringNotContainsString( 'Bcc:', $joined );
		$this->assertStringNotContainsString( 'cc:', strtolower( $joined ) );
		$this->assertStringNotContainsString( 'bcc:', strtolower( $joined ) );

		// No CRLF injection in headers.
		foreach ( $headers as $header ) {
			$this->assertSame( 0, preg_match( '/[\r\n]/', (string) $header ), 'CRLF in header: ' . $header );
		}

		// No CRLF injection in subject.
		$this->assertSame( 0, preg_match( '/[\r\n]/', (string) $mail['subject'] ), 'CRLF in subject' );

		// Content-Type is text/html.
		$this->assertSame( 'text/html', $this->content_type, 'Content-Type must be text/html' );

		// No real addresses.
		$all = $to . ' ' . (string) $mail['subject'] . ' ' . $joined;
		foreach ( array( 'asbranas', 'as brañas', 'ventin', 'ventín', 'anpaventin' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, strtolower( $all ), "real identifier found: {$forbidden}" );
		}
	}

	public function test_enviar_codigo_alta_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_codigo( 'nai@example.com', '123456', 'alta' );
		$this->assert_headers( 'nai@example.com' );
	}

	public function test_enviar_codigo_verificacion_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_codigo( 'nai@example.com', '123456', 'verificacion' );
		$this->assert_headers( 'nai@example.com' );
	}

	public function test_enviar_aviso_baixa_socio_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_baixa_socio( 'nai@example.com', 'Uxía', 'Exemplo' );
		$this->assert_headers( self::MASTER );
	}

	public function test_enviar_aviso_reactivacion_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_reactivacion( 'nai@example.com' );
		$this->assert_headers( self::MASTER );
	}

	public function test_enviar_aviso_baixa_extraescolar_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_baixa_extraescolar( 'nai@example.com', 'Antía', 'Robótica' );
		$this->assert_headers( self::MASTER );
	}

	public function test_enviar_oferta_extraescolar_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_oferta_extraescolar( 'nai@example.com', 'Robótica', 3 );
		$this->assert_headers( 'nai@example.com' );
	}

	public function test_enviar_aviso_pendente_aprobacion_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_pendente_aprobacion( 'nai@example.com', 'Uxía Exemplo' );
		$this->assert_headers( self::MASTER );
	}

	public function test_enviar_aprobacion_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aprobacion( 'nai@example.com', 'https://example.org/area/' );
		$this->assert_headers( 'nai@example.com' );
	}

	public function test_enviar_benvida_alta_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_benvida_alta( 'nai@example.com', 'https://example.org/area/' );
		$this->assert_headers( 'nai@example.com' );
	}

	public function test_enviar_rexeitamento_headers(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_rexeitamento( 'nai@example.com' );
		$this->assert_headers( 'nai@example.com' );
	}
}
