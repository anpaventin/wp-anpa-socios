<?php
/**
 * Contract tests: member-initiated events are written to the audit log
 * (fase E2, 1.49.6) regardless of the "aprobación de altas" setting.
 *
 * The REST handlers need a live wpdb, so these tests pin the contract at
 * source level: each success path must call write_audit_actor() with the
 * actor type 'socio' and the documented action verb.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Member_Audit_Events extends TestCase {

	private function source( string $file ): string {
		$path = __DIR__ . '/../includes/' . $file;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function provider_events(): array {
		return array(
			'alta activa'                 => array( 'class-anpa-socios-rest.php', "'alta_activa'" ),
			'alta pendente de aprobación' => array( 'class-anpa-socios-rest.php', "'alta_pendente'" ),
			'alta segundo proxenitor'     => array( 'class-anpa-socios-rest.php', "'alta_segundo_proxenitor'" ),
			'reactivación solicitada'     => array( 'class-anpa-socios-area-rest.php', "'reactivacion_solicitada'" ),
			'IBAN actualizado'            => array( 'class-anpa-socios-area-rest.php', "'iban_actualizado'" ),
			'baixa solicitada'            => array( 'class-anpa-socios-area-rest.php', "'baixa_solicitada'" ),
			'baixa cancelada'             => array( 'class-anpa-socios-area-rest.php', "'baixa_cancelada'" ),
			'fillo engadido'              => array( 'class-anpa-socios-fillos-rest.php', "'fillo_engadido'" ),
			'fillo actualizado'           => array( 'class-anpa-socios-fillos-rest.php', "'fillo_actualizado'" ),
			'fillo eliminado'             => array( 'class-anpa-socios-fillos-rest.php', "'fillo_eliminado'" ),
			'matrícula creada'            => array( 'class-anpa-socios-extraescolares-rest.php', "'matricula_creada_'" ),
			'matrícula baixa solicitada'  => array( 'class-anpa-socios-extraescolares-rest.php', "'matricula_baixa_solicitada'" ),
			'matrícula baixa directa'     => array( 'class-anpa-socios-extraescolares-rest.php', "'matricula_baixa'" ),
			'matrícula baixa cancelada'   => array( 'class-anpa-socios-extraescolares-rest.php', "'matricula_baixa_cancelada'" ),
			'oferta aceptada'             => array( 'class-anpa-socios-extraescolares-rest.php', "'oferta_aceptada'" ),
		);
	}

	/**
	 * @dataProvider provider_events
	 */
	public function test_member_event_is_audited( string $file, string $action ): void {
		$source = $this->source( $file );
		$this->assertStringContainsString( $action, $source, "Falta o evento de auditoría {$action} en {$file}" );
		// Every call carries the explicit member actor type.
		$this->assertMatchesRegularExpression(
			'/write_audit_actor\(\s*[^;]*?\'socio\'\s*,[^;]*?' . preg_quote( $action, '/' ) . '/s',
			$source,
			"O evento {$action} debe rexistrarse con actor_tipo 'socio'"
		);
	}

	public function test_alta_is_audited_before_the_approval_branch(): void {
		$source = $this->source( 'class-anpa-socios-rest.php' );
		$audit  = strpos( $source, "\$needs_approval ? 'alta_pendente' : 'alta_activa'" );
		$branch = strpos( $source, "if ( \$needs_approval ) {\n\t\t\t// Notify the master" );
		$this->assertNotFalse( $audit );
		$this->assertNotFalse( $branch );
		$this->assertLessThan( $branch, $audit, 'A auditoría da alta debe escribirse antes de bifurcar por aprobación, para que se rexistre sempre.' );
	}

	public function test_banking_audit_never_includes_banking_fields(): void {
		$source = $this->source( 'class-anpa-socios-area-rest.php' );
		preg_match( '/write_audit_actor\([^;]*\'iban_actualizado\'[^;]*;/s', $source, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringNotContainsString( '$sepa', $m[0] );
		$this->assertStringNotContainsString( 'iban', strtolower( str_replace( 'iban_actualizado', '', $m[0] ) ) );
	}
}
