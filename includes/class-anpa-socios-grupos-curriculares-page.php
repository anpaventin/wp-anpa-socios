<?php
/**
 * Settings-page renderer for the curricular-groups editor (fase24).
 *
 * Displays a curso_escolar selector and an editable list of curricular groups
 * with their niveis and morning/afternoon time slots, inside the
 * Axustes → Cursos → Grupos curriculares section.
 *
 * @since  1.28.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the inline curricular-groups editor inside the settings page.
 *
 * @since 1.28.0
 */
final class ANPA_Socios_Grupos_Curriculares_Page {

	/**
	 * Renders the full curricular-groups editor.
	 *
	 * @return void
	 */
	public static function render(): void {
		global $wpdb;

		$cursos_t = ANPA_Socios_DB::tabela_cursos();
		$niveis_t = ANPA_Socios_DB::tabela_niveis();

		$existing = $wpdb->get_col( "SELECT DISTINCT curso_escolar FROM {$cursos_t} ORDER BY curso_escolar DESC" );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$sel = isset( $_GET['curso'] ) ? sanitize_text_field( wp_unslash( $_GET['curso'] ) ) : ( $existing[0] ?? '' );

		echo '<h2>' . esc_html__( 'Grupos curriculares', 'anpa-socios' ) . '</h2>';
		echo '<p class="description" style="max-width:720px">' . esc_html__( 'Un grupo curricular agrupa varios niveis (por exemplo 1º-2º-3º) e define a súa franxa de mañá e de tarde. As actividades extraescolares escollen un horario (mañá ou tarde) e un ou varios grupos curriculares; a franxa da actividade herda a do grupo curricular.', 'anpa-socios' ) . '</p>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="anpa-socios-settings">';
		echo '<input type="hidden" name="tab" value="cursos">';
		echo '<input type="hidden" name="section" value="grupos-curriculares">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="gc-curso">' . esc_html__( 'Curso escolar', 'anpa-socios' ) . '</label></th><td>';
		echo '<select name="curso" id="gc-curso" onchange="this.form.submit()">';
		foreach ( $existing as $c ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $c ), selected( $sel, $c, false ), esc_html( $c ) );
		}
		echo '</select>';
		echo '</td></tr></tbody></table>';
		echo '</form>';

		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $sel ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Selecciona un curso escolar válido.', 'anpa-socios' ) . '</p></div>';
			return;
		}

		// Niveis of this curso (for the multi-select in the form).
		$niveis = ANPA_Socios_DB::get_niveis_for_curso( $sel );

		// Existing curricular groups.
		$grupos = ANPA_Socios_DB::get_grupos_curriculares( $sel, true );

		// Map nivel id → etiqueta for display.
		$nivel_label = array();
		foreach ( $niveis as $n ) {
			$nivel_label[ (int) $n['id'] ] = (string) $n['etiqueta'];
		}

		echo '<div id="anpa-gc-editor" data-curso="' . esc_attr( $sel ) . '">';

		if ( empty( $niveis ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Este curso aínda non ten niveis definidos. Define primeiro a estrutura escolar en Axustes → Cursos → Estrutura escolar.', 'anpa-socios' ) . '</p></div>';
		}

		if ( empty( $grupos ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Aínda non hai grupos curriculares para este curso. Crea o primeiro co formulario de abaixo.', 'anpa-socios' ) . '</p></div>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Grupo', 'anpa-socios' ) . '</th>';
			echo '<th>' . esc_html__( 'Niveis', 'anpa-socios' ) . '</th>';
			echo '<th>' . esc_html__( 'Horario de mañá', 'anpa-socios' ) . '</th>';
			echo '<th>' . esc_html__( 'Horario de tarde', 'anpa-socios' ) . '</th>';
			echo '<th>' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $grupos as $g ) {
				$labels = array();
				foreach ( (array) $g['nivel_ids'] as $nid ) {
					$labels[] = $nivel_label[ (int) $nid ] ?? ( '#' . (int) $nid );
				}
				echo '<tr>';
				echo '<td>' . esc_html( $g['etiqueta'] ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $labels ) ) . '</td>';
				echo '<td>' . ( '' !== $g['franxa_manha'] ? esc_html( str_replace( '-', '–', $g['franxa_manha'] ) ) : '—' ) . '</td>';
				echo '<td>' . ( '' !== $g['franxa_tarde'] ? esc_html( str_replace( '-', '–', $g['franxa_tarde'] ) ) : '—' ) . '</td>';
				echo '<td>';
				printf( '<button class="button button-small gc-eliminar" data-id="%d">%s</button>', (int) $g['id'], esc_html__( 'Eliminar', 'anpa-socios' ) );
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';

		// ── Add form ──────────────────────────────────────────────
		echo '<hr>';
		echo '<h3>' . esc_html__( 'Engadir grupo curricular', 'anpa-socios' ) . '</h3>';
		echo '<form id="anpa-gc-form" class="anpa-gc-form">';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="gc-etiqueta">%s</label></th><td><input type="text" id="gc-etiqueta" class="regular-text" required placeholder="%s"></td></tr>',
			esc_html__( 'Etiqueta', 'anpa-socios' ),
			esc_attr__( 'ex: Grupo 1, 1º-2º-3º', 'anpa-socios' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Niveis', 'anpa-socios' ) . '</th><td>';
		if ( empty( $niveis ) ) {
			echo '<em>' . esc_html__( 'Sen niveis dispoñibles.', 'anpa-socios' ) . '</em>';
		} else {
			foreach ( $niveis as $n ) {
				printf(
					'<label style="display:inline-block;margin:0 12px 6px 0"><input type="checkbox" class="gc-nivel" value="%d"> %s</label>',
					(int) $n['id'],
					esc_html( $n['etiqueta'] )
				);
			}
		}
		echo '</td></tr>';
		printf(
			'<tr><th scope="row"><label for="gc-manha">%s</label></th><td><input type="time" id="gc-manha" step="300"> <span class="description">%s</span> <input type="time" id="gc-manha-fin" step="300"></td></tr>',
			esc_html__( 'Horario de mañá (inicio – fin)', 'anpa-socios' ),
			esc_html__( 'a', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="gc-tarde">%s</label></th><td><input type="time" id="gc-tarde" step="300"> <span class="description">%s</span> <input type="time" id="gc-tarde-fin" step="300"></td></tr>',
			esc_html__( 'Horario de tarde (inicio – fin)', 'anpa-socios' ),
			esc_html__( 'a', 'anpa-socios' )
		);
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Debes indicar polo menos unha franxa completa (mañá ou tarde).', 'anpa-socios' ) . '</p>';
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Engadir grupo curricular', 'anpa-socios' ) );
		echo '</form>';

		$rest_url = rest_url( ANPA_Socios_Admin_REST::REST_NAMESPACE . '/grupos-curriculares' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<script>
		(function() {
			const api = '<?php echo esc_url_raw( $rest_url ); ?>';
			const nonce = '<?php echo esc_js( $nonce ); ?>';
			const curso = '<?php echo esc_js( $sel ); ?>';

			function franxa(startId, endId) {
				const s = document.getElementById(startId).value;
				const e = document.getElementById(endId).value;
				if (!s || !e) return '';
				return s + '-' + e;
			}

			document.getElementById('anpa-gc-form')?.addEventListener('submit', async function(ev) {
				ev.preventDefault();
				const niveis = Array.prototype.slice.call(document.querySelectorAll('.gc-nivel:checked')).map(function(c){ return parseInt(c.value, 10); });
				const payload = {
					curso_escolar: curso,
					etiqueta: document.getElementById('gc-etiqueta').value,
					nivel_ids: niveis,
					franxa_manha: franxa('gc-manha', 'gc-manha-fin'),
					franxa_tarde: franxa('gc-tarde', 'gc-tarde-fin'),
				};
				try {
					const res = await fetch(api, { method: 'POST', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
					const json = await res.json();
					if (json.success) { location.reload(); } else { alert('Erro: ' + (json.message || 'descoñecido')); }
				} catch (err) { alert('Erro de rede: ' + err.message); }
			});

			document.querySelectorAll('.gc-eliminar').forEach(function(btn) {
				btn.addEventListener('click', async function() {
					if (!confirm('<?php echo esc_js( __( 'Eliminar este grupo curricular?', 'anpa-socios' ) ); ?>')) return;
					const id = this.dataset.id;
					try {
						const res = await fetch(api + '?id=' + id, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
						const json = await res.json();
						if (json.success) { location.reload(); } else { alert('Erro: ' + (json.message || 'descoñecido')); }
					} catch (err) { alert('Erro de rede: ' + err.message); }
				});
			});
		})();
		</script>
		<?php
	}
}
