<?php
/**
 * 1.85.0: groups «sen mínimo» (not created, still taking requests), a waiting
 * list where an offer keeps its place, an accepted offer waits for the junta,
 * and an offer not taken goes to the END of the list; positions per group; one
 * trimester rule (the active one) and a single «prazo de inscrición libre».
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-anpa-socios-lista-espera.php';

final class Test_ANPA_Socios_Sen_Minimo_Lista_Espera extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	public function test_new_group_state_and_schema(): void {
		$this->assertContains( 'sen_minimo', ANPA_Socios_Grupo_Serie::estados() );
		$this->assertSame( 'Sen mínimo', ANPA_Socios_Grupo_Serie::estado_label( 'sen_minimo' ) );
		$this->assertFalse( ANPA_Socios_Grupo_Serie::estado_requires_no_enrolments( 'sen_minimo' ), 'whoever has a place keeps it' );
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$m  = $this->body( $db, 'migrate_to_1_48_0' );
		$this->assertStringContainsString( "MODIFY COLUMN estado enum('aberto','pechado','deshabilitado','sen_minimo')", $m );
		$this->assertStringContainsString( 'ADD COLUMN oferta_aceptada_en datetime NULL DEFAULT NULL', $m );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.48.0', '<' ) && ! self::migrate_to_1_48_0()", $db );
	}

	public function test_public_page_and_approvals_treat_it_as_offered_but_not_created(): void {
		$this->assertSame( ANPA_Socios_Oferta_Publica::SEN_MINIMO, ANPA_Socios_Oferta_Publica::estado_grupo( 'sen_minimo', null, 2, 6, 3 ) );
		$this->assertSame( ANPA_Socios_Oferta_Publica::ABERTO, ANPA_Socios_Oferta_Publica::estado_grupo( 'aberto', null, 2, 6 ), 'unchanged' );
		// Approval gives a place while there is room (nobody is charged until it is created).
		$this->assertSame( 'activo', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'sen_minimo', 3, 12 ) );
		$this->assertSame( 'lista_espera', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'sen_minimo', 12, 12 ) );
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( 'anpa-extra-grupo-sen-minimo', $page );
		$this->assertStringContainsString( '.anpa-extra-grupo-sen-minimo', $this->src( 'assets/css/extraescolares.css' ) );
	}

	public function test_sen_minimo_route_mails_families_and_company_not_the_canteen(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->assertStringContainsString( "'/grupo/(?P<id>\\d+)/sen-minimo'", $h );
		$b = $this->body( $h, 'sen_minimo' );
		$this->assertStringContainsString( 'self::contexto_aviso_grupo(', $b, 'refused during the free enrolment period' );
		$this->assertStringContainsString( "array( 'id' => \$ctx['grupo_id'], 'estado' => ANPA_Socios_Grupo_Serie::ESTADO_ABERTO )", $b, 'only an open group' );
		$this->assertStringContainsString( "array_merge( \$ctx['emails_activos'], \$ctx['emails_espera'], \$ctx['emails_pendentes'], \$ctx['empresa_email'] ), 'grupo_sen_minimo'", $b );
		$this->assertStringNotContainsString( 'comedor', strtolower( $b ) );
		$this->assertStringNotContainsString( "estado = 'baixa'", $b, 'enrolments are kept' );
		// «Notificar grupo creado» on a «sen mínimo» group opens it.
		$this->assertStringContainsString( "if ( ANPA_Socios_Grupo_Serie::ESTADO_SEN_MINIMO === \$ctx['estado'] ) {", $this->body( $h, 'notificar_comezo' ) );
		// The area takes requests for it, pending approval.
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "if ( ANPA_Socios_Grupo_Serie::ESTADO_SEN_MINIMO === (string) \$locked['estado'] ) {\n\t\t\t\$pendente = true;", $e );
		$d = ANPA_Socios_Email_Template_Store::get_all_defaults();
		$v = ANPA_Socios_Email_Template_Store::get_variables( 'grupo_sen_minimo' );
		foreach ( array( 'subject', 'html', 'text' ) as $k ) {
			$this->assertSame( count( $v[ $k ] ), substr_count( $d['grupo_sen_minimo'][ $k ], '%s' ), $k );
		}
		$this->assertStringContainsString( 'conserva a súa praza', $d['grupo_sen_minimo']['html'] );
	}

	public function test_an_offer_keeps_its_place_and_an_accepted_one_waits_for_the_junta(): void {
		// A requested baixa still attends until it is confirmed: it holds its place too.
		$this->assertSame( "( estado IN ('activo','oferta','baixa_solicitada') OR ( estado = 'pendente_aprobacion' AND oferta_aceptada_en IS NOT NULL ) )", ANPA_Socios_Lista_Espera::SQL_OCUPAN );
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( '$activos          = ANPA_Socios_Lista_Espera::ocupadas( $grupo_id );', $e, 'enrolment counts held places' );
		$a = $this->src( 'includes/class-anpa-socios-admin-matriculas-handler.php' );
		$ap = $this->body( $a, 'aprobar' );
		$this->assertStringContainsString( "\$de_oferta = ! empty( \$mat['oferta_aceptada_en'] );", $ap );
		$this->assertStringContainsString( '$destino   = ANPA_Socios_Matricula_Estado::ACTIVO;', $ap );
		$this->assertStringContainsString( "'oferta_aceptada_en' => null", $ap );
		$o = $this->src( 'includes/class-anpa-socios-extraescolar-offers.php' );
		$on = $this->body( $o, 'offer_next' );
		$this->assertStringContainsString( 'ANPA_Socios_Lista_Espera::ocupadas( $grupo_id ) >= (int) $grupo[\'max_pupilos\']', $on, 'no offer without a free place' );
		$this->assertStringContainsString( "AND estado = 'lista_espera' AND id <> %d", $on, 'never the same family again straight away' );
		$this->assertStringContainsString( 'ORDER BY trimestre ASC, posicion ASC, id ASC LIMIT 1', $on, 'the whole group list, not only the freed row trimester' );
		$this->assertStringContainsString( 'AND ( oferta_expira IS NULL OR oferta_expira < %s )', $on, 'cooling-off after letting an offer go' );
		$this->assertStringContainsString( "array( 'id' => (int) \$next['id'], 'estado' => 'lista_espera' )", $on, 'guarded update' );
		$this->assertStringContainsString( 'self::encher_prazas_libres();', $this->body( $o, 'expire_stale' ) );
		$this->assertStringContainsString( "'anpa_admin_grupo_pechado'", $ap, 'no approval into a closed group' );
		$this->assertStringContainsString( "ANPA_Socios_Lista_Espera::en_espera( \$grupo_id ) > 0", $e, 'newcomers queue behind' );
		$m = $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Lista_Espera::ocupadas( $target, $mat_id )', $m );
		// Rejecting or withdrawing an accepted offer frees the place for the next one.
		$this->assertStringContainsString( 'ANPA_Socios_Extraescolar_Offers::offer_next(', $this->body( $a, 'rexeitar' ) );
		$this->assertStringContainsString( 'ANPA_Socios_Extraescolar_Offers::offer_next(', $this->body( $e, 'retirar_pendente' ) );
	}

	public function test_an_offer_not_taken_goes_to_the_end_of_the_list(): void {
		$o = $this->src( 'includes/class-anpa-socios-extraescolar-offers.php' );
		$d = $this->body( $o, 'decline_and_advance' );
		$this->assertStringContainsString( 'ANPA_Socios_Lista_Espera::ao_final( $matricula_id )', $d );
		$this->assertStringNotContainsString( "'baixa'", $d, 'it does not leave the list any more' );
		$this->assertStringContainsString( 'self::notify_final_lista( $matricula_id, $motivo );', $d );
		$l = $this->body( $this->src( 'includes/class-anpa-socios-lista-espera.php' ), 'ao_final' );
		// oferta_expira keeps when the offer ended: the family rests DESCANSO_DIAS before another offer.
		$this->assertStringContainsString( "SET estado = 'lista_espera', posicion = %d, oferta_token = NULL, oferta_expira = %s", $l );
		$this->assertSame( 3, ANPA_Socios_Lista_Espera::DESCANSO_DIAS );
		$this->assertStringContainsString( 'self::renumerar( $g, $t );', $l );
		// The family can turn it down from the area (session-protected).
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$i = strpos( $e, "'/area/matricula/(?P<id>\\d+)/oferta/rexeitar'" );
		$this->assertNotFalse( $i );
		$this->assertStringContainsString( "'permission_area_session'", substr( $e, (int) $i, 300 ) );
		$this->assertStringContainsString( "decline_and_advance( (int) \$mat['id'], 'rexeitada'", $this->body( $e, 'rexeitar_oferta' ) );
		$this->assertStringContainsString( "base + '/oferta/rexeitar'", $this->src( 'assets/js/area.js' ) );
		$d2 = ANPA_Socios_Email_Template_Store::get_all_defaults()['oferta_final_lista'];
		$v  = ANPA_Socios_Email_Template_Store::get_variables( 'oferta_final_lista' );
		$this->assertSame( count( $v['html'] ), substr_count( $d2['html'], '%s' ) );
		$this->assertStringContainsString( 'ao final', ANPA_Socios_Email_Template_Store::get_all_defaults()['oferta_extraescolar']['html'] );
	}

	public function test_positions_are_per_group(): void {
		$l = $this->src( 'includes/class-anpa-socios-lista-espera.php' );
		$this->assertStringContainsString( "SELECT MAX(posicion) FROM {\$mat_t} WHERE grupo_id = %d AND trimestre = %d AND estado = 'lista_espera'", $l );
		$e = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringNotContainsString( 'WHERE activitad_id = %d AND trimestre = %d"', $e, 'no per-activity count any more' );
		$this->assertStringContainsString( "\$posicion = 'lista_espera' === \$estado ? ANPA_Socios_Lista_Espera::seguinte_posicion( \$grupo_id, \$trimestre ) : null;", $e );
		$a = $this->src( 'includes/class-anpa-socios-admin-matriculas-handler.php' );
		$this->assertStringNotContainsString( 'WHERE activitad_id = %d AND trimestre = %d AND id <> %d', $a );
		$this->assertStringContainsString( "CASE WHEN m.estado = 'lista_espera' THEN m.posicion ELSE NULL END AS posicion", $a );
		$this->assertSame( array( 7 => 1, 3 => 2, 9 => 3 ), ANPA_Socios_Waitlist::renumber( array( 7, 3, 9 ) ) );
	}

	public function test_one_trimester_rule_and_one_free_period_switch(): void {
		foreach ( array( 'includes/class-anpa-socios-extraescolares-rest.php', 'includes/class-anpa-socios-admin-matriculas-handler.php' ) as $f ) {
			$this->assertStringNotContainsString( 'ANPA_Socios_Trimestre::actual_por_datas(', $this->src( $f ), $f );
		}
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringNotContainsString( "'Matrículas abertas para: '", $js );
		$this->assertStringContainsString( "'Prazo de inscrición libre (sen aprobación): '", $js );
		$this->assertStringContainsString( "var destino = abrir ? triActivo : 0;", $js );
	}
}
