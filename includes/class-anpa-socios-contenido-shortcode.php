<?php
/**
 * FASE37 M7/M8: Public shortcode renderer for administrative content.
 *
 * Provides [anpa_contenido categoria="transporte"] shortcode that renders
 * category content (title, HTML content, icon, documents, links) from the
 * configuration stored in anpa_socios_contenido_admin option.
 *
 * M8 adds structured tables for libros (books) and comedor (menus).
 *
 * @since  1.49.2
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [anpa_contenido] shortcode.
 *
 * @since 1.49.2
 */
final class ANPA_Socios_Contenido_Shortcode {

	/**
	 * Registers the shortcode.
	 *
	 * @since 1.49.2
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'anpa_contenido', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues frontend assets.
	 *
	 * @since 1.49.2
	 * @return void
	 */
	public static function enqueue_assets(): void {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'anpa_contenido' ) ) {
			wp_enqueue_style( 'dashicons' );
			wp_enqueue_style(
				'anpa-contenido-shortcode',
				plugin_dir_path( __DIR__ ) . 'assets/css/contenido-shortcode.css',
				array(),
				ANPA_SOCIOS_VERSION
			);
		}
	}

	/**
	 * Shortcode callback.
	 *
	 * @since  1.49.2
	 * @param  array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'categoria' => '',
			),
			is_array( $atts ) ? $atts : array()
		);

		$categoria = sanitize_key( $atts['categoria'] );

		// Whitelist validation.
		if ( ! in_array( $categoria, ANPA_Socios_Config::CATEGORIAS_VALIDAS, true ) ) {
			return '';
		}

		$config = ANPA_Socios_Config::contenido_admin( $categoria );

		// Fail-closed: inactive or empty.
		if ( empty( $config['activo'] ) || ! is_array( $config ) ) {
			return '';
		}

		// Fail-closed: no title and no content.
		if ( empty( $config['titulo'] ) && empty( $config['contido'] ) && empty( $config['documentos'] ) && empty( $config['enlaces'] ) && empty( $config['items'] ) ) {
			return '';
		}

		ob_start();
		self::render_card( $config, $categoria );
		return ob_get_clean();
	}

	/**
	 * Renders a single category card.
	 *
	 * @since  1.49.2
	 * @param  array  $config    Category configuration.
	 * @param  string $categoria Category key.
	 * @return void
	 */
	private static function render_card( array $config, string $categoria ): void {
		$icono   = ! empty( $config['icono'] ) ? $config['icono'] : '';
		$titulo  = ! empty( $config['titulo'] ) ? $config['titulo'] : '';
		$contido = ! empty( $config['contido'] ) ? $config['contido'] : '';

		echo '<article class="anpa-contenido-card anpa-card" data-categoria="' . esc_attr( $categoria ) . '">';

		// Icon (decorative).
		if ( $icono ) {
			echo '<div class="anpa-card-icon" aria-hidden="true">';
			echo '<span class="dashicons ' . esc_attr( $icono ) . '"></span>';
			echo '</div>';
		}

		// Title.
		if ( $titulo ) {
			echo '<h2 class="anpa-card-title">' . esc_html( $titulo ) . '</h2>';
		}

		// Content.
		if ( $contido ) {
			echo '<div class="anpa-card-content">' . wp_kses_post( $contido ) . '</div>';
		}

		// Documents.
		self::render_documentos( $config['documentos'] ?? array() );

		// Links.
		self::render_enlaces( $config['enlaces'] ?? array() );

		// Structured items (M8: libros/comedor tables).
		self::render_items_table( $config['items'] ?? array(), $categoria );

		echo '</article>';
	}

	/**
	 * Renders documents list.
	 *
	 * @since  1.49.2
	 * @param  array $documentos Documents array.
	 * @return void
	 */
	private static function render_documentos( array $documentos ): void {
		if ( empty( $documentos ) ) {
			return;
		}

		echo '<div class="anpa-card-docs">';
		echo '<h3>' . esc_html__( 'Documentos', 'anpa-socios' ) . '</h3>';
		echo '<ul class="anpa-docs-list">';

		foreach ( $documentos as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$id    = isset( $doc['id'] ) ? absint( $doc['id'] ) : 0;
			$url   = isset( $doc['url'] ) ? esc_url( $doc['url'] ) : '';
			$title = isset( $doc['title'] ) ? $doc['title'] : '';

			// Resolve URL from attachment ID if possible.
			if ( $id > 0 ) {
				$resolved = (string) wp_get_attachment_url( $id );
				if ( $resolved ) {
					$url = $resolved;
				}
			}

			// Fail-closed: skip if no valid URL.
			if ( empty( $url ) ) {
				continue;
			}

			$link_title = $title ? esc_html( $title ) : esc_html__( 'Descargar documento', 'anpa-socios' );
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $link_title . '</a></li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Renders links list.
	 *
	 * @since  1.49.2
	 * @param  array $enlaces Links array.
	 * @return void
	 */
	private static function render_enlaces( array $enlaces ): void {
		if ( empty( $enlaces ) ) {
			return;
		}

		echo '<div class="anpa-card-links">';
		echo '<h3>' . esc_html__( 'Enlaces', 'anpa-socios' ) . '</h3>';
		echo '<ul class="anpa-links-list">';

		foreach ( $enlaces as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}

			$url   = isset( $link['url'] ) ? esc_url( $link['url'] ) : '';
			$title = isset( $link['title'] ) ? $link['title'] : '';

			// Fail-closed: skip invalid URLs.
			if ( empty( $url ) || '#' === $url ) {
				continue;
			}

			$link_text = $title ? esc_html( $title ) : esc_url( $url );
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $link_text . '</a></li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * M8: Renders structured items table for libros/comedor.
	 *
	 * @since  1.49.2
	 * @param  array  $items     Items array.
	 * @param  string $categoria Category key.
	 * @return void
	 */
	private static function render_items_table( array $items, string $categoria ): void {
		if ( empty( $items ) || ! in_array( $categoria, array( 'libros', 'comedor' ), true ) ) {
			return;
		}

		$caption = 'libros' === $categoria
			? esc_html__( 'Lista de libros', 'anpa-socios' )
			: esc_html__( 'Menús do comedor', 'anpa-socios' );

		echo '<div class="anpa-card-table-wrapper">';
		echo '<table class="anpa-items-table">';

		// Caption.
		echo '<caption>' . $caption . '</caption>';

		// Headers.
		echo '<thead><tr>';

		if ( 'libros' === $categoria ) {
			echo '<th scope="col">' . esc_html__( 'Curso', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Nivel', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Materia', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Título', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Editorial', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'ISBN', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Prezo', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Descarga', 'anpa-socios' ) . '</th>';
		} else {
			echo '<th scope="col">' . esc_html__( 'Data', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Menú', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Alérxenos', 'anpa-socios' ) . '</th>';
		}

		echo '</tr></thead>';

		// Body.
		echo '<tbody>';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			echo '<tr>';

			if ( 'libros' === $categoria ) {
				echo '<td>' . esc_html( $item['curso'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['nivel'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['materia'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['titulo'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['editorial'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['isbn'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['prezo'] ?? '' ) . '</td>';

				// Download link.
				$descarga = isset( $item['descarga'] ) ? esc_url_raw( $item['descarga'] ) : '';
				echo '<td>';
				if ( ! empty( $descarga ) ) {
					echo '<a href="' . esc_url( $descarga ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Descargar', 'anpa-socios' ) . '</a>';
				}
				echo '</td>';
			} else {
				echo '<td>' . esc_html( $item['fecha'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['menu'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $item['alerxenos'] ?? '' ) . '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}
}
