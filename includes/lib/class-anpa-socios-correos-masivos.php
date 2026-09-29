<?php
/**
 * «Correos masivos» (1.84.0): the catalogue of every mass email the web sends
 * and the reading of the send log from the audit rows. Pure.
 *
 * @since   1.84.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mass email catalogue and log rows.
 *
 * @since 1.84.0
 */
final class ANPA_Socios_Correos_Masivos {

	/** Audit actions that are a mass send (target_tipo 'email'). */
	const ACCIONS = array( 'masivo', 'inicio_curso_enviado', 'inicio_curso_erro' );

	/**
	 * Every mass email. 'onde': 'aqui' = sent from this section; 'matriculas' /
	 * 'grupos-horarios' = sent where it changes the course or the groups (it is
	 * not a plain send); 'automatico' = the web sends it by itself.
	 *
	 * @return array<int,array{id:string,titulo:string,descricion:string,destinatarios:string,onde:string,plantillas:array<int,string>}>
	 */
	public static function catalogo(): array {
		return array(
			array(
				'id'            => 'inicio_curso_xunta',
				'titulo'        => 'Correo de inicio de curso (para reenviar)',
				'descricion'    => 'Como usar a web: iniciar sesión (código ao correo, sen contrasinal), darse de alta, modificar os datos e inscribirse nas extraescolares. Envíase UNHA soa vez á conta da xunta e dende Gmail reenvíase á etiqueta da Lista Gmail en CCO.',
				'destinatarios' => 'A conta da xunta',
				'onde'          => 'aqui',
				'plantillas'    => array( 'inicio_curso' ),
			),
			array(
				'id'            => 'prazo_matriculas',
				'titulo'        => 'Prazo de matrículas',
				'descricion'    => 'Lembra ás familias ata cando poden matricularse e cando comezan as actividades.',
				'destinatarios' => 'Todos os socios/as activos (CCO)',
				'onde'          => 'aqui',
				'plantillas'    => array( 'prazo_matriculas' ),
			),
			array(
				'id'            => 'comezo_curso',
				'titulo'        => 'Comezo do curso',
				'descricion'    => 'Activa o curso, abre as matrículas do 1º trimestre e avisa ás familias. Envíase co botón «Notificar comezo do curso».',
				'destinatarios' => 'Todos os socios/as activos (CCO)',
				'onde'          => 'matriculas',
				'plantillas'    => array( 'inicio_curso' ),
			),
			array(
				'id'            => 'ventas',
				'titulo'        => 'Matrículas abertas / pechadas',
				'descricion'    => 'Ao cambiar «Matrículas abertas para» coa opción «Avisar por correo a todas as familias».',
				'destinatarios' => 'Todos os socios/as activos (CCO)',
				'onde'          => 'matriculas',
				'plantillas'    => array( 'matriculas_abertas', 'matriculas_pechadas' ),
			),
			array(
				'id'            => 'fin_curso',
				'titulo'        => 'Fin de curso',
				'descricion'    => 'Pecha o curso, os grupos e as matrículas e avisa ás familias. Envíase co botón «Notificar fin de curso».',
				'destinatarios' => 'Todos os socios/as activos (CCO)',
				'onde'          => 'matriculas',
				'plantillas'    => array( 'fin_curso' ),
			),
			array(
				'id'            => 'grupo_creado',
				'titulo'        => 'Grupo creado (comezo do trimestre)',
				'descricion'    => 'Aviso ás familias inscritas, á lista de espera e á empresa dun grupo. Envíase dende cada grupo, co prazo de matrícula pechado.',
				'destinatarios' => 'Familias e empresa do grupo',
				'onde'          => 'grupos-horarios',
				'plantillas'    => array( 'grupo_comezo_trimestre', 'grupo_comezo_espera' ),
			),
			array(
				'id'            => 'grupo_minimo',
				'titulo'        => 'Grupo pechado por non acadar o mínimo',
				'descricion'    => 'Pecha o grupo, dá de baixa as súas matrículas e avisa. Envíase dende cada grupo.',
				'destinatarios' => 'Familias e empresa do grupo',
				'onde'          => 'grupos-horarios',
				'plantillas'    => array( 'grupo_pechado_minimo' ),
			),
			array(
				'id'            => 'automaticos',
				'titulo'        => 'Avisos automáticos',
				'descricion'    => 'A web envía soa os avisos á empresa e ao comedor en cada alta, baixa ou cambio de grupo (cos grupos xa creados) e, os luns ás 10:00, o resumo semanal á xunta. Non aparecen no rexistro.',
				'destinatarios' => 'Empresa e comedor / xunta',
				'onde'          => 'automatico',
				'plantillas'    => array( 'aprobacions_pendentes_semanal' ),
			),
		);
	}

	/**
	 * Name of a send in the log, from the tag of the audit row.
	 *
	 * @param  string $tag Template id or group tag.
	 * @return string
	 */
	public static function titulo_tag( string $tag ): string {
		$t = array(
			'inicio_curso'         => 'Comezo do curso',
			'inicio_curso_xunta'   => 'Inicio de curso (á conta da xunta)',
			'prazo_matriculas'     => 'Prazo de matrículas',
			'matriculas_abertas'   => 'Matrículas abertas',
			'matriculas_pechadas'  => 'Matrículas pechadas',
			'fin_curso'            => 'Fin de curso',
			'grupo_comezo'         => 'Grupo creado: inscritos/as',
			'grupo_espera'         => 'Grupo creado: lista de espera',
			'grupo_minimo'         => 'Grupo pechado por non acadar o mínimo',
		);
		return $t[ $tag ] ?? $tag;
	}

	/**
	 * One log row from an audit row, or null when it is not a mass send.
	 *
	 * @param  array<string,mixed> $a Audit row (actor_email, target_id, accion, timestamp).
	 * @return array{timestamp:string,titulo:string,lotes:int,enviados:int,fallidos:int,por:string}|null
	 */
	public static function fila( array $a ): ?array {
		$accion = (string) ( $a['accion'] ?? '' );
		$id     = (string) ( $a['target_id'] ?? '' );
		$base   = array(
			'timestamp' => (string) ( $a['timestamp'] ?? '' ),
			'por'       => (string) ( $a['actor_email'] ?? '' ),
		);
		if ( 'inicio_curso_enviado' === $accion || 'inicio_curso_erro' === $accion ) {
			$ok = 'inicio_curso_enviado' === $accion;
			return $base + array( 'titulo' => self::titulo_tag( 'inicio_curso_xunta' ), 'lotes' => 1, 'enviados' => $ok ? 1 : 0, 'fallidos' => $ok ? 0 : 1 );
		}
		if ( 'masivo' !== $accion || ! preg_match( '/^([a-z0-9_]+):(\d+)\/(\d+)\/(\d+)$/', $id, $m ) ) {
			return null;
		}
		return $base + array( 'titulo' => self::titulo_tag( $m[1] ), 'lotes' => (int) $m[2], 'enviados' => (int) $m[3], 'fallidos' => (int) $m[4] );
	}
}
