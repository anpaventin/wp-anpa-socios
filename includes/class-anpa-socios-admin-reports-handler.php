<?php
/**
 * Admin REST handler for the full export (with optional decrypted banking)
 * and the audit-log viewer (fase6 PR-5a).
 *
 * Master-only. The full export ALWAYS requires the banking passphrase (it
 * authorises the sensitive bulk egress); the admin chooses whether to include
 * decrypted banking columns. Decryption happens in-memory with the unwrapped
 * secret key. The action is audited.
 *
 * @since  1.8.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for `/admin/export/full` and `/admin/audit`.
 *
 * @since 1.8.0
 */
final class ANPA_Socios_Admin_Reports_Handler {

	/**
	 * Registers the report routes.
	 *
	 * @since  1.8.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/export/full', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'export_full' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/audit', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_audit' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		// 1.76.0: download of the (filtered) log as CSV or .ods.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/audit/export', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'export_audit' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/audit — audit-log rows (master-only). 1.76.0: filters (desde, ata
	 * as local dates, tipo, actor, q), server pagination (pax, por_pax) and each
	 * row enriched with local time, readable action and object detail.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_audit( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$f     = ANPA_Socios_Auditoria::filtros( (array) $request->get_params() );
		$table = ANPA_Socios_DB::tabela_audit_log();
		list( $where, $params ) = ANPA_Socios_Auditoria::where_filtros( self::filtros_utc( $f ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built by the pure helper.
		$total  = (int) ( array() === $params ? $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $params ) ) );
		$offset = ( $f['pax'] - 1 ) * $f['por_pax'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built by the pure helper.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, actor_email, actor_tipo, target_tipo, target_id, accion, timestamp FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $f['por_pax'], $offset ) )
			),
			ARRAY_A
		);

		return new WP_REST_Response(
			array(
				'rows'    => self::enriquecer( is_array( $rows ) ? $rows : array() ),
				'total'   => $total,
				'pax'     => $f['pax'],
				'por_pax' => $f['por_pax'],
				'tipos'   => ANPA_Socios_Auditoria::TIPOS,
			),
			200
		);
	}

	/**
	 * GET /admin/audit/export?formato=csv|ods (+ the same filters) — the log as a
	 * file, newest first, at most 20000 rows (1.76.0).
	 *
	 * @since  1.76.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function export_audit( WP_REST_Request $request ) {
		global $wpdb;

		$f     = ANPA_Socios_Auditoria::filtros( (array) $request->get_params() );
		$table = ANPA_Socios_DB::tabela_audit_log();
		list( $where, $params ) = ANPA_Socios_Auditoria::where_filtros( self::filtros_utc( $f ) );
		$sql = "SELECT id, actor_email, actor_tipo, target_tipo, target_id, accion, timestamp FROM {$table} {$where} ORDER BY id DESC LIMIT 20000";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- placeholders built by the pure helper.
		$rows = $wpdb->get_results( array() === $params ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = self::enriquecer( is_array( $rows ) ? $rows : array() );

		$cab   = ANPA_Socios_Auditoria::COLUMNAS_LOG;
		$filas = array();
		foreach ( $rows as $r ) {
			$filas[] = array( $r['data'], $r['actor_email'], $r['actor_tipo'], $r['accion_label'], $r['accion'], $r['tipo_label'], $r['target_id'], $r['detalle'] );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'auditoria', 'export_auditoria' );

		$data = current_time( 'Y-m-d' );
		if ( 'ods' === sanitize_key( (string) $request->get_param( 'formato' ) ) ) {
			$bytes = ANPA_Socios_Ods::documento( array( array( 'nome' => 'Auditoria', 'cabeceira' => $cab, 'filas' => $filas ) ) );
			if ( null === $bytes ) {
				return new WP_Error( 'anpa_admin_ods', __( 'Non se puido xerar a folla de cálculo neste servidor. Descarga o CSV.', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			return ANPA_Socios_Descarga::resposta( $bytes, ANPA_Socios_Ods::MIME, ANPA_Socios_Listado_Empresa::nome_ficheiro( array( 'ANPA', 'Auditoria' ), $data, 'ods' ) );
		}
		$assoc = array();
		foreach ( $filas as $fila ) {
			$assoc[] = array_combine( $cab, $fila );
		}
		return ANPA_Socios_Descarga::resposta( ANPA_Socios_Csv::document( $cab, $assoc ), 'text/csv; charset=utf-8', ANPA_Socios_Listado_Empresa::nome_ficheiro( array( 'ANPA', 'Auditoria' ), $data, 'csv' ) );
	}

	/**
	 * Local dates of the filters → UTC bounds (the log stores UTC).
	 *
	 * @param  array<string,mixed> $f ANPA_Socios_Auditoria::filtros() result.
	 * @return array<string,string>
	 */
	private static function filtros_utc( array $f ): array {
		$utc = static function ( string $local ): string {
			return function_exists( 'get_gmt_from_date' ) ? (string) get_gmt_from_date( $local ) : $local;
		};
		return array(
			'desde_utc' => '' !== $f['desde'] ? $utc( $f['desde'] . ' 00:00:00' ) : '',
			'ata_utc'   => '' !== $f['ata'] ? $utc( $f['ata'] . ' 23:59:59' ) : '',
			'tipo'      => $f['tipo'],
			'actor'     => $f['actor'],
			'q'         => $f['q'],
		);
	}

	/**
	 * Adds local time, readable action/type and a detail of the object
	 * (member name, pupil + activity, company, group…) with one query per type.
	 *
	 * @param  array<int,array<string,string>> $rows Audit rows.
	 * @return array<int,array<string,string>>
	 */
	private static function enriquecer( array $rows ): array {
		global $wpdb;
		$ids = array();
		foreach ( $rows as $r ) {
			$ids[ (string) $r['target_tipo'] ][ (string) $r['target_id'] ] = true;
		}
		$p       = $wpdb->prefix;
		$detalle = array();
		$consulta = static function ( string $tipo, string $sql, bool $numerico ) use ( $wpdb, $ids, &$detalle ): void {
			$claves = array_keys( $ids[ $tipo ] ?? array() );
			if ( $numerico ) {
				$claves = array_values( array_filter( array_map( 'intval', $claves ) ) );
			}
			foreach ( array_chunk( $claves, 500 ) as $lote ) {
				$ph = implode( ',', array_fill( 0, count( $lote ), $numerico ? '%d' : '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
				$res = $wpdb->get_results( $wpdb->prepare( str_replace( '{IN}', $ph, $sql ), $lote ), ARRAY_A );
				foreach ( is_array( $res ) ? $res : array() as $x ) {
					$detalle[ $tipo ][ strtolower( (string) $x['k'] ) ] = trim( (string) $x['d'] );
				}
			}
		};
		$consulta( 'socio', "SELECT email AS k, CONCAT(nome, ' ', apelidos) AS d FROM {$p}anpa_socios WHERE email IN ({IN})", false );
		$consulta( 'fillo', "SELECT id AS k, CONCAT(nome, ' ', apelidos) AS d FROM {$p}anpa_fillos WHERE id IN ({IN})", true );
		$consulta( 'empresa', "SELECT id AS k, nome AS d FROM {$p}anpa_empresas WHERE id IN ({IN})", true );
		$consulta( 'actividad', "SELECT id AS k, nome AS d FROM {$p}anpa_actividades WHERE id IN ({IN})", true );
		$consulta( 'grupo', "SELECT g.id AS k, CONCAT(COALESCE(a.nome, ''), ' - ', g.nome) AS d FROM {$p}anpa_grupos g LEFT JOIN {$p}anpa_actividades a ON a.id = g.actividad_id WHERE g.id IN ({IN})", true );
		$consulta( 'matricula', "SELECT m.id AS k, CONCAT(COALESCE(f.nome, ''), ' ', COALESCE(f.apelidos, ''), ' - ', COALESCE(a.nome, ''), IF(g.nome IS NULL, '', CONCAT(' (', g.nome, ')'))) AS d FROM {$p}anpa_matriculas m LEFT JOIN {$p}anpa_fillos f ON f.id = m.fillo_id LEFT JOIN {$p}anpa_grupos g ON g.id = m.grupo_id LEFT JOIN {$p}anpa_actividades a ON a.id = COALESCE(NULLIF(m.activitad_id, 0), g.actividad_id) WHERE m.id IN ({IN})", true );

		$out = array();
		foreach ( $rows as $r ) {
			$tipo  = (string) $r['target_tipo'];
			$id    = (string) $r['target_id'];
			$out[] = array(
				'id'           => (string) ( $r['id'] ?? '' ),
				'data'         => function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( (string) $r['timestamp'] ) : (string) $r['timestamp'],
				'actor_email'  => (string) $r['actor_email'],
				'actor_tipo'   => (string) $r['actor_tipo'],
				'accion'       => (string) $r['accion'],
				'accion_label' => ANPA_Socios_Auditoria::etiqueta( (string) $r['accion'], $tipo ),
				'target_tipo'  => $tipo,
				'tipo_label'   => ANPA_Socios_Auditoria::tipo_label( $tipo ),
				'target_id'    => $id,
				'detalle'      => $detalle[ $tipo ][ ( is_numeric( $id ) ? (string) (int) $id : strtolower( $id ) ) ] ?? '',
			);
		}
		return $out;
	}

	/**
	 * POST /admin/export/full { passphrase, include_banking } — master-only.
	 *
	 * Always requires a valid passphrase (authorises the sensitive bulk export).
	 * include_banking adds decrypted titular/IBAN/NIF columns.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function export_full( WP_REST_Request $request ) {
		global $wpdb;

		$body            = ANPA_Socios_Admin_Shared::json_body( $request );
		$passphrase      = (string) ( $body['passphrase'] ?? '' );
		$include_banking = filter_var( $body['include_banking'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( '' === $passphrase ) {
			return new WP_Error( 'anpa_admin_passphrase', __( 'Falta o contrasinal de descifrado', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$public  = ANPA_Socios_Banking_Key::public_key();
		$wrapped = ANPA_Socios_Banking_Key::wrapped_secret();
		if ( null === $public || null === $wrapped ) {
			return new WP_Error( 'anpa_admin_no_key', __( 'A clave bancaria non está configurada', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		// Brute-force lockout on the decryption passphrase (per admin actor):
		// 5 wrong attempts within 15 minutes blocks further tries.
		$actor    = (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL );
		$lock_key = 'anpa_export_pp_fail_' . md5( '' !== $actor ? $actor : 'export' );
		$fails    = (int) get_transient( $lock_key );
		if ( $fails >= 5 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'full', 'export_locked' );
			return new WP_Error( 'anpa_admin_export_locked', 'Demasiados intentos co contrasinal. Téntao de novo en 15 minutos.', array( 'status' => 429 ) );
		}

		$secret = ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], $passphrase );
		if ( null === $secret ) {
			set_transient( $lock_key, $fails + 1, 15 * MINUTE_IN_SECONDS );
			ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'full', 'export_denied' );
			return new WP_Error( 'anpa_admin_bad_passphrase', __( 'Contrasinal incorrecto', 'anpa-socios' ), array( 'status' => 403 ) );
		}
		// Correct passphrase: clear the failed-attempt counter.
		delete_transient( $lock_key );

		$socios = ANPA_Socios_DB::tabela_socios();
		$dom    = ANPA_Socios_DB::tabela_domiciliacions();

		$columns = array( 'email', 'nome', 'apelidos', 'nif', 'telefono', 'estado', 'rol', 'familia_id' );

		if ( $include_banking ) {
			// Only fetch banking ciphertext when it will actually be used.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from DB helper; master-only bulk export.
			$rows = $wpdb->get_results(
				"SELECT s.email, s.nome, s.apelidos, s.nif, s.telefono, s.estado, s.rol, IFNULL(s.familia_id, s.id) AS familia_id,
					d.titular_nome, d.titular_apelidos, d.entidade_bancaria, d.autorizacion,
					d.iban_cifrado, d.titular_nif_cifrado
				FROM {$socios} s
				LEFT JOIN {$dom} d ON d.familia_id = IFNULL(s.familia_id, s.id)
				ORDER BY s.apelidos, s.nome",
				ARRAY_A
			);
			$columns = array_merge(
				$columns,
				array( 'titular_nome', 'titular_apelidos', 'entidade_bancaria', 'iban', 'titular_nif_banco', 'autorizacion' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from DB helper; master-only export.
			$rows = $wpdb->get_results(
				"SELECT s.email, s.nome, s.apelidos, s.nif, s.telefono, s.estado, s.rol, IFNULL(s.familia_id, s.id) AS familia_id
				FROM {$socios} s
				ORDER BY s.apelidos, s.nome",
				ARRAY_A
			);
		}
		$rows = is_array( $rows ) ? $rows : array();

		$out = array();
		foreach ( $rows as $r ) {
			$line = array(
				'email'      => (string) $r['email'],
				'nome'       => (string) $r['nome'],
				'apelidos'   => (string) $r['apelidos'],
				'nif'        => (string) ( $r['nif'] ?? '' ),
				'telefono'   => (string) ( $r['telefono'] ?? '' ),
				'estado'     => (string) $r['estado'],
				'rol'        => (string) $r['rol'],
				'familia_id' => (string) $r['familia_id'],
			);
			if ( $include_banking ) {
				$has_banking               = ! empty( $r['iban_cifrado'] );
				$line['titular_nome']      = (string) ( $r['titular_nome'] ?? '' );
				$line['titular_apelidos']  = (string) ( $r['titular_apelidos'] ?? '' );
				$line['entidade_bancaria'] = (string) ( $r['entidade_bancaria'] ?? '' );
				$line['iban']              = $has_banking ? (string) ANPA_Socios_Crypto::unseal( (string) $r['iban_cifrado'], $public, $secret ) : '';
				$line['titular_nif_banco'] = $has_banking ? (string) ANPA_Socios_Crypto::unseal( (string) $r['titular_nif_cifrado'], $public, $secret ) : '';
				$line['autorizacion']      = isset( $r['autorizacion'] ) ? (string) (int) $r['autorizacion'] : '';
			}
			$out[] = $line;
		}

		sodium_memzero( $secret );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'full', $include_banking ? 'export_full_banking' : 'export_full' );

		$csv      = ANPA_Socios_Csv::document( $columns, $out );
		$filename = 'anpa-export-completo-' . gmdate( 'Y-m-d' ) . '.csv';

		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Content-Length'      => (string) strlen( $csv ),
			'Cache-Control'       => 'no-store',
			'Pragma'              => 'no-cache',
		) );

		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $csv ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/csv' ) ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV body.
					return true;
				}
			}
			return $served;
		}, 10, 2 );

		return $response;
	}
}
