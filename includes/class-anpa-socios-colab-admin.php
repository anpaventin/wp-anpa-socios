<?php
/**
 * Collaborators network (1.104.0): the junta's screen.
 *
 * One wp-admin page (ANPA → Colaboradores): the module switch, the shops,
 * the offers waiting for approval and every offer. Server-rendered forms with
 * admin-post handlers (capability + nonce on each one).
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page of the collaborators module.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab_Admin {

	const CAP = 'manage_options';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
		add_action( 'admin_post_anpa_colab_axustes', array( __CLASS__, 'handle_axustes' ) );
		add_action( 'admin_post_anpa_colab_comercio', array( __CLASS__, 'handle_comercio' ) );
		add_action( 'admin_post_anpa_colab_comercio_estado', array( __CLASS__, 'handle_comercio_estado' ) );
		add_action( 'admin_post_anpa_colab_oferta', array( __CLASS__, 'handle_oferta' ) );
	}

	/**
	 * Submenu entry under the plugin menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			ANPA_Socios_Admin_Settings::OVERVIEW_SLUG,
			esc_html__( 'Colaboradores', 'anpa-socios' ),
			esc_html__( 'Colaboradores', 'anpa-socios' ),
			self::CAP,
			ANPA_Socios_Colab_Service::ADMIN_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Capability + nonce gate for every handler.
	 *
	 * @param  string $accion Nonce action.
	 * @return void
	 */
	private static function gardar( string $accion ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non tes permiso para facer isto.', 'anpa-socios' ) );
		}
		check_admin_referer( $accion );
	}

	/**
	 * Back to the page with a notice (PRG).
	 *
	 * @param  string              $msg   Notice key.
	 * @param  array<string,mixed> $extra Extra query args.
	 * @return void
	 */
	private static function volver( string $msg, array $extra = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'colab_msg' => $msg ), $extra ), ANPA_Socios_Colab_Service::admin_url() ) );
		exit;
	}

	/**
	 * @return string Email of the signed-in admin (audit actor).
	 */
	private static function actor(): string {
		$u = wp_get_current_user();
		return ( $u instanceof WP_User ) ? strtolower( (string) $u->user_email ) : '';
	}

	/**
	 * Saves the module switch and the approval hours.
	 *
	 * @return void
	 */
	public static function handle_axustes(): void {
		self::gardar( 'anpa_colab_axustes' );
		$activo = ! empty( $_POST['activo'] ) ? '1' : '0';
		$horas  = isset( $_POST['horas'] ) ? max( 0, min( 720, (int) wp_unslash( $_POST['horas'] ) ) ) : ANPA_Socios_Colab::HORAS_APROBACION;
		if ( '1' === $activo ) {
			ANPA_Socios_Colab_DB::instalar();
		}
		update_option( ANPA_Socios_Colab_DB::OPTION_ACTIVO, $activo, false );
		update_option( ANPA_Socios_Colab_DB::OPTION_HORAS, $horas, false );
		ANPA_Socios_Admin_Shared::write_audit_actor( self::actor(), 'admin', 'colaboradores', 'axustes', '1' === $activo ? 'colab_activado' : 'colab_desactivado' );
		ANPA_Socios_Colab_Service::limpar_cache();
		self::volver( 'axustes' );
	}

	/**
	 * Creates or edits a shop.
	 *
	 * @return void
	 */
	public static function handle_comercio(): void {
		self::gardar( 'anpa_colab_comercio' );
		$id = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
		$in = isset( $_POST['comercio'] ) && is_array( $_POST['comercio'] ) ? wp_unslash( $_POST['comercio'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by limpar_comercio().
		$r  = ANPA_Socios_Colab::limpar_comercio( (array) $in );
		if ( array() !== $r['erros'] ) {
			set_transient( 'anpa_colab_form_' . get_current_user_id(), array( 'datos' => $r['datos'], 'erros' => $r['erros'] ), 5 * MINUTE_IN_SECONDS );
			self::volver( 'comercio_erro', $id > 0 ? array( 'editar' => $id ) : array() );
		}
		$res = ANPA_Socios_Colab_Service::gardar_comercio( $r['datos'], $id );
		if ( is_wp_error( $res ) ) {
			set_transient( 'anpa_colab_form_' . get_current_user_id(), array( 'datos' => $r['datos'], 'erros' => array( 'email' => $res->get_error_message() ) ), 5 * MINUTE_IN_SECONDS );
			self::volver( 'comercio_erro', $id > 0 ? array( 'editar' => $id ) : array() );
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( self::actor(), 'admin', 'comercio_colab', (string) $res, $id > 0 ? 'update' : 'create' );
		self::volver( 'comercio' );
	}

	/**
	 * Activates or deactivates a shop.
	 *
	 * @return void
	 */
	public static function handle_comercio_estado(): void {
		self::gardar( 'anpa_colab_comercio_estado' );
		$id     = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
		$estado = isset( $_POST['estado'] ) && 'activo' === sanitize_key( wp_unslash( $_POST['estado'] ) ) ? 'activo' : 'inactivo';
		if ( ! ANPA_Socios_Colab_Service::estado_comercio( $id, $estado ) ) {
			self::volver( 'erro' );
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( self::actor(), 'admin', 'comercio_colab', (string) $id, 'comercio_' . $estado );
		self::volver( 'comercio' );
	}

	/**
	 * Approves, rejects or withdraws an offer.
	 *
	 * @return void
	 */
	public static function handle_oferta(): void {
		self::gardar( 'anpa_colab_oferta' );
		$id     = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
		$accion = isset( $_POST['resolver'] ) ? sanitize_key( wp_unslash( $_POST['resolver'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$mapa   = array(
			'aprobar'  => ANPA_Socios_Colab::APROBADA,
			'rexeitar' => ANPA_Socios_Colab::REXEITADA,
			'retirar'  => ANPA_Socios_Colab::RETIRADA,
		);
		if ( ! isset( $mapa[ $accion ] ) || ! ANPA_Socios_Colab_Service::resolver( $id, $mapa[ $accion ], self::actor(), $motivo ) ) {
			// Someone else (or the 24 h rule) resolved it first.
			self::volver( 'oferta_xa' );
		}
		self::volver( 'oferta_' . $accion );
	}

	/**
	 * The page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}
		$activo = ANPA_Socios_Colab_DB::activo();
		$post   = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Rede de colaboradores', 'anpa-socios' ) . '</h1>';
		self::render_aviso();

		// ── Module settings ────────────────────────────────────────────
		echo '<h2>' . esc_html__( 'Axustes do módulo', 'anpa-socios' ) . '</h2>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_axustes">';
		wp_nonce_field( 'anpa_colab_axustes' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Estado', 'anpa-socios' ) . '</th><td><label><input type="checkbox" name="activo" value="1"' . checked( $activo, true, false ) . '> ' . esc_html__( 'Activar a rede de colaboradores', 'anpa-socios' ) . '</label><p class="description">' . esc_html__( 'Mentres estea desactivada, os comercios non poden entrar, non hai carné na área de socios e as páxinas do módulo non amosan nada.', 'anpa-socios' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="colab-horas">' . esc_html__( 'Aprobación automática', 'anpa-socios' ) . '</label></th><td><input type="number" id="colab-horas" name="horas" min="0" max="720" step="1" class="small-text" value="' . (int) ANPA_Socios_Colab_DB::horas_aprobacion() . '"> ' . esc_html__( 'horas', 'anpa-socios' ) . '<p class="description">' . esc_html__( 'Unha oferta enviada por un comercio publícase soa cando pasan estas horas sen que ninguén a revise. Con 0, só se publica cando a xunta a aproba.', 'anpa-socios' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Gardar axustes', 'anpa-socios' ), 'primary', 'submit', false );
		echo '</form>';

		if ( ! $activo ) {
			echo '</div>';
			return;
		}

		// ── Pages the module needs ─────────────────────────────────────
		echo '<h2>' . esc_html__( 'Páxinas', 'anpa-socios' ) . '</h2><table class="widefat striped" style="max-width:860px"><tbody>';
		$paxinas = array(
			ANPA_Socios_Colab_Page::SC_LISTA     => __( 'Lista pública de comercios e ofertas (por exemplo en «Vantaxes de ser socio/a»).', 'anpa-socios' ),
			ANPA_Socios_Colab_Page::SC_ACCESO    => __( 'Panel dos comercios: entran co seu correo e envían as ofertas.', 'anpa-socios' ),
			ANPA_Socios_Colab_Page::SC_VERIFICAR => __( 'Comprobación do carné: a páxina que abre o QR.', 'anpa-socios' ),
		);
		foreach ( $paxinas as $sc => $para ) {
			$url = ANPA_Socios_Hub_Page::find_page_url( $sc );
			echo '<tr><td><code>[' . esc_html( $sc ) . ']</code></td><td>' . esc_html( $para ) . '</td><td>'
				. ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver páxina', 'anpa-socios' ) . '</a>' : '<strong style="color:#b32d2e">' . esc_html__( 'Falta: crea unha páxina con este shortcode', 'anpa-socios' ) . '</strong>' )
				. '</td></tr>';
		}
		echo '</tbody></table>';

		self::render_pendentes( $post );
		self::render_comercios( $post );
		self::render_ofertas( $post );
		echo '</div>';
	}

	/**
	 * Offers waiting for the junta.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	private static function render_pendentes( string $post ): void {
		$pend  = ANPA_Socios_Colab_Service::ofertas( ANPA_Socios_Colab::PENDENTE );
		$horas = ANPA_Socios_Colab_DB::horas_aprobacion();
		echo '<h2>' . esc_html__( 'Ofertas pendentes de aprobación', 'anpa-socios' ) . ' (' . count( $pend ) . ')</h2>';
		if ( array() === $pend ) {
			echo '<p>' . esc_html__( 'Non hai ofertas pendentes.', 'anpa-socios' ) . '</p>';
			return;
		}
		foreach ( $pend as $o ) {
			$cando = $horas > 0
				/* translators: %s: date and time */
				? sprintf( __( 'Publicarase soa o %s se ninguén a revisa.', 'anpa-socios' ), wp_date( 'j/n/Y H:i', (int) $o['enviada_ts'] + $horas * 3600 ) )
				: __( 'Non se publicará ata que a aprobes.', 'anpa-socios' );
			echo '<div class="card" style="max-width:860px"><h3>' . esc_html( (string) $o['titulo'] ) . ' — ' . esc_html( (string) $o['desconto'] ) . '</h3>';
			echo '<p><strong>' . esc_html( (string) $o['comercio_nome'] ) . '</strong> · ' . esc_html( $cando ) . '</p>';
			echo '<p>' . nl2br( esc_html( (string) $o['descricion'] ) ) . '</p>';
			if ( '' !== (string) $o['condicions'] ) {
				echo '<p><em>' . nl2br( esc_html( (string) $o['condicions'] ) ) . '</em></p>';
			}
			echo '<p>' . esc_html( self::vixencia( $o ) ) . '</p>';
			echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_oferta"><input type="hidden" name="id" value="' . (int) $o['id'] . '">';
			wp_nonce_field( 'anpa_colab_oferta' );
			echo '<p><label>' . esc_html__( 'Motivo (se non se aproba; envíaselle ao comercio)', 'anpa-socios' ) . '<br><input type="text" name="motivo" class="large-text" maxlength="300"></label></p>';
			echo '<p><button type="submit" name="resolver" value="aprobar" class="button button-primary">' . esc_html__( 'Aprobar e publicar', 'anpa-socios' ) . '</button> ';
			echo '<button type="submit" name="resolver" value="rexeitar" class="button">' . esc_html__( 'Non aprobar', 'anpa-socios' ) . '</button></p></form></div>';
		}
	}

	/**
	 * Shops: list and the add / edit form.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	private static function render_comercios( string $post ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$editar = isset( $_GET['editar'] ) ? (int) wp_unslash( $_GET['editar'] ) : 0;
		echo '<h2>' . esc_html__( 'Comercios', 'anpa-socios' ) . '</h2>';
		$lista = ANPA_Socios_Colab_Service::comercios();
		if ( array() !== $lista ) {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Comercio', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Correo de acceso', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Responsable', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Estado', 'anpa-socios' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $lista as $c ) {
				$activo = 'activo' === (string) $c['estado'];
				echo '<tr><td><strong>' . esc_html( (string) $c['nome'] ) . '</strong></td><td>' . esc_html( (string) $c['email'] ) . '</td><td>' . esc_html( (string) $c['responsable'] ) . '</td><td>' . esc_html( $activo ? __( 'Activo', 'anpa-socios' ) : __( 'Desactivado', 'anpa-socios' ) ) . '</td><td>';
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'editar', (int) $c['id'], ANPA_Socios_Colab_Service::admin_url() ) ) . '#colab-comercio">' . esc_html__( 'Editar', 'anpa-socios' ) . '</a> ';
				echo '<form method="post" action="' . $post . '" style="display:inline"><input type="hidden" name="action" value="anpa_colab_comercio_estado"><input type="hidden" name="id" value="' . (int) $c['id'] . '"><input type="hidden" name="estado" value="' . ( $activo ? 'inactivo' : 'activo' ) . '">';
				wp_nonce_field( 'anpa_colab_comercio_estado' );
				echo '<button type="submit" class="button button-small">' . esc_html( $activo ? __( 'Desactivar', 'anpa-socios' ) : __( 'Activar', 'anpa-socios' ) ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}

		$c     = $editar > 0 ? ANPA_Socios_Colab_Service::comercio( $editar ) : null;
		$garda = get_transient( 'anpa_colab_form_' . get_current_user_id() );
		$erros = array();
		if ( is_array( $garda ) ) {
			delete_transient( 'anpa_colab_form_' . get_current_user_id() );
			$c     = array_merge( is_array( $c ) ? $c : array(), (array) $garda['datos'] );
			$erros = (array) $garda['erros'];
		}
		$v = static function ( string $k ) use ( $c ): string {
			return is_array( $c ) ? (string) ( $c[ $k ] ?? '' ) : '';
		};
		echo '<h3 id="colab-comercio">' . esc_html( $editar > 0 ? __( 'Editar comercio', 'anpa-socios' ) : __( 'Engadir comercio', 'anpa-socios' ) ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'O comercio entra no seu panel co correo que poñas aquí (recibe un código en cada acceso, sen contrasinal). Dálle a ligazón da páxina do panel.', 'anpa-socios' ) . '</p>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_comercio"><input type="hidden" name="id" value="' . (int) $editar . '">';
		wp_nonce_field( 'anpa_colab_comercio' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$campos = array(
			'nome'        => array( __( 'Nome do comercio', 'anpa-socios' ), 'text', 120, true ),
			'email'       => array( __( 'Correo de acceso', 'anpa-socios' ), 'email', 190, true ),
			'responsable' => array( __( 'Persoa de contacto', 'anpa-socios' ), 'text', 60, false ),
			'telefono'    => array( __( 'Teléfono', 'anpa-socios' ), 'text', 30, false ),
			'enderezo'    => array( __( 'Enderezo', 'anpa-socios' ), 'text', 200, false ),
			'url_web'     => array( __( 'Web (con https://)', 'anpa-socios' ), 'url', 512, false ),
		);
		foreach ( $campos as $k => $def ) {
			echo '<tr><th scope="row"><label for="colab-' . esc_attr( $k ) . '">' . esc_html( $def[0] ) . '</label></th><td><input type="' . esc_attr( $def[1] ) . '" id="colab-' . esc_attr( $k ) . '" name="comercio[' . esc_attr( $k ) . ']" value="' . esc_attr( $v( $k ) ) . '" class="regular-text" maxlength="' . (int) $def[2] . '"' . ( $def[3] ? ' required' : '' ) . '>';
			if ( isset( $erros[ $k ] ) ) {
				echo '<p style="color:#b32d2e">' . esc_html( (string) $erros[ $k ] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="colab-descricion">' . esc_html__( 'Descrición (sae na lista pública)', 'anpa-socios' ) . '</label></th><td><textarea id="colab-descricion" name="comercio[descricion]" rows="3" class="large-text" maxlength="400">' . esc_textarea( $v( 'descricion' ) ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( $editar > 0 ? __( 'Gardar comercio', 'anpa-socios' ) : __( 'Engadir comercio', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Every offer, with the option to withdraw the ones in force.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	private static function render_ofertas( string $post ): void {
		$todas = ANPA_Socios_Colab_Service::ofertas();
		echo '<h2>' . esc_html__( 'Todas as ofertas', 'anpa-socios' ) . '</h2>';
		if ( array() === $todas ) {
			echo '<p>' . esc_html__( 'Aínda non hai ofertas.', 'anpa-socios' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Oferta', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Comercio', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Estado', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Vixencia', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Resolta por', 'anpa-socios' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $todas as $o ) {
			$por = (string) $o['resolta_por'];
			$por = 'auto' === $por ? __( 'Automática', 'anpa-socios' ) : ( 'comercio' === $por ? __( 'O comercio', 'anpa-socios' ) : $por );
			echo '<tr><td><strong>' . esc_html( (string) $o['titulo'] ) . '</strong><br>' . esc_html( (string) $o['desconto'] ) . '</td><td>' . esc_html( (string) $o['comercio_nome'] ) . '</td><td>' . esc_html( ANPA_Socios_Colab::etiqueta_estado( (string) $o['estado'] ) ) . '</td><td>' . esc_html( self::vixencia( $o ) ) . '</td><td>' . esc_html( $por ) . '</td><td>';
			if ( ANPA_Socios_Colab::APROBADA === (string) $o['estado'] ) {
				echo '<form method="post" action="' . $post . '" style="display:inline"><input type="hidden" name="action" value="anpa_colab_oferta"><input type="hidden" name="id" value="' . (int) $o['id'] . '">';
				wp_nonce_field( 'anpa_colab_oferta' );
				echo '<button type="submit" name="resolver" value="retirar" class="button button-small">' . esc_html__( 'Retirar da web', 'anpa-socios' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Validity dates of an offer in words.
	 *
	 * @param  array<string,mixed> $o Offer row.
	 * @return string
	 */
	private static function vixencia( array $o ): string {
		$d = (string) ( $o['valida_dende'] ?? '' );
		$a = (string) ( $o['valida_ata'] ?? '' );
		$f = static function ( string $x ): string {
			return ( '' === $x || '0000-00-00' === $x ) ? '' : date_i18n( 'j/n/Y', (int) strtotime( $x ) );
		};
		if ( '' === $f( $d ) && '' === $f( $a ) ) {
			return __( 'Sen data de fin', 'anpa-socios' );
		}
		if ( '' === $f( $a ) ) {
			/* translators: %s: date */
			return sprintf( __( 'Dende o %s', 'anpa-socios' ), $f( $d ) );
		}
		if ( '' === $f( $d ) ) {
			/* translators: %s: date */
			return sprintf( __( 'Ata o %s', 'anpa-socios' ), $f( $a ) );
		}
		/* translators: 1: start date, 2: end date */
		return sprintf( __( 'Do %1$s ao %2$s', 'anpa-socios' ), $f( $d ), $f( $a ) );
	}

	/**
	 * Notice after a redirect.
	 *
	 * @return void
	 */
	private static function render_aviso(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice key.
		$k = isset( $_GET['colab_msg'] ) ? sanitize_key( wp_unslash( $_GET['colab_msg'] ) ) : '';
		$m = array(
			'axustes'         => array( 'success', __( 'Axustes gardados.', 'anpa-socios' ) ),
			'comercio'        => array( 'success', __( 'Comercio gardado.', 'anpa-socios' ) ),
			'comercio_erro'   => array( 'error', __( 'Revisa os datos do comercio.', 'anpa-socios' ) ),
			'oferta_aprobar'  => array( 'success', __( 'Oferta aprobada e publicada. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_rexeitar' => array( 'success', __( 'Oferta non aprobada. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_retirar'  => array( 'success', __( 'Oferta retirada da web. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_xa'       => array( 'warning', __( 'Esa oferta xa estaba resolta (por outra persoa ou pola aprobación automática).', 'anpa-socios' ) ),
			'erro'            => array( 'error', __( 'Non se puido completar a operación.', 'anpa-socios' ) ),
		);
		if ( isset( $m[ $k ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $m[ $k ][0] ) . ' is-dismissible"><p>' . esc_html( $m[ $k ][1] ) . '</p></div>';
		}
	}
}
