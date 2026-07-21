<?php
/**
 * Settings-page renderer for the parametrizable school structure editor.
 *
 * Displays a curso_escolar selector, an editable list of niveis with their
 * aulas, a copy-from-course UI, and submit-to-REST workflow within the
 * Axustes → Cursos → Estrutura escolar section.
 *
 * @since  1.27.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the inline Estrutura escolar editor inside the settings page.
 *
 * No shortcode or public access — only called from admin settings context.
 *
 * @since 1.27.0
 */
final class ANPA_Socios_Estrutura_Escolar_Page {

    /**
     * Renders the full estrutura editor.
     *
     * @return void
     */
    public static function render(): void {
        global $wpdb;

        $niveis_t   = ANPA_Socios_DB::tabela_niveis();
        $aulas_t    = ANPA_Socios_DB::tabela_aulas();
        $cursos_t   = ANPA_Socios_DB::tabela_cursos();
        $horarios_t = ANPA_Socios_DB::tabela_horarios_comedor();

        // ── Course selector ───────────────────────────────────────
        $existing = $wpdb->get_col( "SELECT DISTINCT curso_escolar FROM {$cursos_t} ORDER BY curso_escolar DESC" );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }
        $sel = isset( $_GET['curso'] ) ? sanitize_text_field( wp_unslash( $_GET['curso'] ) ) : ( $existing[0] ?? '' );

        echo '<h2>' . esc_html__( 'Estrutura escolar', 'anpa-socios' ) . '</h2>';
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="anpa-socios-settings">';
        echo '<input type="hidden" name="tab" value="cursos">';
        echo '<input type="hidden" name="section" value="estrutura">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="est-curso">' . esc_html__( 'Curso escolar', 'anpa-socios' ) . '</label></th><td>';
        echo '<select name="curso" id="est-curso">';
        foreach ( $existing as $c ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $c ),
                selected( $sel, $c, false ),
                esc_html( $c )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Selecciona un curso para ver e editar a súa estrutura de niveis e aulas.', 'anpa-socios' ) . '</p>';
        echo '</td></tr></tbody></table>';
        echo '</form>';

        if ( ! ANPA_Socios_Curso_Escolar::is_valid( $sel ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Selecciona un curso escolar válido.', 'anpa-socios' ) . '</p></div>';
            return;
        }

        // ── Load existing structure ───────────────────────────────
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only list in admin context.
        $niveis = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, codigo, etiqueta, orde, horario_comedor_id, estado FROM {$niveis_t} WHERE curso_escolar = %s AND estado = 'activo' ORDER BY orde ASC, codigo ASC",
            $sel
        ), ARRAY_A );
        if ( ! is_array( $niveis ) ) {
            $niveis = array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only list in admin context.
        $horarios = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, nome, inicio, fin, orde FROM {$horarios_t} WHERE curso_escolar = %s AND estado = 'activo' ORDER BY orde ASC, nome ASC, id ASC",
            $sel
        ), ARRAY_A );
        if ( ! is_array( $horarios ) ) {
            $horarios = array();
        }

        // Load aulas per nivel.
        $nivel_ids = array_map( function ( $n ) { return (int) $n['id']; }, $niveis );
        $aulas_by_nivel = array();
        if ( ! empty( $nivel_ids ) ) {
            $ids_str = implode( ',', $nivel_ids );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only list in admin context.
            $aulas = $wpdb->get_results(
                "SELECT id, nivel_id, codigo, etiqueta, orde, estado FROM {$aulas_t} WHERE nivel_id IN ({$ids_str}) ORDER BY orde ASC, codigo ASC",
                ARRAY_A
            );
            if ( is_array( $aulas ) ) {
                foreach ( $aulas as $a ) {
                    $nid = (int) $a['nivel_id'];
                    if ( ! isset( $aulas_by_nivel[ $nid ] ) ) {
                        $aulas_by_nivel[ $nid ] = array();
                    }
                    $aulas_by_nivel[ $nid ][] = $a;
                }
            }
        }

        // Classroom letters offered (A..H). Not hardcoded to any subset —
        // each level picks its own last letter.
        $letras = range( 'A', 'H' );

        // Current last active classroom letter per nivel (for the select default).
        $ultima_por_nivel = array();
        foreach ( $niveis as $n ) {
            $nid = (int) $n['id'];
            $max = '';
            foreach ( ( $aulas_by_nivel[ $nid ] ?? array() ) as $a ) {
                $code = strtoupper( (string) $a['codigo'] );
                if ( 'activo' === $a['estado'] && in_array( $code, $letras, true ) && $code > $max ) {
                    $max = $code;
                }
            }
            $ultima_por_nivel[ $nid ] = '' !== $max ? $max : 'D';
        }

        // ── Reusable meal schedules + levels bulk editor ─────────
        echo '<div id="anpa-estrutura-editor" data-curso="' . esc_attr( $sel ) . '">';
        echo '<style>
        #anpa-estrutura-editor .anpa-required { color: #b42318; font-weight: 700; }
        #anpa-estrutura-editor .est-editor-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; margin:1rem 0; padding:1rem; }
        #anpa-estrutura-editor .est-editor-card h3 { margin-top:0; }
        #anpa-estrutura-editor .est-table-wrap { overflow-x:auto; }
        #anpa-estrutura-editor table input[type="text"] { min-width:8rem; width:100%; }
        #anpa-estrutura-editor .est-actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-top:1rem; }
        #anpa-estrutura-editor .est-status { min-height:1.5em; white-space:pre-line; }
        @media (max-width: 782px) {
            #anpa-estrutura-editor .est-editor-card { padding:.75rem; }
            #anpa-estrutura-editor table input[type="text"] { min-width:7rem; }
        }
        </style>';

        echo '<p class="description">' . esc_html__( 'Esta páxina garda niveis, aulas e horarios de comedor. Os grupos de actividades gárdanse por separado no seu propio editor.', 'anpa-socios' ) . '</p>';

        echo '<section class="est-editor-card" aria-labelledby="est-horarios-title">';
        echo '<h3 id="est-horarios-title">' . esc_html__( 'Horarios de comedor', 'anpa-socios' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Crea ou modifica os horarios de comedor. Garda esta sección antes de asignar un horario novo a un nivel.', 'anpa-socios' ) . '</p>';
        echo '<div class="est-table-wrap"><table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Nome', 'anpa-socios' ) . ' <span class="anpa-required" aria-hidden="true">*</span></th>';
        echo '<th>' . esc_html__( 'Inicio', 'anpa-socios' ) . ' <span class="anpa-required" aria-hidden="true">*</span></th>';
        echo '<th>' . esc_html__( 'Fin', 'anpa-socios' ) . ' <span class="anpa-required" aria-hidden="true">*</span></th>';
        echo '<th>' . esc_html__( 'Orde', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Acción', 'anpa-socios' ) . '</th>';
        echo '</tr></thead><tbody id="est-horarios-body">';
        foreach ( $horarios as $horario ) {
            $hid = (int) $horario['id'];
            echo '<tr data-horario-row data-id="' . $hid . '" data-key="h-' . $hid . '">';
            printf( '<td><input type="text" class="est-horario-nome" value="%s" maxlength="80" required></td>', esc_attr( $horario['nome'] ) );
            printf( '<td><input type="time" class="est-horario-inicio" value="%s" required></td>', esc_attr( $horario['inicio'] ) );
            printf( '<td><input type="time" class="est-horario-fin" value="%s" required></td>', esc_attr( $horario['fin'] ) );
            printf( '<td><input type="number" class="est-horario-orde small-text" value="%d" min="1" step="1"></td>', (int) $horario['orde'] );
            echo '<td><button type="button" class="button button-small est-eliminar-horario">' . esc_html__( 'Eliminar', 'anpa-socios' ) . '</button></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="est-actions">';
        echo '<button type="button" id="anpa-est-add-horario" class="button">' . esc_html__( 'Engadir horario', 'anpa-socios' ) . '</button>';
        echo '<button type="button" id="anpa-est-gardar-horarios" class="button button-primary">' . esc_html__( 'Gardar horarios de comedor', 'anpa-socios' ) . '</button>';
        echo '<span id="anpa-est-horarios-status" class="est-status" aria-live="polite"></span></div>';
        echo '</section>';

        echo '<section class="est-editor-card" aria-labelledby="est-niveis-title">';
        echo '<h3 id="est-niveis-title">' . esc_html__( 'Niveis e aulas', 'anpa-socios' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Modifica os niveis, as aulas e as asignacións de comedor. A última aula crea ou reactiva as aulas desde A ata esa letra.', 'anpa-socios' ) . '</p>';
        echo '<div class="est-table-wrap"><table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Nivel', 'anpa-socios' ) . ' <span class="anpa-required" aria-hidden="true">*</span></th>';
        echo '<th>' . esc_html__( 'Idade alumnado', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Última aula', 'anpa-socios' ) . '</th>';
        echo '<th>' . esc_html__( 'Horario de comedor', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Acción', 'anpa-socios' ) . '</th>';
        echo '</tr></thead><tbody id="est-niveis-body">';
        foreach ( $niveis as $n ) {
            $nid = (int) $n['id'];
            echo '<tr data-nivel-row data-id="' . $nid . '">';
            printf( '<td><input type="text" class="est-nivel-nome" value="%s" maxlength="30" required></td>', esc_attr( $n['codigo'] ) );
            printf( '<td><input type="number" class="est-nivel-orde small-text" value="%d" min="1" step="1"></td>', (int) $n['orde'] );
            echo '<td><select class="est-nivel-ultima">';
            foreach ( $letras as $l ) {
                printf( '<option value="%1$s"%2$s>A–%1$s</option>', esc_attr( $l ), selected( $ultima_por_nivel[ $nid ] ?? 'D', $l, false ) );
            }
            echo '</select></td><td><select class="est-horario-select"><option value="">' . esc_html__( 'Sen horario', 'anpa-socios' ) . '</option>';
            foreach ( $horarios as $horario ) {
                $key = 'h-' . (int) $horario['id'];
                printf( '<option value="%s"%s>%s (%s–%s)</option>', esc_attr( $key ), selected( (int) ( $n['horario_comedor_id'] ?? 0 ), (int) $horario['id'], false ), esc_html( $horario['nome'] ), esc_html( $horario['inicio'] ), esc_html( $horario['fin'] ) );
            }
            echo '</select></td>';
            printf( '<td><button type="button" class="button button-small est-eliminar-nivel" data-id="%d">%s</button></td>', $nid, esc_html__( 'Eliminar', 'anpa-socios' ) );
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="est-actions">';
        echo '<button type="button" id="anpa-est-add-nivel" class="button">' . esc_html__( 'Novo nivel', 'anpa-socios' ) . '</button>';
        echo '<button type="button" id="anpa-est-gardar-niveis" class="button button-primary">' . esc_html__( 'Gardar cambios nos niveis', 'anpa-socios' ) . '</button>';
        echo '<span id="anpa-est-niveis-status" class="est-status" aria-live="polite"></span></div>';
        echo '</section></div>';

        // ── Copy from course ──────────────────────────────────────
        echo '<hr>';
        echo '<h3>' . esc_html__( 'Copiar estrutura doutro curso', 'anpa-socios' ) . '</h3>';
        echo '<form id="anpa-est-copy-form" class="anpa-est-form">';
        echo '<input type="hidden" name="curso_escolar" value="' . esc_attr( $sel ) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="est-copy-orixe">' . esc_html__( 'Copiar desde', 'anpa-socios' ) . '</label></th><td>';
        echo '<select name="orixe" id="est-copy-orixe">';
        foreach ( $existing as $c ) {
            if ( $c === $sel ) {
                continue;
            }
            printf( '<option value="%s">%s</option>', esc_attr( $c ), esc_html( $c ) );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Copiaranse os niveis e aulas que falten e reactivaranse os equivalentes inactivos. Os niveis activos xa configurados no destino consérvanse.', 'anpa-socios' ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Comedor', 'anpa-socios' ) . '</th><td><label><input type="checkbox" name="copiar_comedor" value="1"> ' . esc_html__( 'Copiar tamén os horarios de comedor e as súas asociacións', 'anpa-socios' ) . '</label></td></tr>';
        echo '</tbody></table>';
        printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Copiar estrutura', 'anpa-socios' ) );
        echo '</form>';

        // ── Inline JS: batch structure editor ────────────────────
        $rest_url = rest_url( ANPA_Socios_Admin_REST::REST_NAMESPACE . '/estrutura' );
        $nonce    = wp_create_nonce( 'wp_rest' );
        ?>
        <script>
        (function() {
            var api = '<?php echo esc_url_raw( $rest_url ); ?>';
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var curso = '<?php echo esc_js( $sel ); ?>';
            var courseSelect = document.getElementById( 'est-curso' );
            var editor = document.getElementById( 'anpa-estrutura-editor' );
            var horariosBody = document.getElementById( 'est-horarios-body' );
            var niveisBody = document.getElementById( 'est-niveis-body' );
            var addHorarioButton = document.getElementById( 'anpa-est-add-horario' );
            var addNivelButton = document.getElementById( 'anpa-est-add-nivel' );
            var saveHorariosButton = document.getElementById( 'anpa-est-gardar-horarios' );
            var saveNiveisButton = document.getElementById( 'anpa-est-gardar-niveis' );
            var horariosStatus = document.getElementById( 'anpa-est-horarios-status' );
            var niveisStatus = document.getElementById( 'anpa-est-niveis-status' );
            var copyForm = document.getElementById( 'anpa-est-copy-form' );
            var copyDescription = copyForm ? copyForm.querySelector( '.description' ) : null;
            var letters = [ 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' ];
            var defaultLastClassroom = 'D';
            var horarioSequence = 0;
            var nivelSequence = 0;
            var horariosDirty = false;
            var niveisDirty = false;
            var allowNavigation = false;
            var pendingRequests = 0;
            var labelBlankHorario = '<?php echo esc_js( __( 'Sen horario', 'anpa-socios' ) ); ?>';
            var labelDelete = '<?php echo esc_js( __( 'Eliminar', 'anpa-socios' ) ); ?>';
            var labelHorarioNovo = '<?php echo esc_js( __( 'Horario novo', 'anpa-socios' ) ); ?>';
            var labelNivelNovo = '<?php echo esc_js( __( 'Nivel novo', 'anpa-socios' ) ); ?>';
            var messageCopyDescription = '<?php echo esc_js( __( 'Os niveis e aulas do curso orixe copiaranse para este curso segundo as regras do servidor.', 'anpa-socios' ) ); ?>';
            var messageNeedHorarioName = '<?php echo esc_js( __( 'Indica o nome do horario.', 'anpa-socios' ) ); ?>';
            var messageNeedPair = '<?php echo esc_js( __( 'Completa as dúas horas ou limpa o horario.', 'anpa-socios' ) ); ?>';
            var messageStartBeforeEnd = '<?php echo esc_js( __( 'A hora de inicio debe ser anterior á de fin.', 'anpa-socios' ) ); ?>';
            var messageDuplicateHorario = '<?php echo esc_js( __( 'Non pode haber horarios de comedor duplicados.', 'anpa-socios' ) ); ?>';
            var messageNeedNivelName = '<?php echo esc_js( __( 'Indica o nome do nivel.', 'anpa-socios' ) ); ?>';
            var messageValidHorario = '<?php echo esc_js( __( 'Escolle un horario de comedor válido.', 'anpa-socios' ) ); ?>';
            var messageDuplicateNivel = '<?php echo esc_js( __( 'Non pode haber dous niveis co mesmo nome.', 'anpa-socios' ) ); ?>';
            var messageDuplicateNivelAge = '<?php echo esc_js( __( 'Non pode haber dous niveis coa mesma idade do alumnado.', 'anpa-socios' ) ); ?>';
            var messageNeedNivelAge = '<?php echo esc_js( __( 'Indica unha idade válida para cada nivel.', 'anpa-socios' ) ); ?>';
            var messageStructureInvalid = '<?php echo esc_js( __( 'Revisa os campos marcados antes de gardar.', 'anpa-socios' ) ); ?>';
            var messageSavingHorarios = '<?php echo esc_js( __( 'Gardando os horarios de comedor…', 'anpa-socios' ) ); ?>';
            var messageSavingNiveis = '<?php echo esc_js( __( 'Gardando os cambios nos niveis…', 'anpa-socios' ) ); ?>';
            var messageSavedButton = '<?php echo esc_js( __( 'Gardando…', 'anpa-socios' ) ); ?>';
            var messageSaveFailed = '<?php echo esc_js( __( 'Non se puido gardar a estrutura.', 'anpa-socios' ) ); ?>';
            var messageSaveNiveisFirst = '<?php echo esc_js( __( 'Hai cambios sen gardar nos niveis. Garda ou recarga esa sección antes de gardar os horarios.', 'anpa-socios' ) ); ?>';
            var messageSaveHorariosFirst = '<?php echo esc_js( __( 'Hai cambios sen gardar nos horarios de comedor. Gárdaos ou recarga a páxina antes de gardar os niveis.', 'anpa-socios' ) ); ?>';
            var messageDiscardDrafts = '<?php echo esc_js( __( 'Hai cambios sen gardar. Se continúas, perderanse. Queres continuar?', 'anpa-socios' ) ); ?>';
            var messageResponseInvalid = '<?php echo esc_js( __( 'Resposta inválida do servidor.', 'anpa-socios' ) ); ?>';
            var messageNetwork = '<?php echo esc_js( __( 'Erro de rede: ', 'anpa-socios' ) ); ?>';
            var messageDeletePersistedHorario = '<?php echo esc_js( __( 'Eliminar definitivamente este horario de comedor?', 'anpa-socios' ) ); ?>';
            var messageDeleteAssignedHorario = '<?php echo esc_js( __( 'Ese horario xa está asignado a un nivel. Desasígnao primeiro e garda para poder retiralo do borrador.', 'anpa-socios' ) ); ?>';
            var messageHorarioAdded = '<?php echo esc_js( __( 'Novo horario engadido. Completa os campos e garda os horarios antes de asignalo a un nivel.', 'anpa-socios' ) ); ?>';
            var messageHorarioRemoved = '<?php echo esc_js( __( 'Horario novo eliminado do borrador.', 'anpa-socios' ) ); ?>';
            var messageNivelAdded = '<?php echo esc_js( __( 'Nivel novo engadido ao borrador. Gárdao para persistilo.', 'anpa-socios' ) ); ?>';
            var messageNeedCourseCopy = '<?php echo esc_js( __( 'Vai copiar a estrutura desde o curso seleccionado. Continuar?', 'anpa-socios' ) ); ?>';
            var messageCopyFailed = '<?php echo esc_js( __( 'Non se puido copiar a estrutura.', 'anpa-socios' ) ); ?>';
            var messageLevelDeletePrompt = '<?php echo esc_js( __( 'Eliminar este nivel? O servidor decidirá se o borra ou o desactiva segundo as referencias activas. Continuar?', 'anpa-socios' ) ); ?>';
            var messageLevelDeletedLocal = '<?php echo esc_js( __( 'fila retirada do borrador.', 'anpa-socios' ) ); ?>';
            var messageLevelDeleteFailed = '<?php echo esc_js( __( 'Non se puido eliminar o nivel.', 'anpa-socios' ) ); ?>';

            if ( ! editor || ! horariosBody || ! niveisBody || ! saveHorariosButton || ! saveNiveisButton ) {
                return;
            }

            if ( copyDescription ) {
                copyDescription.textContent = messageCopyDescription;
            }

            function statusNode( scope ) {
                return 'horarios' === scope ? horariosStatus : niveisStatus;
            }

            function setStatus( message, isError, scope ) {
                var status = statusNode( scope );
                if ( ! status ) {
                    return;
                }
                status.textContent = message || '';
                status.setAttribute( 'role', isError ? 'alert' : 'status' );
            }

            function clearStatus( scope ) {
                setStatus( '', false, scope );
            }

            function mutationControls() {
                return document.querySelectorAll( '#anpa-estrutura-editor input, #anpa-estrutura-editor select, #anpa-estrutura-editor button, #anpa-est-copy-form input, #anpa-est-copy-form select, #anpa-est-copy-form button, #est-curso' );
            }

            function setInteractionLocked( locked ) {
                Array.prototype.forEach.call( mutationControls(), function( control ) {
                    if ( locked ) {
                        control.dataset.anpaWasDisabled = control.disabled ? '1' : '0';
                        control.disabled = true;
                        return;
                    }
                    if ( '0' === control.dataset.anpaWasDisabled ) {
                        control.disabled = false;
                    }
                    delete control.dataset.anpaWasDisabled;
                } );
                if ( editor ) {
                    if ( locked ) {
                        editor.setAttribute( 'aria-busy', 'true' );
                    } else {
                        editor.removeAttribute( 'aria-busy' );
                    }
                }
                if ( copyForm ) {
                    if ( locked ) {
                        copyForm.setAttribute( 'aria-busy', 'true' );
                    } else {
                        copyForm.removeAttribute( 'aria-busy' );
                    }
                }
            }

            function beginRequest() {
                pendingRequests += 1;
                if ( 1 === pendingRequests ) {
                    setInteractionLocked( true );
                }
            }

            function endRequest() {
                pendingRequests = Math.max( 0, pendingRequests - 1 );
                if ( 0 === pendingRequests ) {
                    setInteractionLocked( false );
                }
            }

            function hasPendingRequests() {
                return pendingRequests > 0;
            }

            function hasUnsavedDrafts() {
                return horariosDirty || niveisDirty;
            }

            function confirmDiscardDrafts() {
                return ! hasUnsavedDrafts() || confirm( messageDiscardDrafts );
            }

            window.addEventListener( 'beforeunload', function( event ) {
                if ( allowNavigation || ! ( hasUnsavedDrafts() || hasPendingRequests() ) ) {
                    return;
                }
                event.preventDefault();
                event.returnValue = '';
            } );

            function escapeHtml( value ) {
                return String( value ).replace( /[&<>"']/g, function( character ) {
                    if ( '&' === character ) {
                        return '&amp;';
                    }
                    if ( '<' === character ) {
                        return '&lt;';
                    }
                    if ( '>' === character ) {
                        return '&gt;';
                    }
                    if ( '"' === character ) {
                        return '&quot;';
                    }
                    return '&#39;';
                } );
            }

            function trimValue( input ) {
                return input && 'string' === typeof input.value ? input.value.trim() : '';
            }

            function parseIntOrZero( value ) {
                var parsed = parseInt( value, 10 );
                return isNaN( parsed ) ? 0 : parsed;
            }

            function setValidity( input, message ) {
                if ( ! input ) {
                    return;
                }
                input.setCustomValidity( message || '' );
                if ( message ) {
                    input.setAttribute( 'aria-invalid', 'true' );
                } else {
                    input.removeAttribute( 'aria-invalid' );
                }
            }

            function getHorarioRows() {
                return horariosBody.querySelectorAll( '[data-horario-row]' );
            }

            function getNivelRows() {
                return niveisBody.querySelectorAll( '[data-nivel-row]' );
            }

            function getHorarioKey( row ) {
                return row && row.dataset && row.dataset.key ? row.dataset.key : '';
            }

            function isPersisted( row ) {
                return parseIntOrZero( row && row.dataset && row.dataset.id ? row.dataset.id : 0 ) > 0;
            }

            function getHorarioName( row ) {
                return trimValue( row.querySelector( '.est-horario-nome' ) );
            }

            function getHorarioInicio( row ) {
                return trimValue( row.querySelector( '.est-horario-inicio' ) );
            }

            function getHorarioFin( row ) {
                return trimValue( row.querySelector( '.est-horario-fin' ) );
            }

            function getHorarioOrder( row ) {
                var orderInput = row.querySelector( '.est-horario-orde' );
                return parseIntOrZero( orderInput ? orderInput.value : 0 );
            }

            function nextHorarioKey() {
                horarioSequence += 1;
                return 'novo-' + horarioSequence;
            }

            function nextNivelKey() {
                nivelSequence += 1;
                return 'novo-' + nivelSequence;
            }

            function nextOrderValue( rows, selector ) {
                var max = 0;
                Array.prototype.forEach.call( rows, function( row ) {
                    var input = row.querySelector( selector );
                    var value = parseIntOrZero( input ? input.value : 0 );
                    if ( value > max ) {
                        max = value;
                    }
                } );
                return String( max > 0 ? max + 10 : 10 );
            }

            function horarioLabel( horario ) {
                var base = horario.nome || labelHorarioNovo;
                if ( horario.inicio && horario.fin ) {
                    return base + ' (' + horario.inicio + '–' + horario.fin + ')';
                }
                return base;
            }

            function buildHorarioCatalog() {
                var rows = getHorarioRows();
                var list = [];
                var map = {};

                Array.prototype.forEach.call( rows, function( row ) {
                    var horario = {
                        row: row,
                        key: getHorarioKey( row ),
                        id: parseIntOrZero( row.dataset && row.dataset.id ? row.dataset.id : 0 ),
                        nome: getHorarioName( row ),
                        inicio: getHorarioInicio( row ),
                        fin: getHorarioFin( row ),
                        orde: getHorarioOrder( row )
                    };
                    if ( ! horario.key ) {
                        horario.key = nextHorarioKey();
                        row.dataset.key = horario.key;
                    }
                    horario.label = horarioLabel( horario );
                    list.push( horario );
                    map[ horario.key ] = horario;
                } );

                return {
                    list: list,
                    map: map
                };
            }

            function buildHorarioOptionsHtml( catalog, selectedValue ) {
                var html = '<option value="">' + labelBlankHorario + '</option>';

                catalog.list.forEach( function( horario ) {
                    if ( horario.id > 0 ) {
                        html += '<option value="' + escapeHtml( horario.key ) + '"' + ( selectedValue === horario.key ? ' selected' : '' ) + '>' + escapeHtml( horario.label ) + '</option>';
                    }
                } );

                return html;
            }

            function buildClassroomOptionsHtml( selectedValue ) {
                var html = '';

                letters.forEach( function( letter ) {
                    html += '<option value="' + letter + '"' + ( selectedValue === letter ? ' selected' : '' ) + '>A–' + letter + '</option>';
                } );

                return html;
            }

            function buildHorarioRowHtml( horario ) {
                return '' +
                    '<tr data-horario-row data-id="' + escapeHtml( horario.id ) + '" data-key="' + escapeHtml( horario.key ) + '">' +
                        '<td><input type="text" class="est-horario-nome" value="' + escapeHtml( horario.nome ) + '" maxlength="80" required></td>' +
                        '<td><input type="time" class="est-horario-inicio" value="' + escapeHtml( horario.inicio ) + '" required></td>' +
                        '<td><input type="time" class="est-horario-fin" value="' + escapeHtml( horario.fin ) + '" required></td>' +
                        '<td><input type="number" class="est-horario-orde small-text" value="' + escapeHtml( horario.orde ) + '" min="1" step="1"></td>' +
                        '<td><button type="button" class="button button-small est-eliminar-horario">' + labelDelete + '</button></td>' +
                    '</tr>';
            }

            function buildNivelRowHtml( nivel ) {
                var selectedLast = nivel.ultima || defaultLastClassroom;

                return '' +
                    '<tr data-nivel-row data-id="' + escapeHtml( nivel.id ) + '" data-local-key="' + escapeHtml( nivel.localKey ) + '">' +
                        '<td><input type="text" class="est-nivel-nome" value="' + escapeHtml( nivel.codigo ) + '" maxlength="30" required></td>' +
                        '<td><input type="number" class="est-nivel-orde small-text" value="' + escapeHtml( nivel.orde ) + '" min="1" step="1"></td>' +
                        '<td><select class="est-nivel-ultima">' + buildClassroomOptionsHtml( selectedLast ) + '</select></td>' +
                        '<td><select class="est-horario-select"><option value="">' + labelBlankHorario + '</option></select></td>' +
                        '<td><button type="button" class="button button-small est-eliminar-nivel">' + labelDelete + '</button></td>' +
                    '</tr>';
            }

            function refreshHorarioSelects() {
                var catalog = buildHorarioCatalog();
                var selects = niveisBody.querySelectorAll( '.est-horario-select' );

                Array.prototype.forEach.call( selects, function( select ) {
                    var selected = select.value || '';
                    select.innerHTML = buildHorarioOptionsHtml( catalog, selected );
                    if ( selected && ! catalog.map[ selected ] ) {
                        select.value = '';
                    }
                } );
            }

            function validateHorarioRows() {
                var valid = true;
                var rows = getHorarioRows();
                var windows = {};

                Array.prototype.forEach.call( rows, function( row ) {
                    var nameInput = row.querySelector( '.est-horario-nome' );
                    var startInput = row.querySelector( '.est-horario-inicio' );
                    var endInput = row.querySelector( '.est-horario-fin' );
                    var name = trimValue( nameInput );
                    var start = trimValue( startInput );
                    var end = trimValue( endInput );

                    setValidity( nameInput, '' );
                    setValidity( startInput, '' );
                    setValidity( endInput, '' );

                    if ( ! name ) {
                        setValidity( nameInput, messageNeedHorarioName );
                        valid = false;
                    }

                    if ( ( start && ! end ) || ( ! start && end ) ) {
                        setValidity( startInput, messageNeedPair );
                        setValidity( endInput, messageNeedPair );
                        valid = false;
                    } else if ( start && end && start >= end ) {
                        setValidity( startInput, messageStartBeforeEnd );
                        setValidity( endInput, messageStartBeforeEnd );
                        valid = false;
                    }
                } );

                Array.prototype.forEach.call( rows, function( row ) {
                    var startInput = row.querySelector( '.est-horario-inicio' );
                    var endInput = row.querySelector( '.est-horario-fin' );
                    var start = trimValue( startInput );
                    var end = trimValue( endInput );
                    var key = start && end ? start + '|' + end : '';

                    if ( ! key || ! startInput.validity.valid || ! endInput.validity.valid ) {
                        return;
                    }

                    if ( windows[ key ] ) {
                        setValidity( startInput, messageDuplicateHorario );
                        setValidity( endInput, messageDuplicateHorario );
                        setValidity( windows[ key ].startInput, messageDuplicateHorario );
                        setValidity( windows[ key ].endInput, messageDuplicateHorario );
                        valid = false;
                        return;
                    }

                    windows[ key ] = {
                        startInput: startInput,
                        endInput: endInput
                    };
                } );

                return valid;
            }

            function validateNivelRows() {
                var valid = true;
                var rows = getNivelRows();
                var names = {};
                var ages = {};
                var catalog = buildHorarioCatalog();

                Array.prototype.forEach.call( rows, function( row ) {
                    var nameInput = row.querySelector( '.est-nivel-nome' );
                    var ageInput = row.querySelector( '.est-nivel-orde' );
                    var select = row.querySelector( '.est-horario-select' );
                    var name = trimValue( nameInput );
                    var age = parseIntOrZero( ageInput ? ageInput.value : 0 );
                    var horarioKey = select ? select.value : '';

                    setValidity( nameInput, '' );
                    setValidity( ageInput, '' );
                    setValidity( select, '' );

                    if ( ! name ) {
                        setValidity( nameInput, messageNeedNivelName );
                        valid = false;
                    }

                    if ( age < 1 ) {
                        setValidity( ageInput, messageNeedNivelAge );
                        valid = false;
                    } else if ( ages[ age ] ) {
                        setValidity( ageInput, messageDuplicateNivelAge );
                        setValidity( ages[ age ], messageDuplicateNivelAge );
                        valid = false;
                    } else {
                        ages[ age ] = ageInput;
                    }

                    if ( horarioKey && ! catalog.map[ horarioKey ] ) {
                        setValidity( select, messageValidHorario );
                        valid = false;
                    }
                } );

                Array.prototype.forEach.call( rows, function( row ) {
                    var nameInput = row.querySelector( '.est-nivel-nome' );
                    var normalized = trimValue( nameInput ).toLowerCase();

                    if ( ! normalized ) {
                        return;
                    }

                    if ( names[ normalized ] ) {
                        setValidity( nameInput, messageDuplicateNivel );
                        setValidity( names[ normalized ], messageDuplicateNivel );
                        valid = false;
                        return;
                    }

                    names[ normalized ] = nameInput;
                } );

                return valid;
            }

            function validateStructure( focusFirstInvalid ) {
                var valid;

                refreshHorarioSelects();
                valid = validateHorarioRows() && validateNivelRows();
                if ( ! valid && false !== focusFirstInvalid ) {
                    var firstInvalid = editor.querySelector( '[aria-invalid="true"]' );
                    if ( firstInvalid ) {
                        if ( firstInvalid.focus ) {
                            firstInvalid.focus();
                        }
                        if ( firstInvalid.reportValidity ) {
                            firstInvalid.reportValidity();
                        }
                    }
                }
                return valid;
            }

            function formatConflictLine( conflict ) {
                var parts = [];
                var days = '';

                if ( ! conflict || 'object' !== typeof conflict ) {
                    return '';
                }

                if ( conflict.actividad ) {
                    parts.push( conflict.actividad );
                }
                if ( conflict.grupo ) {
                    parts.push( conflict.grupo );
                }
                if ( conflict.nivel ) {
                    parts.push( conflict.nivel );
                }
                if ( Array.isArray( conflict.dias ) ) {
                    days = conflict.dias.join( ', ' );
                } else if ( conflict.dias ) {
                    days = conflict.dias;
                }
                if ( days ) {
                    parts.push( days );
                }
                if ( conflict.franxa ) {
                    parts.push( conflict.franxa );
                }

                return parts.join( ' — ' );
            }

            function parseJsonResponse( response ) {
                return response.text().then( function( text ) {
                    if ( ! text ) {
                        return {};
                    }

                    try {
                        return JSON.parse( text );
                    } catch ( error ) {
                        throw new Error( messageResponseInvalid );
                    }
                } );
            }

            function postJson( payload ) {
                return fetch( api, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': nonce,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify( payload )
                } ).then( parseJsonResponse );
            }

            function showResponseError( json, fallbackMessage, scope ) {
                var message = fallbackMessage || '<?php echo esc_js( __( 'Erro descoñecido.', 'anpa-socios' ) ); ?>';
                var detailLines = [];
                var conflicts = json && json.data && Array.isArray( json.data.conflicts ) ? json.data.conflicts : null;

                if ( json && json.message ) {
                    message = json.message;
                } else if ( json && json.code && ! json.message ) {
                    message = json.code;
                }

                if ( conflicts ) {
                    conflicts.forEach( function( conflict ) {
                        var line = formatConflictLine( conflict );
                        if ( line ) {
                            detailLines.push( line );
                        }
                    } );
                }

                setStatus( message + ( detailLines.length ? '\n\n' + detailLines.join( '\n' ) : '' ), true, scope );
            }

            function addHorarioRow() {
                var horario = {
                    id: 0,
                    key: nextHorarioKey(),
                    nome: '',
                    inicio: '',
                    fin: '',
                    orde: nextOrderValue( getHorarioRows(), '.est-horario-orde' )
                };

                horariosBody.insertAdjacentHTML( 'beforeend', buildHorarioRowHtml( horario ) );
                horariosDirty = true;
                refreshHorarioSelects();
                clearStatus( 'horarios' );
                var row = horariosBody.lastElementChild;
                var nameInput = row ? row.querySelector( '.est-horario-nome' ) : null;
                if ( nameInput && nameInput.focus ) {
                    nameInput.focus();
                }
                setStatus( messageHorarioAdded, false, 'horarios' );
            }

            function addNivelRow() {
                var nivel = {
                    id: 0,
                    localKey: nextNivelKey(),
                    codigo: '',
                    orde: nextOrderValue( getNivelRows(), '.est-nivel-orde' ),
                    ultima: defaultLastClassroom
                };

                niveisBody.insertAdjacentHTML( 'beforeend', buildNivelRowHtml( nivel ) );
                var row = niveisBody.lastElementChild;
                niveisDirty = true;
                refreshHorarioSelects();
                clearStatus( 'niveis' );
                var nameInput = row ? row.querySelector( '.est-nivel-nome' ) : null;
                if ( nameInput && nameInput.focus ) {
                    nameInput.focus();
                }
                setStatus( messageNivelAdded, false, 'niveis' );
            }

            function horarioIsAssigned( key ) {
                var rows = getNivelRows();
                var assigned = false;

                Array.prototype.forEach.call( rows, function( row ) {
                    var select = row.querySelector( '.est-horario-select' );
                    if ( select && select.value === key ) {
                        assigned = true;
                    }
                } );

                return assigned;
            }

            function removeHorarioRow( row ) {
                var key = getHorarioKey( row );
                var id = parseIntOrZero( row.dataset && row.dataset.id ? row.dataset.id : 0 );

                if ( ! key ) {
                    return;
                }

                if ( horarioIsAssigned( key ) ) {
                    setStatus( messageDeleteAssignedHorario, true, 'horarios' );
                    return;
                }

                if ( isPersisted( row ) ) {
                    if ( ! confirmDiscardDrafts() ) {
                        return;
                    }
                    if ( ! confirm( messageDeletePersistedHorario ) ) {
                        return;
                    }
                    beginRequest();
                    postJson( {
                        accion: 'eliminar_horario',
                        curso_escolar: curso,
                        horario_id: id
                    } ).then( function( json ) {
                        if ( json && true === json.success ) {
                            allowNavigation = true;
                            location.reload();
                            return;
                        }
                        showResponseError( json, '<?php echo esc_js( __( 'Non se puido eliminar o horario de comedor.', 'anpa-socios' ) ); ?>', 'horarios' );
                    } ).catch( function( err ) {
                        setStatus( messageNetwork + err.message, true, 'horarios' );
                    } ).finally( function() {
                        endRequest();
                    } );
                    return;
                }

                if ( row.parentNode ) {
                    row.parentNode.removeChild( row );
                }
                horariosDirty = true;
                clearStatus( 'horarios' );
                refreshHorarioSelects();
                validateStructure( false );
                setStatus( messageHorarioRemoved, false, 'horarios' );
            }

            function removeNivelRow( row ) {
                var id = parseIntOrZero( row.dataset && row.dataset.id ? row.dataset.id : 0 );
                var rowLabel = trimValue( row.querySelector( '.est-nivel-nome' ) ) || labelNivelNovo;

                if ( id > 0 ) {
                    if ( ! confirmDiscardDrafts() ) {
                        return;
                    }
                    if ( ! confirm( messageLevelDeletePrompt ) ) {
                        return;
                    }

                    beginRequest();
                    fetch( api + '?nivel_id=' + encodeURIComponent( id ) + '&curso_escolar=' + encodeURIComponent( curso ), {
                        method: 'DELETE',
                        headers: {
                            'X-WP-Nonce': nonce
                        }
                    } ).then( parseJsonResponse ).then( function( json ) {
                        if ( json && true === json.success ) {
                            allowNavigation = true;
                            location.reload();
                            return;
                        }
                        showResponseError( json, messageLevelDeleteFailed );
                    } ).catch( function( err ) {
                        setStatus( messageNetwork + err.message, true );
                    } ).finally( function() {
                        endRequest();
                    } );
                    return;
                }

                if ( row.parentNode ) {
                    row.parentNode.removeChild( row );
                }
                niveisDirty = true;
                clearStatus( 'niveis' );
                validateStructure( false );
                setStatus( rowLabel + ' — ' + messageLevelDeletedLocal, false, 'niveis' );
            }

            function collectHorariosPayload() {
                var payload = [];
                var rows = getHorarioRows();

                Array.prototype.forEach.call( rows, function( row ) {
                    var nameInput = row.querySelector( '.est-horario-nome' );
                    var startInput = row.querySelector( '.est-horario-inicio' );
                    var endInput = row.querySelector( '.est-horario-fin' );
                    var orderInput = row.querySelector( '.est-horario-orde' );

                    payload.push( {
                        key: getHorarioKey( row ),
                        id: parseIntOrZero( row.dataset && row.dataset.id ? row.dataset.id : 0 ),
                        nome: trimValue( nameInput ),
                        inicio: trimValue( startInput ),
                        fin: trimValue( endInput ),
                        orde: parseIntOrZero( orderInput ? orderInput.value : 0 )
                    } );
                } );

                return payload;
            }

            function collectNiveisPayload() {
                var payload = [];
                var rows = getNivelRows();

                Array.prototype.forEach.call( rows, function( row ) {
                    var nameInput = row.querySelector( '.est-nivel-nome' );
                    var orderInput = row.querySelector( '.est-nivel-orde' );
                    var select = row.querySelector( '.est-horario-select' );
                    var lastSelect = row.querySelector( '.est-nivel-ultima' );

                    payload.push( {
                        id: parseIntOrZero( row.dataset && row.dataset.id ? row.dataset.id : 0 ),
                        codigo: trimValue( nameInput ),
                        orde: parseIntOrZero( orderInput ? orderInput.value : 0 ),
                        ultima_aula: lastSelect ? lastSelect.value : defaultLastClassroom,
                        horario_comedor_key: select ? select.value : ''
                    } );
                } );

                return payload;
            }

            function formatConflictDetails( json ) {
                var details = [];
                var conflicts = json && json.data && Array.isArray( json.data.conflicts ) ? json.data.conflicts : [];

                conflicts.forEach( function( conflict ) {
                    var line = formatConflictLine( conflict );
                    if ( line ) {
                        details.push( line );
                    }
                } );

                return details;
            }

            function saveStructure( scope ) {
                var button = 'horarios' === scope ? saveHorariosButton : saveNiveisButton;
                var buttonText = button.textContent;
                var payload;

                clearStatus( scope );
                if ( 'horarios' === scope && niveisDirty ) {
                    setStatus( messageSaveNiveisFirst, true, 'horarios' );
                    return;
                }
                if ( 'niveis' === scope && horariosDirty ) {
                    setStatus( messageSaveHorariosFirst, true, 'niveis' );
                    return;
                }
                if ( ! validateStructure( true ) ) {
                    setStatus( messageStructureInvalid, true, scope );
                    return;
                }

                payload = {
                    accion: 'gardar_estrutura',
                    scope: scope,
                    curso_escolar: curso,
                    horarios_comedor: collectHorariosPayload(),
                    niveis: collectNiveisPayload()
                };

                button.disabled = true;
                button.setAttribute( 'aria-busy', 'true' );
                button.textContent = messageSavedButton;
                setStatus( 'horarios' === scope ? messageSavingHorarios : messageSavingNiveis, false, scope );

                beginRequest();
                postJson( payload ).then( function( json ) {
                    var details;
                    if ( json && true === json.success ) {
                        allowNavigation = true;
                        location.reload();
                        return;
                    }
                    details = formatConflictDetails( json );
                    setStatus( ( json && json.message ? json.message : messageSaveFailed ) + ( details.length ? '\n\n' + details.join( '\n' ) : '' ), true, scope );
                } ).catch( function( err ) {
                    setStatus( messageNetwork + err.message, true, scope );
                } ).finally( function() {
                    endRequest();
                    button.disabled = false;
                    button.removeAttribute( 'aria-busy' );
                    button.textContent = buttonText;
                } );
            }

            function handleHorarioInputChange() {
                horariosDirty = true;
                clearStatus( 'horarios' );
                validateStructure( false );
            }

            function handleNivelInputChange() {
                niveisDirty = true;
                clearStatus( 'niveis' );
                validateStructure( false );
            }

            horariosBody.addEventListener( 'input', handleHorarioInputChange );
            horariosBody.addEventListener( 'change', handleHorarioInputChange );
            niveisBody.addEventListener( 'input', handleNivelInputChange );
            niveisBody.addEventListener( 'change', handleNivelInputChange );

            horariosBody.addEventListener( 'click', function( event ) {
                var button = event.target && event.target.closest ? event.target.closest( '.est-eliminar-horario' ) : null;
                var row;

                if ( ! button ) {
                    return;
                }

                row = button.closest( '[data-horario-row]' );
                if ( ! row ) {
                    return;
                }

                removeHorarioRow( row );
            } );

            niveisBody.addEventListener( 'click', function( event ) {
                var button = event.target && event.target.closest ? event.target.closest( '.est-eliminar-nivel' ) : null;
                var row;

                if ( ! button ) {
                    return;
                }

                row = button.closest( '[data-nivel-row]' );
                if ( ! row ) {
                    return;
                }

                removeNivelRow( row );
            } );

            if ( addHorarioButton ) {
                addHorarioButton.addEventListener( 'click', function() {
                    addHorarioRow();
                } );
            }

            if ( addNivelButton ) {
                addNivelButton.addEventListener( 'click', function() {
                    addNivelRow();
                } );
            }

            if ( saveHorariosButton ) {
                saveHorariosButton.addEventListener( 'click', function( event ) {
                    event.preventDefault();
                    saveStructure( 'horarios' );
                } );
            }

            if ( saveNiveisButton ) {
                saveNiveisButton.addEventListener( 'click', function( event ) {
                    event.preventDefault();
                    saveStructure( 'niveis' );
                } );
            }

            if ( courseSelect ) {
                courseSelect.addEventListener( 'change', function() {
                    if ( ! confirmDiscardDrafts() ) {
                        courseSelect.value = curso;
                        return;
                    }
                    allowNavigation = true;
                    courseSelect.form.submit();
                } );
            }

            if ( copyForm ) {
                copyForm.addEventListener( 'submit', function( event ) {
                    var data;

                    event.preventDefault();
                    if ( ! confirmDiscardDrafts() ) {
                        return;
                    }
                    if ( ! confirm( messageNeedCourseCopy ) ) {
                        return;
                    }

                    data = new FormData( copyForm );
                    data.append( 'accion', 'copiar_estrutura' );
                    data.append( 'curso_escolar', curso );

                    beginRequest();
                    fetch( api, {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': nonce
                        },
                        body: data
                    } ).then( parseJsonResponse ).then( function( json ) {
                        if ( json && true === json.success ) {
                            allowNavigation = true;
                            location.reload();
                            return;
                        }
                        showResponseError( json, messageCopyFailed );
                    } ).catch( function( err ) {
                        setStatus( messageNetwork + err.message, true );
                    } ).finally( function() {
                        endRequest();
                    } );
                } );
            }

            refreshHorarioSelects();
            validateStructure( false );
        })();
        </script>
        <?php
    }
}
