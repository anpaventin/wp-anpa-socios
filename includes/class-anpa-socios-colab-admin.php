<?php
/**
 * Collaborators network (1.104.0): the junta's screen.
 *
 * One wp-admin page (ANPA → Colaboradores): the module switch, the shops,
 * the shop data waiting for approval, the offers waiting for approval and
 * every offer. Server-rendered forms with admin-post handlers (capability +
 * nonce on each one). Other parts of the module add their own sections
 * through the `anpa_colab_admin_seccions` action.
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_anpa_colab_axustes', array( __CLASS__, 'handle_axustes' ) );
		add_action( 'admin_post_anpa_colab_comercio', array( __CLASS__, 'handle_comercio' ) );
		add_action( 'admin_post_anpa_colab_comercio_estado', array( __CLASS__, 'handle_comercio_estado' ) );
		add_action( 'admin_post_anpa_colab_oferta', array( __CLASS__, 'handle_oferta' ) );
		add_action( 'admin_post_anpa_colab_proposta', array( __CLASS__, 'handle_proposta' ) );
		add_action( 'admin_post_anpa_colab_oferta_editar', array( __CLASS__, 'handle_oferta_editar' ) );
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
	 * The media picker for the card logo, only on this page.
	 *
	 * @param  string $hook Admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ): void {
		if ( false === strpos( (string) $hook, ANPA_Socios_Colab_Service::ADMIN_SLUG ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script(
			'media-editor',
			"document.addEventListener('click',function(e){var b=e.target.closest('[data-colab-media]');if(!b||!window.wp||!wp.media){return;}e.preventDefault();"
			. "var i=document.getElementById(b.getAttribute('data-colab-media'));var f=wp.media({library:{type:'image'},multiple:false});"
			. "f.on('select',function(){var a=f.state().get('selection').first().toJSON();i.value=String(a.id||0);});f.open();});"
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
	 * Saves the module switch, the approval hours and the card logo.
	 *
	 * @return void
	 */
	public static function handle_axustes(): void {
		self::gardar( 'anpa_colab_axustes' );
		$activo = ! empty( $_POST['activo'] ) ? '1' : '0';
		$horas  = isset( $_POST['horas'] ) ? max( 0, min( 720, (int) wp_unslash( $_POST['horas'] ) ) ) : ANPA_Socios_Colab::HORAS_APROBACION;
		$logo   = isset( $_POST['logo_carne'] ) ? max( 0, (int) wp_unslash( $_POST['logo_carne'] ) ) : 0;
		if ( $logo > 0 && ! wp_attachment_is_image( $logo ) ) {
			$logo = 0;
		}
		if ( '1' === $activo ) {
			ANPA_Socios_Colab_DB::instalar();
		}
		update_option( ANPA_Socios_Colab_DB::OPTION_ACTIVO, $activo, false );
		update_option( ANPA_Socios_Colab_DB::OPTION_HORAS, $horas, false );
		update_option( ANPA_Socios_Colab_DB::OPTION_LOGO_CARNE, $logo, false );
		ANPA_Socios_Admin_Shared::write_audit_actor( self::actor(), 'admin', 'colaboradores', 'axustes', '1' === $activo ? 'colab_activado' : 'colab_desactivado' );
		ANPA_Socios_Colab_Service::limpar_cache();
		self::volver( 'axustes' );
	}

	/**
	 * Creates a shop or changes its name / login email. The rest of its data
	 * is the shop's own (sent as a proposal from its panel).
	 *
	 * @return void
	 */
	public static function handle_comercio(): void {
		self::gardar( 'anpa_colab_comercio' );
		$id = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
		$in = isset( $_POST['comercio'] ) && is_array( $_POST['comercio'] ) ? wp_unslash( $_POST['comercio'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by limpar_comercio().
		$in = array_intersect_key( (array) $in, array( 'nome' => 1, 'email' => 1 ) );
		$r  = ANPA_Socios_Colab::limpar_comercio( $in );
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
		// 1.105.0: the version of the offer this form was showing.
		$vista = isset( $_POST['enviada'] ) ? max( 0, (int) wp_unslash( $_POST['enviada'] ) ) : 0;
		$mapa   = array(
			'aprobar'  => ANPA_Socios_Colab::APROBADA,
			'rexeitar' => ANPA_Socios_Colab::REXEITADA,
			'retirar'  => ANPA_Socios_Colab::RETIRADA,
		);
		if ( ! isset( $mapa[ $accion ] ) || ! ANPA_Socios_Colab_Service::resolver( $id, $mapa[ $accion ], self::actor(), $motivo, $vista ) ) {
			// Someone else (or the 24 h rule) resolved it first, or the shop edited it.
			self::volver( 'oferta_xa' );
		}
		self::volver( 'oferta_' . $accion );
	}

	/**
	 * The junta corrects an offer (and brings back a withdrawn, rejected or
	 * expired one): it ends approved.
	 *
	 * @return void
	 */
	public static function handle_oferta_editar(): void {
		self::gardar( 'anpa_colab_oferta_editar' );
		$id = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
		$in = isset( $_POST['oferta'] ) && is_array( $_POST['oferta'] ) ? wp_unslash( $_POST['oferta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by limpar_oferta().
		$r  = ANPA_Socios_Colab::limpar_oferta( array_map( static function ( $v ) {
			return is_scalar( $v ) ? (string) $v : '';
		}, (array) $in ) );
		if ( array() !== $r['erros'] ) {
			self::volver( 'oferta_campos', array( 'oferta' => $id ) );
		}
		if ( is_wp_error( ANPA_Socios_Colab_Service::editar_oferta_xunta( $id, $r['datos'], self::actor() ) ) ) {
			self::volver( 'oferta_xa' );
		}
		self::volver( 'oferta_editada' );
	}

	/**
	 * Approves or rejects the data a shop proposed, only the version shown.
	 *
	 * @return void
	 */
	public static function handle_proposta(): void {
		self::gardar( 'anpa_colab_proposta' );
		$id     = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
		$accion = isset( $_POST['resolver'] ) ? sanitize_key( wp_unslash( $_POST['resolver'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$vista  = isset( $_POST['enviada'] ) ? max( 0, (int) wp_unslash( $_POST['enviada'] ) ) : 0;
		if ( $vista <= 0 || ! in_array( $accion, array( 'aprobar', 'rexeitar' ), true ) || ! ANPA_Socios_Colab_Service::resolver_proposta( $id, 'aprobar' === $accion, self::actor(), $motivo, $vista ) ) {
			self::volver( 'proposta_xa' );
		}
		self::volver( 'proposta_' . $accion );
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
		$logo_id  = ANPA_Socios_Colab_DB::logo_carne_id();
		$logo_url = ANPA_Socios_Colab_Service::logo_carne_url();
		echo '<h2>' . esc_html__( 'Axustes do módulo', 'anpa-socios' ) . '</h2>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_axustes">';
		wp_nonce_field( 'anpa_colab_axustes' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Estado', 'anpa-socios' ) . '</th><td><label><input type="checkbox" name="activo" value="1"' . checked( $activo, true, false ) . '> ' . esc_html__( 'Activar a rede de colaboradores', 'anpa-socios' ) . '</label><p class="description">' . esc_html__( 'Mentres estea desactivada, os comercios non poden entrar, non hai carné na área de socios e as páxinas do módulo non amosan nada.', 'anpa-socios' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="colab-horas">' . esc_html__( 'Aprobación automática', 'anpa-socios' ) . '</label></th><td><input type="number" id="colab-horas" name="horas" min="0" max="720" step="1" class="small-text" value="' . (int) ANPA_Socios_Colab_DB::horas_aprobacion() . '"> ' . esc_html__( 'horas', 'anpa-socios' ) . '<p class="description">' . esc_html__( 'Unha oferta ou uns datos enviados por un comercio apróbanse sós cando pasan estas horas sen que ninguén os revise; as ofertas aprobadas saen no seguinte envío semanal. Con 0, só se aproban cando o fai a xunta.', 'anpa-socios' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="colab-logo-carne">' . esc_html__( 'Logo do carné', 'anpa-socios' ) . '</label></th><td>';
		if ( '' !== $logo_url ) {
			echo '<img src="' . esc_url( $logo_url ) . '" alt="" style="max-height:64px;display:block;margin-bottom:6px">';
		}
		echo '<input type="number" id="colab-logo-carne" name="logo_carne" min="0" step="1" class="small-text" value="' . (int) $logo_id . '"> <button type="button" class="button" data-colab-media="colab-logo-carne">' . esc_html__( 'Escoller da mediateca', 'anpa-socios' ) . '</button>'
			. '<p class="description">' . esc_html__( 'Imaxe que leva o carné das familias. Con 0 úsase o logo do sitio.', 'anpa-socios' ) . '</p></td></tr>';
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
			ANPA_Socios_Colab_Page::SC_LISTA  => array( __( 'Lista pública de comercios e ofertas (por exemplo en «Vantaxes de ser socio/a»).', 'anpa-socios' ), ANPA_Socios_Hub_Page::find_page_url( ANPA_Socios_Colab_Page::SC_LISTA ) ),
			ANPA_Socios_Colab_Page::SC_ACCESO => array( __( 'Panel dos comercios: entran co seu correo, envían os seus datos e as ofertas e ven o QR do comercio.', 'anpa-socios' ), ANPA_Socios_Colab_Page::url_acceso() ),
			ANPA_Socios_Colab_Page::SC_CAIXA  => array( __( 'Caixa: a páxina que abren o QR do comercio e o carné das familias para aplicar as ofertas.', 'anpa-socios' ), ANPA_Socios_Colab_Page::url_caixa() ),
		);
		foreach ( $paxinas as $sc => $def ) {
			echo '<tr><td><code>[' . esc_html( $sc ) . ']</code></td><td>' . esc_html( $def[0] ) . '</td><td>'
				. ( '' !== $def[1] ? '<a href="' . esc_url( $def[1] ) . '">' . esc_html__( 'Ver páxina', 'anpa-socios' ) . '</a>' : '<strong style="color:#b32d2e">' . esc_html__( 'Falta: crea unha páxina con este shortcode', 'anpa-socios' ) . '</strong>' )
				. '</td></tr>';
		}
		echo '</tbody></table>';

		self::render_propostas( $post );
		self::render_pendentes( $post );
		self::render_comercios( $post );
		self::render_oferta_editar( $post );
		self::render_ofertas( $post );

		/**
		 * More sections of the module (e.g. the weekly publication settings).
		 *
		 * @param string $post Escaped admin-post.php URL for the forms.
		 */
		do_action( 'anpa_colab_admin_seccions', $post );
		echo '</div>';
	}

	/**
	 * Shop data waiting for the junta: what is public now next to what the
	 * shop proposes.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	private static function render_propostas( string $post ): void {
		$pend  = ANPA_Socios_Colab_Service::propostas_pendentes();
		$horas = ANPA_Socios_Colab_DB::horas_aprobacion();
		echo '<h2>' . esc_html__( 'Datos pendentes de aprobación', 'anpa-socios' ) . ' (' . count( $pend ) . ')</h2>';
		if ( array() === $pend ) {
			echo '<p>' . esc_html__( 'Non hai datos de comercios pendentes.', 'anpa-socios' ) . '</p>';
			return;
		}
		$nomes = array(
			'nome'        => __( 'Nome do comercio', 'anpa-socios' ),
			'responsable' => __( 'Persoa de contacto', 'anpa-socios' ),
			'telefono'    => __( 'Teléfono', 'anpa-socios' ),
			'url_web'     => __( 'Web', 'anpa-socios' ),
			'enderezo'    => __( 'Enderezo', 'anpa-socios' ),
			'descricion'  => __( 'Descrición', 'anpa-socios' ),
		);
		foreach ( $pend as $c ) {
			$p = ANPA_Socios_Colab_Service::proposta( $c );
			if ( null === $p ) {
				continue;
			}
			$cando = $horas > 0 && 1 !== (int) $c['proposta_manual']
				/* translators: %s: date and time */
				? sprintf( __( 'Aprobaranse sós o %s se ninguén os revisa.', 'anpa-socios' ), wp_date( 'j/n/Y H:i', (int) $c['proposta_ts'] + $horas * 3600 ) )
				: __( 'Non se aprobarán sós: ten que revisalos a xunta.', 'anpa-socios' );
			echo '<div class="card" style="max-width:860px"><h3>' . esc_html( (string) $c['nome'] ) . ' <small>(' . esc_html( (string) $c['email'] ) . ')</small></h3>';
			echo '<p>' . esc_html( $cando ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th></th><th>' . esc_html__( 'Agora', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Proposto', 'anpa-socios' ) . '</th></tr></thead><tbody>';
			foreach ( $nomes as $k => $etiqueta ) {
				$agora  = (string) ( $c[ $k ] ?? '' );
				$novo   = (string) ( $p['campos'][ $k ] ?? '' );
				$estilo = $agora !== $novo ? ' style="background:#fcf9e8"' : '';
				echo '<tr' . $estilo . '><th scope="row">' . esc_html( $etiqueta ) . '</th><td>' . nl2br( esc_html( $agora ) ) . '</td><td>' . nl2br( esc_html( $novo ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
			}
			$logo_agora = ANPA_Socios_Colab_Service::imaxe_url( (int) $c['logo_id'], 'thumbnail' );
			$logo_novo  = ANPA_Socios_Colab_Service::imaxe_url( (int) $p['logo_id'], 'thumbnail' );
			echo '<tr' . ( (int) $c['logo_id'] !== (int) $p['logo_id'] ? ' style="background:#fcf9e8"' : '' ) . '><th scope="row">' . esc_html__( 'Logo', 'anpa-socios' ) . '</th><td>' . self::miniatura( $logo_agora ) . '</td><td>' . self::miniatura( $logo_novo ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- miniatura() escapes.
			echo '</tbody></table>';
			echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_proposta"><input type="hidden" name="id" value="' . (int) $c['id'] . '"><input type="hidden" name="enviada" value="' . (int) $c['proposta_ts'] . '">';
			wp_nonce_field( 'anpa_colab_proposta' );
			echo '<p><label>' . esc_html__( 'Motivo (se non se aproban; envíaselle ao comercio)', 'anpa-socios' ) . '<br><input type="text" name="motivo" class="large-text" maxlength="300"></label></p>';
			echo '<p><button type="submit" name="resolver" value="aprobar" class="button button-primary">' . esc_html__( 'Aprobar os datos', 'anpa-socios' ) . '</button> ';
			echo '<button type="submit" name="resolver" value="rexeitar" class="button">' . esc_html__( 'Non aprobar', 'anpa-socios' ) . '</button></p></form></div>';
		}
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
			$cando = $horas > 0 && 1 !== (int) ( $o['so_manual'] ?? 0 )
				/* translators: %s: date and time */
				? sprintf( __( 'Aprobarase soa o %s se ninguén a revisa.', 'anpa-socios' ), wp_date( 'j/n/Y H:i', (int) $o['enviada_ts'] + $horas * 3600 ) )
				: ( $horas > 0
					? __( 'Non se aprobará soa (a este comercio non se lle aprobou unha oferta hai pouco): ten que aprobala a xunta.', 'anpa-socios' )
					: __( 'Non se publicará ata que a aprobes.', 'anpa-socios' ) );
			$imaxe = ANPA_Socios_Colab_Service::imaxe_url( (int) $o['imaxe_id'], 'thumbnail' );
			echo '<div class="card" style="max-width:860px">';
			if ( '' !== $imaxe ) {
				echo '<img src="' . esc_url( $imaxe ) . '" alt="" style="float:right;max-width:96px;max-height:96px;margin-left:12px">';
			}
			echo '<h3>' . esc_html( (string) $o['titulo'] ) . ' — ' . esc_html( (string) $o['desconto'] ) . '</h3>';
			echo '<p><strong>' . esc_html( (string) $o['comercio_nome'] ) . '</strong> · ' . esc_html( $cando ) . '</p>';
			echo '<p>' . nl2br( esc_html( (string) $o['descricion'] ) ) . '</p>';
			if ( '' !== (string) $o['condicions'] ) {
				echo '<p><em>' . nl2br( esc_html( (string) $o['condicions'] ) ) . '</em></p>';
			}
			echo '<p>' . esc_html( self::vixencia( $o ) ) . ' · ' . esc_html( 1 === (int) $o['acumulable'] ? __( 'Acumulable con outras ofertas', 'anpa-socios' ) : __( 'Non acumulable', 'anpa-socios' ) ) . ' · ' . esc_html( ANPA_Socios_Colab_Page::texto_usos( (int) $o['usos_familia'] ) ) . ( '' === $imaxe ? ' · ' . esc_html__( 'Sen imaxe (usarase o logo do comercio)', 'anpa-socios' ) : '' ) . '</p>';
			echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_oferta"><input type="hidden" name="id" value="' . (int) $o['id'] . '"><input type="hidden" name="enviada" value="' . (int) $o['enviada_ts'] . '">';
			wp_nonce_field( 'anpa_colab_oferta' );
			echo '<p><label>' . esc_html__( 'Motivo (se non se aproba; envíaselle ao comercio)', 'anpa-socios' ) . '<br><input type="text" name="motivo" class="large-text" maxlength="300"></label></p>';
			echo '<p><button type="submit" name="resolver" value="aprobar" class="button button-primary">' . esc_html__( 'Aprobar', 'anpa-socios' ) . '</button> ';
			echo '<button type="submit" name="resolver" value="rexeitar" class="button">' . esc_html__( 'Non aprobar', 'anpa-socios' ) . '</button></p></form></div>';
		}
	}

	/**
	 * Shops: list and the add / edit form (name and login email only).
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
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Comercio', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Correo de acceso', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Datos', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Estado', 'anpa-socios' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $lista as $c ) {
				$activo = 'activo' === (string) $c['estado'];
				$datos  = (int) $c['proposta_ts'] > 0
					? __( 'Pendentes de aprobación', 'anpa-socios' )
					: ( 1 === (int) $c['cubertos'] ? __( 'Aprobados', 'anpa-socios' ) : __( 'Aínda sen enviar', 'anpa-socios' ) );
				echo '<tr><td>' . self::miniatura( ANPA_Socios_Colab_Service::imaxe_url( (int) $c['logo_id'], 'thumbnail' ), 32 ) . ' <strong>' . esc_html( (string) $c['nome'] ) . '</strong></td><td>' . esc_html( (string) $c['email'] ) . '</td><td>' . esc_html( $datos ) . '</td><td>' . esc_html( $activo ? __( 'Activo', 'anpa-socios' ) : __( 'Desactivado', 'anpa-socios' ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- miniatura() escapes.
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
		echo '<p class="description">' . esc_html__( 'Só o nome e o correo de acceso. O comercio entra no seu panel con ese correo (recibe un código en cada acceso, sen contrasinal) e enche alí os seus datos e o seu logo, que revisades antes de publicalos. Dálle a ligazón da páxina do panel.', 'anpa-socios' ) . '</p>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_comercio"><input type="hidden" name="id" value="' . (int) $editar . '">';
		wp_nonce_field( 'anpa_colab_comercio' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$campos = array(
			'nome'  => array( __( 'Nome do comercio', 'anpa-socios' ), 'text', 120 ),
			'email' => array( __( 'Correo de acceso', 'anpa-socios' ), 'email', 190 ),
		);
		foreach ( $campos as $k => $def ) {
			echo '<tr><th scope="row"><label for="colab-' . esc_attr( $k ) . '">' . esc_html( $def[0] ) . '</label></th><td><input type="' . esc_attr( $def[1] ) . '" id="colab-' . esc_attr( $k ) . '" name="comercio[' . esc_attr( $k ) . ']" value="' . esc_attr( $v( $k ) ) . '" class="regular-text" maxlength="' . (int) $def[2] . '" required>';
			if ( isset( $erros[ $k ] ) ) {
				echo '<p style="color:#b32d2e">' . esc_html( (string) $erros[ $k ] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( $editar > 0 ? __( 'Gardar comercio', 'anpa-socios' ) : __( 'Engadir comercio', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Every offer, with the option to withdraw the approved ones.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	private static function render_oferta_editar( string $post ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which offer to show.
		$id = isset( $_GET['oferta'] ) ? (int) wp_unslash( $_GET['oferta'] ) : 0;
		if ( $id <= 0 ) {
			return;
		}
		$o = null;
		foreach ( ANPA_Socios_Colab_Service::ofertas() as $fila ) {
			if ( (int) $fila['id'] === $id ) {
				$o = $fila;
				break;
			}
		}
		if ( null === $o ) {
			return;
		}
		$viva = ANPA_Socios_Colab::APROBADA === (string) $o['estado'];
		echo '<h2 id="colab-oferta-editar">' . esc_html( $viva ? __( 'Corrixir a oferta', 'anpa-socios' ) : __( 'Recuperar a oferta', 'anpa-socios' ) ) . '</h2>';
		echo '<p>' . esc_html( (string) $o['comercio_nome'] ) . ' · ' . esc_html( ANPA_Socios_Colab::etiqueta_estado( (string) $o['estado'], (int) $o['publicada_ts'] > 0 ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Ao gardar, a oferta queda aprobada e avísase ao comercio. Se xa saíra nunha publicación, volve estar en vigor ao momento (mentres as datas o permitan); se non, sairá na seguinte publicación. Para que unha oferta caducada volva estar en vigor, cambia ou borra a data de fin.', 'anpa-socios' ) . '</p>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="anpa_colab_oferta_editar"><input type="hidden" name="id" value="' . (int) $id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		wp_nonce_field( 'anpa_colab_oferta_editar' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="colab-of-titulo">' . esc_html__( 'Título', 'anpa-socios' ) . '</label></th><td><input type="text" id="colab-of-titulo" name="oferta[titulo]" class="regular-text" maxlength="80" required value="' . esc_attr( (string) $o['titulo'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="colab-of-desconto">' . esc_html__( 'Desconto ou vantaxe', 'anpa-socios' ) . '</label></th><td><input type="text" id="colab-of-desconto" name="oferta[desconto]" class="regular-text" maxlength="60" required value="' . esc_attr( (string) $o['desconto'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="colab-of-descricion">' . esc_html__( 'Descrición breve', 'anpa-socios' ) . '</label></th><td><textarea id="colab-of-descricion" name="oferta[descricion]" rows="3" class="large-text" maxlength="300" required>' . esc_textarea( (string) $o['descricion'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="colab-of-condicions">' . esc_html__( 'Condicións', 'anpa-socios' ) . '</label></th><td><textarea id="colab-of-condicions" name="oferta[condicions]" rows="2" class="large-text" maxlength="300">' . esc_textarea( (string) $o['condicions'] ) . '</textarea></td></tr>';
		$data = static function ( $v ): string {
			$v = (string) $v;
			return ( '' === $v || '0000-00-00' === $v ) ? '' : $v;
		};
		echo '<tr><th scope="row">' . esc_html__( 'Vixencia', 'anpa-socios' ) . '</th><td><label>' . esc_html__( 'Dende', 'anpa-socios' ) . ' <input type="date" name="oferta[valida_dende]" value="' . esc_attr( $data( $o['valida_dende'] ) ) . '"></label> <label>' . esc_html__( 'ata', 'anpa-socios' ) . ' <input type="date" name="oferta[valida_ata]" value="' . esc_attr( $data( $o['valida_ata'] ) ) . '"></label><p class="description">' . esc_html__( 'Sen datas, a oferta non ten fin ata que se retire.', 'anpa-socios' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="colab-of-acumulable">' . esc_html__( 'Acumulable con outras ofertas', 'anpa-socios' ) . '</label></th><td><select id="colab-of-acumulable" name="oferta[acumulable]"><option value="0"' . selected( (int) $o['acumulable'], 0, false ) . '>' . esc_html__( 'Non', 'anpa-socios' ) . '</option><option value="1"' . selected( (int) $o['acumulable'], 1, false ) . '>' . esc_html__( 'Si', 'anpa-socios' ) . '</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="colab-of-usos">' . esc_html__( 'Usos por familia', 'anpa-socios' ) . '</label></th><td><input type="number" id="colab-of-usos" name="oferta[usos_familia]" min="0" max="99" step="1" class="small-text" value="' . (int) $o['usos_familia'] . '"><p class="description">' . esc_html__( '0 = ilimitados.', 'anpa-socios' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( $viva ? __( 'Gardar a oferta', 'anpa-socios' ) : __( 'Gardar e recuperar a oferta', 'anpa-socios' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( ANPA_Socios_Colab_Service::admin_url() ) . '">' . esc_html__( 'Cancelar', 'anpa-socios' ) . '</a></form>';
	}

	/**
	 * Every offer, with what the junta can do with each.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	private static function render_ofertas( string $post ): void {
		$todas = ANPA_Socios_Colab_Service::ofertas();
		$usos  = ANPA_Socios_Colab_Service::usos();
		$hoxe  = current_time( 'Y-m-d' );
		echo '<h2>' . esc_html__( 'Todas as ofertas', 'anpa-socios' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A xunta pode corrixir calquera oferta, retirala da web ou recuperar unha retirada, non aprobada ou caducada.', 'anpa-socios' ) . '</p>';
		if ( array() === $todas ) {
			echo '<p>' . esc_html__( 'Aínda non hai ofertas.', 'anpa-socios' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th></th><th>' . esc_html__( 'Oferta', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Comercio', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Estado', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Vixencia', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Condicións de uso', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Resolta por', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Usos (30 días / total)', 'anpa-socios' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $todas as $o ) {
			$u         = $usos[ (int) $o['id'] ] ?? array( 'total' => 0, 'mes' => 0 );
			$por       = (string) $o['resolta_por'];
			$por       = 'auto' === $por ? __( 'Automática', 'anpa-socios' ) : ( 'comercio' === $por ? __( 'O comercio', 'anpa-socios' ) : $por );
			$publicada = (int) $o['publicada_ts'] > 0;
			$estado    = ANPA_Socios_Colab::etiqueta_estado( (string) $o['estado'], $publicada );
			if ( ANPA_Socios_Colab::APROBADA === (string) $o['estado'] && $publicada ) {
				/* translators: %s: date */
				$estado .= ' ' . sprintf( __( '(dende o %s)', 'anpa-socios' ), wp_date( 'j/n/Y', (int) $o['publicada_ts'] ) );
				$estado .= ANPA_Socios_Colab::vixente( (string) $o['valida_dende'], (string) $o['valida_ata'], $hoxe ) ? ' — ' . __( 'en vigor', 'anpa-socios' ) : ' — ' . __( 'fóra de datas', 'anpa-socios' );
			}
			$imaxe = ANPA_Socios_Colab_Service::imaxe_url( (int) $o['imaxe_id'], 'thumbnail' );
			if ( '' === $imaxe ) {
				$imaxe = ANPA_Socios_Colab_Service::imaxe_url( (int) ( $o['comercio_logo_id'] ?? 0 ), 'thumbnail' );
			}
			echo '<tr><td>' . self::miniatura( $imaxe, 40 ) . '</td><td><strong>' . esc_html( (string) $o['titulo'] ) . '</strong><br>' . esc_html( (string) $o['desconto'] ) . '</td><td>' . esc_html( (string) $o['comercio_nome'] ) . '</td><td>' . esc_html( $estado ) . '</td><td>' . esc_html( self::vixencia( $o ) ) . '</td><td>' . esc_html( ANPA_Socios_Colab_Page::texto_usos( (int) $o['usos_familia'] ) ) . '<br>' . esc_html( 1 === (int) $o['acumulable'] ? __( 'Acumulable', 'anpa-socios' ) : __( 'Non acumulable', 'anpa-socios' ) ) . '</td><td>' . esc_html( $por ) . '</td><td>' . (int) $u['mes'] . ' / ' . (int) $u['total'] . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- miniatura() escapes.
			if ( ANPA_Socios_Colab::APROBADA === (string) $o['estado'] ) {
				echo '<form method="post" action="' . $post . '" style="display:inline"><input type="hidden" name="action" value="anpa_colab_oferta"><input type="hidden" name="id" value="' . (int) $o['id'] . '">';
				wp_nonce_field( 'anpa_colab_oferta' );
				echo '<button type="submit" name="resolver" value="retirar" class="button button-small">' . esc_html__( 'Retirar da web', 'anpa-socios' ) . '</button></form> ';
			}
			if ( ANPA_Socios_Colab::PENDENTE !== (string) $o['estado'] ) {
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'oferta', (int) $o['id'], ANPA_Socios_Colab_Service::admin_url() ) . '#colab-oferta-editar' ) . '">' . esc_html( ANPA_Socios_Colab::APROBADA === (string) $o['estado'] ? __( 'Corrixir', 'anpa-socios' ) : __( 'Recuperar', 'anpa-socios' ) ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Un uso é unha oferta aplicada por un comercio na súa caixa ao carné dunha familia socia. Aquí só se ven as contas, nunca que familia a usou.', 'anpa-socios' ) . '</p>';
	}

	/**
	 * A small image, or a dash.
	 *
	 * @param  string $url  Image URL ('' = none).
	 * @param  int    $lado Max side in pixels.
	 * @return string Escaped HTML.
	 */
	private static function miniatura( string $url, int $lado = 96 ): string {
		if ( '' === $url ) {
			return '—';
		}

		return '<img src="' . esc_url( $url ) . '" alt="" style="max-width:' . (int) $lado . 'px;max-height:' . (int) $lado . 'px;vertical-align:middle">';
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
			'axustes'           => array( 'success', __( 'Axustes gardados.', 'anpa-socios' ) ),
			'comercio'          => array( 'success', __( 'Comercio gardado.', 'anpa-socios' ) ),
			'comercio_erro'     => array( 'error', __( 'Revisa os datos do comercio.', 'anpa-socios' ) ),
			'oferta_aprobar'    => array( 'success', __( 'Oferta aprobada: sairá no seguinte envío semanal. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_rexeitar'   => array( 'success', __( 'Oferta non aprobada. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_retirar'    => array( 'success', __( 'Oferta retirada da web. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_editada'    => array( 'success', __( 'Oferta gardada e aprobada. Avisouse ao comercio.', 'anpa-socios' ) ),
			'oferta_campos'     => array( 'error', __( 'Non se gardou: revisa os campos da oferta (título, desconto, descrición e datas).', 'anpa-socios' ) ),
			'oferta_xa'         => array( 'warning', __( 'Non se fixo nada: esa oferta xa estaba resolta (por outra persoa ou pola aprobación automática) ou o comercio cambiouna mentres a revisabas. Revísaa de novo.', 'anpa-socios' ) ),
			'proposta_aprobar'  => array( 'success', __( 'Datos do comercio aprobados. Avisouse ao comercio.', 'anpa-socios' ) ),
			'proposta_rexeitar' => array( 'success', __( 'Datos do comercio non aprobados. Avisouse ao comercio.', 'anpa-socios' ) ),
			'proposta_xa'       => array( 'warning', __( 'Non se fixo nada: eses datos xa estaban resoltos ou o comercio cambiounos mentres os revisabas. Revísaos de novo.', 'anpa-socios' ) ),
			'erro'              => array( 'error', __( 'Non se puido completar a operación.', 'anpa-socios' ) ),
		);
		if ( isset( $m[ $k ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $m[ $k ][0] ) . ' is-dismissible"><p>' . esc_html( $m[ $k ][1] ) . '</p></div>';
		}
	}
}
