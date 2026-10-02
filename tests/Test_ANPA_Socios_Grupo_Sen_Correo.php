<?php
/**
 * 1.86.0: the group state actions («Notificar grupo creado», «Deixar sen
 * mínimo», «Pechar por non acadar o mínimo») can be done without email: a
 * checkbox per group card, checked (= send) by default.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupo_Sen_Correo extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	public function test_email_is_sent_unless_the_body_says_no(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->assertStringContainsString( "return ! array_key_exists( 'notificar', \$body ) || ! empty( \$body['notificar'] );", $this->body( $h, 'con_correo' ), 'default: send' );
		foreach ( array( 'notificar_comezo', 'sen_minimo', 'pechar_minimo' ) as $fn ) {
			$b = $this->body( $h, $fn );
			$this->assertStringContainsString( 'self::con_correo( $request )', $b, $fn );
			$this->assertStringContainsString( "'sen_correo'", $b, $fn . ' audits the silent change' );
			$this->assertStringContainsString( "'correo' => \$correo", $b, $fn );
			$i = strpos( $b, 'if ( $correo' );
			$this->assertNotFalse( $i, $fn );
			$this->assertLessThan( (int) strpos( $b, 'enviar_masivo(' ), (int) $i, $fn . ': the email only inside the guard' );
		}
		$this->assertSame( 'Cambio de estado do grupo sen enviar correo', ANPA_Socios_Auditoria::etiqueta( 'sen_correo', 'grupo' ) );
	}

	public function test_card_has_one_checked_box_for_the_three_actions(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "chkCorreo.type = 'checkbox'; chkCorreo.checked = true;", $js );
		$this->assertSame( 3, substr_count( $js, 'body: { notificar: chkCorreo.checked }' ) );
		$this->assertSame( 3, substr_count( $js, "' + semCorreoTxt())) { return; }" ), 'each confirm says when nothing is sent' );
	}
}
