<?php
/**
 * Contract tests for Mejora 2: enriched first-run setup wizard.
 *
 * The clean-install wizard now also captures the association identity +
 * localization, activates the course with an open-matrículas choice, and seeds
 * a default school structure. Source-inspection style (admin/DB glue).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Setup_Wizard extends TestCase {

	private string $settings;
	private string $estrutura;

	public function setUp(): void {
		parent::setUp();
		$root            = dirname( __DIR__ );
		$this->settings  = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
		$this->estrutura = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-estrutura-handler.php' );
	}

	public function test_wizard_form_collects_identity_localization_and_structure(): void {
		foreach ( array(
			'name="association_name"', 'name="membership_fee"', 'name="contact_email"',
			'name="association_address"', 'name="menu_name"', 'name="require_approval"',
			'name="master_email"', 'name="email_signature"',
			'name="country"', 'name="default_province"', 'name="default_town"', 'name="default_postal_code"',
			'name="abrir_matriculas"', 'name="seed_structure"', 'name="niveis[',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $this->settings, $needle );
		}
	}

	public function test_wizard_processing_saves_config_activates_course_and_seeds(): void {
		$start  = strpos( $this->settings, 'private static function process_setup_inline' );
		$end    = strpos( $this->settings, 'private static function render_setup_result', $start );
		$method = substr( $this->settings, $start, $end - $start );

		$this->assertStringContainsString( 'ANPA_Socios_Config::OPTION_ASSOCIATION', $method );
		$this->assertStringContainsString( 'ANPA_Socios_Config::OPTION_POSTAL_CODE', $method );
		$this->assertStringContainsString( 'ANPA_Socios_Config::OPTION_SIGNATURE', $method );
		$this->assertStringContainsString( "update_option( ANPA_Socios_Config::OPTION, strtolower(", $method ); // master email.
		$this->assertStringContainsString( 'ANPA_Socios_Season::ESTADO_ACTIVO', $method );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Cursos_Handler::update_curso', $method );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Estrutura_Handler::seed_default_structure', $method );
	}

	public function test_seed_default_structure_is_transactional_and_idempotent(): void {
		$this->assertStringContainsString( 'public static function seed_default_structure', $this->estrutura );
		$start  = strpos( $this->estrutura, 'public static function seed_default_structure' );
		$body   = substr( $this->estrutura, $start, 3400 );
		$this->assertStringContainsString( 'SELECT COUNT(*) FROM', $body ); // empty-catalogue guard.
		$this->assertStringContainsString( '$existing > 0', $body );        // skip seeding if levels exist.
		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $body );
		$this->assertStringContainsString( 'sync_aulas_nivel', $body );
		$this->assertStringContainsString( 'SELECT id FROM', $body ); // idempotent skip by codigo.
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
		$this->assertStringContainsString( "query( 'COMMIT' )", $body );
	}
}
