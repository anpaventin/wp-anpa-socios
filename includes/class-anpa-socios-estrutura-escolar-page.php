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
            "SELECT id, codigo, etiqueta, orde, estado FROM {$niveis_t} WHERE curso_escolar = %s ORDER BY orde ASC, codigo ASC",
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

        // ── Niveis list ───────────────────────────────────────────
        echo '<div id="anpa-estrutura-editor" data-curso="' . esc_attr( $sel ) . '">';

        if ( empty( $niveis ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Aínda non hai niveis definidos para este curso. Crea o primeiro nivel usando o formulario de abaixo.', 'anpa-socios' ) . '</p></div>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Nivel', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Código', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Orde', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Estado', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Aulas', 'anpa-socios' ) . '</th>';
            echo '<th>' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $niveis as $n ) {
                $nid   = (int) $n['id'];
                $aulas = $aulas_by_nivel[ $nid ] ?? array();
                $aula_labels = array_map( function ( $a ) {
                    return esc_html( $a['codigo'] . ( $a['estado'] !== 'activo' ? ' (' . $a['estado'] . ')' : '' ) );
                }, $aulas );
                $aula_count = count( $aulas );

                echo '<tr>';
                echo '<td>' . esc_html( $n['etiqueta'] ) . '</td>';
                echo '<td>' . esc_html( $n['codigo'] ) . '</td>';
                echo '<td>' . (int) $n['orde'] . '</td>';
                echo '<td>' . esc_html( $n['estado'] ) . '</td>';
                echo '<td>' . ( $aula_count > 0 ? implode( ', ', $aula_labels ) : '—' ) . '</td>';
                echo '<td>';
                printf( '<button class="button button-small est-editar-nivel" data-id="%d">%s</button> ', $nid, esc_html__( 'Editar', 'anpa-socios' ) );
                printf( '<button class="button button-small est-eliminar-nivel" data-id="%d">%s</button>', $nid, esc_html__( 'Eliminar', 'anpa-socios' ) );
                echo '</td>';
                echo '</tr>';
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
            '<tr><th scope="row"><label for="est-nivel-codigo">%s</label></th><td><input type="text" id="est-nivel-codigo" name="codigo" class="regular-text" required placeholder="%s"></td></tr>',
            esc_html__( 'Código', 'anpa-socios' ),
            esc_attr__( 'ex: 1, 2, INF-3, PRIM-4', 'anpa-socios' )
        );
        printf(
            '<tr><th scope="row"><label for="est-nivel-etiqueta">%s</label></th><td><input type="text" id="est-nivel-etiqueta" name="etiqueta" class="regular-text" required placeholder="%s"></td></tr>',
            esc_html__( 'Etiqueta', 'anpa-socios' ),
            esc_attr__( 'ex: 1º, 2º, Infantil 3, Primaria 4', 'anpa-socios' )
        );
        printf(
            '<tr><th scope="row"><label for="est-nivel-orde">%s</label></th><td><input type="number" id="est-nivel-orde" name="orde" class="small-text" value="10" min="0" step="10"></td></tr>',
            esc_html__( 'Orde', 'anpa-socios' )
        );
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

            document.getElementById('anpa-est-nivel-form')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                const data = new FormData(this);
                data.append('accion', 'engadir_nivel');
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
