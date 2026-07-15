<?php
/**
 * Admin REST handler for the curricular-groups editor (fase24).
 *
 * Endpoints (all master-gated, audited):
 *   GET    /admin/grupos-curriculares?curso_escolar=X  — list groups + niveis
 *   POST   /admin/grupos-curriculares                  — create/update a group
 *   DELETE /admin/grupos-curriculares?id=N             — delete (blocked if in use)
 *
 * @since  1.28.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for curricular-groups CRUD.
 *
 * @since 1.28.0
 */
final class ANPA_Socios_Admin_Grupos_Curriculares_Handler {

	/**
	 * Registers the curricular-groups admin routes.
	 *
	 * @since  1.28.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/grupos-curriculares', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_grupos' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_grupo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_grupo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
	}

	/**
	 * GET /admin/grupos-curriculares?curso_escolar=X
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function get_grupos( WP_REST_Request $request ): WP_REST_Response {
		$curso = $request->get_param( 'curso_escolar' );
		if ( ! is_string( $curso ) || ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso escolar inválido.', 'anpa-socios' ) ), 400 );
		}

		return new WP_REST_Response( array(
			'success'       => true,
			'curso_escolar' => $curso,
			'grupos'        => ANPA_Socios_DB::get_grupos_curriculares( $curso, true ),
		), 200 );
	}

	/**
	 * POST /admin/grupos-curriculares — create or update a curricular group.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function post_grupo( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = isset( $body['curso_escolar'] ) ? (string) $body['curso_escolar'] : '';
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso escolar inválido.', 'anpa-socios' ) ), 400 );
		}

		$snapshot = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( $body );
		if ( array() === $snapshot ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Revisa a etiqueta, os niveis e polo menos unha franxa (mañá ou tarde).', 'anpa-socios' ),
			), 400 );
		}

		// Validate that every nivel belongs to this curso_escolar.
		$nivel_ids = array_map( 'intval', (array) ( $body['nivel_ids'] ?? array() ) );
		$nivel_ids = array_values( array_filter( $nivel_ids, static function ( $v ) { return $v > 0; } ) );
		if ( array() === $nivel_ids || ! ANPA_Socios_DB::niveis_belong_to_curso( $nivel_ids, $curso ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Os niveis seleccionados non pertencen a este curso escolar.', 'anpa-socios' ),
			), 400 );
		}

		$id       = isset( $body['id'] ) ? (int) $body['id'] : 0;
		$gc_table = ANPA_Socios_DB::tabela_grupos_curriculares();
		$gc_niv   = ANPA_Socios_DB::tabela_grupos_curriculares_niveis();
		$now      = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );

		if ( $id > 0 ) {
			$existing = ANPA_Socios_DB::get_grupo_curricular( $id );
			if ( null === $existing || (string) $existing['curso_escolar'] !== $curso ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Grupo curricular non atopado.', 'anpa-socios' ) ), 404 );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler within transaction.
			$updated = $wpdb->update(
				$gc_table,
				array(
					'etiqueta'       => $snapshot['etiqueta'],
					'orde'           => $snapshot['orde'],
					'franxa_manha'   => $snapshot['franxa_manha'],
					'franxa_tarde'   => $snapshot['franxa_tarde'],
					'actualizado_en' => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o grupo curricular.', 'anpa-socios' ) ), 500 );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- refresh niveis within transaction.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$gc_niv} WHERE grupo_curricular_id = %d", $id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler within transaction.
			$inserted = $wpdb->insert(
				$gc_table,
				array(
					'curso_escolar'  => $curso,
					'etiqueta'       => $snapshot['etiqueta'],
					'orde'           => $snapshot['orde'],
					'franxa_manha'   => $snapshot['franxa_manha'],
					'franxa_tarde'   => $snapshot['franxa_tarde'],
					'estado'         => 'activo',
					'creado_en'      => $now,
					'actualizado_en' => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_REST_Response( array(
					'success' => false,
					'message' => __( 'Xa existe un grupo curricular con esa etiqueta neste curso, ou houbo un erro.', 'anpa-socios' ),
				), 409 );
			}
			$id = (int) $wpdb->insert_id;
		}

		foreach ( $nivel_ids as $nivel_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- insert niveis within transaction.
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$gc_niv} (grupo_curricular_id, nivel_id) VALUES (%d, %d)",
				$id,
				$nivel_id
			) );
		}

		$wpdb->query( 'COMMIT' );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo_curricular', (string) $id, $id > 0 ? 'save' : 'create' );

		return new WP_REST_Response( array( 'success' => true, 'id' => $id, 'message' => __( 'Grupo curricular gardado.', 'anpa-socios' ) ), 200 );
	}

	/**
	 * DELETE /admin/grupos-curriculares?id=N
	 *
	 * Blocked (409) when the group is referenced by a yearly offer or a group.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function delete_grupo( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		if ( $id < 1 ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ID inválido.', 'anpa-socios' ) ), 400 );
		}

		if ( null === ANPA_Socios_DB::get_grupo_curricular( $id ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Grupo curricular non atopado.', 'anpa-socios' ) ), 404 );
		}

		if ( ANPA_Socios_DB::grupo_curricular_in_use( $id ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Non se pode eliminar: o grupo curricular está en uso por unha oferta de actividade ou un grupo de matrícula.', 'anpa-socios' ),
			), 409 );
		}

		$gc_table = ANPA_Socios_DB::tabela_grupos_curriculares();
		$gc_niv   = ANPA_Socios_DB::tabela_grupos_curriculares_niveis();

		$wpdb->query( 'START TRANSACTION' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- delete within transaction.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$gc_niv} WHERE grupo_curricular_id = %d", $id ) );
		$deleted = $wpdb->delete( $gc_table, array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido eliminar.', 'anpa-socios' ) ), 500 );
		}
		$wpdb->query( 'COMMIT' );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo_curricular', (string) $id, 'delete' );

		return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Grupo curricular eliminado.', 'anpa-socios' ) ), 200 );
	}
}
