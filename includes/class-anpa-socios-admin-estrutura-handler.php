<?php
/**
 * Admin REST handler for the parametrizable school structure editor.
 *
 * Endpoints:
 *   GET  /admin/estrutura?curso_escolar=X  — list niveis + aulas
 *   POST /admin/estrutura                  — engadir nivel / copiar estrutura
 *   DELETE /admin/estrutura?nivel_id=N     — borrar/desactivar nivel
 *
 * @since  1.27.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Routes and callbacks for estrutura escolar CRUD.
 *
 * @since 1.27.0
 */
final class ANPA_Socios_Admin_Estrutura_Handler {

    /**
     * Registers estrutura admin routes.
     *
     * @since  1.27.0
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/estrutura', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'get_estrutura' ),
                'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'post_estrutura' ),
                'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( __CLASS__, 'delete_nivel' ),
                'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
            ),
        ) );
    }

    /**
     * GET /admin/estrutura?curso_escolar=X
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public static function get_estrutura( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $curso = $request->get_param( 'curso_escolar' );
        if ( ! is_string( $curso ) || ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso escolar inválido.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        $aulas_t  = ANPA_Socios_DB::tabela_aulas();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only REST endpoint.
        $niveis = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, codigo, etiqueta, orde, estado FROM {$niveis_t} WHERE curso_escolar = %s ORDER BY orde ASC, codigo ASC",
            $curso
        ), ARRAY_A );

        $nivel_ids = array();
        if ( is_array( $niveis ) ) {
            foreach ( $niveis as $n ) {
                $nivel_ids[] = (int) $n['id'];
            }
        }

        $aulas = array();
        if ( ! empty( $nivel_ids ) ) {
            $ids_str = implode( ',', $nivel_ids );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only REST endpoint.
            $rows = $wpdb->get_results(
                "SELECT id, nivel_id, codigo, etiqueta, orde, estado FROM {$aulas_t} WHERE nivel_id IN ({$ids_str}) ORDER BY orde ASC, codigo ASC",
                ARRAY_A
            );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    $nid = (int) $r['nivel_id'];
                    if ( ! isset( $aulas[ $nid ] ) ) {
                        $aulas[ $nid ] = array();
                    }
                    $aulas[ $nid ][] = $r;
                }
            }
        }

        return new WP_REST_Response( array(
            'success' => true,
            'curso_escolar' => $curso,
            'niveis'  => is_array( $niveis ) ? $niveis : array(),
            'aulas'   => $aulas,
        ), 200 );
    }

    /**
     * POST /admin/estrutura
     *
     * Supports accion=engadir_nivel and accion=copiar_estrutura.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public static function post_estrutura( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $accion = $request->get_param( 'accion' );
        $curso  = $request->get_param( 'curso_escolar' );

        if ( ! is_string( $curso ) || ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso escolar inválido.', 'anpa-socios' ) ), 400 );
        }

        switch ( $accion ) {
            case 'engadir_nivel':
                return self::engadir_nivel( $curso, $request );
            case 'copiar_estrutura':
                return self::copiar_estrutura( $curso, $request );
            default:
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Acción descoñecida.', 'anpa-socios' ) ), 400 );
        }
    }

    /**
     * Engade un novo nivel (e opcionalmente aulas) ao curso.
     *
     * @param  string          $curso   Curso escolar.
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function engadir_nivel( string $curso, WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $codigo  = trim( $request->get_param( 'codigo' ) );
        $etiqueta = trim( $request->get_param( 'etiqueta' ) );
        $orde    = (int) $request->get_param( 'orde' );

        if ( '' === $codigo || '' === $etiqueta ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Código e etiqueta son obrigatorios.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();

        // Check duplicate.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$niveis_t} WHERE curso_escolar = %s AND codigo = %s",
            $curso,
            $codigo
        ) );
        if ( null !== $existing ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Xa existe un nivel con ese código neste curso.', 'anpa-socios' ) ), 409 );
        }

        $wpdb->query( 'START TRANSACTION' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
        $inserted = $wpdb->insert(
            $niveis_t,
            array(
                'curso_escolar' => $curso,
                'codigo'        => $codigo,
                'etiqueta'      => $etiqueta,
                'orde'          => $orde,
                'estado'        => 'activo',
                'creado_en'     => current_time( 'mysql' ),
                'actualizado_en' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->query( 'COMMIT' );

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel engadido correctamente.', 'anpa-socios' ) ), 200 );
    }

    /**
     * Copia niveis e aulas doutro curso. INSERT IGNORE — non sobrescribe existentes.
     *
     * @param  string          $curso   Curso destino.
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function copiar_estrutura( string $curso, WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $orixe = $request->get_param( 'orixe' );
        if ( ! is_string( $orixe ) || ! ANPA_Socios_Curso_Escolar::is_valid( $orixe ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso orixe inválido.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        $aulas_t  = ANPA_Socios_DB::tabela_aulas();

        $wpdb->query( 'START TRANSACTION' );

        // Copy niveis: INSERT IGNORE so existing ones are preserved.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
        $copied_niveis = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$niveis_t} (curso_escolar, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
             SELECT %s, codigo, etiqueta, orde, estado, NOW(), NOW()
             FROM {$niveis_t} WHERE curso_escolar = %s",
            $curso,
            $orixe
        ) );
        if ( false === $copied_niveis ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Erro ao copiar niveis.', 'anpa-socios' ) ), 500 );
        }

        // Copy aulas: map old nivel_id → new nivel_id via codigo.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
        $copied_aulas = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$aulas_t} (nivel_id, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
             SELECT nd.id, a.codigo, a.etiqueta, a.orde, a.estado, NOW(), NOW()
             FROM {$aulas_t} a
             INNER JOIN {$niveis_t} no ON no.id = a.nivel_id AND no.curso_escolar = %s
             INNER JOIN {$niveis_t} nd ON nd.curso_escolar = %s AND nd.codigo = no.codigo",
            $orixe,
            $curso
        ) );
        if ( false === $copied_aulas ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Erro ao copiar aulas.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->query( 'COMMIT' );

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Estrutura copiada correctamente.', 'anpa-socios' ) ), 200 );
    }

    /**
     * DELETE /admin/estrutura?nivel_id=N&curso_escolar=X
     *
     * If the nivel has active references (fillos_cursos, grupos_niveis,
     * actividades_cursos) it is set to inactive instead of deleted.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public static function delete_nivel( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $curso    = $request->get_param( 'curso_escolar' );

        if ( $nivel_id < 1 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ID de nivel inválido.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t   = ANPA_Socios_DB::tabela_niveis();
        $aulas_t    = ANPA_Socios_DB::tabela_aulas();
        $fc_t       = ANPA_Socios_DB::tabela_fillos_cursos();
        $gn_t       = ANPA_Socios_DB::tabela_grupos_niveis();
        $ac_t       = ANPA_Socios_DB::tabela_actividades_cursos();

        // Check references.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler.
        $fc_refs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$fc_t} WHERE nivel_id = %d",
            $nivel_id
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler.
        $gn_refs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$gn_t} WHERE nivel_id = %d",
            $nivel_id
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler.
        $ac_refs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$ac_t} WHERE nivel_min_id = %d OR nivel_max_id = %d",
            $nivel_id,
            $nivel_id
        ) );

        $has_references = ( $fc_refs + $gn_refs + $ac_refs ) > 0;

        $wpdb->query( 'START TRANSACTION' );

        // Delete or deactivate aulas.
        if ( $has_references ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$aulas_t} SET estado = 'inactivo' WHERE nivel_id = %d",
                $nivel_id
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$niveis_t} SET estado = 'inactivo' WHERE id = %d",
                $nivel_id
            ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$aulas_t} WHERE nivel_id = %d",
                $nivel_id
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$niveis_t} WHERE id = %d",
                $nivel_id
            ) );
        }

        $wpdb->query( 'COMMIT' );

        if ( $has_references ) {
            return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel desactivado (ten referencias activas).', 'anpa-socios' ) ), 200 );
        }

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel e aulas eliminados.', 'anpa-socios' ) ), 200 );
    }
}
