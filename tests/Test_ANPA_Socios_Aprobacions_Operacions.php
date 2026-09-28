<?php
/**
 * 1.73.0: Xestión reorganised — Aprobacións (member signups, out-of-window
 * enrolments and member baixas) moves to Operacións with a count on its
 * button; Empresas moves to Extraescolares. Source-contract tests.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Aprobacions_Operacions extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_count_covers_the_three_queues(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-approvals-handler.php' );
		$this->assertStringContainsString( 'public static function contas_pendentes(): array', $h );
		$this->assertStringContainsString( 'WHERE estado = %s AND ( familia_id IS NULL OR familia_id = id )', $h, 'one per family, as list_pending()' );
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION', $h );
		$this->assertStringContainsString( "WHERE baixa_estado = 'solicitada' AND estado = 'activo' AND rol <> 'master'", $h );
		$this->assertStringContainsString( "\$out['total']  = \$out['socios'] + \$out['matriculas'] + \$out['baixas'];", $h );
		$page = $this->src( 'includes/class-anpa-socios-admin-management-page.php' );
		$this->assertStringContainsString( "\$pendentes = (int) ( 'aprobacions' === \$slug ? \$contas['total'] : \$contas['baixas_actividades'] );", $page );
		$this->assertStringContainsString( "\$label     = (string) \$label . ' (' . \$pendentes . ')';", $page );
		$this->assertStringContainsString( "\$pendentes > 0 ? ' class=\"anpa-mgmt-nav-pendente\"' : ''", $page );
	}

	public function test_contas_are_zero_without_a_database(): void {
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-approvals-handler.php';
		$saved = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = null;
		try {
			$this->assertSame( array( 'socios' => 0, 'matriculas' => 0, 'baixas' => 0, 'total' => 0, 'baixas_actividades' => 0 ), ANPA_Socios_Admin_Approvals_Handler::contas_pendentes() );
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}
	}

	public function test_aprobacions_renders_three_counted_blocks_in_order(): void {
		$js    = $this->src( 'assets/js/admin-management.js' );
		$start = strpos( $js, 'function renderApprovals(rows, historyRows, pendentes, baixas)' );
		$this->assertNotFalse( $start );
		$body = substr( $js, (int) $start, (int) strpos( $js, '// ── Block: Baixas de socios/as', (int) $start ) - (int) $start );
		$socios = strpos( $body, "'Socios/as pendentes de aprobaci\\u00F3n (' + list.length + ')'" );
		$mat    = strpos( $body, "'Matrículas pendentes de aprobación (' + pend.length + ')'" );
		$baixa  = strpos( $body, 'renderBaixasSocios(baixas);' );
		$hist   = strpos( $body, '// ── Historical approvals ──' );
		foreach ( array( $socios, $mat, $baixa, $hist ) as $pos ) {
			$this->assertNotFalse( $pos );
		}
		$this->assertLessThan( $mat, $socios );
		$this->assertLessThan( $baixa, $mat );
		$this->assertLessThan( $hist, $baixa );
		$this->assertStringContainsString( "introS.textContent = 'Altas na asociación feitas polas familias", $body );
		$this->assertStringContainsString( 'setAprobacionsCount(list.length + pendCount + baixasSocios.length);', $body );
		$this->assertStringContainsString( "setNavCount('aprobacions', 'Aprobacións', n);", $js );
		$this->assertStringContainsString( "btn.textContent = label + ' (' + num + ')';", $js );
		$this->assertStringContainsString( "btn.classList.toggle('anpa-mgmt-nav-pendente', num > 0);", $js );
		$this->assertStringContainsString( "setNavCount('matriculas', 'Matrículas', mats.length);", $js );
		// After confirming or rejecting a baixa the whole Aprobacións view reloads.
		$block = substr( $js, (int) strpos( $js, 'function renderBaixasSocios(data)' ), 6000 );
		$this->assertStringContainsString( 'loadApprovals();', $block );
	}

	public function test_texts_point_to_the_new_place(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringNotContainsString( 'Socios → Aprobacións', $js );
		$this->assertStringNotContainsString( 'Socios → Baixas de socios', $js );
		$docs = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringNotContainsString( 'Socios → Aprobacións', $docs );
		$this->assertStringContainsString( 'a xunta decide en Operacións → Aprobacións', $docs );
	}
}
