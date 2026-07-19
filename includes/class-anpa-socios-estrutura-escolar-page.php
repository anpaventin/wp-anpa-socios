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

        $niveis_t = ANPA_Socios_DB::tabela_niveis();
        $aulas_t  = ANPA_Socios_DB::tabela_aulas();
        $cursos_t = ANPA_Socios_DB::tabela_cursos();

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
        echo '<select name="curso" id="est-curso" onchange="this.form.submit()">';
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
            "SELECT id, codigo, etiqueta, orde, comedor_inicio, comedor_fin, estado FROM {$niveis_t} WHERE curso_escolar = %s AND estado = 'activo' ORDER BY orde ASC, codigo ASC",
            $sel
        ), ARRAY_A );
        if ( ! is_array( $niveis ) ) {
            $niveis = array();
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

        // ── Niveis list ───────────────────────────────────────────
        echo '<div id="anpa-estrutura-editor" data-curso="' . esc_attr( $sel ) . '">';
        echo '<style>
        #anpa-estrutura-editor .anpa-required { color: #b42318; font-weight: 700; }
        #anpa-estrutura-editor .est-comedor-row td { background: #fbfcfe; }
        #anpa-estrutura-editor .est-comedor-fieldset {
            border: 1px solid #d0d7de;
            border-radius: 8px;
            margin: .75rem 0 0;
            padding: .75rem;
        }
        #anpa-estrutura-editor .est-comedor-grid {
            display: grid;
            gap: .75rem 1rem;
            grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
            align-items: end;
        }
        #anpa-estrutura-editor .est-comedor-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: center;
        }
        #anpa-estrutura-editor .est-comedor-status {
            margin-top: .5rem;
            white-space: pre-line;
        }
        @media (max-width: 782px) {
            #anpa-estrutura-editor .est-comedor-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>';
        echo '<p class="description">' . esc_html__( 'Cada nivel garda un só nome (por exemplo «1º»), que se usa tanto internamente como para amosalo. Escolle a última aula de cada nivel (A ata a letra que indiques); crearanse as aulas desde A ata esa letra.', 'anpa-socios' ) . '</p>';

        if ( empty( $niveis ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Aínda non hai niveis definidos para este curso. Crea o primeiro nivel usando o formulario de abaixo.', 'anpa-socios' ) . '</p></div>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Nivel', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Orde', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Última aula', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Aulas actuais', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $niveis as $n ) {
                $nid    = (int) $n['id'];
                $aulas  = $aulas_by_nivel[ $nid ] ?? array();
                $activas = array();
                foreach ( $aulas as $a ) {
                    if ( 'activo' === $a['estado'] ) {
                        $activas[] = esc_html( $a['codigo'] );
                    }
                }
                $ultima = $ultima_por_nivel[ $nid ] ?? 'D';
                $orde   = (int) $n['orde'];
                $comedor_inicio = (string) ( $n['comedor_inicio'] ?? '' );
                $comedor_fin    = (string) ( $n['comedor_fin'] ?? '' );

                echo '<tr data-nivel-row data-id="' . $nid . '">';
                printf(
                    '<td><input type="text" class="est-nivel-nome regular-text" value="%s" style="max-width:10rem"></td>',
                    esc_attr( $n['codigo'] )
                );
                printf(
                    '<td><input type="number" class="est-nivel-orde small-text" value="%d" min="1" step="1" style="width:5rem"></td>',
                    $orde
                );
                echo '<td><select class="est-nivel-ultima">';
                foreach ( $letras as $l ) {
                    printf( '<option value="%1$s"%2$s>A–%1$s</option>', esc_attr( $l ), selected( $ultima, $l, false ) );
                }
                echo '</select></td>';
                echo '<td>' . ( ! empty( $activas ) ? implode( ', ', $activas ) : '—' ) . '</td>';
                echo '<td>';
                printf( '<button class="button button-small est-gardar-nivel" data-id="%d">%s</button> ', $nid, esc_html__( 'Gardar', 'anpa-socios' ) );
                printf( '<button class="button button-small est-eliminar-nivel" data-id="%d">%s</button>', $nid, esc_html__( 'Eliminar', 'anpa-socios' ) );
                echo '</td>';
                echo '</tr>';

                echo '<tr class="est-comedor-row" data-comedor-row data-id="' . $nid . '">';
                echo '<td colspan="5">';
                echo '<fieldset class="est-comedor-fieldset">';
                echo '<legend>' . esc_html__( 'Horario de comedor por nivel', 'anpa-socios' ) . '</legend>';
                printf(
                    '<p id="est-comedor-help-%1$d" class="description">%2$s</p>',
                    $nid,
                    esc_html__( 'Completa as dúas horas ou preme Limpar para deixar este nivel sen horario. A validación do navegador é só unha axuda: o servidor revisa os solapes con grupos abertos.', 'anpa-socios' )
                );
                echo '<div class="est-comedor-grid">';
                printf(
                    '<div><label for="est-comedor-inicio-%1$d">%2$s</label><input type="time" id="est-comedor-inicio-%1$d" class="regular-text est-comedor-inicio" value="%3$s" aria-describedby="est-comedor-help-%1$d est-comedor-status-%1$d"></div>',
                    $nid,
                    esc_html__( 'Inicio', 'anpa-socios' ),
                    esc_attr( $comedor_inicio )
                );
                printf(
                    '<div><label for="est-comedor-fin-%1$d">%2$s</label><input type="time" id="est-comedor-fin-%1$d" class="regular-text est-comedor-fin" value="%3$s" aria-describedby="est-comedor-help-%1$d est-comedor-status-%1$d"></div>',
                    $nid,
                    esc_html__( 'Fin', 'anpa-socios' ),
                    esc_attr( $comedor_fin )
                );
                echo '<div class="est-comedor-actions">';
                printf( '<button type="button" class="button est-comedor-limpar" data-id="%d">%s</button>', $nid, esc_html__( 'Limpar', 'anpa-socios' ) );
                printf( '<button type="button" class="button button-primary est-gardar-comedor" data-id="%d">%s</button>', $nid, esc_html__( 'Gardar horario de comedor', 'anpa-socios' ) );
                echo '</div>';
                echo '</div>';
                printf(
                    '<p id="est-comedor-status-%1$d" class="description est-comedor-status" aria-live="polite" tabindex="-1">%2$s</p>',
                    $nid,
                    esc_html( sprintf(
                        /* translators: 1: meal start, 2: meal end. */
                        __( 'Horario actual: %1$s → %2$s', 'anpa-socios' ),
                        '' !== $comedor_inicio ? $comedor_inicio : '—',
                        '' !== $comedor_fin ? $comedor_fin : '—'
                    ) )
                );
                echo '</fieldset>';
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';

        // ── Add new nivel form ────────────────────────────────────
        echo '<hr>';
        echo '<h3>' . esc_html__( 'Engadir nivel', 'anpa-socios' ) . '</h3>';
        echo '<form id="anpa-est-nivel-form" class="anpa-est-form">';
        echo '<input type="hidden" name="curso_escolar" value="' . esc_attr( $sel ) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        printf(
            '<tr><th scope="row"><label for="est-nivel-codigo">%s <span class="anpa-required" aria-hidden="true">*</span></label></th><td><input type="text" id="est-nivel-codigo" name="codigo" class="regular-text" required placeholder="%s"></td></tr>',
            esc_html__( 'Nivel', 'anpa-socios' ),
            esc_attr__( 'ex: 1º, 2º, Infantil 3', 'anpa-socios' )
        );
        echo '<tr><th scope="row"><label for="est-nivel-ultima">' . esc_html__( 'Última aula', 'anpa-socios' ) . '</label></th><td><select id="est-nivel-ultima" name="ultima_aula">';
        foreach ( $letras as $l ) {
            printf( '<option value="%1$s"%2$s>A–%1$s</option>', esc_attr( $l ), selected( 'D', $l, false ) );
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
        printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Engadir nivel', 'anpa-socios' ) );
        echo '</form>';

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
        echo '<p class="description">' . esc_html__( 'Os niveis e aulas do curso orixe copiaranse para este curso. As estruturas existentes non se sobrescribirán (INSERT IGNORE).', 'anpa-socios' ) . '</p>';
        echo '</td></tr></tbody></table>';
        printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Copiar estrutura', 'anpa-socios' ) );
        echo '</form>';

        // ── Inline JS: REST submission ────────────────────────────
        $rest_url = rest_url( ANPA_Socios_Admin_REST::REST_NAMESPACE . '/estrutura' );
        $nonce    = wp_create_nonce( 'wp_rest' );
        ?>
        <script>
        (function() {
            const api = '<?php echo esc_url_raw( $rest_url ); ?>';
            const nonce = '<?php echo esc_js( $nonce ); ?>';
            const curso = '<?php echo esc_js( $sel ); ?>';

            async function postAccion(fields) {
                const data = new FormData();
                data.append('curso_escolar', curso);
                Object.keys(fields).forEach(function(k) { data.append(k, fields[k]); });
                const res = await fetch(api, { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: data });
                return res.json();
            }

            function mealFields(row) {
                return {
                    start: row.querySelector('.est-comedor-inicio'),
                    end: row.querySelector('.est-comedor-fin'),
                    status: row.querySelector('.est-comedor-status'),
                };
            }

            function clearMealPair(row) {
                const fields = mealFields(row);
                if (!fields.start || !fields.end) { return; }
                fields.start.value = '';
                fields.end.value = '';
                fields.start.setCustomValidity('');
                fields.end.setCustomValidity('');
                if (fields.status) {
                    fields.status.textContent = '<?php echo esc_js( __( 'Horario de comedor limpo.', 'anpa-socios' ) ); ?>';
                }
            }

            function validateMealPair(row, focus) {
                const fields = mealFields(row);
                if (!fields.start || !fields.end) { return false; }
                const start = (fields.start.value || '').trim();
                const end = (fields.end.value || '').trim();
                let message = '';
                if ((start && !end) || (!start && end)) {
                    message = '<?php echo esc_js( __( 'Completa as dúas horas ou limpa o horario.', 'anpa-socios' ) ); ?>';
                } else if (start && end && start >= end) {
                    message = '<?php echo esc_js( __( 'A hora de inicio debe ser anterior á de fin.', 'anpa-socios' ) ); ?>';
                }
                fields.start.setCustomValidity(message);
                fields.end.setCustomValidity(message);
                if (fields.status) {
                    fields.status.textContent = message || (start && end ? '<?php echo esc_js( __( 'Horario listo para gardar.', 'anpa-socios' ) ); ?>' : '<?php echo esc_js( __( 'Horario sen configurar.', 'anpa-socios' ) ); ?>');
                }
                if (message && false !== focus) {
                    if (!start && end && fields.start.reportValidity) {
                        fields.start.reportValidity();
                    } else if (fields.end.reportValidity) {
                        fields.end.reportValidity();
                    }
                }
                return '' === message;
            }

            function reportMealError(fields, message) {
                if (!fields.status) { return; }
                fields.status.setAttribute('role', 'alert');
                fields.status.textContent = message;
                fields.status.focus();
            }

            async function saveMealPair(row) {
                if (!validateMealPair(row)) { return; }
                const fields = mealFields(row);
                try {
                    const json = await postAccion({
                        accion: 'gardar_comedor',
                        nivel_id: row.dataset.id,
                        comedor_inicio: fields.start.value.trim(),
                        comedor_fin: fields.end.value.trim(),
                    });
                    if (json.success) {
                        location.reload();
                        return;
                    }
                    if (json.code) {
                        const detailLines = Array.isArray(json?.data?.conflicts) ? json.data.conflicts.map(function(conflict) {
                            return [conflict.actividad, conflict.grupo, conflict.nivel, Array.isArray(conflict.dias) ? conflict.dias.join(', ') : conflict.dias, conflict.franxa].filter(Boolean).join(' — ');
                        }).filter(Boolean).join('\n') : '';
                        reportMealError(fields, json.message + (detailLines ? '\n\n' + detailLines : ''));
                        return;
                    }
                    reportMealError(fields, '<?php echo esc_js( __( 'Erro: ', 'anpa-socios' ) ); ?>' + (json.message || '<?php echo esc_js( __( 'descoñecido', 'anpa-socios' ) ); ?>'));
                } catch(err) {
                    reportMealError(fields, '<?php echo esc_js( __( 'Erro de rede: ', 'anpa-socios' ) ); ?>' + err.message);
                }
            }

            document.querySelectorAll('[data-comedor-row]').forEach(function(row) {
                const fields = mealFields(row);
                if (!fields.start || !fields.end) { return; }
                const syncValidity = function() { validateMealPair(row, false); };
                fields.start.addEventListener('input', syncValidity);
                fields.end.addEventListener('input', syncValidity);
                fields.start.addEventListener('blur', syncValidity);
                fields.end.addEventListener('blur', syncValidity);
                row.querySelector('.est-gardar-comedor')?.addEventListener('click', function() {
                    saveMealPair(row);
                });
                row.querySelector('.est-comedor-limpar')?.addEventListener('click', function() {
                    clearMealPair(row);
                    saveMealPair(row);
                });
                validateMealPair(row, false);
            });

            document.getElementById('anpa-est-nivel-form')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                const codigo = (document.getElementById('est-nivel-codigo').value || '').trim();
                if (!codigo) { alert('<?php echo esc_js( __( 'Indica o nome do nivel.', 'anpa-socios' ) ); ?>'); return; }
                try {
                    const json = await postAccion({
                        accion: 'engadir_nivel',
                        codigo: codigo,
                        ultima_aula: document.getElementById('est-nivel-ultima').value,
                    });
                    if (json.success) { location.reload(); } else { alert('Erro: ' + (json.message || 'descoñecido')); }
                } catch(err) { alert('Erro de rede: ' + err.message); }
            });

            // Per-row save: rename the nivel and set its last classroom letter.
            document.querySelectorAll('.est-gardar-nivel').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    const row = this.closest('[data-nivel-row]');
                    const id = row.dataset.id;
                    const nomeInput = row.querySelector('.est-nivel-nome');
                    const nome = (nomeInput.value || '').trim();
                    const ordeInput = row.querySelector('.est-nivel-orde');
                    const orde = ordeInput ? ordeInput.value : '0';
                    const ultima = row.querySelector('.est-nivel-ultima').value;
                    if (!nome) { alert('<?php echo esc_js( __( 'O nome do nivel non pode quedar baleiro.', 'anpa-socios' ) ); ?>'); return; }
                    try {
                        const r1 = await postAccion({ accion: 'editar_nivel', nivel_id: id, codigo: nome, orde: orde });
                        if (!r1.success) { alert('Erro: ' + (r1.message || 'descoñecido')); return; }
                        const r2 = await postAccion({ accion: 'set_aulas', nivel_id: id, ultima_aula: ultima });
                        if (!r2.success) { alert('Erro: ' + (r2.message || 'descoñecido')); return; }
                        location.reload();
                    } catch(err) { alert('Erro de rede: ' + err.message); }
                });
            });

            document.getElementById('anpa-est-copy-form')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (!confirm('<?php echo esc_js( __( 'Vai copiar a estrutura. As existentes non se sobrescribirán. Continuar?', 'anpa-socios' ) ); ?>')) return;
                const data = new FormData(this);
                data.append('accion', 'copiar_estrutura');
                data.append('curso_escolar', curso);
                try {
                    const res = await fetch(api, {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': nonce },
                        body: data,
                    });
                    const json = await res.json();
                    if (json.success) {
                        location.reload();
                    } else {
                        alert('Erro: ' + (json.message || 'descoñecido'));
                    }
                } catch(err) {
                    alert('Erro de rede: ' + err.message);
                }
            });

            // Delete nivel
            document.querySelectorAll('.est-eliminar-nivel').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    if (!confirm('<?php echo esc_js( __( 'Eliminar este nivel e as súas aulas? Os niveis con referencias activas serán desactivados, non borrados.', 'anpa-socios' ) ); ?>')) return;
                    const id = this.dataset.id;
                    try {
                        const res = await fetch(api + '?nivel_id=' + id + '&curso_escolar=' + curso, {
                            method: 'DELETE',
                            headers: { 'X-WP-Nonce': nonce },
                        });
                        const json = await res.json();
                        if (json.success) {
                            location.reload();
                        } else {
                            alert('Erro: ' + (json.message || 'descoñecido'));
                        }
                    } catch(err) {
                        alert('Erro de rede: ' + err.message);
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
