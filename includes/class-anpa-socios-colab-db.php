<?php
/**
 * Collaborators network (1.104.0): the module's own tables and settings.
 *
 * Kept apart from ANPA_Socios_DB on purpose: its own schema version, its own
 * tables (prefix anpa_colab_), nothing shared with the activity companies.
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema and settings of the collaborators module.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab_DB {

	const DB_VERSION     = '1.0.0';
	const VERSION_OPTION = 'anpa_colab_db_version';

	/** Module switch (off until the junta turns it on). */
	const OPTION_ACTIVO = 'anpa_colab_activo';

	/** Hours until a pending offer approves itself (0 = only by hand). */
	const OPTION_HORAS = 'anpa_colab_horas_aprobacion';

	/**
	 * @return string Shops.
	 */
	public static function comercios(): string {
		global $wpdb;
		return $wpdb->prefix . 'anpa_colab_comercios';
	}

	/**
	 * @return string Offers.
	 */
	public static function ofertas(): string {
		global $wpdb;
		return $wpdb->prefix . 'anpa_colab_ofertas';
	}

	/**
	 * @return string Shop sessions (same shape as the member sessions).
	 */
	public static function sesions(): string {
		global $wpdb;
		return $wpdb->prefix . 'anpa_colab_sesions';
	}

	/**
	 * @return string Shop login codes (never shared with members or companies).
	 */
	public static function codigos(): string {
		global $wpdb;
		return $wpdb->prefix . 'anpa_colab_codigos';
	}

	/**
	 * @return string Member card codes.
	 */
	public static function carnes(): string {
		global $wpdb;
		return $wpdb->prefix . 'anpa_colab_carnes';
	}

	/**
	 * Whether the junta has turned the module on.
	 *
	 * @return bool
	 */
	public static function activo(): bool {
		return '1' === (string) get_option( self::OPTION_ACTIVO, '0' );
	}

	/**
	 * Hours until a pending offer approves itself (0 = only by hand).
	 *
	 * @return int
	 */
	public static function horas_aprobacion(): int {
		$h = get_option( self::OPTION_HORAS, null );
		if ( null === $h || '' === $h ) {
			return ANPA_Socios_Colab::HORAS_APROBACION;
		}

		return max( 0, min( 720, (int) $h ) );
	}

	/**
	 * Creates or updates the tables when the stored version is older.
	 *
	 * @return void
	 */
	public static function instalar(): void {
		if ( self::DB_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::comercios() . " (
			id bigint(20) unsigned not null auto_increment,
			nome varchar(120) not null,
			email varchar(190) not null,
			responsable varchar(60) not null default '',
			telefono varchar(30) not null default '',
			url_web varchar(512) not null default '',
			enderezo varchar(200) not null default '',
			descricion text not null,
			estado varchar(10) not null default 'activo',
			creado_en datetime not null,
			actualizado_en datetime not null,
			unique key email (email),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$cc};" );

		dbDelta( 'CREATE TABLE ' . self::ofertas() . " (
			id bigint(20) unsigned not null auto_increment,
			comercio_id bigint(20) unsigned not null,
			titulo varchar(80) not null,
			desconto varchar(60) not null,
			descricion text not null,
			condicions text not null,
			valida_dende date null default null,
			valida_ata date null default null,
			estado varchar(12) not null default 'pendente',
			motivo varchar(300) not null default '',
			enviada_ts int(10) unsigned not null default 0,
			resolta_ts int(10) unsigned not null default 0,
			resolta_por varchar(190) not null default '',
			creado_en datetime not null,
			actualizado_en datetime not null,
			key comercio_id (comercio_id),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$cc};" );

		dbDelta( 'CREATE TABLE ' . self::sesions() . " (
			id bigint(20) unsigned not null auto_increment,
			token_digest char(64) not null,
			email varchar(190) not null,
			ua_hash char(64) not null,
			ip_hash char(64) not null,
			usage_count smallint(5) unsigned not null default 0,
			max_uses smallint(5) unsigned not null default 100,
			expires_at datetime not null,
			created_at datetime not null default CURRENT_TIMESTAMP,
			unique key token_digest (token_digest),
			key email (email),
			key expires_at (expires_at),
			PRIMARY KEY  (id)
		) {$cc};" );

		dbDelta( 'CREATE TABLE ' . self::codigos() . " (
			id bigint(20) unsigned not null auto_increment,
			email varchar(190) not null,
			codigo_hash varchar(255) not null,
			expira_ts int(10) unsigned not null,
			intentos tinyint(2) unsigned not null default 0,
			usado tinyint(1) unsigned not null default 0,
			ip varchar(45) not null default '',
			creado_ts int(10) unsigned not null,
			key email (email),
			key creado_ts (creado_ts),
			PRIMARY KEY  (id)
		) {$cc};" );

		dbDelta( 'CREATE TABLE ' . self::carnes() . " (
			id bigint(20) unsigned not null auto_increment,
			codigo_digest char(64) not null,
			familia_id bigint(20) unsigned not null,
			expira_ts int(10) unsigned not null,
			creado_ts int(10) unsigned not null,
			unique key codigo_digest (codigo_digest),
			key familia_id (familia_id),
			key expira_ts (expira_ts),
			PRIMARY KEY  (id)
		) {$cc};" );

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Removes what has expired: sessions, login codes and card codes.
	 *
	 * @return void
	 */
	public static function limpar_caducados(): void {
		global $wpdb;
		if ( self::DB_VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		$agora = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, fixed names.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::sesions() . ' WHERE expires_at < %s', current_time( 'mysql' ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::codigos() . ' WHERE creado_ts < %d', $agora - DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::carnes() . ' WHERE expira_ts < %d', $agora ) );
		// phpcs:enable
	}
}
