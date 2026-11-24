<?php
/**
 * Upcoming events (1.101.0): «Data do evento» box in the post editor, the
 * [anpa_proximos_eventos] block and the date box at the top of an event post.
 *
 * @since  1.101.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress glue for ANPA_Socios_Eventos.
 *
 * @since 1.101.0
 */
final class ANPA_Socios_Eventos_Page {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'anpa_proximos_eventos', array( __CLASS__, 'render' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'data_no_evento' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Stylesheet (registered; enqueued by the block and on event posts).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_register_style( 'anpa-eventos', plugins_url( 'assets/css/eventos.css', ANPA_SOCIOS_PLUGIN_FILE ), array(), ANPA_SOCIOS_VERSION );
		if ( is_singular( 'post' ) && '' !== (string) get_post_meta( (int) get_queried_object_id(), ANPA_Socios_Eventos::META_DATA, true ) ) {
			wp_enqueue_style( 'anpa-eventos' );
		}
	}

	/**
	 * Editor box (sidebar).
	 *
	 * @return void
	 */
	public static function meta_box(): void {
		add_meta_box( 'anpa-evento', __( 'Data do evento', 'anpa-socios' ), array( __CLASS__, 'meta_box_html' ), 'post', 'side', 'high' );
	}

	/**
	 * Editor box fields.
	 *
	 * @param  WP_Post $post Post.
	 * @return void
	 */
	public static function meta_box_html( $post ): void {
		wp_nonce_field( 'anpa_evento_' . $post->ID, 'anpa_evento_nonce' );
		$v = function ( $k ) use ( $post ) { return esc_attr( (string) get_post_meta( $post->ID, $k, true ) ); };
		echo '<p><label for="anpa-evento-data"><strong>' . esc_html__( 'Día', 'anpa-socios' ) . '</strong></label><br>'
			. '<input type="date" id="anpa-evento-data" name="anpa_evento_data" value="' . $v( ANPA_Socios_Eventos::META_DATA ) . '"></p>'
			. '<p><label for="anpa-evento-hora">' . esc_html__( 'Hora (opcional)', 'anpa-socios' ) . '</label><br>'
			. '<input type="time" id="anpa-evento-hora" name="anpa_evento_hora" value="' . $v( ANPA_Socios_Eventos::META_HORA ) . '"></p>'
			. '<p><label for="anpa-evento-fin">' . esc_html__( 'Último día, se dura varios (opcional)', 'anpa-socios' ) . '</label><br>'
			. '<input type="date" id="anpa-evento-fin" name="anpa_evento_fin" value="' . $v( ANPA_Socios_Eventos::META_FIN ) . '"></p>'
			. '<p class="description">' . esc_html__( 'Se pos o día, a entrada sae en «Próximos eventos» (portada e Novas) ata que pase. Déixao baleiro se non é un evento.', 'anpa-socios' ) . '</p>';
	}

	/**
	 * Saves the box.
	 *
	 * @param  int     $post_id Post id.
	 * @param  WP_Post $post    Post.
	 * @return void
	 */
	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST['anpa_evento_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anpa_evento_nonce'] ) ), 'anpa_evento_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$e = ANPA_Socios_Eventos::limpar(
			wp_unslash( $_POST['anpa_evento_data'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by limpar().
			wp_unslash( $_POST['anpa_evento_hora'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_unslash( $_POST['anpa_evento_fin'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		$keys = array( 'data' => ANPA_Socios_Eventos::META_DATA, 'hora' => ANPA_Socios_Eventos::META_HORA, 'fin' => ANPA_Socios_Eventos::META_FIN, 'ata' => ANPA_Socios_Eventos::META_ATA );
		foreach ( $keys as $k => $meta ) {
			if ( '' === $e[ $k ] ) {
				delete_post_meta( $post_id, $meta );
			} else {
				update_post_meta( $post_id, $meta, $e[ $k ] );
			}
		}
	}

	/**
	 * Published events still to come.
	 *
	 * @param  int $max How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function proximos( int $max = 3 ): array {
		$hoxe = current_time( 'Y-m-d' );
		$q    = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'meta_query'     => array( array( 'key' => ANPA_Socios_Eventos::META_ATA, 'value' => $hoxe, 'compare' => '>=', 'type' => 'CHAR' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'  => true,
		) );
		$rows = array();
		foreach ( $q as $p ) {
			$rows[] = array(
				'id'   => $p->ID,
				'data' => (string) get_post_meta( $p->ID, ANPA_Socios_Eventos::META_DATA, true ),
				'hora' => (string) get_post_meta( $p->ID, ANPA_Socios_Eventos::META_HORA, true ),
				'fin'  => (string) get_post_meta( $p->ID, ANPA_Socios_Eventos::META_FIN, true ),
				'ata'  => (string) get_post_meta( $p->ID, ANPA_Socios_Eventos::META_ATA, true ),
			);
		}
		return ANPA_Socios_Eventos::proximos( $rows, $hoxe, $max );
	}

	/**
	 * Shortcode [anpa_proximos_eventos numero="3" titulo="Próximos eventos"].
	 * Prints nothing when no event is coming, so the section just disappears.
	 *
	 * @param  mixed $atts Attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		$a = shortcode_atts( array( 'numero' => 3, 'titulo' => __( 'Próximos eventos', 'anpa-socios' ) ), is_array( $atts ) ? $atts : array() );
		$eventos = self::proximos( max( 1, min( 6, (int) $a['numero'] ) ) );
		if ( array() === $eventos ) {
			return '';
		}
		wp_enqueue_style( 'anpa-eventos' );
		$html = '<section class="anpa-eventos" aria-label="' . esc_attr( (string) $a['titulo'] ) . '">';
		if ( '' !== (string) $a['titulo'] ) {
			$html .= '<h2 class="anpa-eventos-titulo">' . esc_html( (string) $a['titulo'] ) . '</h2>';
		}
		$html .= '<ul class="anpa-eventos-lista">';
		foreach ( $eventos as $e ) {
			$c     = ANPA_Socios_Eventos::chip( $e['data'] );
			$html .= '<li class="anpa-evento"><a class="anpa-evento-ligazon" href="' . esc_url( get_permalink( (int) $e['id'] ) ) . '">'
				. '<span class="anpa-evento-chip" aria-hidden="true"><span>' . esc_html( $c['dia'] ) . '</span><strong>' . esc_html( $c['num'] ) . '</strong><span>' . esc_html( $c['mes'] ) . '</span></span>'
				. '<span class="anpa-evento-texto"><span class="anpa-evento-nome">' . esc_html( get_the_title( (int) $e['id'] ) ) . '</span>'
				. '<span class="anpa-evento-cando">' . esc_html( ANPA_Socios_Eventos::etiqueta( $e['data'], $e['hora'], $e['fin'] ) ) . '</span></span>'
				. '</a></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * Date box at the top of an event post.
	 *
	 * @param  string $content Content.
	 * @return string
	 */
	public static function data_no_evento( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id   = (int) get_the_ID();
		$data = ANPA_Socios_Eventos::data( get_post_meta( $id, ANPA_Socios_Eventos::META_DATA, true ) );
		if ( '' === $data ) {
			return $content;
		}
		$texto = ANPA_Socios_Eventos::etiqueta( $data, ANPA_Socios_Eventos::hora( get_post_meta( $id, ANPA_Socios_Eventos::META_HORA, true ) ), ANPA_Socios_Eventos::data( get_post_meta( $id, ANPA_Socios_Eventos::META_FIN, true ) ) );
		$c     = ANPA_Socios_Eventos::chip( $data );
		$caixa = '<p class="anpa-evento-data"><span class="anpa-evento-chip" aria-hidden="true"><span>' . esc_html( $c['dia'] ) . '</span><strong>' . esc_html( $c['num'] ) . '</strong><span>' . esc_html( $c['mes'] ) . '</span></span>'
			. '<span><strong>' . esc_html__( 'Data do evento:', 'anpa-socios' ) . '</strong> ' . esc_html( $texto ) . '</span></p>';
		return $caixa . $content;
	}
}
