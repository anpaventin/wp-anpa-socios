<?php
/**
 * Collaborators network (1.104.0): data access, approvals and notices.
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shops, offers, approvals and the membership check.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab_Service {

	/** Admin page slug (Colaboradores). */
	const ADMIN_SLUG = 'anpa-socios-colab';

	/**
	 * The ONLY read the module makes of the members table: is anyone of this
	 * family a member right now? Read-only, returns nothing but yes or no.
	 *
	 * @param  int $familia_id Family id.
	 * @return bool
	 */
	public static function familia_de_alta( int $familia_id ): bool {
		global $wpdb;
		if ( $familia_id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only membership check.
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ANPA_Socios_DB::tabela_socios() . " WHERE COALESCE(NULLIF(familia_id, 0), id) = %d AND estado = 'activo' AND rol <> 'master'", $familia_id ) );

		return $n > 0;
	}

	/**
	 * Active shop by email (the session principal), or null.
	 *
	 * @param  string $email Shop email.
	 * @return array<string,mixed>|null
	 */
	public static function comercio_activo( string $email ) {
		global $wpdb;
		if ( ! ANPA_Socios_Colab_DB::activo() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, nome, email, responsable, telefono, url_web, enderezo, descricion, estado FROM ' . ANPA_Socios_Colab_DB::comercios() . ' WHERE email = %s LIMIT 1', strtolower( trim( $email ) ) ), ARRAY_A );

		return ( is_array( $row ) && 'activo' === (string) $row['estado'] ) ? $row : null;
	}

	/**
	 * A shop by id, whatever its state.
	 *
	 * @param  int $id Shop id.
	 * @return array<string,mixed>|null
	 */
	public static function comercio( int $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ANPA_Socios_Colab_DB::comercios() . ' WHERE id = %d', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every shop, by name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function comercios(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- own table, no input.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . ANPA_Socios_Colab_DB::comercios() . ' ORDER BY nome ASC', ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Creates or updates a shop (junta only).
	 *
	 * @param  array<string,string> $d  Clean fields (ANPA_Socios_Colab::limpar_comercio).
	 * @param  int                  $id Shop to update (0 = new).
	 * @return int|WP_Error Shop id.
	 */
	public static function gardar_comercio( array $d, int $id = 0 ) {
		global $wpdb;
		$t = ANPA_Socios_Colab_DB::comercios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$outro = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s AND id <> %d LIMIT 1", $d['email'], $id ) );
		if ( null !== $outro ) {
			return new WP_Error( 'anpa_colab_email_dup', __( 'Xa hai outro comercio con ese correo.', 'anpa-socios' ) );
		}
		$agora = current_time( 'mysql' );
		$datos = array(
			'nome'           => $d['nome'],
			'email'          => $d['email'],
			'responsable'    => $d['responsable'],
			'telefono'       => $d['telefono'],
			'url_web'        => $d['url_web'],
			'enderezo'       => $d['enderezo'],
			'descricion'     => $d['descricion'],
			'actualizado_en' => $agora,
		);
		if ( $id > 0 ) {
			$vello = self::comercio( $id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$ok = $wpdb->update( $t, $datos, array( 'id' => $id ) );
			if ( false !== $ok && is_array( $vello ) && strtolower( (string) $vello['email'] ) !== $d['email'] ) {
				// A new address signs in again; the old one loses its sessions and codes.
				self::pechar_sesions( (string) $vello['email'] );
			}
		} else {
			$datos['estado']    = 'activo';
			$datos['creado_en'] = $agora;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$ok = $wpdb->insert( $t, $datos );
			$id = (int) $wpdb->insert_id;
		}
		if ( false === $ok || $id <= 0 ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ) );
		}
		self::limpar_cache();

		return $id;
	}

	/**
	 * Activates or deactivates a shop. A deactivated shop cannot sign in and
	 * its offers leave the public list.
	 *
	 * @param  int    $id     Shop id.
	 * @param  string $estado 'activo' or 'inactivo'.
	 * @return bool
	 */
	public static function estado_comercio( int $id, string $estado ): bool {
		global $wpdb;
		$estado = 'activo' === $estado ? 'activo' : 'inactivo';
		$c      = self::comercio( $id );
		if ( null === $c ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$ok = false !== $wpdb->update( ANPA_Socios_Colab_DB::comercios(), array( 'estado' => $estado, 'actualizado_en' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		if ( $ok && 'inactivo' === $estado ) {
			self::pechar_sesions( (string) $c['email'] );
		}
		self::limpar_cache();

		return $ok;
	}

	/**
	 * Removes the sessions and login codes of a shop address.
	 *
	 * @param  string $email Shop email.
	 * @return void
	 */
	public static function pechar_sesions( string $email ): void {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own tables.
		$wpdb->delete( ANPA_Socios_Colab_DB::sesions(), array( 'email' => $email ), array( '%s' ) );
		$wpdb->delete( ANPA_Socios_Colab_DB::codigos(), array( 'email' => $email ), array( '%s' ) );
		// phpcs:enable
	}

	/**
	 * Offers of one shop, newest first.
	 *
	 * @param  int $comercio_id Shop id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function ofertas_de( int $comercio_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ANPA_Socios_Colab_DB::ofertas() . ' WHERE comercio_id = %d ORDER BY id DESC', $comercio_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Offers with their shop name, for the junta (optionally one state).
	 *
	 * @param  string $estado State filter ('' = all).
	 * @return array<int,array<string,mixed>>
	 */
	public static function ofertas( string $estado = '' ): array {
		global $wpdb;
		$o   = ANPA_Socios_Colab_DB::ofertas();
		$c   = ANPA_Socios_Colab_DB::comercios();
		$sql = "SELECT o.*, c.nome AS comercio_nome, c.email AS comercio_email, c.estado AS comercio_estado FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables; the state is a whitelisted value.
		if ( in_array( $estado, ANPA_Socios_Colab::ESTADOS, true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE o.estado = %s ORDER BY o.enviada_ts ASC, o.id ASC', $estado ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql . ' ORDER BY o.id DESC LIMIT 500', ARRAY_A );
		}
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Creates or edits an offer of a shop. It always goes (back) to pending:
	 * an edited offer leaves the public list until it is approved again.
	 *
	 * @param  int                  $comercio_id Shop id (from the session, never from the request).
	 * @param  array<string,string> $d           Clean fields (ANPA_Socios_Colab::limpar_oferta).
	 * @param  int                  $id          Offer to edit (0 = new).
	 * @return int|WP_Error Offer id.
	 */
	public static function gardar_oferta( int $comercio_id, array $d, int $id = 0 ) {
		global $wpdb;
		$t     = ANPA_Socios_Colab_DB::ofertas();
		$agora = current_time( 'mysql' );
		$datos = array(
			'titulo'         => $d['titulo'],
			'desconto'       => $d['desconto'],
			'descricion'     => $d['descricion'],
			'condicions'     => $d['condicions'],
			'valida_dende'   => '' !== $d['valida_dende'] ? $d['valida_dende'] : null,
			'valida_ata'     => '' !== $d['valida_ata'] ? $d['valida_ata'] : null,
			'estado'         => ANPA_Socios_Colab::PENDENTE,
			'motivo'         => '',
			'enviada_ts'     => time(),
			'resolta_ts'     => 0,
			'resolta_por'    => '',
			'actualizado_en' => $agora,
		);
		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; scoped to the shop.
			$estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$t} WHERE id = %d AND comercio_id = %d", $id, $comercio_id ) );
			if ( null === $estado ) {
				return new WP_Error( 'anpa_colab_non_atopada', __( 'Non se atopou a oferta.', 'anpa-socios' ), array( 'status' => 404 ) );
			}
			// A withdrawn offer stays withdrawn (it no longer counts for the limit).
			if ( ANPA_Socios_Colab::RETIRADA === (string) $estado ) {
				return new WP_Error( 'anpa_colab_retirada', __( 'Unha oferta retirada non se pode cambiar. Crea unha nova.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; scoped to the shop.
			$n = $wpdb->update( $t, $datos, array( 'id' => $id, 'comercio_id' => $comercio_id ) );
			if ( false === $n ) {
				return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$vivas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE comercio_id = %d AND estado <> %s", $comercio_id, ANPA_Socios_Colab::RETIRADA ) );
			if ( $vivas >= ANPA_Socios_Colab::MAX_OFERTAS ) {
				return new WP_Error( 'anpa_colab_max_ofertas', __( 'Xa tes o máximo de ofertas. Retira algunha antes de crear outra.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			$datos['comercio_id'] = $comercio_id;
			$datos['creado_en']   = $agora;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			if ( false === $wpdb->insert( $t, $datos ) ) {
				return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		self::limpar_cache();
		self::avisar_xunta_oferta( $comercio_id, $d['titulo'] );

		return $id;
	}

	/**
	 * A shop withdraws one of its offers.
	 *
	 * @param  int $comercio_id Shop id (from the session).
	 * @param  int $id          Offer id.
	 * @return bool
	 */
	public static function retirar_oferta( int $comercio_id, int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; scoped to the shop.
		$n = $wpdb->update(
			ANPA_Socios_Colab_DB::ofertas(),
			array( 'estado' => ANPA_Socios_Colab::RETIRADA, 'resolta_ts' => time(), 'resolta_por' => 'comercio', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'comercio_id' => $comercio_id )
		);
		self::limpar_cache();

		return false !== $n && $n > 0;
	}

	/**
	 * Approves or rejects a PENDING offer, or withdraws any offer (junta).
	 * The state changes in one statement, so the cron and an admin cannot both
	 * resolve the same offer.
	 *
	 * @param  int    $id     Offer id.
	 * @param  string $estado ANPA_Socios_Colab::APROBADA, REXEITADA or RETIRADA.
	 * @param  string $por    Who resolved it: an admin email or 'auto'.
	 * @param  string $motivo Reason shown to the shop (rejections).
	 * @return bool True when this call changed the offer.
	 */
	public static function resolver( int $id, string $estado, string $por, string $motivo = '' ): bool {
		global $wpdb;
		if ( ! in_array( $estado, array( ANPA_Socios_Colab::APROBADA, ANPA_Socios_Colab::REXEITADA, ANPA_Socios_Colab::RETIRADA ), true ) ) {
			return false;
		}
		$t      = ANPA_Socios_Colab_DB::ofertas();
		$motivo = ANPA_Socios_Colab::texto( $motivo, 300 );
		$agora  = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		if ( ANPA_Socios_Colab::RETIRADA === $estado ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET estado = %s, motivo = %s, resolta_ts = %d, resolta_por = %s, actualizado_en = %s WHERE id = %d AND estado <> %s", $estado, $motivo, time(), $por, $agora, $id, $estado ) );
		} else {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET estado = %s, motivo = %s, resolta_ts = %d, resolta_por = %s, actualizado_en = %s WHERE id = %d AND estado = %s", $estado, $motivo, time(), $por, $agora, $id, ANPA_Socios_Colab::PENDENTE ) );
		}
		// phpcs:enable
		if ( 1 !== (int) $n ) {
			return false;
		}
		self::limpar_cache();
		ANPA_Socios_Admin_Shared::write_audit_actor( 'auto' === $por ? 'sistema' : strtolower( $por ), 'auto' === $por ? 'sistema' : 'admin', 'oferta_colab', (string) $id, 'oferta_' . $estado );
		self::avisar_comercio_resolucion( $id, $estado, $motivo, 'auto' === $por );

		return true;
	}

	/**
	 * Hourly: approves the pending offers that waited the configured hours.
	 *
	 * @return int Offers approved.
	 */
	public static function aprobar_vencidas(): int {
		global $wpdb;
		ANPA_Socios_Colab_DB::limpar_caducados();
		$horas = ANPA_Socios_Colab_DB::horas_aprobacion();
		if ( ! ANPA_Socios_Colab_DB::activo() || $horas <= 0 ) {
			return 0;
		}
		$agora = time();
		$o     = ANPA_Socios_Colab_DB::ofertas();
		$c     = ANPA_Socios_Colab_DB::comercios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.enviada_ts FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id WHERE o.estado = %s AND c.estado = 'activo' AND o.enviada_ts > 0 AND o.enviada_ts <= %d ORDER BY o.id ASC LIMIT 50", ANPA_Socios_Colab::PENDENTE, $agora - $horas * 3600 ), ARRAY_A );
		$n    = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ANPA_Socios_Colab::aprobacion_vencida( (int) $r['enviada_ts'], $horas, $agora ) && self::resolver( (int) $r['id'], ANPA_Socios_Colab::APROBADA, 'auto' ) ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Active shops with their approved offers in force today, for the public list.
	 *
	 * @return array<int,array{comercio:array<string,mixed>,ofertas:array<int,array<string,mixed>>}>
	 */
	public static function publicas(): array {
		global $wpdb;
		$o = ANPA_Socios_Colab_DB::ofertas();
		$c = ANPA_Socios_Colab_DB::comercios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.titulo, o.desconto, o.descricion, o.condicions, o.valida_dende, o.valida_ata, c.id AS comercio_id, c.nome, c.url_web, c.enderezo, c.telefono, c.descricion AS comercio_descricion FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id WHERE o.estado = %s AND c.estado = 'activo' ORDER BY c.nome ASC, o.id DESC", ANPA_Socios_Colab::APROBADA ), ARRAY_A );
		$hoxe = current_time( 'Y-m-d' );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ! ANPA_Socios_Colab::vixente( (string) $r['valida_dende'], (string) $r['valida_ata'], $hoxe ) ) {
				continue;
			}
			$cid = (int) $r['comercio_id'];
			if ( ! isset( $out[ $cid ] ) ) {
				$out[ $cid ] = array(
					'comercio' => array(
						'nome'       => (string) $r['nome'],
						'url_web'    => (string) $r['url_web'],
						'enderezo'   => (string) $r['enderezo'],
						'telefono'   => (string) $r['telefono'],
						'descricion' => (string) $r['comercio_descricion'],
					),
					'ofertas'  => array(),
				);
			}
			$out[ $cid ]['ofertas'][] = $r;
		}

		return array_values( $out );
	}

	/**
	 * URL of the junta's Colaboradores page.
	 *
	 * @return string
	 */
	public static function admin_url(): string {
		return admin_url( 'admin.php?page=' . self::ADMIN_SLUG );
	}

	/**
	 * The public list is on a cached page: drop the page cache when it changes.
	 *
	 * @return void
	 */
	public static function limpar_cache(): void {
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
			Cache_Enabler::clear_complete_cache();
		}
	}

	/**
	 * Tells the junta a shop sent (or edited) an offer. No shop data beyond its name.
	 *
	 * @param  int    $comercio_id Shop id.
	 * @param  string $titulo      Offer title.
	 * @return void
	 */
	private static function avisar_xunta_oferta( int $comercio_id, string $titulo ): void {
		$c     = self::comercio( $comercio_id );
		$nome  = is_array( $c ) ? (string) $c['nome'] : '';
		$horas = ANPA_Socios_Colab_DB::horas_aprobacion();
		$prazo = $horas > 0
			/* translators: %d: hours */
			? sprintf( __( 'Se ninguén a revisa, publicarase soa en %d horas.', 'anpa-socios' ), $horas )
			: __( 'Non se publicará ata que alguén da xunta a aprobe.', 'anpa-socios' );
		$html  = '<h2>' . esc_html__( 'Nova oferta dun comercio colaborador', 'anpa-socios' ) . '</h2>'
			/* translators: 1: shop name, 2: offer title */
			. '<p>' . esc_html( sprintf( __( '%1$s enviou a oferta «%2$s».', 'anpa-socios' ), $nome, $titulo ) ) . ' ' . esc_html( $prazo ) . '</p>'
			. '<p><a href="' . esc_url( self::admin_url() ) . '">' . esc_html__( 'Revisar as ofertas pendentes', 'anpa-socios' ) . '</a></p>';
		/* translators: %s: association name */
		ANPA_Socios_Email::enviar_simple( ANPA_Socios_Config::master_email(), sprintf( __( 'Nova oferta de colaborador pendente — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), $html );
	}

	/**
	 * Tells the shop what happened to its offer.
	 *
	 * @param  int    $id     Offer id.
	 * @param  string $estado New state.
	 * @param  string $motivo Reason (rejections).
	 * @param  bool   $auto   Approved by the 24 h rule.
	 * @return void
	 */
	private static function avisar_comercio_resolucion( int $id, string $estado, string $motivo, bool $auto ): void {
		global $wpdb;
		$o = ANPA_Socios_Colab_DB::ofertas();
		$c = ANPA_Socios_Colab_DB::comercios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT o.titulo, o.resolta_por, c.email, c.estado FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id WHERE o.id = %d", $id ), ARRAY_A );
		if ( ! is_array( $r ) || 'activo' !== (string) $r['estado'] ) {
			return;
		}
		if ( ANPA_Socios_Colab::APROBADA === $estado ) {
			$titulo = __( 'A túa oferta xa está publicada', 'anpa-socios' );
			/* translators: %s: offer title */
			$texto = sprintf( __( 'A oferta «%s» está publicada na web da ANPA e xa a poden ver as familias socias.', 'anpa-socios' ), (string) $r['titulo'] );
		} elseif ( ANPA_Socios_Colab::REXEITADA === $estado ) {
			$titulo = __( 'A túa oferta non se publicou', 'anpa-socios' );
			/* translators: %s: offer title */
			$texto = sprintf( __( 'A xunta directiva revisou a oferta «%s» e non a aprobou. Podes corrixila e enviala de novo desde o teu panel.', 'anpa-socios' ), (string) $r['titulo'] );
		} else {
			$titulo = __( 'A túa oferta foi retirada', 'anpa-socios' );
			/* translators: %s: offer title */
			$texto = sprintf( __( 'A xunta directiva retirou a oferta «%s» da web.', 'anpa-socios' ), (string) $r['titulo'] );
		}
		$html = '<h2>' . esc_html( $titulo ) . '</h2><p>' . esc_html( $texto ) . '</p>';
		if ( '' !== $motivo ) {
			/* translators: %s: reason */
			$html .= '<p>' . esc_html( sprintf( __( 'Motivo: %s', 'anpa-socios' ), $motivo ) ) . '</p>';
		}
		/* translators: %s: contact email */
		$html .= '<p>' . esc_html( sprintf( __( 'Para calquera dúbida, escribe a %s.', 'anpa-socios' ), ANPA_Socios_Config::contact_email() ) ) . '</p>';
		unset( $auto );
		ANPA_Socios_Email::enviar_simple( (string) $r['email'], $titulo . ' — ' . ANPA_Socios_Config::association_name(), $html );
	}
}
