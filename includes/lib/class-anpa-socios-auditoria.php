<?php
/**
 * Audit log presentation (1.76.0): readable action and object labels, which
 * rows are junta decisions for the approvals history, filter normalisation
 * and the WHERE builder for Xestión → Auditoría and its log download. Pure.
 *
 * @since   1.76.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure helpers over wp_anpa_audit_log rows.
 *
 * @since 1.76.0
 */
final class ANPA_Socios_Auditoria {

	/** Object types (target_tipo) and their labels; also the filter whitelist. */
	const TIPOS = array(
		'socio'             => 'Socio/a',
		'fillo'             => 'Fillo/a',
		'fillo_curso'       => 'Curso do fillo/a',
		'matricula'         => 'Matrícula',
		'empresa'           => 'Empresa',
		'actividad'         => 'Actividade',
		'grupo'             => 'Grupo',
		'grupo_serie'       => 'Grupo (serie)',
		'curso'             => 'Curso escolar',
		'estrutura_escolar' => 'Estrutura escolar',
		'horario_comedor'   => 'Horario de comedor',
		'domiciliacion'     => 'Datos bancarios',
		'banking_key'       => 'Clave bancaria',
		'export'            => 'Exportación',
		'import'            => 'Importación',
		'email'             => 'Correo',
	);

	/** Columns of the log download. */
	const COLUMNAS_LOG = array( 'Data', 'Quen', 'Tipo de actor', 'Acción', 'Código da acción', 'Tipo', 'Identificador', 'Detalle' );

	/** Junta decisions shown in Operacións → Aprobacións → Historial. */
	const DECISIONS = array(
		'socio'     => array(
			'approval_approve'      => array( 'Alta de socio/a', 'Aprobada', 'si' ),
			'approval_reject'       => array( 'Alta de socio/a', 'Rexeitada', 'non' ),
			'baixa_confirm'         => array( 'Baixa de socio/a', 'Confirmada', 'si' ),
			// 1.79.0: confirmed while the course was running (exception to the alta conditions).
			'baixa_confirm_excepcion' => array( 'Baixa de socio/a', 'Confirmada como excepción (curso en marcha)', 'si' ),
			// 1.80.0: from the member's edit panel, and in cascade at the end of the course.
			'baixa_directa'           => array( 'Baixa de socio/a', 'Dada de baixa dende a ficha', 'si' ),
			'baixa_directa_excepcion' => array( 'Baixa de socio/a', 'Dada de baixa dende a ficha como excepción (curso en marcha)', 'si' ),
			'baixa_fin_curso'         => array( 'Baixa de socio/a', 'Confirmada no peche do curso', 'si' ),
			'baixa_confirm_familia' => array( 'Baixa de socio/a', 'Confirmada (resto da familia)', 'si' ),
			// Rows written before 1.46.0 were cut at 20 characters.
			'baixa_confirm_famili'  => array( 'Baixa de socio/a', 'Confirmada (resto da familia)', 'si' ),
			'baixa_reject'          => array( 'Baixa de socio/a', 'Rexeitada', 'non' ),
		),
		'matricula' => array(
			'aprobada_praza'      => array( 'Matrícula', 'Aprobada con praza', 'si' ),
			'aprobada_espera'     => array( 'Matrícula', 'Aprobada en lista de espera', 'si' ),
			'matricula_rexeitada' => array( 'Matrícula', 'Rexeitada', 'non' ),
		),
	);

	/** Fixed action labels (target-independent unless noted in etiqueta()). */
	const ACCIONS = array(
		'approval_approve'           => 'Alta de socio/a aprobada',
		'approval_reject'            => 'Alta de socio/a rexeitada',
		'aprobada_praza'             => 'Matrícula aprobada con praza',
		'aprobada_espera'            => 'Matrícula aprobada en lista de espera',
		'matricula_rexeitada'        => 'Matrícula rexeitada',
		'matricula_pendente'         => 'Solicitude de matrícula fóra de prazo',
		'matricula_baixa'            => 'Baixa de matrícula feita pola familia',
		'matricula_baixa_solicitada' => 'Baixa de matrícula solicitada pola familia',
		'matricula_baixa_cancelada'  => 'Solicitude de baixa de matrícula anulada',
		'matricula_retirada'         => 'Solicitude de matrícula retirada pola familia',
		'oferta_aceptada'            => 'Oferta de praza aceptada',
		'baixa_confirm'              => 'Baixa confirmada',
		'baixa_confirm_excepcion'    => 'Baixa confirmada como excepción (curso en marcha)',
		'baixa_directa'              => 'Baixa dada dende a ficha do socio/a',
		'baixa_directa_excepcion'    => 'Baixa dada dende a ficha como excepción (curso en marcha)',
		'baixa_fin_curso'            => 'Baixa confirmada no peche do curso',
		'baixa_familia'              => 'Baixa pola baixa da familia',
		'baixa_confirm_familia'      => 'Baixa confirmada (resto da familia)',
		'baixa_confirm_famili'       => 'Baixa confirmada (resto da familia)',
		'baixa_reject'               => 'Baixa rexeitada',
		'baixa_solicitada'           => 'Baixa solicitada pola familia',
		'baixa_cancelada'            => 'Solicitude de baixa anulada pola familia',
		'reactivacion_solicitada'    => 'Reactivación solicitada',
		'alta_pendente'              => 'Alta solicitada (pendente de aprobación)',
		'alta_activa'                => 'Alta feita (activa)',
		'alta_segundo_proxenitor'    => 'Alta do 2º proxenitor',
		'fillo_engadido'             => 'Fillo/a engadido pola familia',
		'fillo_actualizado'          => 'Fillo/a modificado pola familia',
		'fillo_eliminado'            => 'Fillo/a eliminado pola familia',
		'iban_actualizado'           => 'Datos bancarios actualizados pola familia',
		'create'                     => 'Creación',
		'update'                     => 'Modificación',
		'delete'                     => 'Eliminación',
		'delete_hard'                => 'Eliminación definitiva',
		'mover'                      => 'Cambio de grupo',
		'read'                       => 'Consulta',
		'setup'                      => 'Configuración inicial',
		'decrypt_denied'             => 'Descifrado denegado',
		'decrypt_fail'               => 'Erro ao descifrar',
		'export_csv'                 => 'Exportación CSV',
		'export_csv_novas'           => 'Exportación CSV (só altas novas)',
		'export_ods'                 => 'Folla de cálculo (.ods)',
		'export_ods_empresa'         => 'Folla de cálculo (.ods) da empresa',
		'export_ods_comedor'         => 'Folla de cálculo (.ods) do comedor',
		'export_alumnos_admin'       => 'Exportación do alumnado',
		'export_alumnos_ods'         => 'Folla de cálculo (.ods) do alumnado',
		'export_alumnos_empresa'     => 'Exportación da empresa (activos)',
		'export_alumnos_empresa_todos' => 'Exportación da empresa (todos)',
		'export_alumnos_comedor'     => 'Exportación do comedor',
		'export_full'                => 'Exportación completa',
		'export_full_banking'        => 'Exportación completa con datos bancarios',
		'export_denied'              => 'Exportación denegada',
		'export_locked'              => 'Exportación bloqueada',
		'export_confirmada'          => 'Descarga confirmada',
		'export_descartada'          => 'Descarga descartada',
		'export_auditoria'           => 'Descarga do rexistro de auditoría',
		'import_commit'              => 'Importación CSV',
		'iban_import_commit'         => 'Importación de datos bancarios',
		'disable_commit'             => 'Desactivación de socios sen IBAN',
		'masivo'                     => 'Envío masivo de correo',
		'inicio_curso_enviado'       => 'Correo de inicio de curso enviado',
		'inicio_curso_erro'          => 'Erro no correo de inicio de curso',
		'aviso_comezo'               => 'Aviso «grupo creado» enviado',
		'aviso_marcado'              => 'Grupo marcado como notificado',
		'aviso_borrado'              => 'Marca de notificado retirada',
		'aviso_inicio_curso'         => 'Aviso de inicio de curso',
		'aviso_fin_curso'            => 'Aviso de fin de curso',
		'pechado_minimo'             => 'Grupo pechado por non acadar o mínimo',
		'minimo_baixas'              => 'Baixas por non acadar o mínimo',
		'curso_pechado'              => 'Curso pechado',
		'activar_pechado'            => 'Curso reactivado',
		'ventanas_pechadas'          => 'Ventás de matrícula pechadas',
		'trimestres_seed'            => 'Trimestres inicializados',
		'fin_curso_peche'            => 'Peche de fin de curso',
	);

	/**
	 * Decision (type, decision, sense si|non) or null when the row is not a junta decision.
	 *
	 * @param  string $accion      Action code.
	 * @param  string $target_tipo Object type.
	 * @return array{tipo:string,decision:string,sentido:string}|null
	 */
	public static function aprobacion( string $accion, string $target_tipo ): ?array {
		$d = self::DECISIONS[ $target_tipo ][ $accion ] ?? null;
		return null === $d ? null : array( 'tipo' => $d[0], 'decision' => $d[1], 'sentido' => $d[2] );
	}

	/**
	 * WHERE condition (alias «a») selecting the junta decisions. ASCII, no user input.
	 *
	 * @return string
	 */
	public static function where_aprobacions(): string {
		$partes = array();
		foreach ( self::DECISIONS as $tipo => $accions ) {
			$partes[] = "( a.target_tipo = '" . $tipo . "' AND a.accion IN ('" . implode( "', '", array_keys( $accions ) ) . "') )";
		}
		return implode( ' OR ', $partes );
	}

	/**
	 * Readable action.
	 *
	 * @param  string $accion      Action code.
	 * @param  string $target_tipo Object type.
	 * @return string
	 */
	public static function etiqueta( string $accion, string $target_tipo = '' ): string {
		if ( isset( self::ACCIONS[ $accion ] ) ) {
			return self::ACCIONS[ $accion ];
		}
		if ( preg_match( '/^ventana_aberta_(\d)$/', $accion, $m ) ) {
			return 'Ventá de matrícula aberta: ' . $m[1] . 'º trimestre';
		}
		if ( preg_match( '/^trimestre_activo_(\d)$/', $accion, $m ) ) {
			return 'Trimestre activo: ' . $m[1] . 'º';
		}
		if ( preg_match( '/^estado_([a-z]+)$/', $accion, $m ) ) {
			return 'Estado do grupo: ' . $m[1];
		}
		if ( preg_match( '/^matricula_creada_([a-z_]+)$/', $accion, $m ) ) {
			return 'Matrícula feita pola familia: ' . $m[1];
		}
		if ( preg_match( '/^duplicate_from_(\d+)$/', $accion, $m ) ) {
			return 'Duplicada da actividade ' . $m[1];
		}
		$texto = trim( str_replace( '_', ' ', $accion ) );
		return '' === $texto ? '' : mb_strtoupper( mb_substr( $texto, 0, 1 ) ) . mb_substr( $texto, 1 );
	}

	/**
	 * @param  string $tipo Object type.
	 * @return string Label (the code itself when unknown).
	 */
	public static function tipo_label( string $tipo ): string {
		return self::TIPOS[ $tipo ] ?? $tipo;
	}

	/**
	 * Normalised filters from the request.
	 *
	 * @param  array<string,mixed> $in Raw values (desde, ata, tipo, actor, q, pax, por_pax).
	 * @return array{desde:string,ata:string,tipo:string,actor:string,q:string,pax:int,por_pax:int}
	 */
	public static function filtros( array $in ): array {
		// Only scalar values count (an array in the query string is ignored).
		$in = array_map( static function ( $v ) { return is_scalar( $v ) ? $v : ''; }, $in );
		$data = static function ( $v ): string {
			$v = trim( (string) $v );
			return ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ? $v : '';
		};
		$tipo    = (string) ( $in['tipo'] ?? '' );
		$por_pax = (int) ( $in['por_pax'] ?? 100 );
		return array(
			'desde'   => $data( $in['desde'] ?? '' ),
			'ata'     => $data( $in['ata'] ?? '' ),
			'tipo'    => isset( self::TIPOS[ $tipo ] ) ? $tipo : '',
			'actor'   => mb_substr( strtolower( trim( (string) ( $in['actor'] ?? '' ) ) ), 0, 100 ),
			'q'       => mb_substr( trim( (string) ( $in['q'] ?? '' ) ), 0, 100 ),
			'pax'     => max( 1, (int) ( $in['pax'] ?? 1 ) ),
			'por_pax' => in_array( $por_pax, array( 50, 100, 200, 500 ), true ) ? $por_pax : 100,
		);
	}

	/**
	 * WHERE clause + params for wpdb::prepare(). Dates must already be UTC
	 * bounds («Y-m-d H:i:s»): the log stores UTC.
	 *
	 * @param  array<string,string> $f desde_utc, ata_utc, tipo, actor, q.
	 * @return array{0:string,1:array<int,string>}
	 */
	public static function where_filtros( array $f ): array {
		$cond   = array();
		$params = array();
		$like   = static function ( string $s ): string {
			return '%' . addcslashes( $s, '_%\\' ) . '%';
		};
		if ( '' !== (string) ( $f['desde_utc'] ?? '' ) ) {
			$cond[]   = 'timestamp >= %s';
			$params[] = (string) $f['desde_utc'];
		}
		if ( '' !== (string) ( $f['ata_utc'] ?? '' ) ) {
			$cond[]   = 'timestamp <= %s';
			$params[] = (string) $f['ata_utc'];
		}
		if ( '' !== (string) ( $f['tipo'] ?? '' ) ) {
			$cond[]   = 'target_tipo = %s';
			$params[] = (string) $f['tipo'];
		}
		if ( '' !== (string) ( $f['actor'] ?? '' ) ) {
			$cond[]   = 'actor_email LIKE %s';
			$params[] = $like( (string) $f['actor'] );
		}
		if ( '' !== (string) ( $f['q'] ?? '' ) ) {
			$cond[] = '( target_id LIKE %s OR accion LIKE %s OR actor_email LIKE %s )';
			$q      = $like( (string) $f['q'] );
			array_push( $params, $q, $q, $q );
		}
		return array( array() === $cond ? '' : 'WHERE ' . implode( ' AND ', $cond ), $params );
	}
}
