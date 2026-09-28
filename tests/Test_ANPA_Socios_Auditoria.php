<?php
/**
 * 1.76.0: approvals history with every kind of decision (member signups,
 * enrolment requests, member baixas) and a richer Auditoría (readable actions,
 * filters, server pagination, log download). Pure helpers + source contracts.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Auditoria extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_every_manual_decision_has_a_type_and_a_decision(): void {
		$a = static function ( string $accion, string $tipo ): ?array { return ANPA_Socios_Auditoria::aprobacion( $accion, $tipo ); };
		$this->assertSame( array( 'tipo' => 'Alta de socio/a', 'decision' => 'Aprobada', 'sentido' => 'si' ), $a( 'approval_approve', 'socio' ) );
		$this->assertSame( array( 'tipo' => 'Alta de socio/a', 'decision' => 'Rexeitada', 'sentido' => 'non' ), $a( 'approval_reject', 'socio' ) );
		$this->assertSame( array( 'tipo' => 'Matrícula', 'decision' => 'Aprobada con praza', 'sentido' => 'si' ), $a( 'aprobada_praza', 'matricula' ) );
		$this->assertSame( array( 'tipo' => 'Matrícula', 'decision' => 'Aprobada en lista de espera', 'sentido' => 'si' ), $a( 'aprobada_espera', 'matricula' ) );
		$this->assertSame( array( 'tipo' => 'Matrícula', 'decision' => 'Rexeitada', 'sentido' => 'non' ), $a( 'matricula_rexeitada', 'matricula' ) );
		$this->assertSame( array( 'tipo' => 'Baixa de socio/a', 'decision' => 'Confirmada', 'sentido' => 'si' ), $a( 'baixa_confirm', 'socio' ) );
		$this->assertSame( array( 'tipo' => 'Baixa de socio/a', 'decision' => 'Confirmada (resto da familia)', 'sentido' => 'si' ), $a( 'baixa_confirm_familia', 'socio' ) );
		// Rows written before 1.46.0 were cut at 20 characters.
		$this->assertSame( 'Confirmada (resto da familia)', $a( 'baixa_confirm_famili', 'socio' )['decision'] );
		$this->assertSame( array( 'tipo' => 'Baixa de socio/a', 'decision' => 'Rexeitada', 'sentido' => 'non' ), $a( 'baixa_reject', 'socio' ) );
		// Activity baixas are decided in Extraescolares → Matrículas, not here; other actions are not decisions.
		$this->assertNull( $a( 'baixa_confirm', 'matricula' ) );
		$this->assertNull( $a( 'update', 'socio' ) );
	}

	public function test_history_sql_lists_exactly_the_decisions(): void {
		$w = ANPA_Socios_Auditoria::where_aprobacions();
		foreach ( array( 'approval_approve', 'approval_reject', 'baixa_confirm', 'baixa_confirm_familia', 'baixa_confirm_famili', 'baixa_reject' ) as $x ) {
			$this->assertStringContainsString( "'" . $x . "'", $w );
		}
		foreach ( array( 'aprobada_praza', 'aprobada_espera', 'matricula_rexeitada' ) as $x ) {
			$this->assertStringContainsString( "'" . $x . "'", $w );
		}
		$this->assertStringContainsString( "a.target_tipo = 'socio'", $w );
		$this->assertStringContainsString( "a.target_tipo = 'matricula'", $w );
		$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $w ) );
	}

	public function test_actions_read_as_sentences(): void {
		$e = static function ( string $accion, string $tipo = '' ): string { return ANPA_Socios_Auditoria::etiqueta( $accion, $tipo ); };
		$this->assertSame( 'Alta de socio/a aprobada', $e( 'approval_approve', 'socio' ) );
		$this->assertSame( 'Matrícula aprobada con praza', $e( 'aprobada_praza', 'matricula' ) );
		$this->assertSame( 'Baixa confirmada', $e( 'baixa_confirm', 'matricula' ) );
		$this->assertSame( 'Creación', $e( 'create', 'empresa' ) );
		$this->assertSame( 'Exportación CSV', $e( 'export_csv', 'export' ) );
		$this->assertSame( 'Folla de cálculo (.ods) do comedor', $e( 'export_ods_comedor', 'export' ) );
		$this->assertSame( 'Envío masivo de correo', $e( 'masivo', 'email' ) );
		// Actions with a variable suffix.
		$this->assertSame( 'Ventá de matrícula aberta: 2º trimestre', $e( 'ventana_aberta_2', 'curso' ) );
		$this->assertSame( 'Trimestre activo: 3º', $e( 'trimestre_activo_3', 'curso' ) );
		$this->assertSame( 'Estado do grupo: pechado', $e( 'estado_pechado', 'grupo' ) );
		$this->assertSame( 'Matrícula feita pola familia: activo', $e( 'matricula_creada_activo', 'matricula' ) );
		$this->assertSame( 'Duplicada da actividade 12', $e( 'duplicate_from_12', 'actividad' ) );
		// Unknown codes stay readable.
		$this->assertSame( 'Algo novo', $e( 'algo_novo', 'x' ) );
	}

	public function test_object_types_have_labels(): void {
		$this->assertSame( 'Socio/a', ANPA_Socios_Auditoria::tipo_label( 'socio' ) );
		$this->assertSame( 'Matrícula', ANPA_Socios_Auditoria::tipo_label( 'matricula' ) );
		$this->assertSame( 'Datos bancarios', ANPA_Socios_Auditoria::tipo_label( 'domiciliacion' ) );
		$this->assertSame( 'cousa_rara', ANPA_Socios_Auditoria::tipo_label( 'cousa_rara' ) );
		$this->assertArrayHasKey( 'export', ANPA_Socios_Auditoria::TIPOS );
	}

	public function test_filters_are_normalised(): void {
		$f = ANPA_Socios_Auditoria::filtros( array( 'desde' => '2026-09-01', 'ata' => '2026-02-30', 'tipo' => 'socio', 'actor' => '  Xunta@Example.org ', 'q' => str_repeat( 'x', 300 ), 'pax' => '-3', 'por_pax' => '9999' ) );
		$this->assertSame( '2026-09-01', $f['desde'] );
		$this->assertSame( '', $f['ata'], 'invalid date dropped' );
		$this->assertSame( 'socio', $f['tipo'] );
		$this->assertSame( 'xunta@example.org', $f['actor'] );
		$this->assertSame( 100, mb_strlen( $f['q'] ) );
		$this->assertSame( 1, $f['pax'] );
		$this->assertSame( 100, $f['por_pax'], 'only 50/100/200/500' );
		$this->assertSame( '', ANPA_Socios_Auditoria::filtros( array( 'tipo' => "socio' OR 1=1" ) )['tipo'], 'type is whitelisted' );
	}

	public function test_where_builder_uses_placeholders_only(): void {
		list( $sql, $params ) = ANPA_Socios_Auditoria::where_filtros( array( 'desde_utc' => '2026-08-31 22:00:00', 'ata_utc' => '2026-09-30 21:59:59', 'tipo' => 'socio', 'actor' => 'xunta', 'q' => '50%_' ) );
		$this->assertSame( 'WHERE timestamp >= %s AND timestamp <= %s AND target_tipo = %s AND actor_email LIKE %s AND ( target_id LIKE %s OR accion LIKE %s OR actor_email LIKE %s )', $sql );
		$this->assertSame( array( '2026-08-31 22:00:00', '2026-09-30 21:59:59', 'socio', '%xunta%', '%50\\%\\_%', '%50\\%\\_%', '%50\\%\\_%' ), $params );
		list( $none, $p0 ) = ANPA_Socios_Auditoria::where_filtros( array() );
		$this->assertSame( '', $none );
		$this->assertSame( array(), $p0 );
	}

	public function test_log_download_columns(): void {
		$this->assertSame( array( 'Data', 'Quen', 'Tipo de actor', 'Acción', 'Código da acción', 'Tipo', 'Identificador', 'Detalle' ), ANPA_Socios_Auditoria::COLUMNAS_LOG );
	}

	// ── Wiring ──

	public function test_history_endpoint_joins_members_and_enrolments_and_returns_local_time(): void {
		$h     = $this->src( 'includes/class-anpa-socios-admin-approvals-handler.php' );
		$start = strpos( $h, 'public static function list_history(): WP_REST_Response' );
		$this->assertNotFalse( $start );
		$body = substr( $h, (int) $start, (int) strpos( $h, "\n\t/**", (int) $start ) - (int) $start );
		$this->assertStringContainsString( 'ANPA_Socios_Auditoria::where_aprobacions()', $body );
		$this->assertStringContainsString( "LEFT JOIN {\$soc_t} s ON a.target_tipo = 'socio' AND s.email = a.target_id", $body );
		$this->assertStringContainsString( "LEFT JOIN {\$mat_t} m ON a.target_tipo = 'matricula' AND m.id = CAST(a.target_id AS UNSIGNED)", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Auditoria::aprobacion(', $body );
		$this->assertStringContainsString( 'get_date_from_gmt(', $body );
	}

	public function test_audit_endpoints_filter_paginate_and_download(): void {
		$r = $this->src( 'includes/class-anpa-socios-admin-reports-handler.php' );
		$this->assertStringContainsString( "'/audit/export'", $r );
		// /audit and /audit/export are master only (like /export/full).
		$this->assertGreaterThanOrEqual( 3, substr_count( $r, "'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' )" ) );
		$this->assertStringContainsString( 'ANPA_Socios_Auditoria::where_filtros(', $r );
		$this->assertStringContainsString( "'total'   => \$total", $r );
		$this->assertStringContainsString( "'export_auditoria'", $r );
		$this->assertStringContainsString( 'ANPA_Socios_Ods::documento(', $r );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "anpaAdminFetch('audit?' + auditQuery(st.filtros, st.pax))", $js );
		$this->assertStringContainsString( "'audit/export?formato=' + formato + '&' + auditQuery(st.filtros, 1)", $js );
		$this->assertStringContainsString( "['Tipo', 'Decisión', 'Persoa / alumno/a', 'Detalle', 'Solicitado', 'Resolto', 'Resolto por']", $js );
	}
}
