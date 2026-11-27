<?php
/**
 * Collaborators network (1.105.0): the weekly publication.
 *
 * Once a week (by default Thursday 16:00, site time) the approved offers go
 * out in one batch: ANPA_Socios_Colab_Service::sacar_aprobadas() marks them
 * published, then a WordPress post lists every offer in force with its shop
 * (title, intro and featured image from ANPA_Socios_Colab_Calendario) and the
 * member families get the same content by email (Bcc batches, like every
 * other mass email of the plugin). The post carries its own category
 * («Ofertas para socios»), never «Novas». The Telegram post comes from the
 * plugin that already announces every new post.
 *
 * @since   1.105.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly post + email of the collaborators' offers.
 *
 * @since 1.105.0
 */
final class ANPA_Socios_Colab_Publicacion {

	const CAP = 'manage_options';

	const OPT_DIA        = 'anpa_colab_pub_dia';
	const OPT_HORA       = 'anpa_colab_pub_hora';
	const OPT_SEMPRE     = 'anpa_colab_pub_sempre';
	const OPT_TERMO      = 'anpa_colab_pub_termo';
	const OPT_AUTOR      = 'anpa_colab_pub_autor';
	const OPT_TELEGRAM   = 'anpa_colab_pub_telegram';
	const OPT_CORREO     = 'anpa_colab_pub_correo';
	const OPT_IMAXES     = 'anpa_colab_pub_imaxes';
	const OPT_IMPORTADAS = 'anpa_colab_pub_importadas';
	const OPT_FEITO      = 'anpa_colab_pub_feito';
	const OPT_ULTIMA     = 'anpa_colab_pub_ultima';

	/** Defaults. */
	const DIA_DEFECTO  = 4;
	const HORA_DEFECTO = '16:00';

	/** The category of the posts (created on demand). */
	const TERMO_SLUG = 'ofertas-socios';

	/** Slug that must never be used for these posts. */
	const SLUG_PROHIBIDO = 'novas';

	/** admin-post actions (also the nonce actions). */
	const ACCION_AXUSTES = 'anpa_colab_publicacion';
	const ACCION_AGORA   = 'anpa_colab_publicar_agora';

	/** Audit actions and the mass-mail log tag. */
	const AUDIT_PUBLICACION = 'colab_publicacion';
	const AUDIT_AXUSTES     = 'colab_pub_axustes';
	const TAG_CORREO        = 'colab_semanal';

	/** Notice query arg after a redirect. */
	const QS_MSG = 'colab_pub_msg';

	/**
	 * Hooks (the cron call is wired by the main plugin file).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'anpa_colab_admin_seccions', array( __CLASS__, 'render_seccion' ) );
		add_action( 'admin_post_' . self::ACCION_AXUSTES, array( __CLASS__, 'handle_axustes' ) );
		add_action( 'admin_post_' . self::ACCION_AGORA, array( __CLASS__, 'handle_publicar_agora' ) );
		add_action( 'admin_post_' . self::ACCION_ESPECIAL, array( __CLASS__, 'handle_publicar_especial' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	// ── Settings (getters with defaults) ───────────────────────────────

	/** @return int ISO weekday of the batch (1 = Monday … 7 = Sunday). */
	public static function dia(): int {
		$d = (int) get_option( self::OPT_DIA, self::DIA_DEFECTO );
		return ( $d >= 1 && $d <= 7 ) ? $d : self::DIA_DEFECTO;
	}

	/** @return array{0:int,1:int} Hour and minute of the batch. */
	public static function hora(): array {
		$h = self::limpar_hora( (string) get_option( self::OPT_HORA, self::HORA_DEFECTO ) );
		$p = explode( ':', '' !== $h ? $h : self::HORA_DEFECTO );
		return array( (int) $p[0], (int) $p[1] );
	}

	/** @return bool Publish even when no new offer came out this week. */
	const ACCION_ESPECIAL = 'anpa_colab_publicar_especial';

	/**
	 * Since the owner's rule of 2026-11-27 a week without NEW offers never
	 * publishes anything; the old setting is ignored.
	 *
	 * @deprecated 1.105.0
	 */
	public static function sempre(): bool {
		return false;
	}

	/** @return bool Old stored setting (unused). */
	private static function sempre_antigo(): bool {
		return '0' !== (string) get_option( self::OPT_SEMPRE, '1' );
	}

	/** @return bool Send the email to the member families. */
	public static function con_correo(): bool {
		return '0' !== (string) get_option( self::OPT_CORREO, '1' );
	}

	/** @return string Telegram channel URL ('' = none). */
	public static function telegram_url(): string {
		return self::url_segura( (string) get_option( self::OPT_TELEGRAM, '' ) );
	}

	/** @return array<string,int> Image overrides per calendar key (attachment ids). */
	public static function imaxes(): array {
		$v   = get_option( self::OPT_IMAXES, array() );
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $k => $id ) {
			if ( isset( ANPA_Socios_Colab_Calendario::claves()[ (string) $k ] ) && (int) $id > 0 ) {
				$out[ (string) $k ] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Post author: the configured user if it can still publish, otherwise the
	 * first administrator.
	 *
	 * @return int
	 */
	public static function autor_id(): int {
		$id = (int) get_option( self::OPT_AUTOR, 0 );
		if ( $id > 0 && user_can( $id, 'publish_posts' ) ) {
			return $id;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
		return is_array( $admins ) && array() !== $admins ? (int) $admins[0] : 0;
	}

	/**
	 * Category of the posts: the configured one when valid and not «Novas»,
	 * otherwise «Ofertas para socios» (created on demand). 0 only when it
	 * cannot be created: then nothing is published (WordPress would fall back
	 * to the default category, which may be «Novas»).
	 *
	 * @return int Term id.
	 */
	public static function termo_id(): int {
		$id = (int) get_option( self::OPT_TERMO, 0 );
		if ( $id > 0 ) {
			$t = get_term( $id, 'category' );
			if ( $t instanceof WP_Term && ! self::e_novas( $t ) ) {
				return (int) $t->term_id;
			}
		}
		$t = get_term_by( 'slug', self::TERMO_SLUG, 'category' );
		if ( $t instanceof WP_Term ) {
			return (int) $t->term_id;
		}
		$r = wp_insert_term(
			__( 'Ofertas para socios', 'anpa-socios' ),
			'category',
			array(
				'slug'        => self::TERMO_SLUG,
				'description' => __( 'Ofertas exclusivas para socios da ANPA dos establecementos colaboradores.', 'anpa-socios' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			$existe = $r->get_error_data( 'term_exists' );
			return is_numeric( $existe ) ? (int) $existe : 0;
		}
		return (int) ( $r['term_id'] ?? 0 );
	}

	/**
	 * Whether a term is the news category (never used for these posts).
	 *
	 * @param  WP_Term $t Term.
	 * @return bool
	 */
	public static function e_novas( WP_Term $t ): bool {
		return self::SLUG_PROHIBIDO === (string) $t->slug || self::SLUG_PROHIBIDO === sanitize_title( (string) $t->name );
	}

	// ── Cron and manual publication ────────────────────────────────────

	/**
	 * Hourly: runs this week's batch once its moment has passed. The batch is
	 * recorded BEFORE any work so a crash or a slow run never repeats it. On
	 * a site that never ran a batch the current week is only recorded: the
	 * first post goes out at the next scheduled moment, not at activation.
	 *
	 * @param  int|null $agora Unix time (tests/manual); null = now.
	 * @return void
	 */
	public static function executar_se_toca( ?int $agora = null ): void {
		if ( ! class_exists( 'ANPA_Socios_Colab_DB' ) || ! ANPA_Socios_Colab_DB::activo() ) {
			return;
		}
		$agora = null === $agora ? time() : $agora;
		$tz    = wp_timezone();
		list( $h, $m ) = self::hora();
		$feito = (int) get_option( self::OPT_FEITO, 0 );
		if ( $feito <= 0 ) {
			update_option( self::OPT_FEITO, ANPA_Socios_Colab_Calendario::ultima_saida( $agora, self::dia(), $h, $m, $tz ), false );
			return;
		}
		$toca = ANPA_Socios_Colab_Calendario::lote_pendente( $agora, $feito, self::dia(), $h, $m, $tz );
		if ( $toca <= 0 ) {
			return;
		}
		update_option( self::OPT_FEITO, $toca, false );
		try {
			self::publicar( $agora, 'semanal' );
		} catch ( \Throwable $e ) {
			self::gardar_resultado( array( 'ts' => $agora, 'manual' => false, 'motivo' => 'erro', 'post_id' => 0, 'ofertas' => 0, 'enviados' => 0, 'fallidos' => 0 ) );
		}
	}

	/**
	 * «Publicar agora as pendentes»: releases the approved offers and publishes
	 * at once, only if there is at least one that had not gone out. The next
	 * weekly moment then has nothing new, so it stays silent unless more
	 * offers are approved before it.
	 *
	 * @return array<string,mixed> Result (see publicar()).
	 */
	public static function publicar_agora(): array {
		return self::publicar( time(), 'pendentes' );
	}

	/**
	 * Special publication the junta launches by hand with the offers in force
	 * (expired ones never go out again), with the title, text and image it
	 * chose. Also releases any approved offer still waiting.
	 *
	 * @param  string $titulo Title ('' = the one of the season).
	 * @param  string $intro  Intro text ('' = the one of the season).
	 * @param  string $clave  Calendar key of the image ('' = the one of the season).
	 * @return array<string,mixed> Result (see publicar()).
	 */
	public static function publicar_especial( string $titulo, string $intro, string $clave ): array {
		return self::publicar( time(), 'especial', array( 'titulo' => $titulo, 'intro' => $intro, 'clave' => $clave ) );
	}

	/**
	 * Next scheduled batch moment.
	 *
	 * @param  int|null $agora Unix time; null = now.
	 * @return int
	 */
	public static function seguinte_saida( ?int $agora = null ): int {
		list( $h, $m ) = self::hora();
		return ANPA_Socios_Colab_Calendario::seguinte_saida( null === $agora ? time() : $agora, self::dia(), $h, $m, wp_timezone() );
	}

	/**
	 * One batch: release approved offers, create the post, send the email.
	 *
	 * Modes: 'semanal' (the weekly moment) and 'pendentes' (the junta's
	 * button) publish ONLY when at least one approved offer had not gone out
	 * yet; 'especial' publishes the offers in force with the junta's own
	 * title, text and image.
	 *
	 * @param  int                  $agora Unix time.
	 * @param  string               $modo  'semanal', 'pendentes' or 'especial'.
	 * @param  array<string,string> $propio Title, intro and image key of a special publication.
	 * @return array{ts:int,manual:bool,motivo:string,post_id:int,ofertas:int,enviados:int,fallidos:int}
	 */
	private static function publicar( int $agora, string $modo, array $propio = array() ): array {
		$manual = 'semanal' !== $modo;
		$res    = array( 'ts' => $agora, 'manual' => $manual, 'motivo' => '', 'post_id' => 0, 'ofertas' => 0, 'enviados' => 0, 'fallidos' => 0 );
		if ( ! class_exists( 'ANPA_Socios_Colab_Service' ) ) {
			$res['motivo'] = 'erro';
			return self::gardar_resultado( $res );
		}
		$novas = method_exists( 'ANPA_Socios_Colab_Service', 'sacar_aprobadas' ) ? (int) ANPA_Socios_Colab_Service::sacar_aprobadas( $agora ) : 0;
		// No new offer, nothing to tell: no post and no email.
		if ( 'especial' !== $modo && $novas <= 0 ) {
			$res['motivo'] = 'sen_novas';
			return self::gardar_resultado( $res );
		}
		$grupos = ANPA_Socios_Colab_Service::publicas();
		$total  = 0;
		foreach ( $grupos as $g ) {
			$total += count( (array) ( $g['ofertas'] ?? array() ) );
		}
		$res['ofertas'] = $total;
		if ( $total <= 0 ) {
			$res['motivo'] = 'sen_ofertas';
			return self::gardar_resultado( $res );
		}
		$termo = self::termo_id();
		if ( $termo <= 0 ) {
			$res['motivo'] = 'sen_categoria';
			return self::gardar_resultado( $res );
		}

		$ymd  = wp_date( 'Y-m-d', $agora );
		$ano  = (int) wp_date( 'Y', $agora );
		$cal  = ANPA_Socios_Colab_Calendario::para( $ymd );
		if ( 'especial' === $modo ) {
			$claves = ANPA_Socios_Colab_Calendario::claves();
			$titulo = trim( (string) ( $propio['titulo'] ?? '' ) );
			$intro  = trim( (string) ( $propio['intro'] ?? '' ) );
			$clave  = (string) ( $propio['clave'] ?? '' );
			if ( '' !== $titulo ) {
				$cal['titulo'] = $titulo;
			}
			if ( '' !== $intro ) {
				$cal['intro'] = $intro;
			}
			if ( isset( $claves[ $clave ] ) ) {
				$cal['clave'] = $clave;
			}
		}
		$tele = self::telegram_url();
		$post = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $cal['titulo'],
				'post_content'  => self::contido_html( $grupos, $cal, $ano, $tele, false, '' ),
				'post_author'   => self::autor_id(),
				'post_category' => array( $termo ),
			),
			true
		);
		if ( is_wp_error( $post ) || (int) $post <= 0 ) {
			$res['motivo'] = 'erro';
			return self::gardar_resultado( $res );
		}
		$post = (int) $post;
		// Only our category, whatever the default category of the site is.
		wp_set_post_categories( $post, array( $termo ), false );
		$imaxe = self::imaxe_id( $cal['clave'] );
		if ( $imaxe > 0 ) {
			set_post_thumbnail( $post, $imaxe );
		}
		$res['post_id'] = $post;
		ANPA_Socios_Admin_Shared::write_audit_actor( $manual ? self::actor() : 'system', $manual ? 'admin' : 'system', 'colaboradores', (string) $post, self::AUDIT_PUBLICACION );
		if ( method_exists( 'ANPA_Socios_Colab_Service', 'limpar_cache' ) ) {
			ANPA_Socios_Colab_Service::limpar_cache();
		}

		if ( self::con_correo() ) {
			$area   = class_exists( 'ANPA_Socios_Admin_Settings' ) ? (string) ANPA_Socios_Admin_Settings::landing_page_url() : '';
			$html   = '<h1 style="font-size:22px">' . esc_html( $cal['titulo'] ) . '</h1>' . self::contido_html( $grupos, $cal, $ano, $tele, true, $area );
			$resumo = self::enviar_correos( $cal['titulo'], $html, $manual );
			$res['enviados'] = (int) $resumo['enviados'];
			$res['fallidos'] = (int) $resumo['fallidos'];
		}
		$res['motivo'] = 'publicada';
		return self::gardar_resultado( $res );
	}

	/**
	 * Remembers the last batch result for the settings screen.
	 *
	 * @param  array<string,mixed> $res Result.
	 * @return array<string,mixed>
	 */
	private static function gardar_resultado( array $res ): array {
		update_option( self::OPT_ULTIMA, $res, false );
		return $res;
	}

	// ── Content ────────────────────────────────────────────────────────

	/**
	 * Escaped HTML of the post (and of the email when $correo).
	 *
	 * @param  array<int,array<string,mixed>> $grupos   ANPA_Socios_Colab_Service::publicas().
	 * @param  array<string,string>           $cal      ANPA_Socios_Colab_Calendario::para().
	 * @param  int                            $ano      Year of the post (validity dates in another year show it).
	 * @param  string                         $telegram Telegram channel URL ('' = no link sentence).
	 * @param  bool                           $correo   Email version (adds the opt-out line).
	 * @param  string                         $area_url Member area URL for the opt-out line ('' = no link).
	 * @return string
	 */
	public static function contido_html( array $grupos, array $cal, int $ano, string $telegram, bool $correo, string $area_url ): string {
		$h = '<p>' . esc_html( (string) ( $cal['intro'] ?? '' ) ) . '</p>';
		foreach ( $grupos as $g ) {
			$c       = (array) ( $g['comercio'] ?? array() );
			$nome    = (string) ( $c['nome'] ?? '' );
			$logo    = self::url_segura( (string) ( $c['logo_url'] ?? '' ) );
			$web     = self::url_segura( (string) ( $c['url_web'] ?? '' ) );
			$enderezo = trim( (string) ( $c['enderezo'] ?? '' ) );

			$h .= '<div class="anpa-colab-pub-comercio" style="border-top:2px solid #e5e5e5;margin-top:24px;padding-top:12px">';
			$h .= '<h2 style="font-size:20px;margin:0 0 6px">';
			if ( '' !== $logo ) {
				$h .= '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $nome ) . '" width="48" height="48" style="width:48px;height:48px;object-fit:contain;vertical-align:middle;margin-right:8px"> ';
			}
			$h .= esc_html( $nome ) . '</h2>';
			$datos = array();
			if ( '' !== $enderezo ) {
				$datos[] = esc_html( $enderezo );
			}
			if ( '' !== $web ) {
				$datos[] = '<a href="' . esc_url( $web ) . '" rel="noopener">' . esc_html__( 'Páxina web', 'anpa-socios' ) . '</a>';
			}
			if ( array() !== $datos ) {
				$h .= '<p style="margin:0 0 10px;color:#555">' . implode( ' · ', $datos ) . '</p>';
			}

			foreach ( (array) ( $g['ofertas'] ?? array() ) as $o ) {
				$o      = (array) $o;
				$titulo = (string) ( $o['titulo'] ?? '' );
				$imaxe  = self::url_segura( (string) ( $o['imaxe_url'] ?? '' ) );
				$imaxe  = '' !== $imaxe ? $imaxe : $logo;
				$desc   = trim( (string) ( $o['descricion'] ?? '' ) );
				$cond   = trim( (string) ( $o['condicions'] ?? '' ) );
				$usos   = isset( $o['usos_familia'] ) ? (int) $o['usos_familia'] : 1;

				$h .= '<div class="anpa-colab-pub-oferta" style="margin:12px 0;padding:12px;border:1px solid #e5e5e5;border-radius:8px">';
				if ( '' !== $imaxe ) {
					$h .= '<p style="margin:0 0 8px"><img src="' . esc_url( $imaxe ) . '" alt="' . esc_attr( $titulo ) . '" width="120" style="max-width:120px;height:auto"></p>';
				}
				$h .= '<h3 style="font-size:17px;margin:0 0 4px">' . esc_html( $titulo ) . '</h3>';
				$h .= '<p style="margin:0 0 6px"><strong>' . esc_html( (string) ( $o['desconto'] ?? '' ) ) . '</strong></p>';
				if ( '' !== $desc ) {
					$h .= '<p style="margin:0 0 6px">' . nl2br( esc_html( $desc ), false ) . '</p>';
				}
				if ( '' !== $cond ) {
					$h .= '<p style="margin:0 0 6px"><em>' . esc_html__( 'Condicións:', 'anpa-socios' ) . '</em> ' . esc_html( $cond ) . '</p>';
				}
				$h .= '<ul style="margin:0;padding-left:20px">';
				$h .= '<li>' . esc_html( ANPA_Socios_Colab_Calendario::vixencia( (string) ( $o['valida_ata'] ?? '' ), $ano ) ) . '</li>';
				$h .= '<li>' . ( ! empty( $o['acumulable'] ) ? esc_html__( 'Acumulable con outras ofertas', 'anpa-socios' ) : esc_html__( 'Non acumulable con outras ofertas', 'anpa-socios' ) ) . '</li>';
				$h .= '<li>' . esc_html( ANPA_Socios_Colab_Calendario::usos_texto( $usos ) ) . '</li>';
				$h .= '</ul></div>';
			}
			$h .= '</div>';
		}

		$peche = esc_html__( 'Estas ofertas son exclusivas para as familias socias da ANPA: para usalas, amosa o carné de socio/a no establecemento.', 'anpa-socios' );
		if ( '' !== $telegram ) {
			$peche .= ' ' . esc_html__( 'Únete á canle de Telegram da ANPA para non perder ningunha oferta:', 'anpa-socios' ) . ' <a href="' . esc_url( $telegram ) . '" rel="noopener">' . esc_html( $telegram ) . '</a>';
		}
		$h .= '<p style="margin-top:24px">' . $peche . '</p>';

		if ( $correo ) {
			$baixa = esc_html__( 'Se non queres recibir este correo semanal, podes desactivalo na área de socios, en «O meu carné de socio/a».', 'anpa-socios' );
			$area  = self::url_segura( $area_url );
			if ( '' !== $area ) {
				$baixa .= ' <a href="' . esc_url( $area ) . '">' . esc_html__( 'Ir á área de socios', 'anpa-socios' ) . '</a>';
			}
			$h .= '<p style="font-size:13px;color:#666">' . $baixa . '</p>';
		}
		return $h;
	}

	/**
	 * An http(s) URL or ''.
	 *
	 * @param  string $u URL.
	 * @return string
	 */
	public static function url_segura( string $u ): string {
		$u = trim( $u );
		if ( '' === $u || ! preg_match( '#^https?://[^\s<>"]+$#i', $u ) ) {
			return '';
		}
		$s = strtolower( (string) parse_url( $u, PHP_URL_SCHEME ) );
		return in_array( $s, array( 'http', 'https' ), true ) && '' !== (string) parse_url( $u, PHP_URL_HOST ) ? $u : '';
	}

	// ── Email ──────────────────────────────────────────────────────────

	/**
	 * Member families' addresses for the weekly email: every active member
	 * (the same list as the other mass emails) minus the families that turned
	 * the weekly email off in «O meu carné de socio/a».
	 *
	 * @return array<int,string>
	 */
	public static function destinatarios(): array {
		if ( ! class_exists( 'ANPA_Socios_Admin_Trimestres_Handler' ) || ! method_exists( 'ANPA_Socios_Admin_Trimestres_Handler', 'socios_activos_emails' ) ) {
			return array();
		}
		$fora = array_flip( self::emails_sen_correo() );
		$out  = array();
		foreach ( ANPA_Socios_Admin_Trimestres_Handler::socios_activos_emails() as $e ) {
			if ( ! isset( $fora[ strtolower( trim( (string) $e ) ) ] ) ) {
				$out[] = (string) $e;
			}
		}
		return $out;
	}

	/**
	 * Addresses of the members of opted-out families (read-only; '' when the
	 * families table does not exist yet).
	 *
	 * @return array<int,string>
	 */
	private static function emails_sen_correo(): array {
		global $wpdb;
		$t = $wpdb->prefix . 'anpa_colab_familias';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table check.
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) {
			return array();
		}
		$soc = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, read-only.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT s.email FROM {$soc} s INNER JOIN {$t} f ON f.familia_id = COALESCE(NULLIF(s.familia_id, 0), s.id) WHERE f.sen_correo = %d AND s.email <> ''", 1 ) );
		return array_map( static function ( $e ): string { return strtolower( trim( (string) $e ) ); }, is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Sends the weekly email: visible recipient the junta's address, the
	 * families in Bcc batches of ANPA_Socios_Envio_Masivo::TAMANO_LOTE, from
	 * the association identity with the signature; one audit row in the
	 * mass-mail log («Correos masivos»).
	 *
	 * @param  string $asunto Subject.
	 * @param  string $html   Escaped HTML body.
	 * @param  bool   $manual From the «Publicar agora» button.
	 * @return array{lotes:int,enviados:int,fallidos:int,lotes_fallidos:int}
	 */
	private static function enviar_correos( string $asunto, string $html, bool $manual ): array {
		$lotes = ANPA_Socios_Envio_Masivo::lotes( self::destinatarios() );
		if ( array() === $lotes ) {
			return ANPA_Socios_Envio_Masivo::resumo( array() );
		}
		$to      = ANPA_Socios_Config::master_email();
		$assoc   = ANPA_Socios_Config::association_name();
		$headers = array( 'From: ' . $assoc . ' <' . $to . '>', 'Reply-To: ' . $assoc . ' <' . $to . '>' );
		$body    = ANPA_Socios_Email::wrap_html( $html );
		$subject = wp_specialchars_decode( $asunto, ENT_QUOTES );
		$out     = array();
		add_filter( 'wp_mail_content_type', array( 'ANPA_Socios_Email', 'content_type_html' ) );
		try {
			foreach ( $lotes as $lote ) {
				$ok = false;
				try {
					$ok = (bool) wp_mail( $to, $subject, $body, array_merge( $headers, array( ANPA_Socios_Envio_Masivo::cabeceira_bcc( $lote ) ) ) );
				} catch ( \Throwable $e ) {
					$ok = false;
				}
				$out[] = array( 'ok' => $ok, 'n' => count( $lote ) );
			}
		} finally {
			remove_filter( 'wp_mail_content_type', array( 'ANPA_Socios_Email', 'content_type_html' ) );
		}
		$resumo = ANPA_Socios_Envio_Masivo::resumo( $out );
		ANPA_Socios_Admin_Shared::write_audit_actor( $manual ? self::actor() : 'system', $manual ? 'admin' : 'system', 'email', ANPA_Socios_Envio_Masivo::etiqueta_auditoria( self::TAG_CORREO, $resumo ), 'masivo' );
		return $resumo;
	}

	// ── Seasonal image ─────────────────────────────────────────────────

	/**
	 * Featured image of a key: the junta's override, otherwise the plugin's
	 * PNG imported once into the media library (remembered by key + file
	 * hash, so a new version of the file is imported again). 0 = none.
	 *
	 * @param  string $clave Calendar key.
	 * @return int Attachment id.
	 */
	public static function imaxe_id( string $clave ): int {
		$over = self::imaxes()[ $clave ] ?? 0;
		if ( $over > 0 && wp_attachment_is_image( $over ) ) {
			return $over;
		}
		$ficheiro = self::ficheiro_imaxe( $clave, 'png' );
		if ( '' === $ficheiro ) {
			return 0;
		}
		$hash = (string) md5_file( $ficheiro );
		$imp  = get_option( self::OPT_IMPORTADAS, array() );
		$imp  = is_array( $imp ) ? $imp : array();
		$k    = $clave . '|' . $hash;
		if ( isset( $imp[ $k ] ) && wp_attachment_is_image( (int) $imp[ $k ] ) ) {
			return (int) $imp[ $k ];
		}
		$bits = (string) file_get_contents( $ficheiro ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local plugin file.
		$up   = wp_upload_bits( 'anpa-colab-' . $clave . '-' . substr( $hash, 0, 8 ) . '.png', null, $bits );
		if ( ! empty( $up['error'] ) || empty( $up['file'] ) ) {
			return 0;
		}
		$titulo = ANPA_Socios_Colab_Calendario::claves()[ $clave ] ?? $clave;
		$id     = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $titulo,
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			return 0;
		}
		$id = (int) $id;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $titulo );
		$imp[ $k ] = $id;
		update_option( self::OPT_IMPORTADAS, $imp, false );
		return $id;
	}

	/**
	 * Path of a plugin illustration, or '' when missing.
	 *
	 * @param  string $clave Calendar key.
	 * @param  string $ext   'png' or 'svg'.
	 * @return string
	 */
	private static function ficheiro_imaxe( string $clave, string $ext ): string {
		if ( ! preg_match( '/^[a-z0-9-]+$/', $clave ) ) {
			return '';
		}
		$f = dirname( __DIR__ ) . '/' . ANPA_Socios_Colab_Calendario::DIR_IMAXES . $clave . '.' . $ext;
		return is_readable( $f ) ? $f : '';
	}

	/**
	 * Preview URL of a key for the settings screen.
	 *
	 * @param  string $clave Calendar key.
	 * @return string
	 */
	private static function previa_url( string $clave ): string {
		$over = self::imaxes()[ $clave ] ?? 0;
		if ( $over > 0 ) {
			$u = wp_get_attachment_image_url( $over, 'medium' );
			if ( is_string( $u ) && '' !== $u ) {
				return $u;
			}
		}
		return self::previa_orixinal( $clave );
	}

	// ── Admin screen ───────────────────────────────────────────────────

	/**
	 * Media library + the picker script on ANPA → Colaboradores only.
	 *
	 * @return void
	 */
	public static function enqueue_admin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page check only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! class_exists( 'ANPA_Socios_Colab_Service' ) || ANPA_Socios_Colab_Service::ADMIN_SLUG !== $page || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'anpa-colab-publicacion-admin',
			plugins_url( 'assets/js/colab-publicacion-admin.js', dirname( __DIR__ ) . '/anpa-socios.php' ),
			array(),
			defined( 'ANPA_SOCIOS_VERSION' ) ? ANPA_SOCIOS_VERSION : '1',
			true
		);
	}

	/**
	 * «Publicación semanal» section of ANPA → Colaboradores.
	 *
	 * @param  string $post Escaped admin-post URL.
	 * @return void
	 */
	public static function render_seccion( $post = '' ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$post = '' !== (string) $post ? (string) $post : esc_url( admin_url( 'admin-post.php' ) );
		list( $h, $m ) = self::hora();
		$tz      = wp_timezone();
		$proxima = self::seguinte_saida();
		$cal     = ANPA_Socios_Colab_Calendario::para( wp_date( 'Y-m-d', $proxima ) );

		echo '<h2 id="colab-publicacion">' . esc_html__( 'Publicación semanal', 'anpa-socios' ) . '</h2>';
		self::render_aviso();
		$agardan = method_exists( 'ANPA_Socios_Colab_Service', 'pendentes_de_saida' ) ? (int) ANPA_Socios_Colab_Service::pendentes_de_saida() : 0;
		echo '<p>' . esc_html__( 'Cando hai ofertas novas aprobadas, o día fixado publícase unha entrada con todas as ofertas en vigor e o establecemento que as ofrece, e envíase o mesmo contido por correo ás familias socias. Se esa semana non hai ningunha oferta nova, non se publica nin se envía nada.', 'anpa-socios' ) . '</p>';
		if ( $agardan > 0 ) {
			/* translators: 1: number of offers, 2: next batch («o xoves 3 ás 16:00»), 3: post title */
			echo '<p><strong>' . esc_html( sprintf( _n( 'Hai %1$d oferta nova aprobada: sairá %2$s, co título «%3$s».', 'Hai %1$d ofertas novas aprobadas: sairán %2$s, co título «%3$s».', $agardan, 'anpa-socios' ), $agardan, ANPA_Socios_Colab_Calendario::texto_saida( $proxima, $tz ), $cal['titulo'] ) ) . '</strong></p>';
		} else {
			/* translators: %s: next batch («o xoves 3 ás 16:00») */
			echo '<p><strong>' . esc_html( sprintf( __( 'Agora mesmo non hai ofertas novas pendentes de publicar: %s non sairá nada, agás que se aprobe algunha antes.', 'anpa-socios' ), ANPA_Socios_Colab_Calendario::texto_saida( $proxima, $tz ) ) ) . '</strong></p>';
		}
		self::render_ultima();

		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="' . esc_attr( self::ACCION_AXUSTES ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		wp_nonce_field( self::ACCION_AXUSTES );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="colab-pub-dia">' . esc_html__( 'Día e hora', 'anpa-socios' ) . '</label></th><td><select id="colab-pub-dia" name="dia">';
		foreach ( ANPA_Socios_Colab_Calendario::NOMES_DIA as $n => $nome ) {
			echo '<option value="' . (int) $n . '"' . selected( self::dia(), $n, false ) . '>' . esc_html( ucfirst( $nome ) ) . '</option>';
		}
		echo '</select> <input type="time" name="hora" step="60" value="' . esc_attr( sprintf( '%02d:%02d', $h, $m ) ) . '" aria-label="' . esc_attr__( 'Hora', 'anpa-socios' ) . '">';
		echo '<p class="description">' . esc_html__( 'Hora do sitio. A publicación faise na primeira revisión automática despois desa hora (cada hora).', 'anpa-socios' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="colab-pub-termo">' . esc_html__( 'Categoría das entradas', 'anpa-socios' ) . '</label></th><td>';
		$novas   = get_term_by( 'slug', self::SLUG_PROHIBIDO, 'category' );
		$propia  = get_term_by( 'slug', self::TERMO_SLUG, 'category' );
		$excluir = array();
		if ( $novas instanceof WP_Term ) {
			$excluir[] = (int) $novas->term_id;
		}
		if ( $propia instanceof WP_Term ) {
			$excluir[] = (int) $propia->term_id;
		}
		wp_dropdown_categories(
			array(
				'name'              => 'termo',
				'id'                => 'colab-pub-termo',
				'taxonomy'          => 'category',
				'hide_empty'        => false,
				'exclude'           => $excluir,
				'selected'          => (int) get_option( self::OPT_TERMO, 0 ),
				'show_option_none'  => __( 'Ofertas para socios (recomendada)', 'anpa-socios' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'As entradas das ofertas levan sempre a súa propia categoría, nunca «Novas».', 'anpa-socios' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="colab-pub-autor">' . esc_html__( 'Autor/a das entradas', 'anpa-socios' ) . '</label></th><td>';
		wp_dropdown_users(
			array(
				'name'             => 'autor',
				'id'               => 'colab-pub-autor',
				'capability'       => array( 'publish_posts' ),
				'selected'         => (int) get_option( self::OPT_AUTOR, 0 ),
				'show_option_none' => __( 'A primeira persoa administradora', 'anpa-socios' ),
				'option_none_value' => '0',
			)
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="colab-pub-telegram">' . esc_html__( 'Canle de Telegram da ANPA', 'anpa-socios' ) . '</label></th><td><input type="url" id="colab-pub-telegram" name="telegram" class="regular-text" placeholder="https://t.me/…" value="' . esc_attr( self::telegram_url() ) . '"><p class="description">' . esc_html__( 'A entrada e o correo rematan invitando a unirse a esta canle para non perder ningunha oferta. Baleiro: sen ligazón.', 'anpa-socios' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Correo ás familias', 'anpa-socios' ) . '</th><td><label><input type="checkbox" name="correo" value="1"' . checked( self::con_correo(), true, false ) . '> ' . esc_html__( 'Enviar o mesmo contido por correo ás familias socias', 'anpa-socios' ) . '</label><p class="description">' . esc_html__( 'Non o reciben as familias que o desactivaron na área de socios («O meu carné de socio/a»).', 'anpa-socios' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Imaxes de cada época', 'anpa-socios' ) . '</th><td><p class="description">' . esc_html__( 'A imaxe destacada da entrada depende da data. Podes cambiar calquera delas por unha túa da mediateca.', 'anpa-socios' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		$over = self::imaxes();
		foreach ( ANPA_Socios_Colab_Calendario::claves() as $clave => $nome ) {
			$campo = 'colab-pub-imaxe-' . $clave;
			$previa = self::previa_url( $clave );
			echo '<tr><td style="width:30%">' . esc_html( $nome ) . '</td><td>';
			echo '<img id="' . esc_attr( $campo ) . '-previa" src="' . esc_url( $previa ) . '" alt="" width="160" height="84" style="width:160px;height:84px;object-fit:cover;background:#f0f0f1' . ( '' === $previa ? ';display:none' : '' ) . '" data-orixinal="' . esc_url( self::previa_orixinal( $clave ) ) . '">';
			echo '</td><td><input type="hidden" id="' . esc_attr( $campo ) . '" name="imaxes[' . esc_attr( $clave ) . ']" value="' . (int) ( $over[ $clave ] ?? 0 ) . '">';
			echo '<button type="button" class="button anpa-colab-pub-escoller" data-campo="' . esc_attr( $campo ) . '" data-titulo="' . esc_attr( $nome ) . '" data-boton="' . esc_attr__( 'Usar esta imaxe', 'anpa-socios' ) . '">' . esc_html__( 'Escoller', 'anpa-socios' ) . '</button> ';
			echo '<button type="button" class="button-link anpa-colab-pub-restaurar" data-campo="' . esc_attr( $campo ) . '">' . esc_html__( 'Usar a da ANPA', 'anpa-socios' ) . '</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Gardar a publicación semanal', 'anpa-socios' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Publicar agora as ofertas pendentes', 'anpa-socios' ) . '</h3>';
		echo '<form method="post" action="' . $post . '" data-confirmar="' . esc_attr__( 'Publicar agora a entrada coas ofertas novas e enviar o correo ás familias?', 'anpa-socios' ) . '" class="anpa-colab-pub-agora"><input type="hidden" name="action" value="' . esc_attr( self::ACCION_AGORA ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		wp_nonce_field( self::ACCION_AGORA );
		submit_button( __( 'Publicar agora', 'anpa-socios' ), 'secondary', 'submit', false, $agardan > 0 ? array() : array( 'disabled' => 'disabled' ) );
		echo ' <span class="description">' . esc_html__( 'Publica xa a entrada (e o correo, se está activado) coas ofertas aprobadas que aínda non saíron, sen agardar ao día fixado. Ese día xa non sairá nada, agás que se aproben máis ofertas antes.', 'anpa-socios' ) . '</span>';
		echo '</form>';

		// ── Special publication: the junta chooses text and image ───────
		$hoxe = ANPA_Socios_Colab_Calendario::para( wp_date( 'Y-m-d' ) );
		echo '<h3>' . esc_html__( 'Publicación especial coas ofertas en vigor', 'anpa-socios' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Lanza unha entrada (e o correo, se está activado) con todas as ofertas en vigor, haxa ou non ofertas novas. As ofertas caducadas ou retiradas non se publican. Proponse o título, o texto e a imaxe desta época: podes cambialos antes de publicar.', 'anpa-socios' ) . '</p>';
		echo '<form method="post" action="' . $post . '" data-confirmar="' . esc_attr__( 'Publicar agora esta entrada coas ofertas en vigor e enviar o correo ás familias?', 'anpa-socios' ) . '" class="anpa-colab-pub-agora"><input type="hidden" name="action" value="' . esc_attr( self::ACCION_ESPECIAL ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		wp_nonce_field( self::ACCION_ESPECIAL );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="colab-esp-titulo">' . esc_html__( 'Título', 'anpa-socios' ) . '</label></th><td><input type="text" id="colab-esp-titulo" name="titulo" class="large-text" maxlength="120" value="' . esc_attr( $hoxe['titulo'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="colab-esp-intro">' . esc_html__( 'Texto de presentación', 'anpa-socios' ) . '</label></th><td><textarea id="colab-esp-intro" name="intro" rows="4" class="large-text" maxlength="800">' . esc_textarea( $hoxe['intro'] ) . '</textarea><p class="description">' . esc_html__( 'Despois deste texto van as ofertas e, ao final, a invitación á canle de Telegram.', 'anpa-socios' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="colab-esp-imaxe">' . esc_html__( 'Imaxe', 'anpa-socios' ) . '</label></th><td><select id="colab-esp-imaxe" name="clave">';
		foreach ( ANPA_Socios_Colab_Calendario::claves() as $clave => $nome ) {
			echo '<option value="' . esc_attr( $clave ) . '"' . selected( $hoxe['clave'], $clave, false ) . '>' . esc_html( $nome ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Úsase a imaxe desa época (a túa, se a cambiaches máis arriba).', 'anpa-socios' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Publicar a entrada especial', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Plugin image URL of a key (what «Usar a da ANPA» restores in the preview).
	 *
	 * @param  string $clave Calendar key.
	 * @return string
	 */
	private static function previa_orixinal( string $clave ): string {
		foreach ( array( 'png', 'svg' ) as $ext ) {
			if ( '' !== self::ficheiro_imaxe( $clave, $ext ) ) {
				return plugins_url( ANPA_Socios_Colab_Calendario::DIR_IMAXES . $clave . '.' . $ext, dirname( __DIR__ ) . '/anpa-socios.php' );
			}
		}
		return '';
	}

	/**
	 * Last batch result line.
	 *
	 * @return void
	 */
	private static function render_ultima(): void {
		$u = get_option( self::OPT_ULTIMA, array() );
		if ( ! is_array( $u ) || empty( $u['ts'] ) ) {
			return;
		}
		$cando = wp_date( 'j/n/Y H:i', (int) $u['ts'] );
		$txt   = array(
			'publicada'     => __( 'publicouse a entrada', 'anpa-socios' ),
			'sen_novas'     => __( 'non se publicou porque non había ofertas novas', 'anpa-socios' ),
			'sen_ofertas'   => __( 'non se publicou porque non había ofertas en vigor', 'anpa-socios' ),
			'sen_categoria' => __( 'non se publicou porque non se puido crear a categoría', 'anpa-socios' ),
			'erro'          => __( 'non se puido publicar', 'anpa-socios' ),
		);
		$motivo = (string) ( $u['motivo'] ?? 'erro' );
		/* translators: 1: date and time, 2: what happened */
		$linea = sprintf( __( 'Última publicación (%1$s): %2$s.', 'anpa-socios' ), $cando, $txt[ $motivo ] ?? $txt['erro'] );
		echo '<p>' . esc_html( $linea );
		if ( 'publicada' === $motivo && (int) ( $u['post_id'] ?? 0 ) > 0 ) {
			$url = get_permalink( (int) $u['post_id'] );
			if ( is_string( $url ) && '' !== $url ) {
				echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver entrada', 'anpa-socios' ) . '</a>';
			}
			/* translators: 1: offers, 2: emails sent, 3: emails failed */
			echo ' ' . esc_html( sprintf( __( '(%1$d ofertas; correos: %2$d enviados, %3$d fallidos)', 'anpa-socios' ), (int) ( $u['ofertas'] ?? 0 ), (int) ( $u['enviados'] ?? 0 ), (int) ( $u['fallidos'] ?? 0 ) ) );
		}
		echo '</p>';
	}

	/**
	 * Notice after a redirect.
	 *
	 * @return void
	 */
	private static function render_aviso(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice key.
		$k = isset( $_GET[ self::QS_MSG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QS_MSG ] ) ) : '';
		$m = array(
			'gardado'       => array( 'success', __( 'Publicación semanal gardada.', 'anpa-socios' ) ),
			'publicada'     => array( 'success', __( 'Entrada publicada coas ofertas en vigor.', 'anpa-socios' ) ),
			'sen_ofertas'   => array( 'warning', __( 'Non se publicou nada: non hai ningunha oferta en vigor.', 'anpa-socios' ) ),
			'sen_novas'     => array( 'warning', __( 'Non se publicou nada: non hai ofertas novas aprobadas pendentes de publicar.', 'anpa-socios' ) ),
			'sen_categoria' => array( 'error', __( 'Non se publicou nada: non se puido crear a categoría «Ofertas para socios».', 'anpa-socios' ) ),
			'erro'          => array( 'error', __( 'Non se puido publicar a entrada.', 'anpa-socios' ) ),
		);
		if ( isset( $m[ $k ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $m[ $k ][0] ) . ' is-dismissible"><p>' . esc_html( $m[ $k ][1] ) . '</p></div>';
		}
	}

	/**
	 * Saves the settings (capability + nonce, PRG).
	 *
	 * @return void
	 */
	public static function handle_axustes(): void {
		self::gardia( self::ACCION_AXUSTES );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in gardia().
		$dia = isset( $_POST['dia'] ) ? (int) wp_unslash( $_POST['dia'] ) : self::DIA_DEFECTO;
		update_option( self::OPT_DIA, ( $dia >= 1 && $dia <= 7 ) ? $dia : self::DIA_DEFECTO, false );
		$hora = self::limpar_hora( isset( $_POST['hora'] ) ? sanitize_text_field( wp_unslash( $_POST['hora'] ) ) : '' );
		update_option( self::OPT_HORA, '' !== $hora ? $hora : self::HORA_DEFECTO, false );
		update_option( self::OPT_CORREO, ! empty( $_POST['correo'] ) ? '1' : '0', false );

		$termo = isset( $_POST['termo'] ) ? max( 0, (int) wp_unslash( $_POST['termo'] ) ) : 0;
		if ( $termo > 0 ) {
			$t = get_term( $termo, 'category' );
			if ( ! ( $t instanceof WP_Term ) || self::e_novas( $t ) ) {
				$termo = 0;
			}
		}
		update_option( self::OPT_TERMO, $termo, false );

		$autor = isset( $_POST['autor'] ) ? max( 0, (int) wp_unslash( $_POST['autor'] ) ) : 0;
		update_option( self::OPT_AUTOR, ( $autor > 0 && user_can( $autor, 'publish_posts' ) ) ? $autor : 0, false );

		$tele = isset( $_POST['telegram'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['telegram'] ) ), array( 'http', 'https' ) ) : '';
		update_option( self::OPT_TELEGRAM, self::url_segura( $tele ), false );

		$imaxes = array();
		$in     = isset( $_POST['imaxes'] ) && is_array( $_POST['imaxes'] ) ? wp_unslash( $_POST['imaxes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value cast to int below.
		$claves = ANPA_Socios_Colab_Calendario::claves();
		foreach ( (array) $in as $k => $id ) {
			$k  = sanitize_key( (string) $k );
			$id = (int) $id;
			if ( isset( $claves[ $k ] ) && $id > 0 && wp_attachment_is_image( $id ) ) {
				$imaxes[ $k ] = $id;
			}
		}
		update_option( self::OPT_IMAXES, $imaxes, false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		ANPA_Socios_Admin_Shared::write_audit_actor( self::actor(), 'admin', 'colaboradores', 'publicacion', self::AUDIT_AXUSTES );
		self::volver( 'gardado' );
	}

	/**
	 * «Publicar agora» (capability + nonce, PRG).
	 *
	 * @return void
	 */
	public static function handle_publicar_agora(): void {
		self::gardia( self::ACCION_AGORA );
		$res    = self::publicar_agora();
		$motivo = (string) ( $res['motivo'] ?? 'erro' );
		self::volver( in_array( $motivo, array( 'publicada', 'sen_ofertas', 'sen_novas', 'sen_categoria' ), true ) ? $motivo : 'erro' );
	}

	/**
	 * Special publication with the junta's title, text and image
	 * (capability + nonce, PRG).
	 *
	 * @return void
	 */
	public static function handle_publicar_especial(): void {
		self::gardia( self::ACCION_ESPECIAL );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in gardia().
		$titulo = isset( $_POST['titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['titulo'] ) ) : '';
		$intro  = isset( $_POST['intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['intro'] ) ) : '';
		$clave  = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$res    = self::publicar_especial( mb_substr( $titulo, 0, 120 ), mb_substr( $intro, 0, 800 ), $clave );
		$motivo = (string) ( $res['motivo'] ?? 'erro' );
		self::volver( in_array( $motivo, array( 'publicada', 'sen_ofertas', 'sen_categoria' ), true ) ? $motivo : 'erro' );
	}

	/**
	 * Capability + nonce gate of both handlers.
	 *
	 * @param  string $accion Nonce action.
	 * @return void
	 */
	private static function gardia( string $accion ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non tes permiso para facer isto.', 'anpa-socios' ) );
		}
		check_admin_referer( $accion );
	}

	/**
	 * Back to ANPA → Colaboradores with a notice (PRG).
	 *
	 * @param  string $msg Notice key.
	 * @return void
	 */
	private static function volver( string $msg ): void {
		wp_safe_redirect( add_query_arg( self::QS_MSG, $msg, admin_url( 'admin.php?page=anpa-socios-colab' ) ) . '#colab-publicacion' );
		exit;
	}

	/** @return string Email of the signed-in admin (audit actor). */
	private static function actor(): string {
		$u = wp_get_current_user();
		return ( $u instanceof WP_User ) ? strtolower( (string) $u->user_email ) : '';
	}

	/**
	 * 'HH:MM' (24 h) or ''.
	 *
	 * @param  string $h Raw time.
	 * @return string
	 */
	public static function limpar_hora( string $h ): string {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $h ), $m ) ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}
}
