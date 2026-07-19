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
            "SELECT id, codigo, etiqueta, orde, comedor_inicio, comedor_fin, estado FROM {$niveis_t} WHERE curso_escolar = %s AND estado = 'activo' ORDER BY orde ASC, codigo ASC",
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
            case 'editar_nivel':
                return self::editar_nivel( $curso, $request );
            case 'set_aulas':
                return self::set_aulas( $curso, $request );
            case 'gardar_comedor':
                return self::gardar_comedor( $curso, $request );
            case 'copiar_estrutura':
                return self::copiar_estrutura( $curso, $request );
            default:
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Acción descoñecida.', 'anpa-socios' ) ), 400 );
        }
    }

    /**
     * Valid classroom letters offered per level (A..H). Not hardcoded to any
     * subset — each level picks its own last letter within this range.
     *
     * @return string[]
     */
    private static function aula_letras(): array {
        return range( 'A', 'H' );
    }

    /**
     * Ensures a level has active aulas A..$ultima and deactivates/removes any
     * beyond it. Aulas still referenced by a fillo assignment are deactivated
     * (never hard-deleted) to preserve history; unreferenced extras are removed.
     *
     * @param  int    $nivel_id Nivel id.
     * @param  string $ultima   Last classroom letter (A..H).
     * @return bool
     */
    private static function sync_aulas_nivel( int $nivel_id, string $ultima ): bool {
        global $wpdb;

        $aulas_t = ANPA_Socios_DB::tabela_aulas();
        $fc_t    = ANPA_Socios_DB::tabela_fillos_cursos();
        $letras  = self::aula_letras();
        $ultima  = strtoupper( trim( $ultima ) );
        $max_idx = array_search( $ultima, $letras, true );
        if ( false === $max_idx ) {
            $max_idx = 3; // default to 'D' when the letter is out of range.
        }

        // Ensure A..ultima exist and are active.
        for ( $i = 0; $i <= $max_idx; $i++ ) {
            $letra = $letras[ $i ];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded upsert within admin handler.
            $written = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$aulas_t} (nivel_id, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
                 VALUES (%d, %s, %s, %d, 'activo', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE estado = 'activo', etiqueta = VALUES(etiqueta), orde = VALUES(orde), actualizado_en = NOW()",
                $nivel_id,
                $letra,
                $letra,
                ( $i + 1 ) * 10
            ) );
            if ( false === $written ) {
                return false;
            }
        }

        // Handle letters beyond ultima: deactivate if referenced, else delete.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler read.
        $extras = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, codigo FROM {$aulas_t} WHERE nivel_id = %d",
            $nivel_id
        ), ARRAY_A );
        if ( ! is_array( $extras ) ) {
            return false;
        }
        foreach ( $extras as $a ) {
            $idx = array_search( strtoupper( (string) $a['codigo'] ), $letras, true );
            if ( false !== $idx && $idx <= $max_idx ) {
                continue; // within range, already handled above.
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler read.
            $refs_result = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$fc_t} WHERE aula_id = %d",
                (int) $a['id']
            ) );
            if ( null === $refs_result ) {
                return false;
            }
            $refs = (int) $refs_result;
            if ( $refs > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler write.
                $written = $wpdb->query( $wpdb->prepare( "UPDATE {$aulas_t} SET estado = 'inactivo', actualizado_en = NOW() WHERE id = %d", (int) $a['id'] ) );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler write.
                $written = $wpdb->query( $wpdb->prepare( "DELETE FROM {$aulas_t} WHERE id = %d", (int) $a['id'] ) );
            }
            if ( false === $written ) {
                return false;
            }
        }

        return true;
    }

    /**
     * POST accion=set_aulas — set a level's last classroom letter.
     *
     * @param  string          $curso   Curso escolar.
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function set_aulas( string $curso, WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $ultima   = strtoupper( trim( (string) $request->get_param( 'ultima_aula' ) ) );
        if ( $nivel_id < 1 || ! in_array( $ultima, self::aula_letras(), true ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Datos inválidos.', 'anpa-socios' ) ), 400 );
        }

        // Confirm the nivel belongs to this curso escolar.
        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler read.
        $owned = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$niveis_t} WHERE id = %d AND curso_escolar = %s",
            $nivel_id,
            $curso
        ) );
        if ( $owned < 1 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
        }

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron actualizar as aulas.', 'anpa-socios' ) ), 500 );
        }
        if ( ! self::sync_aulas_nivel( $nivel_id, $ultima ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron actualizar as aulas.', 'anpa-socios' ) ), 500 );
        }
        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron actualizar as aulas.', 'anpa-socios' ) ), 500 );
        }

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Aulas actualizadas.', 'anpa-socios' ) ), 200 );
    }

    /**
     * Stores or clears the annual meal window for one level.
     *
     * @param  string          $curso   Curso escolar.
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    private static function gardar_comedor( string $curso, WP_REST_Request $request ) {
        global $wpdb;

        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $interval = ANPA_Socios_Disponibilidade_Horaria::normalize_interval(
            $request->get_param( 'comedor_inicio' ),
            $request->get_param( 'comedor_fin' )
        );
        if ( $nivel_id < 1 || null === $interval ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Indica as dúas horas e comproba que o inicio sexa anterior ao fin.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        if ( array() !== $interval ) {
            $conflicts = self::comedor_conflicts_for_nivel( $curso, $nivel_id, $interval );
            if ( array() !== $conflicts ) {
                return new WP_Error(
                    'anpa_admin_comedor_conflict',
                    __( 'Hai grupos abertos deste nivel que solapan co horario de comedor.', 'anpa-socios' ),
                    array(
                        'status'    => 409,
                        'conflicts' => $conflicts,
                    )
                );
            }
        }

        $updated = $wpdb->update(
            $niveis_t,
            array(
                'comedor_inicio' => $interval['inicio'] ?? null,
                'comedor_fin'    => $interval['fin'] ?? null,
                'actualizado_en' => current_time( 'mysql' ),
            ),
            array( 'id' => $nivel_id, 'curso_escolar' => $curso ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%s' )
        );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o horario de comedor.', 'anpa-socios' ) ), 500 );
        }
        if ( 0 === $updated ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$niveis_t} WHERE id = %d AND curso_escolar = %s",
                $nivel_id,
                $curso
            ) );
            if ( $exists < 1 ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
            }
        }

        ANPA_Socios_Admin_Shared::write_audit(
            $request,
            'nivel',
            (string) $nivel_id,
            array() === $interval ? 'comedor_clear' : 'gardar_comedor'
        );

        return new WP_REST_Response( array(
            'success'         => true,
            'comedor_inicio' => $interval['inicio'] ?? null,
            'comedor_fin'    => $interval['fin'] ?? null,
            'message'         => __( 'Horario de comedor actualizado.', 'anpa-socios' ),
        ), 200 );
    }

    /**
     * Returns conflicting open groups for one level's meal window.
     *
     * The lookup is a single joined query over grupos + grupos_niveis +
     * actividades + niveis so the guard stays set-based and avoids N+1.
     *
     * @param  string               $curso   Curso escolar.
     * @param  int                  $nivel_id Nivel id.
     * @param  array{inicio:string,fin:string} $interval Meal interval.
     * @return array<int,array<string,mixed>>
     */
    private static function comedor_conflicts_for_nivel( string $curso, int $nivel_id, array $interval ): array {
        global $wpdb;

        $grupos_t  = ANPA_Socios_DB::tabela_grupos();
        $gn_t      = ANPA_Socios_DB::tabela_grupos_niveis();
        $niveis_t  = ANPA_Socios_DB::tabela_niveis();
        $act_t     = ANPA_Socios_DB::tabela_actividades();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only guard query in admin handler.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.id AS grupo_id, g.nome AS grupo_nome, g.actividad_id, a.nome AS actividad_nome, g.franxa, g.dias, n.id AS nivel_id, n.codigo AS nivel_codigo
             FROM {$grupos_t} g
             INNER JOIN {$gn_t} gn ON gn.grupo_id = g.id
             INNER JOIN {$niveis_t} n ON n.id = gn.nivel_id
             INNER JOIN {$act_t} a ON a.id = g.actividad_id
             WHERE g.curso_escolar = %s
               AND g.estado = 'aberto'
               AND gn.nivel_id IN (%d)
               AND g.franxa <> ''
               AND g.dias <> ''
             ORDER BY a.nome ASC, g.nome ASC, g.id ASC",
            $curso,
            $nivel_id
        ), ARRAY_A );

        if ( ! is_array( $rows ) || array() === $rows ) {
            return array();
        }

        $conflicts = array();
        foreach ( $rows as $row ) {
            $group = array(
                'horario' => (string) ( $row['franxa'] ?? '' ),
                'dias'    => array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['dias'] ?? '' ) ) ) ) ),
            );

            if ( array() === ANPA_Socios_Disponibilidade_Horaria::conflicts(
                $group,
                array(
                    $nivel_id => $interval,
                )
            ) ) {
                continue;
            }

            $conflicts[] = array(
                'actividad_id'    => (int) ( $row['actividad_id'] ?? 0 ),
                'actividad'       => (string) ( $row['actividad_nome'] ?? '' ),
                'grupo_id'        => (int) ( $row['grupo_id'] ?? 0 ),
                'grupo'           => (string) ( $row['grupo_nome'] ?? '' ),
                'nivel_id'        => (int) ( $row['nivel_id'] ?? 0 ),
                'nivel'           => (string) ( $row['nivel_codigo'] ?? '' ),
                'dias'            => $group['dias'],
                'franxa'          => (string) ( $row['franxa'] ?? '' ),
                'comedor_inicio'  => $interval['inicio'] ?? null,
                'comedor_fin'     => $interval['fin'] ?? null,
            );
        }

        return $conflicts;
    }

    /**
     * POST accion=editar_nivel — rename a level's label/orde.
     *
     * The single "Nivel" value is stored as both `codigo` and `etiqueta`
     * (they were historically split into a relation key + a display label,
     * but the editor now uses one field to avoid the redundancy).
     *
     * @param  string          $curso   Curso escolar.
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function editar_nivel( string $curso, WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $codigo   = trim( (string) $request->get_param( 'codigo' ) );
        $orde     = (int) $request->get_param( 'orde' );
        if ( $nivel_id < 1 || '' === $codigo ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Datos inválidos.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();

        // Reject a duplicate codigo within the same curso (excluding this row).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler read.
        $dup = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$niveis_t} WHERE curso_escolar = %s AND codigo = %s AND id <> %d",
            $curso,
            $codigo,
            $nivel_id
        ) );
        if ( $dup > 0 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Xa existe outro nivel con ese código neste curso.', 'anpa-socios' ) ), 409 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler write.
        $updated = $wpdb->update(
            $niveis_t,
            array( 'codigo' => $codigo, 'etiqueta' => $codigo, 'orde' => $orde > 0 ? $orde : 10, 'actualizado_en' => current_time( 'mysql' ) ),
            array( 'id' => $nivel_id, 'curso_escolar' => $curso ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d', '%s' )
        );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel actualizado.', 'anpa-socios' ) ), 200 );
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

        // Single "Nivel" value used as both codigo (relation key) and etiqueta
        // (display) — the editor no longer asks for both separately.
        $codigo = trim( (string) $request->get_param( 'codigo' ) );
        $orde   = (int) $request->get_param( 'orde' );
        $ultima = strtoupper( trim( (string) $request->get_param( 'ultima_aula' ) ) );
        if ( ! in_array( $ultima, self::aula_letras(), true ) ) {
            $ultima = 'D';
        }

        if ( '' === $codigo ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'O nivel é obrigatorio.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();

        // Default orde to the end of the list when not provided.
        if ( $orde <= 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler read.
            $orde_result = $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(orde), 0) FROM {$niveis_t} WHERE curso_escolar = %s",
                $curso
            ) );
            if ( null === $orde_result ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
            }
            $orde = 10 + (int) $orde_result;
        }

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
        $inserted = $wpdb->insert(
            $niveis_t,
            array(
                'curso_escolar' => $curso,
                'codigo'        => $codigo,
                'etiqueta'      => $codigo,
                'orde'          => $orde,
                'estado'        => 'activo',
                'creado_en'     => current_time( 'mysql' ),
                'actualizado_en' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            if ( false !== strpos( $wpdb->last_error, 'Duplicate entry' ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Xa existe un nivel con ese código neste curso.', 'anpa-socios' ) ), 409 );
            }
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        // Create the level's classrooms A..ultima in the same transaction.
        if ( ! self::sync_aulas_nivel( (int) $wpdb->insert_id, $ultima ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

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

        $orixe          = $request->get_param( 'orixe' );
        $copiar_comedor = rest_sanitize_boolean( $request->get_param( 'copiar_comedor' ) );
        if ( ! is_string( $orixe ) || ! ANPA_Socios_Curso_Escolar::is_valid( $orixe ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso orixe inválido.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        $aulas_t  = ANPA_Socios_DB::tabela_aulas();

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido iniciar a copia da estrutura.', 'anpa-socios' ) ), 500 );
        }

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

        // Meal windows are opt-in and only fill a completely empty destination.
        $copied_comedor = $wpdb->query( $wpdb->prepare(
            "UPDATE {$niveis_t} nd
             INNER JOIN {$niveis_t} no ON no.curso_escolar = %s AND no.codigo = nd.codigo
             SET nd.comedor_inicio = no.comedor_inicio,
                 nd.comedor_fin = no.comedor_fin,
                 nd.actualizado_en = NOW()
             WHERE nd.curso_escolar = %s
               AND %d = 1 AND nd.comedor_inicio IS NULL AND nd.comedor_fin IS NULL",
            $orixe,
            $curso,
            $copiar_comedor ? 1 : 0
        ) );
        if ( false === $copied_comedor ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Erro ao copiar o horario de comedor.', 'anpa-socios' ) ), 500 );
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

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido completar a copia da estrutura.', 'anpa-socios' ) ), 500 );
        }

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

        if ( $nivel_id < 1 || ! is_string( $curso ) || ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ID de nivel inválido.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t   = ANPA_Socios_DB::tabela_niveis();
        $aulas_t    = ANPA_Socios_DB::tabela_aulas();
        $fc_t       = ANPA_Socios_DB::tabela_fillos_cursos();
        $gn_t       = ANPA_Socios_DB::tabela_grupos_niveis();

        $exists_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$niveis_t} WHERE id = %d AND curso_escolar = %s",
            $nivel_id,
            $curso
        ) );
        if ( null === $exists_result ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nivel.', 'anpa-socios' ) ), 500 );
        }
        if ( (int) $exists_result < 1 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
        }

        // Check real references (fillos_cursos, grupos_niveis).
        // NOTE: nivel_min_id / nivel_max_id in actividades_cursos are legacy
        // columns no longer functionally used — they are NOT checked here so
        // that every historically-referenced nivel is not blocked from deletion.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler.
        $fc_refs_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$fc_t} WHERE nivel_id = %d",
            $nivel_id
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler.
        $gn_refs_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$gn_t} WHERE nivel_id = %d",
            $nivel_id
        ) );
        if ( null === $fc_refs_result || null === $gn_refs_result ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar as referencias do nivel.', 'anpa-socios' ) ), 500 );
        }

        $has_references = ( (int) $fc_refs_result + (int) $gn_refs_result ) > 0;

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido actualizar o nivel.', 'anpa-socios' ) ), 500 );
        }

        // Delete or deactivate aulas.
        if ( $has_references ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $aulas_result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$aulas_t} SET estado = 'inactivo' WHERE nivel_id = %d",
                $nivel_id
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $nivel_result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$niveis_t} SET estado = 'inactivo' WHERE id = %d",
                $nivel_id
            ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $aulas_result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$aulas_t} WHERE nivel_id = %d",
                $nivel_id
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
            $nivel_result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$niveis_t} WHERE id = %d",
                $nivel_id
            ) );
        }
        if ( false === $aulas_result || false === $nivel_result ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido actualizar o nivel.', 'anpa-socios' ) ), 500 );
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido completar a actualización do nivel.', 'anpa-socios' ) ), 500 );
        }

        if ( $has_references ) {
            return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel desactivado (ten referencias activas).', 'anpa-socios' ) ), 200 );
        }

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel e aulas eliminados.', 'anpa-socios' ) ), 200 );
    }
}
