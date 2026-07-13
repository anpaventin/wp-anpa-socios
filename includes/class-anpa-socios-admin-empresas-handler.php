<?php
/**
 * Admin REST handler for the empresas domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/empresa*` endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Empresas_Handler {

	/**
	 * Registers empresas admin routes.
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/empresas', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_empresas' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_empresa' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/empresa/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_empresa' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_empresa' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
	}

	public static function list_empresas(): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, nome, email, responsable, telefono, url_web, estado, creado_en, actualizado_en FROM {$wpdb->prefix}anpa_empresas ORDER BY nome ASC",
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	public static function create_empresa( WP_REST_Request $request ) {
		global $wpdb;

		$body = ANPA_Socios_Admin_Shared::json_body( $request );

		// Field-level validation for specific error messages.
		$err = self::validar_campos_empresa( $body );
		if ( null !== $err ) {
			return $err;
		}

		$payload = ANPA_Socios_Admin_Payload::validar_empresa( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'anpa_empresas',
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$code = (string) $wpdb->last_error;
			if ( false !== strpos( $code, '1062' ) ) {
				return new WP_Error( 'anpa_admin_email_taken', __( 'Email xa rexistrado', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'empresa', (string) $wpdb->insert_id, 'create' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nome, email, responsable, telefono, url_web, estado, creado_en, actualizado_en FROM {$wpdb->prefix}anpa_empresas WHERE id = %d",
				$wpdb->insert_id
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $row ) ? $row : array(), 201 );
	}

	public static function update_empresa( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$body = ANPA_Socios_Admin_Shared::json_body( $request );

		// Field-level validation for specific error messages.
		$err = self::validar_campos_empresa( $body );
		if ( null !== $err ) {
			return $err;
		}

		$payload = ANPA_Socios_Admin_Payload::validar_empresa( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$payload['actualizado_en'] = current_time( 'mysql' );
		$table = ANPA_Socios_DB::tabela_empresas();
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id )
		);
		if ( 0 === $exists ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Empresa non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$updated = $wpdb->update(
			$table,
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$code = (string) $wpdb->last_error;
			if ( false !== strpos( $code, '1062' ) ) {
				return new WP_Error( 'anpa_admin_email_taken', __( 'Email xa rexistrado', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		if ( $updated > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'empresa', (string) $id, 'update' );
		}

		return new WP_REST_Response( $payload + array( 'id' => $id ), 200 );
	}

	public static function delete_empresa( WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		$table = ANPA_Socios_DB::tabela_empresas();
		$estado = $wpdb->get_var(
			$wpdb->prepare( "SELECT estado FROM {$table} WHERE id = %d", $id )
		);
		if ( null === $estado ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Empresa non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'inactivo' !== $estado ) {
			return new WP_Error( 'anpa_admin_must_deactivate', __( 'Desactiva a empresa antes de eliminala.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		// Never remove a company while any activity still references it.
		$act_table = ANPA_Socios_DB::tabela_actividades();
		$linked_act = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT nome FROM {$act_table} WHERE empresa_id = %d ORDER BY id LIMIT 1",
				$id
			)
		);
		if ( null !== $linked_act && false !== $linked_act && '' !== $linked_act ) {
			return new WP_Error(
				'anpa_admin_empresa_has_actividades',
				sprintf(
					/* translators: %s: name of the first linked activity */
					__( 'Non se pode eliminar a empresa porque ten actividades asociadas: %s', 'anpa-socios' ),
					$linked_act
				),
				array( 'status' => 409 )
			);
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 0 === $deleted ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Empresa non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'empresa', (string) $id, 'delete' );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Field-level validation for empresa required fields.
	 *
	 * Returns specific error messages for common missing/invalid fields,
	 * or null if all required fields pass basic checks.
	 *
	 * @since  1.34.0
	 * @param  array<string,mixed> $body Raw request body.
	 * @return WP_Error|null
	 */
	private static function validar_campos_empresa( array $body ) {
		$issue = ANPA_Socios_Admin_Payload::diagnosticar_empresa( $body );
		if ( null === $issue ) {
			return null;
		}
		$messages = array(
			'nome_required'        => __( 'O nome é obrigatorio.', 'anpa-socios' ),
			'responsable_required' => __( 'O responsable é obrigatorio.', 'anpa-socios' ),
			'telefono_required'    => __( 'O teléfono é obrigatorio.', 'anpa-socios' ),
			'email_required'       => __( 'O email é obrigatorio.', 'anpa-socios' ),
			'email_invalid'        => __( 'O email non é válido.', 'anpa-socios' ),
			'estado_invalid'       => __( 'O estado da empresa non é válido.', 'anpa-socios' ),
		);

		return new WP_Error( 'anpa_admin_' . $issue, $messages[ $issue ] ?? __( 'Revisa os datos da empresa.', 'anpa-socios' ), array( 'status' => 400 ) );
	}
}
