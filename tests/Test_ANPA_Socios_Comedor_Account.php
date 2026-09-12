<?php
/**
 * 1.56.0: canteen account — an email set in Axustes logs in through the company flow and sees every
 * active enrolment with the families' options and authorisations.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

final class Test_ANPA_Socios_Comedor_Account extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	protected function tearDown(): void {
		delete_option( ANPA_Socios_Config::OPTION_COMEDOR_EMAIL );
		parent::tearDown();
	}

	public function test_config_reads_the_canteen_email_case_insensitively(): void {
		delete_option( ANPA_Socios_Config::OPTION_COMEDOR_EMAIL );
		$this->assertSame( '', ANPA_Socios_Config::comedor_email() );
		$this->assertFalse( ANPA_Socios_Config::is_comedor_email( 'comedor@example.com' ) );
		$this->assertFalse( ANPA_Socios_Config::is_comedor_email( '' ) );

		update_option( ANPA_Socios_Config::OPTION_COMEDOR_EMAIL, 'Comedor@Example.com' );
		$this->assertSame( 'comedor@example.com', ANPA_Socios_Config::comedor_email() );
		$this->assertTrue( ANPA_Socios_Config::is_comedor_email( ' COMEDOR@example.com ' ) );
		$this->assertFalse( ANPA_Socios_Config::is_comedor_email( 'outra@example.com' ) );
	}

	public function test_canteen_profile_is_a_synthetic_company_with_id_zero(): void {
		$profile = ANPA_Socios_Empresa_REST::comedor_profile( 'Comedor@Example.com' );
		$this->assertSame( 0, $profile['id'] );
		$this->assertSame( 'comedor@example.com', $profile['email'] );
		$this->assertSame( 'activo', $profile['estado'] );
		$this->assertSame( 'comedor', $profile['tipo'] );
		$this->assertTrue( ANPA_Socios_Empresa_REST::is_comedor_profile( $profile ) );
		$this->assertFalse( ANPA_Socios_Empresa_REST::is_comedor_profile( array( 'id' => 4, 'estado' => 'activo' ) ) );
		$this->assertFalse( ANPA_Socios_Empresa_REST::is_comedor_profile( null ) );
		// public_empresa() keeps working on the synthetic profile.
		$this->assertSame( 'Comedor escolar', ANPA_Socios_Empresa_View::public_empresa( $profile )['nome'] );
	}

	public function test_the_canteen_email_is_reserved_in_every_direction(): void {
		update_option( ANPA_Socios_Config::OPTION_COMEDOR_EMAIL, 'comedor@example.com' );
		// No DB needed: the config check runs before any query.
		$this->assertSame( 0, ANPA_Socios_Email_Ownership::empresa_por_email( 'Comedor@Example.com' ) );
		$err = ANPA_Socios_Email_Ownership::conflito_para_empresa( 'comedor@example.com' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'anpa_email_reservado_comedor', $err->get_error_code() );
		$err = ANPA_Socios_Email_Ownership::conflito_para_socio( 'comedor@example.com', 'p1_email' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( ANPA_Socios_Email_Ownership::MSG_EMAIL_DE_COMEDOR, $err->get_error_message() );
		$this->assertArrayHasKey( 'p1_email', $err->get_error_data()['fields'] );
	}

	public function test_login_flow_treats_the_canteen_email_as_a_company(): void {
		$pre = $this->src( 'includes/class-anpa-socios-preflight-rest.php' );
		$this->assertStringContainsString( "if ( ANPA_Socios_Config::is_comedor_email( \$email ) ) {\n\t\t\t\$flags['empresa'] = 'activo';", $pre );

		$rest = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "if ( ANPA_Socios_Config::is_comedor_email( \$email ) ) {\n\t\t\treturn self::comedor_profile( \$email );", $rest );
		$this->assertStringContainsString( "if ( ! \$empresa && ANPA_Socios_Config::is_comedor_email( \$email ) ) {", $rest );
		// Panel: every company (empresa_id 0), active only; export: full active list, never baixas.
		$this->assertStringContainsString( "if ( ( \$empresa_id > 0 || \$comedor ) && null !== \$curso ) {", $rest );
		$this->assertStringContainsString( "WHERE ( %d = 0 OR a.empresa_id = %d )", $rest );
		$this->assertStringContainsString( "rows_panel_empresa( \$empresa_id, \$curso, \$comedor )", $rest );
		$this->assertStringContainsString( "\$todos   = ! \$comedor && ( 'todos' === \$ambito );", $rest );
		$this->assertStringContainsString( "'alumnos-comedor.csv'", $rest );
		$this->assertStringContainsString( "'export_alumnos_comedor'", $rest );
		foreach ( array( 'autorizacion_comedor', 'tarde_transicion', 'tardes_divertidas_continua', 'recollida_autorizada', 'cesion_datos_empresa' ) as $campo ) {
			$this->assertStringContainsString( "'{$campo}'", $rest );
		}
	}

	public function test_export_columns_carry_options_and_the_company_name(): void {
		$empresa = ANPA_Socios_Alumnos_Export::columns_panel_empresa();
		$comedor = ANPA_Socios_Alumnos_Export::columns_panel_comedor();
		foreach ( array( 'autorizacion_comedor', 'tarde_transicion', 'tardes_divertidas_continua', 'recollida_autorizada', 'cesion_datos_empresa', 'socio_email' ) as $c ) {
			$this->assertContains( $c, $empresa );
			$this->assertContains( $c, $comedor );
		}
		$this->assertSame( 'empresa_nome', $comedor[0] );
		$this->assertNotContains( 'empresa_nome', $empresa );
		$this->assertCount( 8, ANPA_Socios_Alumnos_Export::columns( false ), 'legacy contract untouched' );

		$lib = $this->src( 'includes/lib/class-anpa-socios-alumnos-export.php' );
		$this->assertStringContainsString( "'( %d = 0 OR a.empresa_id = %d )'", $lib );
		$this->assertStringContainsString( "LEFT JOIN {\$empresas} e ON e.id = a.empresa_id", $lib );
	}

	public function test_settings_field_saves_with_ownership_check_and_ui_switches_to_canteen_mode(): void {
		$settings = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'name="comedor_email"', $settings );
		$this->assertStringContainsString( "array_key_exists( 'comedor_email', \$_POST )", $settings );
		$this->assertStringContainsString( "ANPA_Socios_Email_Ownership::socio_por_email( \$comedor )", $settings );
		$this->assertStringContainsString( "'comedor_email_conflict'", $settings );
		$this->assertStringContainsString( "self::redirect_msg( \$msg );", $settings );

		$tpl = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'data-empresa-titulo', $tpl );
		$this->assertStringContainsString( 'data-empresa-so-empresa', $tpl );

		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "const comedor = profile.tipo === 'comedor';", $js );
		$this->assertStringContainsString( 'function opcionsLabel(r)', $js );
		$this->assertStringContainsString( "__( 'Panel do comedor', 'anpa-socios' )", $js );
		$this->assertStringContainsString( "if (btnTodos) { btnTodos.hidden = comedor; }", $js );
	}
}
