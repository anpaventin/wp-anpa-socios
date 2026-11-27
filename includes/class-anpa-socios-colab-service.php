<?php
/**
 * Collaborators network (1.104.0): data access, approvals and notices.
 *
 * 1.105.0 (v2): shop data as proposals, offer images and logos, the
 * permanent family card, the shop key and the point-of-sale operations.
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shops, offers, approvals, cards and uses.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab_Service {

	/** Admin page slug (Colaboradores). */
	const ADMIN_SLUG = 'anpa-socios-colab';

	/** Images (logo or offer) a shop may upload per hour. */
	const SUBIDAS_HORA = 10;

	/** Largest image a shop may upload, in bytes. */
	const MAX_BYTES_IMAXE = 2097152;

	/** Longest side of a stored image, in pixels. */
	const LADO_IMAXE = 800;

	/** Attachment meta: id of the shop that uploaded it (only those are ever deleted). */
	const META_COMERCIO = '_anpa_colab_comercio';

	/** Attachment meta: the image was approved once (it may be in a post: never deleted). */
	const META_APROBADA = '_anpa_colab_aprobada';

	/**
	 * Read of the members table #1: is anyone of this family a member right
	 * now? Read-only, returns nothing but yes or no.
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
	 * Read of the members table #2 (1.105.0 v2): names and surnames of the
	 * family's adults, only for the family's own card. Read-only.
	 *
	 * @param  int $familia_id Family id.
	 * @return array<int,string>
	 */
	public static function nomes_familia( int $familia_id ): array {
		global $wpdb;
		if ( $familia_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only, family-scoped.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT nome, apelidos FROM ' . ANPA_Socios_DB::tabela_socios() . " WHERE COALESCE(NULLIF(familia_id, 0), id) = %d AND estado = 'activo' AND rol <> 'master' ORDER BY ( rol_familia = 'secundario' ) ASC, id ASC LIMIT 2", $familia_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$n = trim( ANPA_Socios_Colab::texto( (string) ( $r['nome'] ?? '' ) . ' ' . (string) ( $r['apelidos'] ?? '' ), 160 ) );
			if ( '' !== $n ) {
				$out[] = $n;
			}
		}

		return $out;
	}

	// ── Shops ─────────────────────────────────────────────────────────

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
	 * Creates a shop or changes its name / login email (junta only). Every
	 * other datum is the shop's own, sent as a proposal.
	 *
	 * @param  array<string,string> $d  Clean fields (ANPA_Socios_Colab::limpar_comercio); only nome and email are used.
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
			if ( false !== $ok && is_array( $vello ) && (string) $vello['nome'] !== $d['nome'] ) {
				self::limpar_cache(); // The name is on the public list.
			}
		} else {
			$datos = array_merge(
				$datos,
				array(
					'responsable' => '',
					'telefono'    => '',
					'url_web'     => '',
					'enderezo'    => '',
					'descricion'  => '',
					'estado'      => 'activo',
					'qr_version'  => 1,
					'creado_en'   => $agora,
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$ok = $wpdb->insert( $t, $datos );
			$id = (int) $wpdb->insert_id;
			if ( false !== $ok && $id > 0 ) {
				self::asegurar_qr( array( 'id' => $id, 'qr_version' => 1, 'qr_digest' => '' ) );
			}
		}
		if ( false === $ok || $id <= 0 ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ) );
		}

		return $id;
	}

	/**
	 * Activates or deactivates a shop. A deactivated shop cannot sign in, its
	 * key stops working at the till and its offers leave the public list.
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

	// ── Shop data proposals ───────────────────────────────────────────

	/**
	 * The shop's proposal (pending or not approved), or null.
	 *
	 * @param  array<string,mixed> $c Shop row.
	 * @return array{campos:array<string,string>,logo_id:int,enviada_ts:int}|null
	 */
	public static function proposta( array $c ) {
		$j = json_decode( (string) ( $c['proposta'] ?? '' ), true );
		if ( ! is_array( $j ) ) {
			return null;
		}
		$campos = array();
		foreach ( ANPA_Socios_Colab::campos_perfil() as $k ) {
			$campos[ $k ] = isset( $j['campos'][ $k ] ) && is_scalar( $j['campos'][ $k ] ) ? (string) $j['campos'][ $k ] : (string) ( $c[ $k ] ?? '' );
		}

		return array(
			'campos'     => $campos,
			'logo_id'    => max( 0, (int) ( $j['logo_id'] ?? 0 ) ),
			'enviada_ts' => max( 0, (int) ( $j['enviada_ts'] ?? 0 ) ),
		);
	}

	/**
	 * Shops with a proposal waiting for the junta, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function propostas_pendentes(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- own table, no input.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . ANPA_Socios_Colab_DB::comercios() . ' WHERE proposta_ts > 0 ORDER BY proposta_ts ASC, id ASC', ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The shop proposes its data. Until the junta (or the hours rule) approves
	 * it, the public list keeps the last approved version.
	 *
	 * @param  int                  $comercio_id Shop id (from the session).
	 * @param  array<string,string> $campos      Clean fields (ANPA_Socios_Colab::limpar_perfil).
	 * @return true|WP_Error
	 */
	public static function propor_datos( int $comercio_id, array $campos ) {
		$c = self::comercio( $comercio_id );
		if ( null === $c ) {
			return new WP_Error( 'anpa_colab_non_atopado', __( 'Non se atopou o comercio.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( ! self::contar_gardado( $comercio_id ) ) {
			return self::erro_limite();
		}
		$p           = self::proposta( $c ) ?? array( 'logo_id' => (int) $c['logo_id'] );
		$p['campos'] = array();
		foreach ( ANPA_Socios_Colab::campos_perfil() as $k ) {
			$p['campos'][ $k ] = (string) ( $campos[ $k ] ?? '' );
		}

		return self::gardar_proposta( $c, $p );
	}

	/**
	 * A new logo for the shop's proposal (the attachment comes from recibir_imaxe()).
	 *
	 * @param  int $comercio_id   Shop id (from the session).
	 * @param  int $attachment_id Re-encoded image.
	 * @return true|WP_Error
	 */
	public static function propor_logo( int $comercio_id, int $attachment_id ) {
		$c = self::comercio( $comercio_id );
		if ( null === $c ) {
			return new WP_Error( 'anpa_colab_non_atopado', __( 'Non se atopou o comercio.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$p = self::proposta( $c );
		if ( null === $p ) {
			$p = array( 'campos' => array(), 'logo_id' => (int) $c['logo_id'] );
			foreach ( ANPA_Socios_Colab::campos_perfil() as $k ) {
				$p['campos'][ $k ] = (string) ( $c[ $k ] ?? '' );
			}
		}
		$vello        = (int) $p['logo_id'];
		$p['logo_id'] = $attachment_id;
		$r            = self::gardar_proposta( $c, $p );
		if ( true === $r && $vello > 0 && $vello !== $attachment_id && $vello !== (int) $c['logo_id'] ) {
			self::borrar_imaxe_non_aprobada( $vello );
		}

		return $r;
	}

	/**
	 * The shop withdraws its proposal (pending or not approved).
	 *
	 * @param  int $comercio_id Shop id (from the session).
	 * @return bool
	 */
	public static function retirar_proposta( int $comercio_id ): bool {
		global $wpdb;
		$c = self::comercio( $comercio_id );
		$p = null === $c ? null : self::proposta( $c );
		if ( null === $p ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . ANPA_Socios_Colab_DB::comercios() . " SET proposta = NULL, proposta_ts = 0, proposta_motivo = '', actualizado_en = %s WHERE id = %d", current_time( 'mysql' ), $comercio_id ) );
		if ( $p['logo_id'] > 0 && $p['logo_id'] !== (int) $c['logo_id'] ) {
			self::borrar_imaxe_non_aprobada( $p['logo_id'] );
		}

		return false !== $n;
	}

	/**
	 * Approves or rejects the shop's PENDING proposal (junta or the hours rule).
	 * One conditional statement on the version read, so the cron and an admin
	 * cannot both resolve it and an admin never resolves a version they did not see.
	 *
	 * @param  int    $id      Shop id.
	 * @param  bool   $aprobar Approve (true) or not (false).
	 * @param  string $por     An admin email or 'auto'.
	 * @param  string $motivo  Reason shown to the shop (rejections).
	 * @param  int    $vista   `proposta_ts` of the version the junta saw (0 = any).
	 * @return bool True when this call changed the shop.
	 */
	public static function resolver_proposta( int $id, bool $aprobar, string $por, string $motivo = '', int $vista = 0 ): bool {
		global $wpdb;
		$c = self::comercio( $id );
		if ( null === $c || (int) $c['proposta_ts'] <= 0 ) {
			return false;
		}
		$version = (int) $c['proposta_ts'];
		if ( $vista > 0 && $vista !== $version ) {
			return false;
		}
		$p = self::proposta( $c );
		if ( null === $p ) {
			return false;
		}
		$motivo = ANPA_Socios_Colab::texto( $motivo, 300 );
		if ( $aprobar ) {
			$datos = array_merge(
				$p['campos'],
				array(
					'logo_id'         => $p['logo_id'],
					'cubertos'        => 1,
					'proposta'        => null,
					'proposta_ts'     => 0,
					'proposta_manual' => 0,
					'proposta_motivo' => '',
					'actualizado_en'  => current_time( 'mysql' ),
				)
			);
		} else {
			$datos = array(
				'proposta_ts'     => 0,
				'proposta_manual' => 1,
				'proposta_motivo' => $motivo,
				'actualizado_en'  => current_time( 'mysql' ),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; only from the version read.
		$n = $wpdb->update( ANPA_Socios_Colab_DB::comercios(), $datos, array( 'id' => $id, 'proposta_ts' => $version ) );
		if ( 1 !== (int) $n ) {
			return false;
		}
		if ( $aprobar ) {
			self::marcar_imaxe_aprobada( $p['logo_id'] );
			self::limpar_cache();
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( 'auto' === $por ? 'sistema' : strtolower( $por ), 'auto' === $por ? 'sistema' : 'admin', 'comercio_colab', (string) $id, $aprobar ? 'proposta_aprobada' : 'proposta_rexeitada' );
		self::avisar_comercio_proposta( $c, $aprobar, $motivo );

		return true;
	}

	// ── Shop key (QR at the till) ─────────────────────────────────────

	/**
	 * The shop's key (what its QR carries after «#k=»).
	 *
	 * @param  array<string,mixed> $c Shop row (id, qr_version).
	 * @return string
	 */
	public static function clave_comercio( array $c ): string {
		return ANPA_Socios_Colab::clave_comercio( (int) $c['id'], max( 1, (int) ( $c['qr_version'] ?? 1 ) ), self::segredo( 'comercio' ) );
	}

	/**
	 * Makes sure the stored lookup digest matches the shop's current key
	 * (new shops, shops from 1.104.0, or after the site salts changed).
	 *
	 * @param  array<string,mixed> $c Shop row (id, qr_version, qr_digest).
	 * @return void
	 */
	public static function asegurar_qr( array $c ): void {
		global $wpdb;
		$d = ANPA_Socios_Colab::digest( self::clave_comercio( $c ), self::segredo( 'caixa' ) );
		if ( hash_equals( $d, (string) ( $c['qr_digest'] ?? '' ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->update( ANPA_Socios_Colab_DB::comercios(), array( 'qr_digest' => $d ), array( 'id' => (int) $c['id'] ) );
	}

	/**
	 * A new shop key: the old QR stops working.
	 *
	 * @param  int $id Shop id (from the session).
	 * @return bool
	 */
	public static function renovar_qr( int $id ): bool {
		global $wpdb;
		$c = self::comercio( $id );
		if ( null === $c ) {
			return false;
		}
		$vella = max( 1, (int) $c['qr_version'] );
		$nova  = $vella >= 65535 ? 1 : $vella + 1;
		$d     = ANPA_Socios_Colab::digest( self::clave_comercio( array( 'id' => $id, 'qr_version' => $nova ) ), self::segredo( 'caixa' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; only from the version read.
		$n = $wpdb->update( ANPA_Socios_Colab_DB::comercios(), array( 'qr_version' => $nova, 'qr_digest' => $d ), array( 'id' => $id, 'qr_version' => $vella ) );

		return 1 === (int) $n;
	}

	/**
	 * The ACTIVE shop a till key belongs to, or null. Found through the keyed
	 * digest of the key (never the key itself), then compared in constant time.
	 *
	 * @param  string $clave Key, stored form ('' = none).
	 * @return array{id:int,nome:string}|null
	 */
	public static function comercio_por_clave( string $clave ) {
		global $wpdb;
		$d = ANPA_Socios_Colab::digest( '' !== $clave ? $clave : 'x', self::segredo( 'caixa' ) );
		if ( '' === $clave || ! ANPA_Socios_Colab_DB::activo() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, nome, estado, qr_digest FROM ' . ANPA_Socios_Colab_DB::comercios() . ' WHERE qr_digest = %s LIMIT 1', $d ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['qr_digest'], $d ) || 'activo' !== (string) $row['estado'] ) {
			return null;
		}

		return array( 'id' => (int) $row['id'], 'nome' => (string) $row['nome'] );
	}

	// ── Family card ───────────────────────────────────────────────────

	/**
	 * The family's card (created on first call): its current code and settings.
	 *
	 * @param  int $familia_id Family id.
	 * @return array{codigo:string,version:int,recibir_correo:bool}|null
	 */
	public static function carne( int $familia_id ) {
		global $wpdb;
		if ( $familia_id <= 0 ) {
			return null;
		}
		$t   = ANPA_Socios_Colab_DB::familias();
		$row = self::fila_carne( $familia_id );
		if ( null === $row ) {
			$codigo = ANPA_Socios_Colab::codigo_familia( $familia_id, 1, self::segredo( 'familia' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; two first calls at once create one row.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} ( familia_id, qr_version, codigo_digest, sen_correo, creado_ts, actualizado_ts ) VALUES ( %d, 1, %s, 0, %d, %d )", $familia_id, ANPA_Socios_Colab::digest( $codigo, self::segredo( 'carne' ) ), time(), time() ) );
			$row = self::fila_carne( $familia_id );
			if ( null === $row ) {
				return null;
			}
		}
		$version = max( 1, (int) $row['qr_version'] );
		$codigo  = ANPA_Socios_Colab::codigo_familia( $familia_id, $version, self::segredo( 'familia' ) );
		$d       = ANPA_Socios_Colab::digest( $codigo, self::segredo( 'carne' ) );
		if ( ! hash_equals( $d, (string) $row['codigo_digest'] ) ) {
			// The site salts changed: the code is derived again, keep finding it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->update( $t, array( 'codigo_digest' => $d, 'actualizado_ts' => time() ), array( 'familia_id' => $familia_id ) );
		}

		return array(
			'codigo'         => $codigo,
			'version'        => $version,
			'recibir_correo' => 1 !== (int) $row['sen_correo'],
		);
	}

	/**
	 * A new card for the family: the old QR stops working.
	 *
	 * @param  int $familia_id Family id.
	 * @return bool
	 */
	public static function renovar_carne( int $familia_id ): bool {
		global $wpdb;
		if ( null === self::carne( $familia_id ) ) {
			return false;
		}
		$row   = self::fila_carne( $familia_id );
		$vella = max( 1, (int) ( $row['qr_version'] ?? 1 ) );
		$nova  = $vella >= 65535 ? 1 : $vella + 1;
		$d     = ANPA_Socios_Colab::digest( ANPA_Socios_Colab::codigo_familia( $familia_id, $nova, self::segredo( 'familia' ) ), self::segredo( 'carne' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; only from the version read.
		$n = $wpdb->update( ANPA_Socios_Colab_DB::familias(), array( 'qr_version' => $nova, 'codigo_digest' => $d, 'actualizado_ts' => time() ), array( 'familia_id' => $familia_id, 'qr_version' => $vella ) );

		return 1 === (int) $n;
	}

	/**
	 * Family id a card code belongs to (0 = unknown code). Says nothing about
	 * whether the family is a member: see familia_de_alta().
	 *
	 * @param  string $codigo Code, stored form ('' = none).
	 * @return int
	 */
	public static function familia_por_codigo( string $codigo ): int {
		global $wpdb;
		$d = ANPA_Socios_Colab::digest( '' !== $codigo ? $codigo : 'x', self::segredo( 'carne' ) );
		if ( '' === $codigo ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT familia_id, codigo_digest FROM ' . ANPA_Socios_Colab_DB::familias() . ' WHERE codigo_digest = %s LIMIT 1', $d ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['codigo_digest'], $d ) ) {
			return 0;
		}

		return (int) $row['familia_id'];
	}

	/**
	 * The family receives (or not) the weekly offers email.
	 *
	 * @param  int  $familia_id Family id.
	 * @param  bool $recibir    True = receive it.
	 * @return bool
	 */
	public static function recibir_correo( int $familia_id, bool $recibir ): bool {
		global $wpdb;
		if ( null === self::carne( $familia_id ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return false !== $wpdb->update( ANPA_Socios_Colab_DB::familias(), array( 'sen_correo' => $recibir ? 0 : 1, 'actualizado_ts' => time() ), array( 'familia_id' => $familia_id ) );
	}

	/**
	 * Families that asked not to receive the weekly offers email.
	 *
	 * @return array<int,int> Family ids.
	 */
	public static function familias_sen_correo(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- own table, no input.
		$ids = $wpdb->get_col( 'SELECT familia_id FROM ' . ANPA_Socios_Colab_DB::familias() . ' WHERE sen_correo = 1' );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	// ── Offers ────────────────────────────────────────────────────────

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
		$sql = "SELECT o.*, c.nome AS comercio_nome, c.email AS comercio_email, c.estado AS comercio_estado, c.logo_id AS comercio_logo_id FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id";
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
	 * an edited offer leaves the public list until it is approved again and
	 * goes out in a batch again.
	 *
	 * 1.105.0: an offer the junta did not approve never publishes itself when
	 * it is sent again, nor do the new offers of a shop the junta turned down
	 * in the last days: those wait for a person (`so_manual`). A shop may save
	 * a limited number of times per hour.
	 *
	 * @param  int                      $comercio_id Shop id (from the session, never from the request).
	 * @param  array<string,string|int> $d           Clean fields (ANPA_Socios_Colab::limpar_oferta).
	 * @param  int                      $id          Offer to edit (0 = new).
	 * @return int|WP_Error Offer id.
	 */
	public static function gardar_oferta( int $comercio_id, array $d, int $id = 0 ) {
		global $wpdb;
		$t = ANPA_Socios_Colab_DB::ofertas();
		if ( ! self::contar_gardado( $comercio_id ) ) {
			return self::erro_limite();
		}
		$datos = array(
			'titulo'       => (string) $d['titulo'],
			'desconto'     => (string) $d['desconto'],
			'descricion'   => (string) $d['descricion'],
			'condicions'   => (string) $d['condicions'],
			'valida_dende' => '' !== $d['valida_dende'] ? $d['valida_dende'] : null,
			'valida_ata'   => '' !== $d['valida_ata'] ? $d['valida_ata'] : null,
			'acumulable'   => (int) ( $d['acumulable'] ?? 0 ) === 1 ? 1 : 0,
			'usos_familia' => max( 0, min( ANPA_Socios_Colab::MAX_USOS_FAMILIA, (int) ( $d['usos_familia'] ?? 1 ) ) ),
		);
		if ( $id > 0 ) {
			$fila = self::reabrir_oferta( $comercio_id, $id, $datos );
			if ( is_wp_error( $fila ) ) {
				return $fila;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$vivas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE comercio_id = %d AND estado <> %s", $comercio_id, ANPA_Socios_Colab::RETIRADA ) );
			if ( $vivas >= ANPA_Socios_Colab::MAX_OFERTAS ) {
				return new WP_Error( 'anpa_colab_max_ofertas', __( 'Xa tes o máximo de ofertas. Retira algunha antes de crear outra.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$negadas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE comercio_id = %d AND estado IN ( %s, %s ) AND resolta_por NOT IN ( '', 'comercio', 'auto' ) AND resolta_ts >= %d", $comercio_id, ANPA_Socios_Colab::REXEITADA, ANPA_Socios_Colab::RETIRADA, time() - ANPA_Socios_Colab::DIAS_SO_MANUAL * DAY_IN_SECONDS ) );
			$agora   = current_time( 'mysql' );
			$datos   = array_merge(
				$datos,
				array(
					'estado'         => ANPA_Socios_Colab::PENDENTE,
					'motivo'         => '',
					'enviada_ts'     => time(),
					'resolta_ts'     => 0,
					'resolta_por'    => '',
					'publicada_ts'   => 0,
					'imaxe_id'       => 0,
					'so_manual'      => $negadas > 0 ? 1 : 0,
					'comercio_id'    => $comercio_id,
					'creado_en'      => $agora,
					'actualizado_en' => $agora,
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			if ( false === $wpdb->insert( $t, $datos ) ) {
				return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		self::avisar_xunta_oferta( $comercio_id, (string) $d['titulo'] );

		return $id;
	}

	/**
	 * Sets (or with 0 removes) the image of an offer. Like any edit, the offer
	 * goes back to pending, and it counts against the saves per hour.
	 *
	 * @param  int $comercio_id   Shop id (from the session).
	 * @param  int $id            Offer id.
	 * @param  int $attachment_id Re-encoded image (0 = remove).
	 * @return true|WP_Error
	 */
	public static function imaxe_oferta( int $comercio_id, int $id, int $attachment_id ) {
		if ( ! self::contar_gardado( $comercio_id ) ) {
			return self::erro_limite();
		}
		$fila = self::reabrir_oferta( $comercio_id, $id, array( 'imaxe_id' => max( 0, $attachment_id ) ) );
		if ( is_wp_error( $fila ) ) {
			return $fila;
		}
		$vella = (int) $fila['imaxe_id'];
		if ( $vella > 0 && $vella !== $attachment_id ) {
			self::borrar_imaxe_non_aprobada( $vella );
		}
		self::avisar_xunta_oferta( $comercio_id, (string) $fila['titulo'] );

		return true;
	}

	/**
	 * Writes changes to an offer of the shop and puts it back to pending, only
	 * from the state just read (if the junta withdrew or resolved it meanwhile,
	 * this edit does not bring it back).
	 *
	 * @param  int                 $comercio_id Shop id.
	 * @param  int                 $id          Offer id.
	 * @param  array<string,mixed> $cambios     Columns to change.
	 * @return array<string,mixed>|WP_Error The row as it was before.
	 */
	private static function reabrir_oferta( int $comercio_id, int $id, array $cambios ) {
		global $wpdb;
		$t = ANPA_Socios_Colab_DB::ofertas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; scoped to the shop.
		$fila   = $wpdb->get_row( $wpdb->prepare( "SELECT estado, so_manual, imaxe_id, publicada_ts, titulo FROM {$t} WHERE id = %d AND comercio_id = %d", $id, $comercio_id ), ARRAY_A );
		$estado = is_array( $fila ) ? (string) $fila['estado'] : null;
		if ( null === $estado ) {
			return new WP_Error( 'anpa_colab_non_atopada', __( 'Non se atopou a oferta.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		// A withdrawn offer stays withdrawn (it no longer counts for the limit).
		if ( ANPA_Socios_Colab::RETIRADA === (string) $estado ) {
			return new WP_Error( 'anpa_colab_retirada', __( 'Unha oferta retirada non se pode cambiar. Crea unha nova.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$publica            = ANPA_Socios_Colab::APROBADA === $estado && (int) $fila['publicada_ts'] > 0;
		$datos              = array_merge(
			$cambios,
			array(
				'estado'         => ANPA_Socios_Colab::PENDENTE,
				'motivo'         => '',
				'enviada_ts'     => time(),
				'resolta_ts'     => 0,
				'resolta_por'    => '',
				'publicada_ts'   => 0,
				'actualizado_en' => current_time( 'mysql' ),
			)
		);
		$datos['so_manual'] = ( ANPA_Socios_Colab::REXEITADA === $estado || 1 === (int) $fila['so_manual'] ) ? 1 : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; scoped to the shop.
		$n = $wpdb->update( $t, $datos, array( 'id' => $id, 'comercio_id' => $comercio_id, 'estado' => $estado ) );
		if ( false === $n ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 0 === (int) $n ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; scoped to the shop.
			$agora_estado = (string) $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$t} WHERE id = %d AND comercio_id = %d", $id, $comercio_id ) );
			if ( $agora_estado !== $estado && ANPA_Socios_Colab::PENDENTE !== $agora_estado ) {
				return new WP_Error( 'anpa_colab_cambiou', __( 'A oferta cambiou de estado mentres a editabas. Recarga a páxina.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
		}
		if ( $publica ) {
			self::limpar_cache(); // Only an offer that was on the public list changes it.
		}

		return $fila;
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
	 * resolve the same offer. An approved offer goes out in the next weekly
	 * batch (sacar_aprobadas()).
	 *
	 * @param  int    $id     Offer id.
	 * @param  string $estado ANPA_Socios_Colab::APROBADA, REXEITADA or RETIRADA.
	 * @param  string $por    Who resolved it: an admin email or 'auto'.
	 * @param  string $motivo Reason shown to the shop (rejections).
	 * @param  int    $vista  1.105.0: `enviada_ts` of the version the junta was
	 *                        looking at (0 = any). If the shop edited the offer
	 *                        meanwhile, nothing is approved or rejected.
	 * @return bool True when this call changed the offer.
	 */
	public static function resolver( int $id, string $estado, string $por, string $motivo = '', int $vista = 0 ): bool {
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
			$so_esta = $vista > 0 ? $wpdb->prepare( ' AND enviada_ts = %d', $vista ) : '';
			$manual  = ANPA_Socios_Colab::APROBADA === $estado ? ', so_manual = 0' : '';
			$n       = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET estado = %s, motivo = %s, resolta_ts = %d, resolta_por = %s, actualizado_en = %s{$manual} WHERE id = %d AND estado = %s", $estado, $motivo, time(), $por, $agora, $id, ANPA_Socios_Colab::PENDENTE ) . $so_esta );
		}
		// phpcs:enable
		if ( 1 !== (int) $n ) {
			return false;
		}
		if ( ANPA_Socios_Colab::RETIRADA === $estado ) {
			self::limpar_cache(); // Approving or rejecting never changes the public list: the batch does.
		}
		if ( ANPA_Socios_Colab::APROBADA === $estado ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			self::marcar_imaxe_aprobada( (int) $wpdb->get_var( $wpdb->prepare( "SELECT imaxe_id FROM {$t} WHERE id = %d", $id ) ) );
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( 'auto' === $por ? 'sistema' : strtolower( $por ), 'auto' === $por ? 'sistema' : 'admin', 'oferta_colab', (string) $id, 'oferta_' . $estado );
		self::avisar_comercio_resolucion( $id, $estado, $motivo, 'auto' === $por );

		return true;
	}

	/**
	 * Hourly: approves the pending offers and shop data proposals that waited
	 * the configured hours (never those that wait for a person).
	 *
	 * @return int Offers and proposals approved.
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.enviada_ts FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id WHERE o.estado = %s AND o.so_manual = 0 AND c.estado = 'activo' AND o.enviada_ts > 0 AND o.enviada_ts <= %d ORDER BY o.id ASC LIMIT 50", ANPA_Socios_Colab::PENDENTE, $agora - $horas * 3600 ), ARRAY_A );
		$n    = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ANPA_Socios_Colab::aprobacion_vencida( (int) $r['enviada_ts'], $horas, $agora ) && self::resolver( (int) $r['id'], ANPA_Socios_Colab::APROBADA, 'auto' ) ) {
				++$n;
			}
		}
		// Shop data: the same rule; a proposal sent again after a rejection waits for a person.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$props = $wpdb->get_results( $wpdb->prepare( "SELECT id, proposta_ts FROM {$c} WHERE proposta_ts > 0 AND proposta_manual = 0 AND estado = 'activo' AND proposta_ts <= %d ORDER BY id ASC LIMIT 50", $agora - $horas * 3600 ), ARRAY_A );
		foreach ( is_array( $props ) ? $props : array() as $r ) {
			if ( ANPA_Socios_Colab::aprobacion_vencida( (int) $r['proposta_ts'], $horas, $agora ) && self::resolver_proposta( (int) $r['id'], true, 'auto', '', (int) $r['proposta_ts'] ) ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Weekly batch (called by the publication): approved offers that are not
	 * out yet go out now.
	 *
	 * @param  int $agora Batch time (Unix).
	 * @return int Offers that went out.
	 */
	public static function sacar_aprobadas( int $agora ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ANPA_Socios_Colab_DB::ofertas() . ' SET publicada_ts = %d WHERE estado = %s AND publicada_ts = 0', max( 1, $agora ), ANPA_Socios_Colab::APROBADA ) );
		if ( $n > 0 ) {
			self::limpar_cache();
		}

		return max( 0, $n );
	}

	/**
	 * Approved offers that have not gone out in a publication yet.
	 *
	 * @return int
	 */
	public static function pendentes_de_saida(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ANPA_Socios_Colab_DB::ofertas() . ' WHERE estado = %s AND publicada_ts = 0', ANPA_Socios_Colab::APROBADA ) );
	}

	/**
	 * The junta corrects an offer, whatever its state. The result is an
	 * APPROVED offer: a withdrawn, rejected or expired one comes back this way.
	 * An offer that was already out stays out (it is in force again at once
	 * if its dates allow); one that never went out waits for the next
	 * publication. The shop is told.
	 *
	 * @param  int                      $id  Offer id.
	 * @param  array<string,string|int> $d   Clean fields (ANPA_Socios_Colab::limpar_oferta).
	 * @param  string                   $por Admin email.
	 * @return true|WP_Error
	 */
	public static function editar_oferta_xunta( int $id, array $d, string $por ) {
		global $wpdb;
		$t = ANPA_Socios_Colab_DB::ofertas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$antes = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$t} WHERE id = %d", $id ) );
		if ( null === $antes ) {
			return new WP_Error( 'anpa_colab_non_atopada', __( 'Non se atopou a oferta.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = $wpdb->update(
			$t,
			array(
				'titulo'         => (string) $d['titulo'],
				'desconto'       => (string) $d['desconto'],
				'descricion'     => (string) $d['descricion'],
				'condicions'     => (string) $d['condicions'],
				'valida_dende'   => '' !== $d['valida_dende'] ? $d['valida_dende'] : null,
				'valida_ata'     => '' !== $d['valida_ata'] ? $d['valida_ata'] : null,
				'acumulable'     => (int) ( $d['acumulable'] ?? 0 ) === 1 ? 1 : 0,
				'usos_familia'   => max( 0, min( ANPA_Socios_Colab::MAX_USOS_FAMILIA, (int) ( $d['usos_familia'] ?? 1 ) ) ),
				'estado'         => ANPA_Socios_Colab::APROBADA,
				'motivo'         => '',
				'so_manual'      => 0,
				'resolta_ts'     => time(),
				'resolta_por'    => $por,
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( false === $n ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		self::limpar_cache();
		ANPA_Socios_Admin_Shared::write_audit_actor( strtolower( $por ), 'admin', 'oferta_colab', (string) $id, ANPA_Socios_Colab::APROBADA === (string) $antes ? 'oferta_editada_xunta' : 'oferta_recuperada' );
		self::avisar_comercio_edicion( $id );

		return true;
	}

	/**
	 * Tells the shop the junta changed one of its offers.
	 *
	 * @param  int $id Offer id.
	 * @return void
	 */
	private static function avisar_comercio_edicion( int $id ): void {
		global $wpdb;
		$o = ANPA_Socios_Colab_DB::ofertas();
		$c = ANPA_Socios_Colab_DB::comercios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT o.titulo, c.email, c.estado FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id WHERE o.id = %d", $id ), ARRAY_A );
		if ( ! is_array( $r ) || 'activo' !== (string) $r['estado'] ) {
			return;
		}
		$titulo = __( 'A xunta actualizou unha oferta túa', 'anpa-socios' );
		/* translators: %s: offer title */
		$html = '<h2>' . esc_html( $titulo ) . '</h2><p>' . esc_html( sprintf( __( 'A xunta directiva revisou e actualizou a oferta «%s», que queda aprobada. Podes ver como quedou no teu panel; se algo non é correcto, cámbiaa alí ou retíraa.', 'anpa-socios' ), (string) $r['titulo'] ) ) . '</p>';
		/* translators: %s: contact email */
		$html .= '<p>' . esc_html( sprintf( __( 'Para calquera dúbida, escribe a %s.', 'anpa-socios' ), ANPA_Socios_Config::contact_email() ) ) . '</p>';
		ANPA_Socios_Email::enviar_simple( (string) $r['email'], $titulo . ' — ' . ANPA_Socios_Config::association_name(), $html );
	}

	/**
	 * Unix time of the next weekly batch. The publication class decides it
	 * when present; otherwise Thursday 16:00 in the site timezone.
	 *
	 * @param  int $agora Reference time.
	 * @return int
	 */
	public static function seguinte_lote( int $agora ): int {
		if ( class_exists( 'ANPA_Socios_Colab_Publicacion' ) && method_exists( 'ANPA_Socios_Colab_Publicacion', 'seguinte_saida' ) ) {
			return (int) ANPA_Socios_Colab_Publicacion::seguinte_saida( $agora );
		}

		return ANPA_Socios_Colab::proximo_lote( $agora, 4, 16, 0, wp_timezone() );
	}

	/**
	 * When an approved offer goes out, in words («Sae o xoves 3 ás 16:00»).
	 *
	 * @param  int $ts Batch time.
	 * @return string
	 */
	public static function texto_lote( int $ts ): string {
		/* translators: 1: weekday, 2: day of the month, 3: time */
		return sprintf( __( 'Sae o %1$s %2$s ás %3$s', 'anpa-socios' ), wp_date( 'l', $ts ), wp_date( 'j', $ts ), wp_date( 'H:i', $ts ) );
	}

	/**
	 * Active shops with their published offers in force today, for the public
	 * list and the weekly batch.
	 *
	 * @return array<int,array{comercio:array<string,mixed>,ofertas:array<int,array<string,mixed>>}>
	 */
	public static function publicas(): array {
		global $wpdb;
		$o = ANPA_Socios_Colab_DB::ofertas();
		$c = ANPA_Socios_Colab_DB::comercios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.titulo, o.desconto, o.descricion, o.condicions, o.valida_dende, o.valida_ata, o.acumulable, o.usos_familia, o.imaxe_id, o.publicada_ts, c.id AS comercio_id, c.nome, c.url_web, c.enderezo, c.telefono, c.descricion AS comercio_descricion, c.logo_id FROM {$o} o JOIN {$c} c ON c.id = o.comercio_id WHERE o.estado = %s AND o.publicada_ts > 0 AND c.estado = 'activo' ORDER BY c.nome ASC, o.id DESC", ANPA_Socios_Colab::APROBADA ), ARRAY_A );
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
						'id'         => $cid,
						'nome'       => (string) $r['nome'],
						'url_web'    => (string) $r['url_web'],
						'enderezo'   => (string) $r['enderezo'],
						'telefono'   => (string) $r['telefono'],
						'descricion' => (string) $r['comercio_descricion'],
						'logo_id'    => (int) $r['logo_id'],
						'logo_url'   => self::imaxe_url( (int) $r['logo_id'] ),
					),
					'ofertas'  => array(),
				);
			}
			$r['acumulable']   = 1 === (int) $r['acumulable'];
			$r['usos_familia'] = (int) $r['usos_familia'];
			$r['imaxe_id']     = (int) $r['imaxe_id'];
			$r['publicada_ts'] = (int) $r['publicada_ts'];
			$r['imaxe_url']    = self::imaxe_url( (int) $r['imaxe_id'] );
			$r['logo_url']     = $out[ $cid ]['comercio']['logo_url'];
			$out[ $cid ]['ofertas'][] = $r;
		}

		return array_values( $out );
	}

	/**
	 * Every published offer in force with the family's remaining uses (member area).
	 *
	 * @param  int $familia_id Family id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function ofertas_familia( int $familia_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, family-scoped counts.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT oferta_id, COUNT(*) AS n FROM ' . ANPA_Socios_Colab_DB::usos() . ' WHERE familia_id = %d AND desfeito_ts = 0 GROUP BY oferta_id', $familia_id ), ARRAY_A );
		$usados = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$usados[ (int) $r['oferta_id'] ] = (int) $r['n'];
		}
		$out = array();
		foreach ( self::publicas() as $g ) {
			foreach ( $g['ofertas'] as $o ) {
				$u     = $usados[ (int) $o['id'] ] ?? 0;
				$resta = ANPA_Socios_Colab::restan( (int) $o['usos_familia'], $u );
				$out[] = array(
					'id'           => (int) $o['id'],
					'titulo'       => (string) $o['titulo'],
					'desconto'     => (string) $o['desconto'],
					'descricion'   => (string) $o['descricion'],
					'condicions'   => (string) $o['condicions'],
					'valida_ata'   => (string) $o['valida_ata'],
					'acumulable'   => (bool) $o['acumulable'],
					'usos_familia' => (int) $o['usos_familia'],
					'usados'       => $u,
					'restan'       => $resta,
					'disponible'   => null === $resta || $resta > 0,
					'imaxe_url'    => '' !== (string) $o['imaxe_url'] ? (string) $o['imaxe_url'] : (string) $o['logo_url'],
					'comercio'     => (string) $g['comercio']['nome'],
					'enderezo'     => (string) $g['comercio']['enderezo'],
				);
			}
		}

		return $out;
	}

	// ── Point of sale (caixa) ─────────────────────────────────────────

	/**
	 * This shop's published offers in force today with the family's uses.
	 * Nothing about the family but how many uses it has left.
	 *
	 * @param  int $comercio_id Shop id (from the till key).
	 * @param  int $familia_id  Family id (from the card code, already checked).
	 * @return array<int,array<string,mixed>>
	 */
	public static function ofertas_caixa( int $comercio_id, int $familia_id ): array {
		global $wpdb;
		$o = ANPA_Socios_Colab_DB::ofertas();
		$u = ANPA_Socios_Colab_DB::usos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.titulo, o.desconto, o.descricion, o.condicions, o.valida_dende, o.valida_ata, o.acumulable, o.usos_familia, ( SELECT COUNT(*) FROM {$u} x WHERE x.oferta_id = o.id AND x.familia_id = %d AND x.desfeito_ts = 0 ) AS usados FROM {$o} o WHERE o.comercio_id = %d AND o.estado = %s AND o.publicada_ts > 0 ORDER BY o.id DESC", $familia_id, $comercio_id, ANPA_Socios_Colab::APROBADA ), ARRAY_A );
		$hoxe = current_time( 'Y-m-d' );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ! ANPA_Socios_Colab::vixente( (string) $r['valida_dende'], (string) $r['valida_ata'], $hoxe ) ) {
				continue;
			}
			$resta = ANPA_Socios_Colab::restan( (int) $r['usos_familia'], (int) $r['usados'] );
			$out[] = array(
				'id'           => (int) $r['id'],
				'titulo'       => (string) $r['titulo'],
				'desconto'     => (string) $r['desconto'],
				'descricion'   => (string) $r['descricion'],
				'condicions'   => (string) $r['condicions'],
				'acumulable'   => 1 === (int) $r['acumulable'],
				'usos_familia' => (int) $r['usos_familia'],
				'usados'       => (int) $r['usados'],
				'restan'       => $resta,
				'disponible'   => null === $resta || $resta > 0,
			);
		}

		return $out;
	}

	/**
	 * Applies one use of an offer for a family. The limit check and the insert
	 * are ONE statement (plus a named lock), so two taps at once never count
	 * past the limit.
	 *
	 * @param  int $comercio_id Shop id (from the till key).
	 * @param  int $familia_id  Family id (already checked as a member).
	 * @param  int $oferta_id   Offer id.
	 * @return int|WP_Error Use id.
	 */
	public static function aplicar_uso( int $comercio_id, int $familia_id, int $oferta_id ) {
		global $wpdb;
		$o = ANPA_Socios_Colab_DB::ofertas();
		$u = ANPA_Socios_Colab_DB::usos();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$fila = $wpdb->get_row( $wpdb->prepare( "SELECT valida_dende, valida_ata FROM {$o} WHERE id = %d AND comercio_id = %d AND estado = %s AND publicada_ts > 0", $oferta_id, $comercio_id, ANPA_Socios_Colab::APROBADA ), ARRAY_A );
		if ( $familia_id <= 0 || ! is_array( $fila ) || ! ANPA_Socios_Colab::vixente( (string) $fila['valida_dende'], (string) $fila['valida_ata'], current_time( 'Y-m-d' ) ) ) {
			return new WP_Error( 'anpa_colab_oferta_non_disponible', __( 'Esa oferta non está dispoñible neste comercio.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$lock = 'anpa_colab_uso_' . $oferta_id . '_' . $familia_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) );
		$n  = $wpdb->query( $wpdb->prepare( "INSERT INTO {$u} ( oferta_id, comercio_id, familia_id, creado_ts, desfeito_ts ) SELECT o.id, o.comercio_id, %d, %d, 0 FROM {$o} o, ( SELECT COUNT(*) AS usados FROM {$u} WHERE oferta_id = %d AND familia_id = %d AND desfeito_ts = 0 ) k WHERE o.id = %d AND o.comercio_id = %d AND o.estado = %s AND o.publicada_ts > 0 AND ( o.usos_familia = 0 OR k.usados < o.usos_familia )", $familia_id, time(), $oferta_id, $familia_id, $oferta_id, $comercio_id, ANPA_Socios_Colab::APROBADA ) );
		$id = (int) $wpdb->insert_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		// phpcs:enable
		if ( 1 !== (int) $n || $id <= 0 ) {
			return new WP_Error( 'anpa_colab_sen_usos', __( 'Esta familia xa non ten usos desta oferta.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		return $id;
	}

	/**
	 * Undoes a use this same shop applied to this same family in the last
	 * minutes (the row stays, marked as undone).
	 *
	 * @param  int $comercio_id Shop id (from the till key).
	 * @param  int $familia_id  Family id (from the card code).
	 * @param  int $uso_id      Use id.
	 * @return bool
	 */
	public static function desfacer_uso( int $comercio_id, int $familia_id, int $uso_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; scoped to the shop and the family.
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . ANPA_Socios_Colab_DB::usos() . ' SET desfeito_ts = %d WHERE id = %d AND comercio_id = %d AND familia_id = %d AND desfeito_ts = 0 AND creado_ts >= %d', time(), $uso_id, $comercio_id, $familia_id, time() - ANPA_Socios_Colab::DESFACER_SEGUNDOS ) );

		return 1 === (int) $n;
	}

	/**
	 * Uses per offer that were not undone: total and in the last 30 days.
	 *
	 * @since  1.105.0
	 * @param  int $comercio_id One shop only (0 = every shop).
	 * @return array<int,array{total:int,mes:int}> Keyed by offer id.
	 */
	public static function usos( int $comercio_id = 0 ): array {
		global $wpdb;
		$t     = ANPA_Socios_Colab_DB::usos();
		$dende = time() - 30 * DAY_IN_SECONDS;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		if ( $comercio_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT oferta_id, COUNT(*) AS total, SUM(creado_ts >= %d) AS mes FROM {$t} WHERE comercio_id = %d AND desfeito_ts = 0 GROUP BY oferta_id", $dende, $comercio_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT oferta_id, COUNT(*) AS total, SUM(creado_ts >= %d) AS mes FROM {$t} WHERE desfeito_ts = 0 GROUP BY oferta_id", $dende ), ARRAY_A );
		}
		// phpcs:enable
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[ (int) $r['oferta_id'] ] = array( 'total' => (int) $r['total'], 'mes' => (int) $r['mes'] );
		}

		return $out;
	}

	// ── Images ────────────────────────────────────────────────────────

	/**
	 * Takes an uploaded logo or offer image: at most 2 MB, only JPEG / PNG /
	 * WebP (checked by name and by content), ALWAYS decoded and re-encoded (at
	 * most 800×800, metadata dropped) and only the re-encoded file is stored,
	 * as an attachment with no parent.
	 *
	 * @param  int   $comercio_id Shop id (from the session).
	 * @param  mixed $ficheiro    One entry of the request's file params.
	 * @param  string $titulo     Attachment title (the shop name).
	 * @return int|WP_Error Attachment id.
	 */
	public static function recibir_imaxe( int $comercio_id, $ficheiro, string $titulo ) {
		$non = new WP_Error( 'anpa_colab_imaxe', __( 'A imaxe ten que ser JPG, PNG ou WebP.', 'anpa-socios' ), array( 'status' => 415 ) );
		if ( ! is_array( $ficheiro ) || UPLOAD_ERR_OK !== (int) ( $ficheiro['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $ficheiro['tmp_name'] ) || ! is_uploaded_file( (string) $ficheiro['tmp_name'] ) ) {
			return new WP_Error( 'anpa_colab_imaxe', __( 'Non se recibiu a imaxe. Téntao de novo.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$chave = 'anpa_colab_subidas_' . $comercio_id;
		$feito = (int) get_transient( $chave );
		if ( $feito >= self::SUBIDAS_HORA ) {
			return new WP_Error( 'anpa_colab_limite', __( 'Demasiadas imaxes seguidas. Téntao de novo dentro dunha hora.', 'anpa-socios' ), array( 'status' => 429 ) );
		}
		set_transient( $chave, $feito + 1, HOUR_IN_SECONDS );
		$tmp = (string) $ficheiro['tmp_name'];
		$tam = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable = rejected below.
		if ( $tam <= 0 || $tam > self::MAX_BYTES_IMAXE ) {
			return new WP_Error( 'anpa_colab_imaxe_grande', __( 'A imaxe non pode pasar de 2 MB.', 'anpa-socios' ), array( 'status' => 413 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$tipos = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		$ft    = wp_check_filetype_and_ext( $tmp, sanitize_file_name( (string) ( $ficheiro['name'] ?? '' ) ), array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
		$gi    = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not an image = rejected.
		if ( empty( $ft['type'] ) || ! isset( $tipos[ (string) $ft['type'] ] ) || ! is_array( $gi ) || (string) ( $gi['mime'] ?? '' ) !== (string) $ft['type'] ) {
			return $non;
		}
		$w = (int) $gi[0];
		$h = (int) $gi[1];
		if ( $w < 1 || $h < 1 || $w * $h > 40000000 ) {
			return $non;
		}
		// GD decodes to pixels and writes a new file: nothing of the original
		// (metadata, extra chunks, trailing data) survives.
		$so_gd = static function ( $editores ) {
			return in_array( 'WP_Image_Editor_GD', (array) $editores, true ) ? array( 'WP_Image_Editor_GD' ) : $editores;
		};
		add_filter( 'wp_image_editors', $so_gd );
		$ed = wp_get_image_editor( $tmp );
		if ( is_wp_error( $ed ) ) {
			remove_filter( 'wp_image_editors', $so_gd );
			return $non;
		}
		if ( $w > self::LADO_IMAXE || $h > self::LADO_IMAXE ) {
			$rs = $ed->resize( self::LADO_IMAXE, self::LADO_IMAXE, false );
			if ( is_wp_error( $rs ) ) {
				remove_filter( 'wp_image_editors', $so_gd );
				return $non;
			}
		}
		$ed->set_quality( 82 );
		$mime = (string) $ft['type'];
		if ( 'image/webp' === $mime && ! $ed::supports_mime_type( 'image/webp' ) ) {
			$mime = 'image/jpeg';
		}
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			remove_filter( 'wp_image_editors', $so_gd );
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$nome  = wp_unique_filename( $up['path'], 'colab-' . strtolower( wp_generate_password( 12, false, false ) ) . '.' . $tipos[ $mime ] );
		$saved = $ed->save( trailingslashit( $up['path'] ) . $nome, $mime );
		remove_filter( 'wp_image_editors', $so_gd );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $saved['mime-type'],
				'post_title'     => ANPA_Socios_Colab::texto( $titulo, 120 ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			(string) $saved['path'],
			0,
			true
		);
		if ( is_wp_error( $att ) || (int) $att <= 0 ) {
			wp_delete_file( (string) $saved['path'] );
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		update_post_meta( (int) $att, self::META_COMERCIO, $comercio_id );
		wp_update_attachment_metadata( (int) $att, wp_generate_attachment_metadata( (int) $att, (string) $saved['path'] ) );

		return (int) $att;
	}

	/**
	 * URL of an attachment, or ''.
	 *
	 * @param  int    $id   Attachment id.
	 * @param  string $size Image size.
	 * @return string
	 */
	public static function imaxe_url( int $id, string $size = 'medium' ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$u = wp_get_attachment_image_url( $id, $size );

		return is_string( $u ) ? $u : '';
	}

	/**
	 * Logo on the member card: the one the junta chose, or the site logo.
	 *
	 * @return string URL or ''.
	 */
	public static function logo_carne_url(): string {
		$u = self::imaxe_url( ANPA_Socios_Colab_DB::logo_carne_id(), 'medium' );
		if ( '' === $u ) {
			$u = self::imaxe_url( (int) get_theme_mod( 'custom_logo', 0 ), 'medium' );
		}
		if ( '' === $u && function_exists( 'get_site_icon_url' ) ) {
			$u = (string) get_site_icon_url( 256 );
		}

		return $u;
	}

	/**
	 * Deletes an image a shop uploaded that was never approved (only the
	 * module's own uploads, never any other attachment).
	 *
	 * @param  int $id Attachment id.
	 * @return void
	 */
	private static function borrar_imaxe_non_aprobada( int $id ): void {
		if ( $id <= 0 || '' === (string) get_post_meta( $id, self::META_COMERCIO, true ) || '' !== (string) get_post_meta( $id, self::META_APROBADA, true ) ) {
			return;
		}
		wp_delete_attachment( $id, true );
	}

	/**
	 * Marks one of the module's images as approved (it may then be in a post).
	 *
	 * @param  int $id Attachment id.
	 * @return void
	 */
	private static function marcar_imaxe_aprobada( int $id ): void {
		if ( $id > 0 && '' !== (string) get_post_meta( $id, self::META_COMERCIO, true ) ) {
			update_post_meta( $id, self::META_APROBADA, 1 );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────

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
	 * Secret a code is derived or looked up with (never stored, never sent).
	 *
	 * @param  string $para 'familia' / 'carne' (family code / its lookup) or
	 *                      'comercio' / 'caixa' (shop key / its lookup).
	 * @return string
	 */
	private static function segredo( string $para ): string {
		return wp_salt( 'auth' ) . '|anpa_colab_' . $para;
	}

	/**
	 * @param  int $familia_id Family id.
	 * @return array<string,mixed>|null
	 */
	private static function fila_carne( int $familia_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT qr_version, codigo_digest, sen_correo FROM ' . ANPA_Socios_Colab_DB::familias() . ' WHERE familia_id = %d', $familia_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Counts one save of a shop (offers, images, data); false over the limit.
	 *
	 * @param  int $comercio_id Shop id.
	 * @return bool
	 */
	private static function contar_gardado( int $comercio_id ): bool {
		$chave = 'anpa_colab_gardados_' . $comercio_id;
		$feito = (int) get_transient( $chave );
		if ( $feito >= ANPA_Socios_Colab::GARDADOS_HORA ) {
			return false;
		}
		set_transient( $chave, $feito + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * @return WP_Error Too many saves.
	 */
	private static function erro_limite(): WP_Error {
		return new WP_Error( 'anpa_colab_limite', __( 'Demasiados cambios seguidos. Téntao de novo dentro dunha hora.', 'anpa-socios' ), array( 'status' => 429 ) );
	}

	/**
	 * Stores a proposal and tells the junta.
	 *
	 * @param  array<string,mixed> $c Shop row.
	 * @param  array<string,mixed> $p Proposal (campos, logo_id).
	 * @return true|WP_Error
	 */
	private static function gardar_proposta( array $c, array $p ) {
		global $wpdb;
		// A new version always gets a new stamp, so the junta never approves one it did not see.
		$ts               = max( time(), (int) $c['proposta_ts'] + 1 );
		$p['enviada_ts']  = $ts;
		$json             = wp_json_encode( array( 'campos' => $p['campos'], 'logo_id' => (int) $p['logo_id'], 'enviada_ts' => $ts ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = $wpdb->update(
			ANPA_Socios_Colab_DB::comercios(),
			array( 'proposta' => (string) $json, 'proposta_ts' => $ts, 'proposta_motivo' => '', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => (int) $c['id'] )
		);
		if ( false === $n ) {
			return new WP_Error( 'anpa_colab_db', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		self::avisar_xunta_proposta( (string) $c['nome'], 1 === (int) $c['proposta_manual'] );

		return true;
	}

	/**
	 * Tells the junta a shop sent (or changed) its data or logo.
	 *
	 * @param  string $nome   Shop name (approved).
	 * @param  bool   $manual The proposal waits for a person.
	 * @return void
	 */
	private static function avisar_xunta_proposta( string $nome, bool $manual ): void {
		$horas = ANPA_Socios_Colab_DB::horas_aprobacion();
		$prazo = ( $horas > 0 && ! $manual )
			/* translators: %d: hours */
			? sprintf( __( 'Se ninguén os revisa, aprobaranse sós en %d horas.', 'anpa-socios' ), $horas )
			: __( 'Non se aprobarán ata que alguén da xunta os revise.', 'anpa-socios' );
		$html  = '<h2>' . esc_html__( 'Datos dun comercio colaborador pendentes', 'anpa-socios' ) . '</h2>'
			/* translators: %s: shop name */
			. '<p>' . esc_html( sprintf( __( '%s enviou os seus datos ou o seu logo.', 'anpa-socios' ), $nome ) ) . ' ' . esc_html( $prazo ) . '</p>'
			. '<p><a href="' . esc_url( self::admin_url() ) . '">' . esc_html__( 'Revisar os datos pendentes', 'anpa-socios' ) . '</a></p>';
		/* translators: %s: association name */
		ANPA_Socios_Email::enviar_simple( ANPA_Socios_Config::master_email(), sprintf( __( 'Datos de colaborador pendentes — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), $html );
	}

	/**
	 * Tells the shop what happened to its data proposal.
	 *
	 * @param  array<string,mixed> $c       Shop row (before).
	 * @param  bool                $aprobar Approved.
	 * @param  string              $motivo  Reason (rejections).
	 * @return void
	 */
	private static function avisar_comercio_proposta( array $c, bool $aprobar, string $motivo ): void {
		if ( 'activo' !== (string) $c['estado'] ) {
			return;
		}
		$titulo = $aprobar ? __( 'Os datos do teu comercio están aprobados', 'anpa-socios' ) : __( 'Os datos do teu comercio non se aprobaron', 'anpa-socios' );
		$texto  = $aprobar
			? __( 'A xunta directiva aprobou os datos e o logo que enviaches. Xa son os que ven as familias.', 'anpa-socios' )
			: __( 'A xunta directiva revisou os datos que enviaches e non os aprobou. Podes corrixilos e envialos de novo desde o teu panel.', 'anpa-socios' );
		$html   = '<h2>' . esc_html( $titulo ) . '</h2><p>' . esc_html( $texto ) . '</p>';
		if ( '' !== $motivo ) {
			/* translators: %s: reason */
			$html .= '<p>' . esc_html( sprintf( __( 'Motivo: %s', 'anpa-socios' ), $motivo ) ) . '</p>';
		}
		/* translators: %s: contact email */
		$html .= '<p>' . esc_html( sprintf( __( 'Para calquera dúbida, escribe a %s.', 'anpa-socios' ), ANPA_Socios_Config::contact_email() ) ) . '</p>';
		ANPA_Socios_Email::enviar_simple( (string) $c['email'], $titulo . ' — ' . ANPA_Socios_Config::association_name(), $html );
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
			? sprintf( __( 'Se ninguén a revisa, aprobarase soa en %d horas e sairá no seguinte envío semanal.', 'anpa-socios' ), $horas )
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
	 * @param  bool   $auto   Approved by the hours rule.
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
			$titulo = __( 'A túa oferta está aprobada', 'anpa-socios' );
			/* translators: 1: offer title, 2: when it goes out («Sae o xoves 3 ás 16:00») */
			$texto = sprintf( __( 'A oferta «%1$s» está aprobada e sairá no seguinte envío semanal ás familias socias. %2$s.', 'anpa-socios' ), (string) $r['titulo'], self::texto_lote( self::seguinte_lote( time() ) ) );
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
