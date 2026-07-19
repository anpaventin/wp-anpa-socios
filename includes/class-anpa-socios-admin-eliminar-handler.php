<?php
/**
 * Admin REST handler for complete socio deletion.
 *
 * @since  1.21.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for definitive family deletion (admin/master only).
 *
 * @since 1.21.0
 */
final class ANPA_Socios_Admin_Eliminar_Handler {

	/**
	 * Registers the delete socio route.
	 *
	 * @since  1.21.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socio/(?P<email>[^/]+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'eliminar_socio' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * DELETE /admin/socio/<email>
	 *
	 * Deletes an already-disabled socio. If related family data exists, the
	 * first request returns a structured 409 and a second, explicit family
	 * confirmation is required before deleting every operational relation.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function eliminar_socio( WP_REST_Request $request ) {
		global $wpdb;

		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Email inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return new WP_Error( 'anpa_admin_protected_root',
				'O administrador raíz non pode ser eliminado',
				array( 'status' => 403 ) );
		}

		$cascade = in_array( (string) $request->get_param( 'cascade_family' ), array( '1', 'true' ), true );
		$confirm = (string) $request->get_param( 'confirm' );
		if ( $cascade && 'ELIMINAR_FAMILIA' !== $confirm ) {
			return new WP_Error(
				'anpa_admin_family_confirmation_invalid',
				__( 'Escribe ELIMINAR_FAMILIA para confirmar o borrado completo.', 'anpa-socios' ),
				array( 'status' => 400 )
			);
		}

		$socio = self::fetch_socio( $email, false );
		if ( is_wp_error( $socio ) ) {
			return $socio;
		}
		if ( ! is_array( $socio ) ) {
			return new WP_Error( 'anpa_admin_socio_not_found', __( 'Socio non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'baixa' !== $socio['estado'] ) {
			return new WP_Error( 'anpa_admin_must_deactivate', __( 'Desactiva o socio/a antes de eliminalo definitivamente.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$context = self::load_family_context( $socio, false );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$protected = self::validate_family_members( $context['members'] );
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		if ( $context['has_dependencies'] && ! $cascade ) {
			return self::family_confirmation_error( $context['summary'] );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$locked_socio = self::fetch_socio( $email, true );
		if ( is_wp_error( $locked_socio ) || ! is_array( $locked_socio ) || 'baixa' !== $locked_socio['estado'] ) {
			$wpdb->query( 'ROLLBACK' );
			return is_wp_error( $locked_socio )
				? $locked_socio
				: new WP_Error( 'anpa_admin_delete_changed', __( 'Os datos do socio cambiaron. Recarga e inténtao de novo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$context = self::load_family_context( $locked_socio, true );
		if ( is_wp_error( $context ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $context;
		}
		$protected = self::validate_family_members( $context['members'] );
		if ( is_wp_error( $protected ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $protected;
		}
		if ( $context['has_dependencies'] && ! $cascade ) {
			$wpdb->query( 'ROLLBACK' );
			return self::family_confirmation_error( $context['summary'] );
		}

		if ( ! self::delete_family_context( $context ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$action = $context['has_dependencies'] ? 'delete_family' : 'delete';
		$audit_target = sprintf( 'familia:%d/socio:%d', (int) $context['familia_id'], (int) $locked_socio['id'] );
		if ( ! self::write_delete_audit( $request, $audit_target, $action ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_audit_error', __( 'Non se puido rexistrar a auditoría; non se eliminou ningún dato.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array(
			'message' => $context['has_dependencies']
				? __( 'Familia eliminada permanentemente.', 'anpa-socios' )
				: __( 'Socio/a eliminado permanentemente.', 'anpa-socios' ),
			'email'   => $email,
			'deleted' => array(
				'parents'           => count( $context['member_ids'] ),
				'children'          => count( $context['fillo_ids'] ),
				'enrolments'        => (int) $context['summary']['enrolments'],
				'school_assignments' => (int) $context['summary']['school_assignments'],
				'banking'           => (int) $context['summary']['banking'],
				'sessions'          => (int) $context['summary']['sessions'],
				'verification_codes' => (int) $context['summary']['verification_codes'],
			),
		), 200 );
	}

	/**
	 * Inserts the deletion audit row inside the caller's transaction.
	 *
	 * @param  WP_REST_Request $request Incoming admin request.
	 * @param  string          $target  Non-PII family/socio identifier.
	 * @param  string          $action  delete or delete_family.
	 * @return bool
	 */
	private static function write_delete_audit( WP_REST_Request $request, string $target, string $action ): bool {
		global $wpdb;

		$row = ANPA_Socios_Admin_Payload::audit_row(
			(string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL ),
			(string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ),
			'socio',
			$target,
			$action
		);

		return false !== $wpdb->insert(
			ANPA_Socios_DB::tabela_audit_log(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Loads one socio, optionally locking it for a destructive transaction.
	 *
	 * @param  string $email      Socio email.
	 * @param  bool   $for_update Add FOR UPDATE.
	 * @return array|null|WP_Error
	 */
	private static function fetch_socio( string $email, bool $for_update ) {
		global $wpdb;

		$table              = ANPA_Socios_DB::tabela_socios();
		$lock               = $for_update ? ' FOR UPDATE' : '';
		$wpdb->last_error   = '';
		$row                = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, email, estado, rol, familia_id FROM {$table} WHERE email = %s{$lock}", $email ),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Resolves every operational relation belonging to the socio's family.
	 *
	 * @param  array $socio      Requested socio row.
	 * @param  bool  $for_update Lock family/children rows.
	 * @return array|WP_Error
	 */
	private static function load_family_context( array $socio, bool $for_update ) {
		global $wpdb;

		$socios_table = ANPA_Socios_DB::tabela_socios();
		$fillos_table = ANPA_Socios_DB::tabela_fillos();
		$familia_id   = ! empty( $socio['familia_id'] ) ? (int) $socio['familia_id'] : (int) $socio['id'];
		$lock         = $for_update ? ' FOR UPDATE' : '';

		$wpdb->last_error = '';
		$members          = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, estado, rol, familia_id FROM {$socios_table} WHERE COALESCE(NULLIF(familia_id, 0), id) = %d ORDER BY id ASC{$lock}",
				$familia_id
			),
			ARRAY_A
		);
		if ( ! is_array( $members ) || '' !== $wpdb->last_error ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$member_ids = array_map( 'intval', array_column( $members, 'id' ) );
		$emails     = array_values( array_filter( array_column( $members, 'email' ) ) );
		$conditions = array( 'familia_id = %d' );
		$params     = array( $familia_id );
		if ( ! empty( $emails ) ) {
			$conditions[] = 'socio_email IN (' . implode( ',', array_fill( 0, count( $emails ), '%s' ) ) . ')';
			$params       = array_merge( $params, $emails );
		}

		$wpdb->last_error = '';
		$children         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, socio_email, familia_id FROM {$fillos_table} WHERE " . implode( ' OR ', $conditions ) . " ORDER BY id ASC{$lock}",
				$params
			),
			ARRAY_A
		);
		if ( ! is_array( $children ) || '' !== $wpdb->last_error ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$fillo_ids          = array_map( 'intval', array_column( $children, 'id' ) );
		$enrolments         = self::count_by_ids( ANPA_Socios_DB::tabela_matriculas(), 'fillo_id', $fillo_ids, $for_update );
		$school_assignments = self::count_by_ids( ANPA_Socios_DB::tabela_fillos_cursos(), 'fillo_id', $fillo_ids, $for_update );
		if ( null === $enrolments || null === $school_assignments ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$codes_table       = $wpdb->prefix . 'anpa_codigos_verificacion';
		$banking           = self::count_by_values( ANPA_Socios_DB::tabela_domiciliacions(), 'familia_id', array( $familia_id ), '%d', $for_update );
		$sessions          = self::count_by_values( ANPA_Socios_DB::tabela_sesions(), 'email', $emails, '%s', $for_update );
		$verification_codes = self::count_by_values( $codes_table, 'email', $emails, '%s', $for_update );
		if ( null === $banking || null === $sessions || null === $verification_codes ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return array(
			'familia_id'      => $familia_id,
			'members'         => $members,
			'member_ids'      => $member_ids,
			'emails'          => $emails,
			'fillo_ids'       => $fillo_ids,
			'has_dependencies' => count( $members ) > 1 || ! empty( $fillo_ids ) || $banking > 0,
			'summary'         => array(
				'other_parents'      => max( 0, count( $members ) - 1 ),
				'children'           => count( $children ),
				'enrolments'         => $enrolments,
				'school_assignments' => $school_assignments,
				'banking'            => $banking,
				'sessions'           => $sessions,
				'verification_codes' => $verification_codes,
				'has_banking'        => $banking > 0,
			),
		);
	}

	/**
	 * Blocks any family containing a protected or master account.
	 *
	 * @param  array $members Family socio rows.
	 * @return null|WP_Error
	 */
	private static function validate_family_members( array $members ) {
		$root = ANPA_Socios_Config::master_email();
		foreach ( $members as $member ) {
			if ( 'master' === $member['rol'] || ( ! empty( $member['email'] ) && ANPA_Socios_Roles::is_protected_admin( (string) $member['email'], $root ) ) ) {
				return new WP_Error( 'anpa_admin_master_delete', __( 'Non se pode eliminar unha familia que inclúe un administrador.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
		}

		return null;
	}

	/**
	 * Returns the structured conflict consumed by the two-step admin UI.
	 *
	 * @param  array $summary Family dependency summary.
	 * @return WP_Error
	 */
	private static function family_confirmation_error( array $summary ): WP_Error {
		return new WP_Error(
			'anpa_admin_family_confirmation_required',
			__( 'A eliminación inclúe outro proxenitor ou datos familiares. Confirma o borrado completo da familia.', 'anpa-socios' ),
			array(
				'status'                       => 409,
				'requires_family_confirmation' => true,
				'summary'                      => $summary,
			)
		);
	}

	/**
	 * Counts rows whose foreign key is one of the supplied integer ids.
	 *
	 * @param  string $table  Trusted table name.
	 * @param  string $column Trusted column name.
	 * @param  array  $ids        Integer ids.
	 * @param  bool   $for_update Lock matching rows before destructive work.
	 * @return int|null
	 */
	private static function count_by_ids( string $table, string $column, array $ids, bool $for_update ): ?int {
		return self::count_by_values( $table, $column, array_map( 'intval', $ids ), '%d', $for_update );
	}

	/**
	 * Counts and optionally locks rows matching trusted scalar values.
	 *
	 * @param  string $table       Trusted table name.
	 * @param  string $column      Trusted column name.
	 * @param  array  $values      Scalar values.
	 * @param  string $placeholder %d or %s.
	 * @param  bool   $for_update  Lock matching rows.
	 * @return int|null
	 */
	private static function count_by_values( string $table, string $column, array $values, string $placeholder, bool $for_update ): ?int {
		if ( empty( $values ) ) {
			return 0;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$lock             = $for_update ? ' FOR UPDATE' : '';
		$rows              = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE {$column} IN (" . implode( ',', array_fill( 0, count( $values ), $placeholder ) ) . "){$lock}",
				$values
			)
		);

		return '' === $wpdb->last_error && is_array( $rows ) ? count( $rows ) : null;
	}

	/**
	 * Deletes every family relation. Caller owns the transaction.
	 *
	 * @param  array $context Locked family context.
	 * @return bool
	 */
	private static function delete_family_context( array $context ): bool {
		global $wpdb;

		$fillo_ids = $context['fillo_ids'];
		if ( ! self::delete_by_ids( ANPA_Socios_DB::tabela_matriculas(), 'fillo_id', $fillo_ids ) ) {
			return false;
		}
		if ( ! self::delete_by_ids( ANPA_Socios_DB::tabela_fillos_cursos(), 'fillo_id', $fillo_ids ) ) {
			return false;
		}
		if ( ! self::delete_by_ids( ANPA_Socios_DB::tabela_fillos(), 'id', $fillo_ids ) ) {
			return false;
		}
		if ( false === $wpdb->delete( ANPA_Socios_DB::tabela_domiciliacions(), array( 'familia_id' => $context['familia_id'] ), array( '%d' ) ) ) {
			return false;
		}
		if ( ! self::delete_by_values( ANPA_Socios_DB::tabela_sesions(), 'email', $context['emails'], '%s' ) ) {
			return false;
		}
		$codes_table = $wpdb->prefix . 'anpa_codigos_verificacion';
		if ( ! self::delete_by_values( $codes_table, 'email', $context['emails'], '%s' ) ) {
			return false;
		}

		return self::delete_by_ids( ANPA_Socios_DB::tabela_socios(), 'id', $context['member_ids'], count( $context['member_ids'] ) );
	}

	/**
	 * Deletes rows by integer ids.
	 *
	 * @param  string   $table          Trusted table name.
	 * @param  string   $column         Trusted column name.
	 * @param  array    $ids            Integer ids.
	 * @param  int|null $expected_count Optional exact affected-row count.
	 * @return bool
	 */
	private static function delete_by_ids( string $table, string $column, array $ids, ?int $expected_count = null ): bool {
		return self::delete_by_values( $table, $column, array_map( 'intval', $ids ), '%d', $expected_count );
	}

	/**
	 * Deletes rows using a prepared IN clause.
	 *
	 * @param  string   $table          Trusted table name.
	 * @param  string   $column         Trusted column name.
	 * @param  array    $values         Scalar values.
	 * @param  string   $placeholder    %d or %s.
	 * @param  int|null $expected_count Optional exact affected-row count.
	 * @return bool
	 */
	private static function delete_by_values( string $table, string $column, array $values, string $placeholder, ?int $expected_count = null ): bool {
		if ( empty( $values ) ) {
			return null === $expected_count || 0 === $expected_count;
		}
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE {$column} IN (" . implode( ',', array_fill( 0, count( $values ), $placeholder ) ) . ')',
				$values
			)
		);

		return false !== $result && ( null === $expected_count || $expected_count === (int) $result );
	}
}
