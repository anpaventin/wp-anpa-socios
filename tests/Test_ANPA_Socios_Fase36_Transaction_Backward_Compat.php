<?php
/**
 * TDD: FASE36 Microfase 5.3 — Transactional backward compatibility tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-template-migration.php';

final class Test_ANPA_Socios_Fase36_Transaction_Backward_Compat extends TestCase {

	/** @var \ReflectionMethod */
	private static $render_fn;

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'anpa_socios_email_templates' );

		if ( null === self::$render_fn ) {
			$ref = new \ReflectionMethod( 'ANPA_Socios_Email', 'render_with_template' );
			$ref->setAccessible( true );
			self::$render_fn = $ref;
		}
	}

	private function call_render( string $template_id, array $context, callable $fallback ): array {
		return self::$render_fn->invoke( null, $template_id, $context, $fallback );
	}

	// =========================================================================
	// 1. enviar_codigo → verification_code
	// =========================================================================

	public function test_enviar_codigo_template_ref(): void {
		$content = $this->call_render(
			'verification_code',
			array( 'association_name' => 'ANPA', 'nome' => 'test@example.com', 'codigo' => '123456' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
		$this->assertArrayHasKey( 'subject', $content );
		$this->assertArrayHasKey( 'html', $content );
	}

	public function test_enviar_codigo_fallback_uses_hardcoded(): void {
		$content = $this->call_render(
			'verification_code',
			array( 'association_name' => 'ANPA', 'nome' => 'test@example.com', 'codigo' => '123456' ),
			function () { return array( 'subject' => 'HARDCODED', 'html' => '<p>HARDCODED</p>', 'text' => 'HARDCODED' ); }
		);
		$this->assertSame( 'HARDCODED', $content['subject'] );
	}

	public function test_enviar_codigo_custom_overrides(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) { return true; }
		}
		ANPA_Socios_Email_Template_Store::save( 'verification_code', 'CUSTOM', '<p>CUSTOM</p>', 'CUSTOM' );

		$content = $this->call_render(
			'verification_code',
			array( 'association_name' => 'ANPA', 'nome' => 'test@example.com', 'codigo' => '123456' ),
			function () { return array( 'subject' => 'HARDCODED', 'html' => '<p>HARDCODED</p>', 'text' => 'HARDCODED' ); }
		);
		$this->assertSame( 'CUSTOM', $content['subject'] );
	}

	// =========================================================================
	// 2-9. Template reference mapping
	// =========================================================================

	public function test_baixa_socio_template_ref(): void {
		$content = $this->call_render(
			'baixa_socio',
			array( 'association_name' => 'ANPA', 'nome' => 'Xoan', 'apelidos' => 'Pérez', 'email_socio' => 'test@example.com' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_reactivacion_template_ref(): void {
		$content = $this->call_render(
			'reactivacion',
			array( 'association_name' => 'ANPA', 'email_socio' => 'test@example.com' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_baixa_extraescolar_template_ref(): void {
		$content = $this->call_render(
			'baixa_extraescolar',
			array( 'association_name' => 'ANPA', 'alumno' => 'María', 'actividade' => 'Xadrez', 'email_socio' => 'test@example.com' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_oferta_extraescolar_template_ref(): void {
		$content = $this->call_render(
			'oferta_extraescolar',
			array( 'association_name' => 'ANPA', 'actividade' => 'Fútbol', 'dias_prazo' => 5 ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_pendente_aprobacion_template_ref(): void {
		$content = $this->call_render(
			'pendente_aprobacion',
			array( 'association_name' => 'ANPA', 'nome' => 'Lucía', 'email_socio' => 'test@example.com', 'login_url' => 'http://example.org' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_aprobacion_template_ref(): void {
		$content = $this->call_render(
			'aprobacion',
			array( 'association_name' => 'ANPA', 'login_url' => 'http://example.org/login' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_benvida_alta_template_ref(): void {
		$content = $this->call_render(
			'benvida_alta',
			array( 'association_name' => 'ANPA', 'login_url' => 'http://example.org/login' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	public function test_rexeitamento_template_ref(): void {
		$content = $this->call_render(
			'rexeitamento',
			array( 'association_name' => 'ANPA', 'contact_email' => 'contact@example.com' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	// =========================================================================
	// 10. send_from_master → verify treatment
	// =========================================================================

	public function test_send_from_master_is_helper(): void {
		$reflection = new \ReflectionClass( 'ANPA_Socios_Email' );
		$this->assertTrue(
			$reflection->hasMethod( 'send_from_master' ),
			'send_from_master must exist as private helper'
		);
		$method = $reflection->getMethod( 'send_from_master' );
		$this->assertTrue( $method->isPrivate(), 'send_from_master must be private' );
	}

	// =========================================================================
	// Template reference mapping
	// =========================================================================

	public function test_all_template_ids_mapped(): void {
		$template_ids = array(
			'verification_code', 'baixa_socio', 'reactivacion',
			'baixa_extraescolar', 'oferta_extraescolar', 'pendente_aprobacion',
			'aprobacion', 'benvida_alta', 'rexeitamento', 'send_from_master',
		);

		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		foreach ( $template_ids as $id ) {
			$this->assertArrayHasKey( $id, $defaults, "Template ID '$id' must exist in defaults" );
		}
	}

	// =========================================================================
	// Dynamic variables
	// =========================================================================

	public function test_dynamic_variables_pass_correctly(): void {
		$content = $this->call_render(
			'verification_code',
			array( 'association_name' => 'ANPA As Brañas', 'nome' => 'Xoan', 'codigo' => '654321' ),
			function () { return array( 'subject' => 'S', 'html' => '<p>H</p>', 'text' => 'H' ); }
		);
		$this->assertIsArray( $content );
	}

	// =========================================================================
	// Legacy fallback
	// =========================================================================

	public function test_legacy_fallback_without_customization(): void {
		delete_option( 'anpa_socios_email_templates' );

		$content = $this->call_render(
			'verification_code',
			array( 'association_name' => 'ANPA', 'nome' => 'test@example.com', 'codigo' => '123456' ),
			function () { return array( 'subject' => 'LEGACY', 'html' => '<p>LEGACY</p>', 'text' => 'LEGACY' ); }
		);
		$this->assertSame( 'LEGACY', $content['subject'], 'Legacy fallback must be used when no customization exists' );
	}

	public function test_legacy_fallback_with_migration_defaults(): void {
		ANPA_Socios_Email_Template_Migration::seed_if_needed();

		$content = $this->call_render(
			'verification_code',
			array( 'association_name' => 'ANPA', 'nome' => 'test@example.com', 'codigo' => '123456' ),
			function () { return array( 'subject' => 'LEGACY', 'html' => '<p>LEGACY</p>', 'text' => 'LEGACY' ); }
		);
		$this->assertNotSame( 'LEGACY', $content['subject'], 'Migration seeds defaults that override hardcoded fallback' );
	}
}
