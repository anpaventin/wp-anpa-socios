<?php
/**
 * Admin REST handler for the parametrizable school structure editor.
 *
 * Endpoints:
 *   GET  /admin/estrutura?curso_escolar=X  — list niveis + aulas
 *   POST /admin/estrutura                  — engadir nivel / gardar estrutura
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
     * GET /admin/estrutura
     *
     * Levels are global since 1.35.0; this endpoint returns the full catalogue
     * plus the per-level aulas and the optional meal schedule list for the
     * requested course.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public static function get_estrutura( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $curso = $request->get_param( 'curso_escolar' );
        $curso = is_string( $curso ) && ANPA_Socios_Curso_Escolar::is_valid( $curso ) ? $curso : '';

        $niveis_t   = ANPA_Socios_DB::tabela_niveis();
        $aulas_t    = ANPA_Socios_DB::tabela_aulas();
        $horarios_t = ANPA_Socios_DB::tabela_horarios_comedor();

        $niveis = ANPA_Socios_DB::get_niveis();
        if ( ! is_array( $niveis ) ) {
            $niveis = array();
        }

        // Optional course-scoped meal schedules still come from the selected course.
        $horarios = array();
        if ( '' !== $curso ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only REST endpoint.
            $horarios = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, nome, inicio, fin, orde, estado FROM {$horarios_t} WHERE curso_escolar = %s AND estado = 'activo' ORDER BY orde ASC, nome ASC, id ASC",
                $curso
            ), ARRAY_A );
        }

        $nivel_ids = array();
        foreach ( $niveis as $n ) {
            if ( isset( $n['id'] ) ) {
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

        // fase31: per-course comedor assignment (nivel_id → horario_comedor_id)
        // resolved from the pivot, since levels are global but comedor is per
        // course.
        $comedor_por_nivel = '' !== $curso ? ANPA_Socios_DB::get_niveis_comedor_curso( $curso ) : array();

        return new WP_REST_Response( array(
            'success'           => true,
            'curso_escolar'     => $curso,
            'niveis'            => $niveis,
            'aulas'             => $aulas,
            'horarios_comedor'  => is_array( $horarios ) ? $horarios : array(),
            'comedor_por_nivel' => $comedor_por_nivel,
        ), 200 );
    }

    /**
     * POST /admin/estrutura
     *
     * Supports accion=engadir_nivel and accion=copiar_estrutura.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public static function post_estrutura( WP_REST_Request $request ) {
        global $wpdb;

        $accion = $request->get_param( 'accion' );

        switch ( $accion ) {
            case 'engadir_nivel':
                return self::engadir_nivel( $request );
            case 'editar_nivel':
                return self::editar_nivel( $request );
            case 'toggle_nivel':
                return self::toggle_nivel( $request );
            case 'rename_nivel':
                return self::rename_nivel( $request );
        }

        $curso = $request->get_param( 'curso_escolar' );
        if ( ! is_string( $curso ) || ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso escolar inválido.', 'anpa-socios' ) ), 400 );
        }

        switch ( $accion ) {
            case 'set_aulas':
                return self::set_aulas( $request );
            case 'gardar_estrutura':
                return self::gardar_estrutura_lote( $curso, $request );
            case 'eliminar_horario':
                return self::eliminar_horario_comedor( $curso, $request );
            case 'gardar_comedor':
                return self::gardar_comedor( $curso, $request );
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

    /** Returns a strict positive integer without coercing malformed input. */
    private static function positive_integer_input( $value ): ?int {
        if ( is_int( $value ) && $value > 0 ) {
            return $value;
        }
        if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
            return (int) $value;
        }
        return null;
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
            "SELECT id, codigo FROM {$aulas_t} WHERE nivel_id = %d ORDER BY id FOR UPDATE",
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
            $wpdb->last_error = '';
            $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$fc_t} WHERE aula_id = %d ORDER BY id FOR UPDATE",
                (int) $a['id']
            ) );
            if ( '' !== (string) $wpdb->last_error ) {
                return false;
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
     * Levels are global, so the level lock only checks existence.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function set_aulas( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $ultima   = strtoupper( trim( (string) $request->get_param( 'ultima_aula' ) ) );
        if ( $nivel_id < 1 || ! in_array( $ultima, self::aula_letras(), true ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Datos inválidos.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        $wpdb->last_error = '';
        $owned_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$niveis_t} WHERE id = %d",
            $nivel_id
        ) );
        if ( null === $owned_result || '' !== (string) $wpdb->last_error ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nivel.', 'anpa-socios' ) ), 500 );
        }
        if ( (int) $owned_result < 1 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
        }

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron actualizar as aulas.', 'anpa-socios' ) ), 500 );
        }
        $wpdb->last_error = '';
        $locked_nivel = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$niveis_t} WHERE id = %d FOR UPDATE",
            $nivel_id
        ) );
        if ( null === $locked_nivel || '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido bloquear o nivel.', 'anpa-socios' ) ), 500 );
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
     * Saves all visible levels, classroom ranges and reusable meal schedules.
     *
     * Validation and conflict checks happen before the transaction. Existing
     * level codes are temporarily moved inside the transaction so exchanging
     * two names in one save does not trip the annual unique key.
     *
     * @param  string           $curso   Curso escolar.
     * @param  WP_REST_Request  $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    private static function gardar_estrutura_lote( string $curso, WP_REST_Request $request ) {
        global $wpdb;

        $scope = sanitize_key( (string) $request->get_param( 'scope' ) );
        $scope = '' !== $scope ? $scope : 'todo';
        if ( ! in_array( $scope, array( 'todo', 'horarios', 'niveis' ), true ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Ámbito de gardado inválido.', 'anpa-socios' ) ), 400 );
        }
        $save_horarios = in_array( $scope, array( 'todo', 'horarios' ), true );
        $save_niveis   = in_array( $scope, array( 'todo', 'niveis' ), true );

        $raw_niveis  = $request->get_param( 'niveis' );
        $raw_horarios = $request->get_param( 'horarios_comedor' );
        if ( ! ANPA_Socios_Estrutura_Escolar::has_supported_collection_sizes( $raw_niveis, $raw_horarios ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'A estrutura enviada non é válida.', 'anpa-socios' ) ), 400 );
        }

        $horarios = array();
        $seen_keys = array();
        $seen_windows = array();
        foreach ( $raw_horarios as $index => $raw ) {
            if ( ! is_array( $raw ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Hai un horario de comedor inválido.', 'anpa-socios' ) ), 400 );
            }
            $key = sanitize_key( (string) ( $raw['key'] ?? 'novo-' . $index ) );
            $id  = absint( $raw['id'] ?? 0 );
            $nome = trim( sanitize_text_field( (string) ( $raw['nome'] ?? '' ) ) );
            $interval = ANPA_Socios_Disponibilidade_Horaria::normalize_interval( $raw['inicio'] ?? null, $raw['fin'] ?? null );
            if ( '' === $key || '' === $nome || null === $interval || array() === $interval || strlen( $nome ) > 80 ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Completa o nome, inicio e fin de cada horario de comedor.', 'anpa-socios' ) ), 400 );
            }
            $window_key = $interval['inicio'] . '-' . $interval['fin'];
            if ( isset( $seen_keys[ $key ] ) || isset( $seen_windows[ $window_key ] ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non pode haber horarios de comedor duplicados.', 'anpa-socios' ) ), 409 );
            }
            $seen_keys[ $key ] = true;
            $seen_windows[ $window_key ] = true;
            $horarios[ $key ] = array(
                'id'     => $id,
                'nome'   => $nome,
                'inicio' => $interval['inicio'],
                'fin'    => $interval['fin'],
                'orde'   => max( 1, absint( $raw['orde'] ?? ( ( $index + 1 ) * 10 ) ) ),
            );
        }

        $niveis = array();
        $seen_codes = array();
        $seen_ages = array();
        $submitted_ids = array();
        foreach ( $raw_niveis as $index => $raw ) {
            if ( ! is_array( $raw ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Hai un nivel inválido.', 'anpa-socios' ) ), 400 );
            }
            $id = absint( $raw['id'] ?? 0 );
            $codigo = trim( sanitize_text_field( (string) ( $raw['codigo'] ?? '' ) ) );
            $age = self::positive_integer_input( $raw['orde'] ?? null );
            $ultima = strtoupper( trim( sanitize_text_field( (string) ( $raw['ultima_aula'] ?? '' ) ) ) );
            $horario_key = sanitize_key( (string) ( $raw['horario_comedor_key'] ?? '' ) );
            if ( '' === $codigo || strlen( $codigo ) > 30 || ! in_array( $ultima, self::aula_letras(), true ) || ( '' !== $horario_key && ! isset( $horarios[ $horario_key ] ) ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Revisa o nome, a última aula e o horario asignado a cada nivel.', 'anpa-socios' ) ), 400 );
            }
            $canonical_code = strtoupper( $codigo );
            if ( isset( $seen_codes[ $canonical_code ] ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non pode haber dous niveis co mesmo nome.', 'anpa-socios' ) ), 409 );
            }
            $seen_codes[ $canonical_code ] = true;
            if ( null === $age ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Indica unha idade válida para cada nivel.', 'anpa-socios' ) ), 400 );
            }
            if ( isset( $seen_ages[ $age ] ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non pode haber dous niveis coa mesma idade do alumnado.', 'anpa-socios' ) ), 409 );
            }
            $seen_ages[ $age ] = true;
            if ( $id > 0 ) {
                $submitted_ids[ $id ] = true;
            }
            $niveis[] = array(
                'id'                   => $id,
                'codigo'               => $codigo,
                'orde'                 => $age,
                'ultima_aula'          => $ultima,
                'horario_comedor_key'  => $horario_key,
            );
        }

        $niveis_t   = ANPA_Socios_DB::tabela_niveis();
        $horarios_t = ANPA_Socios_DB::tabela_horarios_comedor();
        $pivot_t    = ANPA_Socios_DB::tabela_niveis_curso();
        $changed_horario_ids     = array();
        $current_nivel_horarios  = array();

        // Ownership and duplicate checks are fail-closed before any write.
        foreach ( $horarios as $horario ) {
            if ( $horario['id'] > 0 ) {
                $wpdb->last_error = '';
                $owned = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$horarios_t} WHERE id = %d AND curso_escolar = %s", $horario['id'], $curso ) );
                if ( null === $owned || '' !== (string) $wpdb->last_error ) {
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o horario de comedor.', 'anpa-socios' ) ), 500 );
                }
                if ( (int) $owned < 1 ) {
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Horario de comedor non atopado.', 'anpa-socios' ) ), 404 );
                }
                $wpdb->last_error = '';
                $current_interval = $wpdb->get_row( $wpdb->prepare( "SELECT inicio, fin FROM {$horarios_t} WHERE id = %d AND curso_escolar = %s", $horario['id'], $curso ), ARRAY_A );
                if ( ! is_array( $current_interval ) || '' !== (string) $wpdb->last_error ) {
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar a franxa do horario.', 'anpa-socios' ) ), 500 );
                }
                if ( $horario['inicio'] !== (string) $current_interval['inicio'] || $horario['fin'] !== (string) $current_interval['fin'] ) {
                    $changed_horario_ids[ $horario['id'] ] = true;
                }
            } elseif ( ! $save_horarios ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Garda primeiro os horarios de comedor novos.', 'anpa-socios' ) ), 409 );
            }
        }
        foreach ( $niveis as $nivel ) {
            if ( $nivel['id'] > 0 ) {
                $wpdb->last_error = '';
                $owned = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$niveis_t} WHERE id = %d", $nivel['id'] ) );
                if ( null === $owned || '' !== (string) $wpdb->last_error ) {
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nivel.', 'anpa-socios' ) ), 500 );
                }
                if ( (int) $owned < 1 ) {
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
                }
                // fase31: the current comedor assignment is per-course (pivot).
                // No pivot row means "no comedor that course" (null → 0), which
                // is not an error.
                $wpdb->last_error = '';
                $current_horario = $wpdb->get_var( $wpdb->prepare( "SELECT horario_comedor_id FROM {$pivot_t} WHERE nivel_id = %d AND curso_escolar = %s", $nivel['id'], $curso ) );
                if ( '' !== (string) $wpdb->last_error ) {
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o horario asignado ao nivel.', 'anpa-socios' ) ), 500 );
                }
                $current_nivel_horarios[ $nivel['id'] ] = (int) $current_horario;
            }
            $wpdb->last_error = '';
            $duplicate_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$niveis_t} WHERE codigo = %s AND estado = 'activo' AND id <> %d LIMIT 1", $nivel['codigo'], $nivel['id'] ) );
            if ( '' !== (string) $wpdb->last_error ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar os niveis duplicados.', 'anpa-socios' ) ), 500 );
            }
            if ( null !== $duplicate_id && (int) $duplicate_id !== $nivel['id'] && ! isset( $submitted_ids[ (int) $duplicate_id ] ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Xa existe outro nivel con ese nome neste curso.', 'anpa-socios' ) ), 409 );
            }
        }

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido iniciar o gardado da estrutura.', 'anpa-socios' ) ), 500 );
        }

        $niveis_by_id = array();
        foreach ( $niveis as $nivel ) {
            if ( $nivel['id'] > 0 ) {
                $niveis_by_id[ $nivel['id'] ] = $nivel;
            }
        }
        $nivel_ids = array_keys( $niveis_by_id );
        sort( $nivel_ids, SORT_NUMERIC );
        foreach ( $nivel_ids as $nivel_id ) {
            $wpdb->last_error = '';
            $locked_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$niveis_t} WHERE id = %d FOR UPDATE",
                $nivel_id
            ) );
            if ( null === $locked_id || '' !== (string) $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
            }
        }
        $horario_ids_to_lock = array();
        foreach ( $horarios as $horario ) {
            if ( $horario['id'] > 0 ) {
                $horario_ids_to_lock[] = $horario['id'];
            }
        }
        $horario_ids_to_lock = array_values( array_unique( $horario_ids_to_lock ) );
        sort( $horario_ids_to_lock, SORT_NUMERIC );
        foreach ( $horario_ids_to_lock as $horario_id_to_lock ) {
            $wpdb->last_error = '';
            $locked_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$horarios_t} WHERE id = %d AND curso_escolar = %s FOR UPDATE",
                $horario_id_to_lock,
                $curso
            ) );
            if ( null === $locked_id || '' !== (string) $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Horario de comedor non atopado.', 'anpa-socios' ) ), 404 );
            }
        }
        foreach ( $nivel_ids as $nivel_id ) {
            $nivel = $niveis_by_id[ $nivel_id ];
            if ( '' === $nivel['horario_comedor_key'] ) {
                continue;
            }
            $interval = $horarios[ $nivel['horario_comedor_key'] ];
            $proposed_horario_id = (int) $interval['id'];
            if ( 'horarios' === $scope && ( $proposed_horario_id < 1 || ! isset( $changed_horario_ids[ $proposed_horario_id ] ) ) ) {
                continue;
            }
            if ( 'niveis' === $scope && ( $current_nivel_horarios[ $nivel_id ] ?? 0 ) === $proposed_horario_id ) {
                continue;
            }
            if ( ! self::lock_comedor_group_rows( $nivel_id ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear os grupos asociados.', 'anpa-socios' ) ), 500 );
            }
            $conflicts = self::comedor_conflicts_for_nivel( $curso, $nivel_id, array( 'inicio' => $interval['inicio'], 'fin' => $interval['fin'] ) );
            if ( null === $conflicts ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar os conflitos de comedor.', 'anpa-socios' ) ), 500 );
            }
            if ( array() !== $conflicts ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'anpa_admin_comedor_conflict', __( 'Hai grupos abertos que solapan cun horario de comedor asignado.', 'anpa-socios' ), array( 'status' => 409, 'conflicts' => $conflicts ) );
            }
        }

        $horario_ids = array();
        if ( $save_horarios ) {
            foreach ( $horarios as $key => $horario ) {
                if ( $horario['id'] > 0 ) {
                    $written = $wpdb->update(
                        $horarios_t,
                        array( 'nome' => $horario['nome'], 'inicio' => $horario['inicio'], 'fin' => $horario['fin'], 'orde' => $horario['orde'], 'estado' => 'activo', 'actualizado_en' => current_time( 'mysql' ) ),
                        array( 'id' => $horario['id'], 'curso_escolar' => $curso ),
                        array( '%s', '%s', '%s', '%d', '%s', '%s' ),
                        array( '%d', '%s' )
                    );
                    $horario_id = $horario['id'];
                } else {
                    $written = $wpdb->insert(
                        $horarios_t,
                        array( 'curso_escolar' => $curso, 'nome' => $horario['nome'], 'inicio' => $horario['inicio'], 'fin' => $horario['fin'], 'orde' => $horario['orde'], 'estado' => 'activo', 'creado_en' => current_time( 'mysql' ), 'actualizado_en' => current_time( 'mysql' ) ),
                        array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
                    );
                    $horario_id = (int) $wpdb->insert_id;
                }
                if ( false === $written || $horario_id < 1 ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron gardar os horarios de comedor.', 'anpa-socios' ) ), 500 );
                }
                $horario_ids[ $key ] = $horario_id;
            }
        } else {
            foreach ( $horarios as $key => $horario ) {
                if ( $horario['id'] < 1 ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Garda primeiro os horarios de comedor novos.', 'anpa-socios' ) ), 409 );
                }
                $horario_ids[ $key ] = $horario['id'];
            }
        }

        if ( $save_niveis ) {
            // Temporary unique codes allow swaps in the same atomic snapshot.
            foreach ( $niveis as $nivel ) {
                if ( $nivel['id'] > 0 ) {
                    $temporary = '__bulk_' . $nivel['id'];
                    if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$niveis_t} SET codigo = %s WHERE id = %d", $temporary, $nivel['id'] ) ) ) {
                        $wpdb->query( 'ROLLBACK' );
                        return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron preparar os niveis.', 'anpa-socios' ) ), 500 );
                    }
                }
            }

            foreach ( $niveis as $nivel ) {
                $horario_key = $nivel['horario_comedor_key'];
                $horario_id  = '' !== $horario_key ? $horario_ids[ $horario_key ] : null;
                $nivel_id    = $nivel['id'];

                if ( $nivel_id < 1 ) {
                    $nivel_id = 0;
                }

                // Levels are global (since 1.35.0) and their comedor is stored
                // per-course in the pivot (since 1.36.0); the niveis row no longer
                // carries horario_comedor_id/comedor_inicio/comedor_fin (dropped
                // in 1.37.0). The comedor assignment is written below via the
                // pivot (set_nivel_comedor).
                $values = array(
                    'codigo'         => $nivel['codigo'],
                    'etiqueta'       => $nivel['codigo'],
                    'orde'           => $nivel['orde'],
                    'estado'         => 'activo',
                    'actualizado_en' => current_time( 'mysql' ),
                );
                if ( $nivel_id > 0 ) {
                		$written = $wpdb->update( $niveis_t, $values, array( 'id' => $nivel_id ), array( '%s', '%s', '%d', '%s', '%s' ), array( '%d' ) );
                	} else {
                		$values['creado_en'] = current_time( 'mysql' );
                		$written = $wpdb->insert( $niveis_t, $values, array( '%s', '%s', '%d', '%s', '%s', '%s' ) );
                		$nivel_id = (int) $wpdb->insert_id;
                	}
                if ( false === $written || $nivel_id < 1 || ! self::sync_aulas_nivel( $nivel_id, $nivel['ultima_aula'] ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar a estrutura completa.', 'anpa-socios' ) ), 500 );
                }

                // fase31: authoritative per-course comedor assignment lives in the
                // pivot. The niveis.horario_comedor_id above is kept only as the
                // legacy compatibility bridge until 1.37.0 drops it.
                if ( ! ANPA_Socios_DB::set_nivel_comedor( $nivel_id, $curso, null !== $horario_id ? (int) $horario_id : null ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar a asignación de comedor.', 'anpa-socios' ) ), 500 );
                }
            }
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido completar o gardado da estrutura.', 'anpa-socios' ) ), 500 );
        }

        $audit_action = 'horarios' === $scope ? 'gardar_horarios' : ( 'niveis' === $scope ? 'gardar_niveis' : 'gardar_estrutura' );
        ANPA_Socios_Admin_Shared::write_audit( $request, 'estrutura_escolar', $curso, $audit_action );

        $message = 'horarios' === $scope
            ? __( 'Horarios de comedor gardados correctamente.', 'anpa-socios' )
            : ( 'niveis' === $scope ? __( 'Cambios nos niveis gardados correctamente.', 'anpa-socios' ) : __( 'Estrutura e horarios gardados correctamente.', 'anpa-socios' ) );
        return new WP_REST_Response( array( 'success' => true, 'message' => $message ), 200 );
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

        $niveis_t   = ANPA_Socios_DB::tabela_niveis();
        $horarios_t = ANPA_Socios_DB::tabela_horarios_comedor();

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido iniciar o gardado do horario.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->last_error = '';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$niveis_t} WHERE id = %d FOR UPDATE",
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nivel.', 'anpa-socios' ) ), 500 );
        }
        if ( null === $exists ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
        }

        if ( array() !== $interval ) {
            $wpdb->last_error = '';
            $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$horarios_t} WHERE curso_escolar = %s AND inicio = %s AND fin = %s FOR UPDATE",
                $curso,
                $interval['inicio'],
                $interval['fin']
            ) );
            if ( '' !== (string) $wpdb->last_error || ! self::lock_comedor_group_rows( $nivel_id ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear os datos do horario.', 'anpa-socios' ) ), 500 );
            }
            $conflicts = self::comedor_conflicts_for_nivel( $curso, $nivel_id, $interval );
            if ( null === $conflicts ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar os conflitos de comedor.', 'anpa-socios' ) ), 500 );
            }
            if ( array() !== $conflicts ) {
                $wpdb->query( 'ROLLBACK' );
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

        $horario_id = null;
        if ( array() !== $interval ) {
            $inserted = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$horarios_t} (curso_escolar, nome, inicio, fin, orde, estado, creado_en, actualizado_en)
                 VALUES (%s, %s, %s, %s, 10, 'activo', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), estado = 'activo', actualizado_en = NOW()",
                $curso,
                sprintf( __( 'Horario comedor %1$s-%2$s', 'anpa-socios' ), $interval['inicio'], $interval['fin'] ),
                $interval['inicio'],
                $interval['fin']
            ) );
            $horario_id = (int) $wpdb->insert_id;
            if ( false === $inserted || $horario_id < 1 ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido crear o horario de comedor.', 'anpa-socios' ) ), 500 );
            }
        }

        // fase31: comedor is stored per-course in the pivot ONLY. The niveis
        // bridge columns (horario_comedor_id/comedor_inicio/comedor_fin) were
        // dropped in 1.37.0. Clearing the window (null id) removes the pivot row.
        if ( ! ANPA_Socios_DB::set_nivel_comedor( $nivel_id, $curso, null !== $horario_id ? (int) $horario_id : null ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o horario de comedor.', 'anpa-socios' ) ), 500 );
        }
        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o horario de comedor.', 'anpa-socios' ) ), 500 );
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
     * Deletes one reusable meal schedule only after every level is unassigned.
     *
     * @param  string          $curso   Curso escolar.
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    private static function eliminar_horario_comedor( string $curso, WP_REST_Request $request ) {
        global $wpdb;

        $horario_id = (int) $request->get_param( 'horario_id' );
        if ( $horario_id < 1 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Horario de comedor inválido.', 'anpa-socios' ) ), 400 );
        }

        $horarios_t = ANPA_Socios_DB::tabela_horarios_comedor();
        // fase31: comedor→nivel references live in the per-course pivot
        // (wp_anpa_niveis_curso) since 1.36.0; the legacy niveis.horario_comedor_id
        // column was dropped in 1.37.0, so the reference check must target the pivot.
        $pivot_t = ANPA_Socios_DB::tabela_niveis_curso();

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido iniciar a eliminación do horario.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->last_error = '';
        $wpdb->get_col( $wpdb->prepare(
            "SELECT nivel_id FROM {$pivot_t} WHERE horario_comedor_id = %d ORDER BY nivel_id FOR UPDATE",
            $horario_id
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear os niveis asociados.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->last_error = '';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$horarios_t} WHERE id = %d AND curso_escolar = %s FOR UPDATE",
            $horario_id,
            $curso
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o horario.', 'anpa-socios' ) ), 500 );
        }
        if ( null === $exists ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Horario de comedor non atopado.', 'anpa-socios' ) ), 404 );
        }

        $wpdb->last_error = '';
        $references = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pivot_t} WHERE horario_comedor_id = %d",
            $horario_id
        ) );
        if ( null === $references || '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar os niveis asociados.', 'anpa-socios' ) ), 500 );
        }
        if ( (int) $references > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error(
                'anpa_admin_horario_in_use',
                __( 'Desasigna este horario de todos os niveis e garda antes de eliminalo.', 'anpa-socios' ),
                array( 'status' => 409 )
            );
        }

        $deleted = $wpdb->delete(
            $horarios_t,
            array( 'id' => $horario_id, 'curso_escolar' => $curso ),
            array( '%d', '%s' )
        );
        if ( false === $deleted || 1 !== (int) $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido eliminar o horario de comedor.', 'anpa-socios' ) ), 500 );
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido completar a eliminación do horario.', 'anpa-socios' ) ), 500 );
        }

        ANPA_Socios_Admin_Shared::write_audit( $request, 'horario_comedor', (string) $horario_id, 'delete' );

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Horario de comedor eliminado.', 'anpa-socios' ) ), 200 );
    }

    /**
     * Locks the relationship range and every currently associated group row.
     *
     * The nivel_id index next-key lock prevents a concurrent association from
     * appearing between conflict validation and the meal-window write.
     *
     * @param  int $nivel_id Level identifier.
     * @return bool
     */
    private static function lock_comedor_group_rows( int $nivel_id ): bool {
        global $wpdb;

        $gn_t     = ANPA_Socios_DB::tabela_grupos_niveis();
        $grupos_t = ANPA_Socios_DB::tabela_grupos();

        $wpdb->last_error = '';
        $grupo_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT grupo_id FROM {$gn_t} WHERE nivel_id = %d ORDER BY grupo_id FOR UPDATE",
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error || ! is_array( $grupo_ids ) ) {
            return false;
        }
        if ( array() === $grupo_ids ) {
            return true;
        }

        $grupo_ids   = array_map( 'intval', $grupo_ids );
        $placeholders = implode( ', ', array_fill( 0, count( $grupo_ids ), '%d' ) );
        $wpdb->last_error = '';
        $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$grupos_t} WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE",
            ...$grupo_ids
        ) );

        return '' === (string) $wpdb->last_error;
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
     * @return array<int,array<string,mixed>>|null
     */
    private static function comedor_conflicts_for_nivel( string $curso, int $nivel_id, array $interval ): ?array {
        global $wpdb;

        $grupos_t  = ANPA_Socios_DB::tabela_grupos();
        $gn_t      = ANPA_Socios_DB::tabela_grupos_niveis();
        $niveis_t  = ANPA_Socios_DB::tabela_niveis();
        $act_t     = ANPA_Socios_DB::tabela_actividades();

        $wpdb->last_error = '';
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

        if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
            return null;
        }
        if ( array() === $rows ) {
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
     * POST accion=editar_nivel — rename a level's code and order.
     *
     * Levels are global, so the duplicate check now spans the full catalogue.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function editar_nivel( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $codigo   = trim( (string) $request->get_param( 'codigo' ) );
        $orde     = self::positive_integer_input( $request->get_param( 'orde' ) );
        if ( $nivel_id < 1 || '' === $codigo || null === $orde ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Datos inválidos.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->last_error = '';
        $owned_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$niveis_t} WHERE id = %d FOR UPDATE",
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nivel.', 'anpa-socios' ) ), 500 );
        }
        if ( null === $owned_result ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
        }

        $wpdb->last_error = '';
        $duplicate_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$niveis_t} WHERE codigo = %s AND id <> %d FOR UPDATE",
            $codigo,
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nome do nivel.', 'anpa-socios' ) ), 500 );
        }
        if ( null !== $duplicate_id ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Xa existe outro nivel con ese código.', 'anpa-socios' ) ), 409 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin handler write.
        $updated = $wpdb->update(
            $niveis_t,
            array( 'codigo' => $codigo, 'etiqueta' => $codigo, 'orde' => $orde, 'actualizado_en' => current_time( 'mysql' ) ),
            array( 'id' => $nivel_id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido completar a actualización do nivel.', 'anpa-socios' ) ), 500 );
        }

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nivel actualizado.', 'anpa-socios' ) ), 200 );
    }

    /**
     * POST accion=toggle_nivel — enable/disable a level.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function toggle_nivel( WP_REST_Request $request ): WP_REST_Response {
        $nivel_id   = (int) $request->get_param( 'nivel_id' );
        $habilitado = rest_sanitize_boolean( $request->get_param( 'habilitado' ) );

        if ( $nivel_id < 1 ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ID de nivel inválido.', 'anpa-socios' ) ), 400 );
        }

        $result = ANPA_Socios_DB::toggle_nivel( $nivel_id, $habilitado );
        if ( is_wp_error( $result ) ) {
            $data   = $result->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 409;
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ),
                $status > 0 ? $status : 409
            );
        }

        return new WP_REST_Response(
            array(
                'success'    => true,
                'habilitado' => $habilitado,
                'message'    => $habilitado ? __( 'Nivel habilitado.', 'anpa-socios' ) : __( 'Nivel deshabilitado.', 'anpa-socios' ),
            ),
            200
        );
    }

    /**
     * POST accion=rename_nivel — rename only the display label.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function rename_nivel( WP_REST_Request $request ): WP_REST_Response {
        $nivel_id = (int) $request->get_param( 'nivel_id' );
        $etiqueta = trim( (string) $request->get_param( 'etiqueta' ) );

        if ( $nivel_id < 1 || '' === $etiqueta ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Datos inválidos.', 'anpa-socios' ) ), 400 );
        }

        $result = ANPA_Socios_DB::rename_nivel( $nivel_id, $etiqueta );
        if ( is_wp_error( $result ) ) {
            $data   = $result->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ),
                $status > 0 ? $status : 400
            );
        }

        return new WP_REST_Response(
            array(
                'success'  => true,
                'message'  => __( 'Nivel renomeado correctamente.', 'anpa-socios' ),
                'etiqueta' => $etiqueta,
            ),
            200
        );
    }

    /**
     * Engade un novo nivel.
     *
     * @param  WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    private static function engadir_nivel( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        // Single "Nivel" value used as both codigo (relation key) and etiqueta
        // (display) — the editor no longer asks for both separately.
        $codigo = trim( (string) $request->get_param( 'codigo' ) );
        $orde   = self::positive_integer_input( $request->get_param( 'orde' ) );
        $ultima = strtoupper( trim( (string) $request->get_param( 'ultima_aula' ) ) );
        if ( ! in_array( $ultima, self::aula_letras(), true ) ) {
            $ultima = 'D';
        }

        if ( '' === $codigo || null === $orde ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'O nivel e unha idade válida son obrigatorios.', 'anpa-socios' ) ), 400 );
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin REST handler with transaction.
        $inserted = $wpdb->insert(
            $niveis_t,
            array(
                'codigo'         => $codigo,
                'etiqueta'       => $codigo,
                'orde'           => $orde,
                'estado'         => 'activo',
                'habilitado'     => 1,
                'creado_en'      => current_time( 'mysql' ),
                'actualizado_en' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            if ( false !== strpos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Xa existe un nivel con ese código.', 'anpa-socios' ) ), 409 );
            }
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido gardar o nivel.', 'anpa-socios' ) ), 500 );
        }

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
     * Seeds default global levels + their classrooms (first-run wizard).
     *
     * Idempotent and transactional: a level whose codigo already exists is
     * skipped, so re-running never duplicates. Reuses sync_aulas_nivel so each
     * level gets classrooms A..última. Each spec: [codigo, orde (age), ultima_aula].
     *
     * @since  1.46.4
     * @param  array<int,array<string,mixed>> $specs Level specifications.
     * @return bool True on success (or nothing to do), false on DB failure.
     */
    public static function seed_default_structure( array $specs ): bool {
        global $wpdb;

        if ( empty( $specs ) ) {
            return true;
        }

        $niveis_t = ANPA_Socios_DB::tabela_niveis();

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return false;
        }

        foreach ( $specs as $spec ) {
            $codigo = trim( (string) ( $spec['codigo'] ?? '' ) );
            $orde   = (int) ( $spec['orde'] ?? 0 );
            $ultima = strtoupper( trim( (string) ( $spec['ultima_aula'] ?? 'D' ) ) );
            if ( '' === $codigo || $orde < 1 ) {
                continue;
            }
            if ( ! in_array( $ultima, self::aula_letras(), true ) ) {
                $ultima = 'D';
            }

            // Idempotent: skip a level that already exists (by codigo).
            $wpdb->last_error = '';
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$niveis_t} WHERE codigo = %s", $codigo ) );
            if ( '' !== (string) $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return false;
            }
            if ( null !== $exists ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded seed within transaction.
            $inserted = $wpdb->insert(
                $niveis_t,
                array(
                    'codigo'         => $codigo,
                    'etiqueta'       => $codigo,
                    'orde'           => $orde,
                    'estado'         => 'activo',
                    'habilitado'     => 1,
                    'creado_en'      => current_time( 'mysql' ),
                    'actualizado_en' => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
            );
            if ( false === $inserted || ! self::sync_aulas_nivel( (int) $wpdb->insert_id, $ultima ) ) {
                $wpdb->query( 'ROLLBACK' );
                return false;
            }
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        return true;
    }

    /**
     * DELETE /admin/estrutura?nivel_id=N&curso_escolar=X
     *
     * If the nivel has active references (fillos_cursos or grupos_niveis),
     * it is set to inactive instead of deleted.
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


        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido actualizar o nivel.', 'anpa-socios' ) ), 500 );
        }

        $wpdb->last_error = '';
        	$exists_result = $wpdb->get_var( $wpdb->prepare(
        		"SELECT id FROM {$niveis_t} WHERE id = %d FOR UPDATE",
        		$nivel_id
        	) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puido comprobar o nivel.', 'anpa-socios' ) ), 500 );
        }
        if ( null === $exists_result ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nivel non atopado.', 'anpa-socios' ) ), 404 );
        }

        $wpdb->last_error = '';
        $locked_aula_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$aulas_t} WHERE nivel_id = %d ORDER BY id FOR UPDATE",
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error || ! is_array( $locked_aula_ids ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear as aulas do nivel.', 'anpa-socios' ) ), 500 );
        }
        foreach ( $locked_aula_ids as $aula_id ) {
            $wpdb->last_error = '';
            $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$fc_t} WHERE aula_id = %d ORDER BY id FOR UPDATE",
                (int) $aula_id
            ) );
            if ( '' !== (string) $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear as referencias das aulas.', 'anpa-socios' ) ), 500 );
            }
        }

        $wpdb->last_error = '';
        $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$fc_t} WHERE nivel_id = %d ORDER BY id FOR UPDATE",
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear as referencias dos fillos.', 'anpa-socios' ) ), 500 );
        }
        $wpdb->last_error = '';
        $wpdb->get_col( $wpdb->prepare(
            "SELECT grupo_id FROM {$gn_t} WHERE nivel_id = %d ORDER BY grupo_id FOR UPDATE",
            $nivel_id
        ) );
        if ( '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron bloquear as referencias dos grupos.', 'anpa-socios' ) ), 500 );
        }


        $wpdb->last_error = '';
        $fc_refs_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$fc_t} WHERE nivel_id = %d",
            $nivel_id
        ) );
        if ( null === $fc_refs_result || '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar as referencias dos fillos.', 'anpa-socios' ) ), 500 );
        }
        $wpdb->last_error = '';
        $gn_refs_result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$gn_t} WHERE nivel_id = %d",
            $nivel_id
        ) );
        if ( null === $gn_refs_result || '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar as referencias do nivel.', 'anpa-socios' ) ), 500 );
        }


        $aula_refs_result = 0;
        foreach ( $locked_aula_ids as $aula_id ) {
            $wpdb->last_error = '';
            $refs = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$fc_t} WHERE aula_id = %d",
                (int) $aula_id
            ) );
            if ( null === $refs || '' !== (string) $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Non se puideron comprobar as referencias das aulas.', 'anpa-socios' ) ), 500 );
            }
            $aula_refs_result += (int) $refs;
        }

        $has_references = ( (int) $fc_refs_result + (int) $gn_refs_result + $aula_refs_result ) > 0;

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
