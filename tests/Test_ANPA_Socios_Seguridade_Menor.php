<?php
/**
 * 1.103.0 minor security hardening: security headers on live pages,
 * no-store on the plugin's REST JSON, http(s)-only company websites,
 * email templates that survive a stray `%`, audit anonymisation on manual
 * deletion, audit rows for backup/restore, and an accurate SECURITY.md.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';

final class Test_ANPA_Socios_Seguridade_Menor extends TestCase {

	private static function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	// ── 1. Security headers on live pages ─────────────────────────

	public function test_live_pages_send_security_headers_without_csp(): void {
		$h = ANPA_Socios_Non_Cachear::SECURITY_HEADERS;
		$this->assertSame( 'SAMEORIGIN', $h['X-Frame-Options'] );
		$this->assertSame( 'nosniff', $h['X-Content-Type-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $h['Referrer-Policy'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $h );

		$src = self::src( 'includes/lib/class-anpa-socios-non-cachear.php' );
		$pos = strpos( $src, 'nocache_headers();' );
		$this->assertNotFalse( $pos );
		$this->assertStringContainsString( 'foreach ( self::SECURITY_HEADERS as $name => $value )', substr( $src, $pos, 300 ) );
	}

	// ── 2. no-store on the plugin's REST responses ────────────────

	public function test_plugin_namespaces_are_not_cached(): void {
		$this->assertTrue( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa-socios/v1/area/me' ) );
		$this->assertTrue( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa-socios/v1/empresa/me/export' ) );
		$this->assertTrue( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa-socios/v1/area/fillos' ) );
		$this->assertTrue( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa-socios/v1/admin/socios' ) );
		$this->assertTrue( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa/v1/verificar-codigo' ) );
		$this->assertTrue( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa-socios/v1' ) );
	}

	public function test_other_namespaces_are_left_alone(): void {
		$this->assertFalse( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/wp/v2/posts' ) );
		$this->assertFalse( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa-socios/v10/area/me' ) );
		$this->assertFalse( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/anpa/v1x' ) );
		$this->assertFalse( ANPA_Socios_Empresa_REST::ruta_sen_cache( '' ) );
		$this->assertFalse( ANPA_Socios_Empresa_REST::ruta_sen_cache( '/other/anpa-socios/v1/area' ) );
	}

	public function test_no_store_filter_is_registered_and_keeps_existing_cache_control(): void {
		$src = self::src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "add_filter( 'rest_post_dispatch', array( __CLASS__, 'engadir_no_store' ), 10, 3 );", $src );
		$this->assertStringContainsString( "\$response->header( 'Cache-Control', 'no-store, private' );", $src );
		$this->assertStringContainsString( "strcasecmp( (string) \$name, 'Cache-Control' )", $src );
	}

	// ── 3. Company website scheme ─────────────────────────────────

	public function test_url_web_accepts_only_http_and_https(): void {
		$this->assertTrue( ANPA_Socios_Admin_Payload::url_web_valida( null ) );
		$this->assertTrue( ANPA_Socios_Admin_Payload::url_web_valida( '' ) );
		$this->assertTrue( ANPA_Socios_Admin_Payload::url_web_valida( 'https://teatro.example.com' ) );
		$this->assertTrue( ANPA_Socios_Admin_Payload::url_web_valida( 'HTTP://example.com/a?b=c' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::url_web_valida( 'javascript:alert(1)' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::url_web_valida( ' JavaScript:alert(1)' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::url_web_valida( 'data:text/html,x' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::url_web_valida( 'www.example.com' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::url_web_valida( 'https://' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::url_web_valida( array( 'https://x' ) ) );
	}

	public function test_empresa_with_bad_website_is_rejected_with_a_code(): void {
		$base = array(
			'nome'        => 'Escola',
			'email'       => 'a@b.es',
			'responsable' => 'Ana',
			'telefono'    => '666111222',
		);
		$bad = array_replace( $base, array( 'url_web' => 'javascript:alert(1)' ) );
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_empresa( $bad ) );
		$this->assertSame( 'url_web_invalid', ANPA_Socios_Admin_Payload::diagnosticar_empresa( $bad ) );
		$ok = ANPA_Socios_Admin_Payload::validar_empresa( array_replace( $base, array( 'url_web' => 'https://x.example' ) ) );
		$this->assertSame( 'https://x.example', $ok['url_web'] );

		$this->assertStringContainsString( "'url_web_invalid'", self::src( 'includes/class-anpa-socios-admin-empresas-handler.php' ) );
		$this->assertStringContainsString( "esc_url_raw( (string) ( \$row['url_web'] ?? '' ), array( 'http', 'https' ) )", self::src( 'includes/class-anpa-socios-admin-import-handler.php' ) );
	}

	public function test_company_panel_links_only_http_urls(): void {
		$js = self::src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'if (profile.url_web && /^https?:\/\//i.test(profile.url_web)) {', $js );
	}

	// ── 4. Email templates with a stray % ─────────────────────────

	public function test_stray_percent_falls_back_to_built_in_template(): void {
		$context  = array( 'association_name' => 'ANPA', 'nome' => 'Xoan', 'codigo' => '123456' );
		$expected = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context );
		$result   = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context, array(
			'subject' => 'Código para %s ao 50%.',
			'html'    => '<p>Ola %s, desconto do 100%.</p><p>%s</p>',
			'text'    => 'Ola %s: %s %',
		) );
		$this->assertSame( $expected['subject'], $result['subject'] );
		$this->assertSame( $expected['html'], $result['html'] );
		$this->assertSame( $expected['text'], $result['text'] );
		$this->assertStringContainsString( '123456', $result['text'] );
	}

	public function test_valid_custom_template_is_still_used(): void {
		$result = ANPA_Socios_Email_Template_Renderer::render(
			'verification_code',
			array( 'association_name' => 'ANPA', 'nome' => 'Xoan', 'codigo' => '123456' ),
			array( 'subject' => 'Código (50%%) para %s', 'html' => '', 'text' => 'Ola %s: %s' )
		);
		$this->assertSame( 'Código (50%) para ANPA', $result['subject'] );
		$this->assertSame( 'Ola Xoan: 123456', $result['text'] );
	}

	public function test_formatar_without_default_returns_text_unchanged(): void {
		$this->assertSame( 'Desconto 50%.', ANPA_Socios_Email_Template_Renderer::formatar( 'Desconto 50%.', array( 'x' ) ) );
		$this->assertSame( 'Desconto %s %', ANPA_Socios_Email_Template_Renderer::formatar( 'Desconto %s %', array( 'x' ), 'non_existe', 'text' ) );
	}

	// ── 5. Manual deletion anonymises the audit log ───────────────

	public function test_manual_delete_reuses_retention_anonymisation_in_transaction(): void {
		$ret = self::src( 'includes/class-anpa-socios-retencion-service.php' );
		$this->assertStringContainsString( 'public static function anonimizar( array $emails ): bool', $ret );

		$h     = self::src( 'includes/class-anpa-socios-admin-eliminar-handler.php' );
		$start = strpos( $h, "START TRANSACTION" );
		$call  = strpos( $h, 'ANPA_Socios_Retencion_Service::anonimizar( $context[\'emails\'] )' );
		$end   = strpos( $h, "'COMMIT'" );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $call );
		$this->assertNotFalse( $end );
		$this->assertTrue( $start < $call && $call < $end, 'Anonymisation must run inside the delete transaction.' );
	}

	// ── 6. Backup and restore are audited ─────────────────────────

	public function test_backup_and_restore_write_audit_rows(): void {
		$s = self::src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( "write_audit_actor( \$actor, 'admin', 'copia', \$fname, 'copia_descargada' );", $s );
		$this->assertStringContainsString( "write_audit_actor( \$actor, 'admin', 'copia', 'anpabak', 'copia_recuperada' );", $s );
		$this->assertSame( 'Copia de seguridade descargada', ANPA_Socios_Auditoria::etiqueta( 'copia_descargada' ) );
		$this->assertSame( 'Copia de seguridade recuperada', ANPA_Socios_Auditoria::etiqueta( 'copia_recuperada' ) );
	}

	// ── 7. SECURITY.md is accurate ────────────────────────────────

	public function test_security_md_describes_real_encryption(): void {
		$md = self::src( 'SECURITY.md' );
		$this->assertStringNotContainsString( 'PII at rest', $md );
		$this->assertStringNotContainsString( '1.49.x', $md );
		$this->assertStringContainsString( 'sodium_crypto_box_seal', $md );
		$this->assertStringContainsString( 'Argon2id', $md );
		$this->assertStringContainsString( 'stored in clear', $md );
		$this->assertStringContainsString( 'Latest stable release', $md );
	}
}
