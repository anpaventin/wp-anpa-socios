<?php
/**
 * Database lifecycle helpers for the ANPA Socios plugin.
 *
 * @since  1.1.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and maintains the custom tables owned by anpa-socios.
 *
 * @since 1.1.0
 */
class ANPA_Socios_DB {

	/**
	 * Option key that stores the installed schema version.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const VERSION_OPTION = 'anpa_socios_db_version';

	/**
	 * Current schema version for anpa-socios-owned tables.
	 *
	 * 1.2.0 adds `rol` and `pendiente_alta` to wp_anpa_socios.
	 * 1.3.0 adds fillos, empresas, actividades, matriculas, audit_log.
	 * 1.4.0 adds wp_anpa_area_sessions_empresas (empresa passwordless sessions).
	 * 1.5.0 normalizes fillos curso/aula to canonical enum values.
	 * 1.6.0 adds socios nif/telefono/familia_id and fillos image_consent.
	 * 1.7.0 adds wp_anpa_domiciliacions (encrypted SEPA banking data).
	 * 1.8.0 adds socios baixa_estado + baixa_solicitada_en (member baixa workflow).
	 * 1.10.0 adds curso lifecycle table and actividad franxa horaria.
	 * 1.11.0 adds icono to actividades for dynamic offered-activity cards.
	 * 1.12.0 adds franxa to grupos so schedules come from group-level slots.
	 * 1.13.0 adds url_web to empresas for public activity cards.
	 * 1.14.0 adds wp_anpa_fillos_cursos (fillo curso/grupo per ano escolar)
	 *        and its migration seed.
	 * 1.15.0 adds extraescolar enrolment authorisations to matriculas.
	 * 1.16.0 adds min_pupilos/max_pupilos to actividades for per-activity
	 *        place capacity fields with 10/15 defaults.
	 * 1.17.0 moves course-year-specific activity settings into
	 *        anpa_actividades_cursos and adds curso_escolar to grupos.
	 * 1.18.0 adds course season lifecycle — estado + season dates on cursos.
	 * 1.19.0 adds 'pendente_aprobacion' to the wp_anpa_socios estado enum for
	 *        the optional new-socio approval workflow.
	 * 1.20.0 widens fillos_cursos.aula enum to A-H for larger schools.
	 * 1.21.0 adds fillos.familia_id (FK to socios family group) + backfill.
	 * 1.22.0 adds socios.rol_familia enum('principal','secundario') + backfill.
	 * 1.23.0 allows socios.email NULL for 2nd parent contact-without-login.
	 * 1.24.0 renames actividades.idade_min/idade_max → curso_min/curso_max.
	 * 1.25.0 adds baixa_en datetime NULL to matriculas (baixa date tracking).
	 * 1.26.0 repairs legacy duplicate active courses, keeping the newest one.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DB_VERSION = '1.26.0';

	/**
	 * Cron hook used to remove expired member-area sessions.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CLEANUP_HOOK = 'anpa_area_sessions_cleanup';

	/**
	 * Creates or upgrades anpa-socios-owned tables.
	 *
	 * This method is intended to be called from register_activation_hook().
	 * It schedules the cleanup cron and only runs dbDelta when the stored
	 * schema version is older than the current plugin schema version.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function crear_tabelas(): void {
		self::programar_limpeza_sesions();

		// fase7: schedule the extraescolar offer-expiry cron (idempotent). Guarded
		// so DB migration stays usable even if the class is not yet loaded.
		if ( class_exists( 'ANPA_Socios_Extraescolar_Offers' ) ) {
			ANPA_Socios_Extraescolar_Offers::programar();
		}
		if ( class_exists( 'ANPA_Socios_Season_Service' ) ) {
			ANPA_Socios_Season_Service::programar();
		}

		$installed_version = (string) get_option( self::VERSION_OPTION, '0.0.0' );
		if ( version_compare( $installed_version, self::DB_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = self::tabela_sesions();

		$sql = "CREATE TABLE {$table_name} (
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
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// fase13b: create the base tables (wp_anpa_socios + verification codes)
		// that were previously owned by the anpa-verificacion plugin, so this
		// plugin is self-sufficient. Only creates them when MISSING, so it never
		// alters the estado enum or other columns on an existing install.
		self::create_base_tables();

		// 1.2.0: extend wp_anpa_socios with rol + pendiente_alta estado.
		// dbDelta does not always update enum columns; run an explicit
		// migration guarded by the previous schema version.
		self::migrate_1_1_0_to_1_2_0();

		// 1.3.0: create fillos, empresas, actividades, matriculas and
		// audit_log tables. dbDelta is idempotent so this is safe to
		// call on every activation.
		self::create_1_3_0_tables();

		// 1.4.0: create empresa passwordless-session table.
		self::create_1_4_0_tables();

		// 1.5.0: normalize existing fillos curso/aula to canonical values.
		self::normalize_1_5_0_fillos_curso_aula();

		// 1.6.0: add socios nif/telefono/familia_id columns (fillos
		// image_consent is added by dbDelta in create_1_3_0_tables).
		self::migrate_1_5_0_to_1_6_0();

		// 1.7.0: create the encrypted SEPA banking table.
		self::create_1_7_0_tables();

		// 1.8.0: add the socio baixa workflow columns.
		self::migrate_1_7_0_to_1_8_0();

		// 1.9.0: extraescolares management — activity option sets, the new
		// grupos table, and the matriculas enrolment/waitlist columns.
		self::migrate_to_1_9_0();

		// 1.10.0: course lifecycle gates + real timetable franxas.
		self::migrate_to_1_10_0();

		// 1.11.0: offered activities icon metadata.
		self::migrate_to_1_11_0();

		// 1.12.0: group-specific schedule slot for comedor/tarde splits.
		self::migrate_to_1_12_0();

		// 1.13.0: empresa url_web for public activity card links.
		self::migrate_to_1_13_0();

		// 1.14.0: fillo course assignments per school year.
		self::migrate_to_1_14_0();

		// 1.15.0: extraescolar enrolment authorisations.
		self::migrate_to_1_15_0();

		// 1.16.0: per-activity min/max pupil capacity + default values for existing.
		self::migrate_to_1_16_0();

		// 1.17.0: activity data that changes every school year lives in a
		// separate yearly table; groups are also scoped to curso_escolar.
		self::migrate_to_1_17_0();

		// 1.18.0: course season lifecycle — estado + season dates on cursos.
		self::migrate_to_1_18_0();

		// 1.19.0: add 'pendente_aprobacion' to the socios estado enum.
		self::migrate_to_1_19_0();

		// 1.20.0: widen the fillos_cursos.aula enum to A-H for larger schools.
		self::migrate_to_1_20_0();

		// 1.21.0: add fillos.familia_id column + backfill from socios.
		self::migrate_to_1_21_0();

		// 1.22.0: add socios.rol_familia enum + backfill from familia_id.
		self::migrate_to_1_22_0();

		// 1.23.0: allow socios.email NULL for 2nd-parent contact-without-login.
		self::migrate_to_1_23_0();

		// 1.24.0: rename idade_min/idade_max → curso_min/curso_max.
		self::migrate_to_1_24_0();

		// 1.25.0: add baixa_en datetime NULL to matriculas.
		self::migrate_to_1_25_0();

		// 1.26.0: enforce one active course in restored/legacy data.
		if ( ! self::migrate_to_1_26_0() ) {
			return;
		}

		// Ensure the configured master email holds the master role so the
		// legacy master-only guards (root-baixa block + preseason preflight)
		// keep resolving. Idempotent. The admin surface itself is gated by
		// the manage_options capability since fase17, not by this role.
		$master_email = ANPA_Socios_Config::master_email();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time idempotent role backfill on migration.
		$wpdb->query( $wpdb->prepare(
			"UPDATE " . self::tabela_socios() . " SET rol = 'master' WHERE email = %s AND rol <> 'master'",
			$master_email
		) );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Creates the base tables (socios + verification codes) if MISSING.
	 *
	 * Absorbed from the former anpa-verificacion plugin (fase13b). Guarded by a
	 * table-existence check so it only runs on a fresh install — it must never
	 * dbDelta over an existing wp_anpa_socios (that would try to revert the
	 * estado enum back to varchar). The ALTER-based migrations extend the base
	 * schema afterwards.
	 *
	 * @since  1.26.0
	 * @return void
	 */
	private static function create_base_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$socios          = self::tabela_socios();
		$codigos         = $wpdb->prefix . 'anpa_codigos_verificacion';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( self::table_missing( $socios ) ) {
			dbDelta( "CREATE TABLE {$socios} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(100) NOT NULL,
				nome varchar(150) NOT NULL,
				apelidos varchar(100) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'activo',
				creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email)
			) {$charset_collate};" );
		}

		if ( self::table_missing( $codigos ) ) {
			dbDelta( "CREATE TABLE {$codigos} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(100) NOT NULL,
				codigo_hash varchar(255) NOT NULL,
				creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expira_en datetime NOT NULL,
				intentos tinyint(2) unsigned NOT NULL DEFAULT 0,
				usado tinyint(1) unsigned NOT NULL DEFAULT 0,
				ip varchar(45) DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY email (email)
			) {$charset_collate};" );
		}
	}

	/**
	 * Whether a full-name table is absent from the database.
	 *
	 * @since  1.26.0
	 * @param  string $table Full table name.
	 * @return bool
	 */
	private static function table_missing( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found !== $table;
	}

	/**
	 * Returns the wp_anpa_socios table name.
	 *
	 * The base table is created by create_base_tables() (fase13b, absorbed from
	 * anpa-verificacion); this plugin also extends it via ALTER TABLE migrations.
	 *
	 * @since  1.2.0
	 * @return string
	 */
	public static function tabela_socios(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_socios';
	}

	/**
	 * Returns whether the wp_anpa_socios.rol column already exists.
	 *
	 * @since  1.2.0
	 * @return bool
	 */
	private static function socios_tem_columna_rol(): bool {
		global $wpdb;

		$table   = self::tabela_socios();
		$column  = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'rol'
			)
		);

		return null !== $column;
	}

	/**
	 * Returns whether wp_anpa_socios.estado enum includes pendiente_alta.
	 *
	 * Reads SHOW COLUMNS and looks for the value in the Type definition.
	 *
	 * @since  1.2.0
	 * @return bool
	 */
	private static function socios_estado_inclue_pendiente_alta(): bool {
		global $wpdb;

		$table  = self::tabela_socios();
		$type   = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'estado'
			)
		);

		if ( ! is_string( $type ) ) {
			return false;
		}

		return false !== strpos( $type, 'pendiente_alta' );
	}

	/**
	 * Migration 1.1.0 -> 1.2.0: add `rol` column and extend `estado` enum.
	 *
	 * Idempotent: each step is checked before running.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	private static function migrate_1_1_0_to_1_2_0(): void {
		global $wpdb;

		$table = self::tabela_socios();

		if ( ! self::socios_tem_columna_rol() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by column check.
			$wpdb->query(
				"ALTER TABLE {$table} ADD COLUMN rol enum('socio','master') NOT NULL DEFAULT 'socio' AFTER apelidos"
			);
		}

		if ( ! self::socios_estado_inclue_pendiente_alta() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by enum check.
			$wpdb->query(
				"ALTER TABLE {$table} MODIFY COLUMN estado enum('activo','pendiente_alta','baixa') NOT NULL DEFAULT 'activo'"
			);
		}
	}

	/**
	 * Returns the full member-area sessions table name.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public static function tabela_sesions(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_area_sessions';
	}

	/**
	 * Returns the full empresa area-sessions table name.
	 *
	 * @since  1.4.0
	 * @return string
	 */
	public static function tabela_sesions_empresas(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_area_sessions_empresas';
	}

	/**
	 * Schedules the daily expired-session cleanup if it is not present.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function programar_limpeza_sesions(): void {
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
	}

	/**
	 * Unschedules the expired-session cleanup cron event.
	 *
	 * This does not delete tables or user data.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function desprogramar_limpeza_sesions(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( false === $timestamp ) {
			return;
		}

		wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
	}

	/**
	 * Deletes expired member-area sessions (socio and empresa).
	 *
	 * Intended to run from the daily anpa_area_sessions_cleanup cron hook.
	 *
	 * @since  1.1.0
	 * @return int|false Number of deleted rows (combined), or false on DB error.
	 */
	public static function borrar_sesions_expiradas() {
		global $wpdb;

		$table_name = self::tabela_sesions();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cron cleanup uses the dedicated indexed sessions table.
		$deleted_socios = $wpdb->query( "DELETE FROM {$table_name} WHERE expires_at < NOW()" );

		$empresa_table = self::tabela_sesions_empresas();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cron cleanup for empresa sessions.
		$deleted_empresas = $wpdb->query( "DELETE FROM {$empresa_table} WHERE expires_at < NOW()" );

		if ( false === $deleted_socios || false === $deleted_empresas ) {
			return false;
		}

		return $deleted_socios + $deleted_empresas;
	}

	// ──────────────────────────────────────────────
	// 1.3.0 schema: fillos, empresas, actividades, matriculas, audit
	// ──────────────────────────────────────────────

	/**
	 * Returns the full anpa_fillos table name.
	 *
	 * @since  1.3.0
	 * @return string
	 */
	public static function tabela_fillos(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_fillos';
	}

	/**
	 * Returns the full anpa_empresas table name.
	 *
	 * @since  1.3.0
	 * @return string
	 */
	public static function tabela_empresas(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_empresas';
	}

	/**
	 * Returns the full anpa_actividades table name.
	 *
	 * @since  1.3.0
	 * @return string
	 */
	public static function tabela_actividades(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_actividades';
	}

	/**
	 * Returns the full anpa_actividades_cursos table name.
	 *
	 * @since  1.17.0
	 * @return string
	 */
	public static function tabela_actividades_cursos(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_actividades_cursos';
	}

	/**
	 * Returns the full anpa_matriculas table name.
	 *
	 * @since  1.3.0
	 * @return string
	 */
	public static function tabela_matriculas(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_matriculas';
	}

	/**
	 * Returns the full anpa_grupos table name (fase7).
	 *
	 * @since  1.9.0
	 * @return string
	 */
	public static function tabela_grupos(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_grupos';
	}

	/**
	 * Returns the full anpa_cursos table name (fase10).
	 *
	 * @since  1.10.0
	 * @return string
	 */
	public static function tabela_cursos(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_cursos';
	}

	/**
	 * Returns the full anpa_fillos_cursos table name.
	 *
	 * @since  1.14.0
	 * @return string
	 */
	public static function tabela_fillos_cursos(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_fillos_cursos';
	}

	/**
	 * Returns whether the given column exists on the given (full) table name.
	 *
	 * @since  1.9.0
	 * @param  string $table  Full table name.
	 * @param  string $column Column name.
	 * @return bool
	 */
	private static function tem_columna( string $table, string $column ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
		);

		return null !== $found;
	}

	/**
	 * Migration to 1.9.0: extraescolares management schema.
	 *
	 * Idempotent and guarded. Adds activity option-set columns, creates the
	 * grupos table, and extends matriculas with the enrolment/waitlist columns
	 * (group, trimester, waitlist position, expiring offer token) plus the new
	 * estado enum and the trimester-scoped uniqueness key.
	 *
	 * @since  1.9.0
	 * @return void
	 */
	private static function migrate_to_1_9_0(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$actividades     = self::tabela_actividades();
		$matriculas      = self::tabela_matriculas();
		$grupos          = self::tabela_grupos();

		// --- actividades: add option-set columns (idade_min/max retained, ignored).
		if ( ! self::tem_columna( $actividades, 'horarios' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN horarios varchar(20) NOT NULL DEFAULT '' AFTER curso_escolar" );
		}
		if ( ! self::tem_columna( $actividades, 'grupos' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN grupos varchar(20) NOT NULL DEFAULT '' AFTER horarios" );
		}
		if ( ! self::tem_columna( $actividades, 'dias' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN dias varchar(40) NOT NULL DEFAULT '' AFTER grupos" );
		}

		// --- grupos: new enrollable-group table.
		$grupos_sql = "CREATE TABLE {$grupos} (
			id bigint(20) unsigned not null auto_increment,
			actividad_id bigint(20) unsigned not null,
			curso_range enum('1-2-3','4-5-6') not null,
			franxa varchar(20) not null default '',
			dias varchar(40) not null default '',
			min_pupilos smallint(5) unsigned not null default 0,
			max_pupilos smallint(5) unsigned not null default 0,
			estado enum('aberto','pechado') not null default 'aberto',
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			key actividad_id (actividad_id),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $grupos_sql );

		// --- matriculas: enrolment / waitlist columns.
		if ( ! self::tem_columna( $matriculas, 'grupo_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN grupo_id bigint(20) unsigned NULL DEFAULT NULL AFTER activitad_id, ADD KEY grupo_id (grupo_id)" );
		}
		if ( ! self::tem_columna( $matriculas, 'trimestre' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN trimestre tinyint(3) unsigned NOT NULL DEFAULT 1 AFTER grupo_id" );
		}
		if ( ! self::tem_columna( $matriculas, 'posicion' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN posicion int(10) unsigned NULL DEFAULT NULL AFTER estado" );
		}
		if ( ! self::tem_columna( $matriculas, 'oferta_token' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN oferta_token char(64) NULL DEFAULT NULL AFTER posicion" );
		}
		if ( ! self::tem_columna( $matriculas, 'oferta_expira' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN oferta_expira datetime NULL DEFAULT NULL AFTER oferta_token" );
		}

		// --- matriculas: extend estado enum (guarded by presence of a new value).
		$estado_col = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$matriculas} LIKE %s", 'estado' ),
			ARRAY_A
		);
		$estado_type = is_array( $estado_col ) ? (string) ( $estado_col['Type'] ?? '' ) : '';
		if ( '' !== $estado_type && false === strpos( $estado_type, 'lista_espera' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded enum migration.
			$wpdb->query( "ALTER TABLE {$matriculas} MODIFY COLUMN estado enum('activo','lista_espera','oferta','baixa_solicitada','baixa') NOT NULL DEFAULT 'activo'" );
		}

		// --- matriculas: swap unique key to be trimester-scoped.
		$has_old_key = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = %s",
				$wpdb->dbname,
				$matriculas,
				'fillo_actividad'
			)
		);
		$has_new_key = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = %s",
				$wpdb->dbname,
				$matriculas,
				'fillo_actividad_trim'
			)
		);
		if ( (int) $has_old_key > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded index migration.
			$wpdb->query( "ALTER TABLE {$matriculas} DROP INDEX fillo_actividad" );
		}
		if ( 0 === (int) $has_new_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded index migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD UNIQUE KEY fillo_actividad_trim (fillo_id, activitad_id, trimestre)" );
		}
	}

	/**
	 * Migration to 1.10.0: curso lifecycle + real timetable franxas.
	 *
	 * @since  1.10.0
	 * @return void
	 */
	private static function migrate_to_1_10_0(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$cursos          = self::tabela_cursos();
		$actividades     = self::tabela_actividades();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cursos_sql = "CREATE TABLE {$cursos} (
			id bigint(20) unsigned not null auto_increment,
			curso_escolar varchar(9) not null,
			matriculas_abertas tinyint(1) not null default 1,
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			unique key curso_escolar (curso_escolar),
			PRIMARY KEY  (id)
		) {$charset_collate};";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema creation via dbDelta.
		dbDelta( $cursos_sql );

		if ( ! self::tem_columna( $actividades, 'franxa' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN franxa varchar(20) NOT NULL DEFAULT '' AFTER curso_escolar" );
		}

		// Best-effort backfill for rows created before real franxas existed. Admins can refine later.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query( "UPDATE {$actividades} SET franxa = '16:45-17:45' WHERE franxa = '' AND horarios LIKE '%tarde%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query( "UPDATE {$actividades} SET franxa = '14:20-16:10' WHERE franxa = '' AND horarios LIKE '%manha%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fallback for any remaining legacy row.
		$wpdb->query( "UPDATE {$actividades} SET franxa = '16:45-17:45' WHERE franxa = ''" );

		$current = ANPA_Socios_Curso_Escolar::current();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- creates the current course gate if absent.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$cursos} (curso_escolar, matriculas_abertas) VALUES (%s, 0)",
				$current
			)
		);
	}

	/**
	 * Migration to 1.11.0: icono for dynamic offered-activity cards.
	 *
	 * @since  1.11.0
	 * @return void
	 */
	private static function migrate_to_1_11_0(): void {
		global $wpdb;

		$actividades = self::tabela_actividades();
		if ( ! self::tem_columna( $actividades, 'icono' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN icono varchar(20) NOT NULL DEFAULT '🎒' AFTER nome" );
		}

		// Default any legacy empty icon so public cards always render consistently.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query( "UPDATE {$actividades} SET icono = '🎒' WHERE icono = ''" );
	}

	/**
	 * Migration to 1.12.0: group-level franxa horaria.
	 *
	 * @since  1.12.0
	 * @return void
	 */
	private static function migrate_to_1_12_0(): void {
		global $wpdb;

		$grupos      = self::tabela_grupos();
		$actividades = self::tabela_actividades();
		if ( ! self::tem_columna( $grupos, 'franxa' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$grupos} ADD COLUMN franxa varchar(20) NOT NULL DEFAULT '' AFTER curso_range" );
		}

		// Legacy backfill from the previous activity-level franxa, then split comedor
		// groups to the real school dining-room slots. Admins can refine per group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query( "UPDATE {$grupos} g INNER JOIN {$actividades} a ON a.id = g.actividad_id SET g.franxa = a.franxa WHERE g.franxa = '' AND a.franxa <> ''" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- comedor 4º-6º first slot.
		$wpdb->query( "UPDATE {$grupos} g INNER JOIN {$actividades} a ON a.id = g.actividad_id SET g.franxa = '14:20-15:10' WHERE a.horarios LIKE '%manha%' AND g.curso_range = '4-5-6'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- comedor 1º-3º second slot.
		$wpdb->query( "UPDATE {$grupos} g INNER JOIN {$actividades} a ON a.id = g.actividad_id SET g.franxa = '15:10-16:10' WHERE a.horarios LIKE '%manha%' AND g.curso_range = '1-2-3'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tarde fallback.
		$wpdb->query( "UPDATE {$grupos} g INNER JOIN {$actividades} a ON a.id = g.actividad_id SET g.franxa = '16:45-17:45' WHERE g.franxa = '' AND a.horarios LIKE '%tarde%'" );
	}

	/**
	 * Migration to 1.13.0: empresa url_web for public activity-card links.
	 *
	 * @since  1.13.0
	 * @return void
	 */
	private static function migrate_to_1_13_0(): void {
		global $wpdb;

		$empresas = self::tabela_empresas();
		if ( ! self::tem_columna( $empresas, 'url_web' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$empresas} ADD COLUMN url_web varchar(512) NOT NULL DEFAULT '' AFTER telefono" );
		}
	}

	/**
	 * Migration to 1.14.0: fillo course assignments per school year.
	 *
	 * Creates wp_anpa_fillos_cursos and seeds it from existing fillos
	 * data for the current course. Idempotent via dbDelta + INSERT IGNORE.
	 *
	 * @since  1.14.0
	 * @return void
	 */
	private static function migrate_to_1_14_0(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::tabela_fillos_cursos();
		$fillos          = self::tabela_fillos();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fillo_id        bigint(20) unsigned NOT NULL,
			curso_escolar   varchar(20) NOT NULL,
			curso           enum('1','2','3','4','5','6') NOT NULL,
			aula            enum('A','B','C','D') NOT NULL,
			creado_en       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actualizado_en  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY fillo_curso (fillo_id, curso_escolar),
			KEY             fillo_id (fillo_id),
			KEY             curso_escolar (curso_escolar),
			PRIMARY KEY     (id)
		) {$charset_collate};";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema creation via dbDelta.
		dbDelta( $sql );

		// Seed: one row per existing active fillo for the current course.
		$current  = ANPA_Socios_Curso_Escolar::current();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data seed.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (fillo_id, curso_escolar, curso, aula)
				SELECT id, %s, curso, aula
				FROM {$fillos}
				WHERE estado = 'activo'
				AND curso IN ('1','2','3','4','5','6')
				AND aula IN ('A','B','C','D')
				AND id NOT IN (
					SELECT fillo_id FROM {$table} WHERE curso_escolar = %s
				)",
				$current,
				$current
			)
		);
	}

	/**
	 * Migration to 1.15.0: extraescolar enrolment authorisations.
	 *
	 * @since  1.15.0
	 * @return void
	 */
	private static function migrate_to_1_15_0(): void {
		global $wpdb;

		$matriculas = self::tabela_matriculas();
		if ( ! self::tem_columna( $matriculas, 'autorizacion_comedor' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN autorizacion_comedor enum('si','non','na') NOT NULL DEFAULT 'na' AFTER posicion" );
		}
		if ( ! self::tem_columna( $matriculas, 'tarde_transicion' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN tarde_transicion enum('comedor','familia','na') NOT NULL DEFAULT 'na' AFTER autorizacion_comedor" );
		}
		if ( ! self::tem_columna( $matriculas, 'tardes_divertidas_continua' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN tardes_divertidas_continua tinyint(1) NOT NULL DEFAULT 0 AFTER tarde_transicion" );
		}
		if ( ! self::tem_columna( $matriculas, 'recollida_autorizada' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN recollida_autorizada tinyint(1) NOT NULL DEFAULT 0 AFTER tardes_divertidas_continua" );
		}
		if ( ! self::tem_columna( $matriculas, 'cesion_datos_empresa' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN cesion_datos_empresa tinyint(1) NOT NULL DEFAULT 0 AFTER recollida_autorizada" );
		}
	}

	/**
	 * Migration 1.15.0 -> 1.16.0: add min_pupilos / max_pupilos columns to
	 * the actividades table for per-activity place capacity, default 10/15.
	 *
	 * Existing rows receive the defaults so capacity records are never null.
	 *
	 * @since  1.16.0
	 * @return void
	 */
	private static function migrate_to_1_16_0(): void {
		global $wpdb;

		$actividades = self::tabela_actividades();
		if ( ! self::tem_columna( $actividades, 'min_pupilos' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN min_pupilos smallint(5) unsigned NOT NULL DEFAULT 10 AFTER dias" );
		}
		if ( ! self::tem_columna( $actividades, 'max_pupilos' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN max_pupilos smallint(5) unsigned NOT NULL DEFAULT 15 AFTER min_pupilos" );
		}

		// Set defaults for any existing rows that have 0 in these fields
		// (legacy rows created before this migration).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time data default.
		$wpdb->query( "UPDATE {$actividades} SET min_pupilos = 10 WHERE min_pupilos = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time data default.
		$wpdb->query( "UPDATE {$actividades} SET max_pupilos = 15 WHERE max_pupilos = 0" );
	}

	/**
	 * Migration 1.16.0 -> 1.17.0: yearly activity settings.
	 *
	 * Activity identity stays in anpa_actividades. Values that can change each
	 * school year (course year, franxa, option sets and min/max capacity) are
	 * copied to anpa_actividades_cursos. Groups are also scoped by curso_escolar.
	 *
	 * @since  1.17.0
	 * @return void
	 */
	private static function migrate_to_1_17_0(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$actividades     = self::tabela_actividades();
		$act_cursos      = self::tabela_actividades_cursos();
		$grupos          = self::tabela_grupos();
		$matriculas      = self::tabela_matriculas();
		$current         = ANPA_Socios_Curso_Escolar::current();

		$sql = "CREATE TABLE {$act_cursos} (
			id bigint(20) unsigned not null auto_increment,
			actividad_id bigint(20) unsigned not null,
			curso_escolar varchar(20) not null,
			franxa varchar(20) not null default '',
			horarios varchar(20) not null default '',
			grupos varchar(20) not null default '',
			dias varchar(40) not null default '',
			min_pupilos smallint(5) unsigned not null default 10,
			max_pupilos smallint(5) unsigned not null default 15,
			custo decimal(10,2) not null default 0.00,
			estado enum('activo','inactivo') not null default 'activo',
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			unique key actividad_curso (actividad_id, curso_escolar),
			key curso_escolar (curso_escolar),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$charset_collate};";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema creation via dbDelta.
		dbDelta( $sql );

		if ( ! self::tem_columna( $grupos, 'curso_escolar' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$grupos} ADD COLUMN curso_escolar varchar(20) NOT NULL DEFAULT '' AFTER actividad_id, ADD KEY curso_escolar (curso_escolar)" );
		}

		// Seed one yearly row per existing activity. Blank legacy course values are
		// assigned to the current school year so existing activities keep working.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data seed.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$act_cursos}
				(actividad_id, curso_escolar, franxa, horarios, grupos, dias, min_pupilos, max_pupilos, custo, estado)
				SELECT id,
				       CASE WHEN curso_escolar <> '' THEN curso_escolar ELSE %s END,
				       franxa, horarios, grupos, dias,
				       CASE WHEN min_pupilos > 0 THEN min_pupilos ELSE 10 END,
				       CASE WHEN max_pupilos > 0 THEN max_pupilos ELSE 15 END,
				       custo,
				       estado
				FROM {$actividades}",
				$current
			)
		);

		// Backfill existing groups from their activity course year.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$grupos} g
				 INNER JOIN {$actividades} a ON a.id = g.actividad_id
				 SET g.curso_escolar = CASE WHEN a.curso_escolar <> '' THEN a.curso_escolar ELSE %s END
				 WHERE g.curso_escolar = ''",
				$current
			)
		);

		// Guarantee no blank capacity in the new yearly table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- data normalization.
		$wpdb->query( "UPDATE {$act_cursos} SET min_pupilos = 10 WHERE min_pupilos = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- data normalization.
		$wpdb->query( "UPDATE {$act_cursos} SET max_pupilos = 15 WHERE max_pupilos = 0 OR max_pupilos < min_pupilos" );

		// Fill missing positions by deterministic registration order, scoped by
		// activity + trimester. Position is separate from estado and represents the
		// entry order in the activity, so it is not grouped by state nor group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- data backfill for display.
		$wpdb->query(
			"UPDATE {$matriculas} m
			 INNER JOIN (
				SELECT m1.id, COUNT(m2.id) AS pos
				FROM {$matriculas} m1
				INNER JOIN {$matriculas} m2
				  ON m2.activitad_id = m1.activitad_id
				 AND m2.trimestre = m1.trimestre
				 AND m2.id <= m1.id
				GROUP BY m1.id
			 ) x ON x.id = m.id
			 SET m.posicion = x.pos
			 WHERE m.posicion IS NULL"
		);
	}

	/**
	 * Migration 1.17.0 -> 1.18.0: course season lifecycle.
	 *
	 * Adds `estado` (pendente|activo|pechado) and the season dates
	 * `data_inicio` (1 September of the start year) and `data_peche`
	 * (20 June of the end year) to the cursos table, then seeds those
	 * values for existing course rows. The initial estado is derived from
	 * today's date via ANPA_Socios_Season so a course created after the
	 * previous one closed but before its start date is correctly `pendente`.
	 *
	 * @since  1.18.0
	 * @return void
	 */
	private static function migrate_to_1_18_0(): void {
		global $wpdb;

		$cursos = self::tabela_cursos();

		if ( ! self::tem_columna( $cursos, 'estado' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$cursos} ADD COLUMN estado enum('pendente','activo','pechado') NOT NULL DEFAULT 'activo' AFTER matriculas_abertas" );
		}
		if ( ! self::tem_columna( $cursos, 'data_inicio' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$cursos} ADD COLUMN data_inicio date NULL DEFAULT NULL AFTER estado" );
		}
		if ( ! self::tem_columna( $cursos, 'data_peche' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$cursos} ADD COLUMN data_peche date NULL DEFAULT NULL AFTER data_inicio" );
		}

		// Seed season dates + lifecycle state for existing course rows.
		$today = date( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time seed on migration.
		$rows = $wpdb->get_results( "SELECT id, curso_escolar, data_inicio, data_peche FROM {$cursos}", ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$curso = (string) $row['curso_escolar'];
				if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
					continue;
				}
				$inicio = ! empty( $row['data_inicio'] ) ? (string) $row['data_inicio'] : ANPA_Socios_Season::default_data_inicio( $curso );
				$peche  = ! empty( $row['data_peche'] ) ? (string) $row['data_peche'] : ANPA_Socios_Season::default_data_peche( $curso );
				$estado = ANPA_Socios_Season::estado_for( $today, $inicio, $peche );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent per-row seed.
				$wpdb->update(
					$cursos,
					array(
						'data_inicio'    => $inicio,
						'data_peche'     => $peche,
						'estado'         => $estado,
						'actualizado_en' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Migration to 1.19.0: extend the socios estado enum with
	 * 'pendente_aprobacion' for the optional new-socio approval workflow.
	 *
	 * Idempotent: guarded by inspecting the current enum definition.
	 *
	 * @since  1.23.0
	 * @return void
	 */
	private static function migrate_to_1_19_0(): void {
		global $wpdb;

		$socios = self::tabela_socios();
		$col    = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$socios} LIKE %s", 'estado' ),
			ARRAY_A
		);
		$type = is_array( $col ) ? (string) ( $col['Type'] ?? '' ) : '';
		if ( '' !== $type && false === strpos( $type, 'pendente_aprobacion' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded enum migration.
			$wpdb->query( "ALTER TABLE {$socios} MODIFY COLUMN estado enum('activo','pendiente_alta','pendente_aprobacion','baixa') NOT NULL DEFAULT 'activo'" );
		}
	}

	/**
	 * Migration to 1.20.0: widen the fillos_cursos.aula enum to A-H so larger
	 * schools can offer more than four lines ("aula") per course.
	 *
	 * Idempotent: guarded by inspecting the current enum definition — the
	 * ALTER only runs when the Type string does not already include 'H'.
	 * Keeps the column NOT NULL with no default (unchanged otherwise).
	 *
	 * @since  1.30.0
	 * @return void
	 */
	private static function migrate_to_1_20_0(): void {
		global $wpdb;

		$fillos_cursos = self::tabela_fillos_cursos();
		$col           = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$fillos_cursos} LIKE %s", 'aula' ),
			ARRAY_A
		);
		$type = is_array( $col ) ? (string) ( $col['Type'] ?? '' ) : '';
		if ( '' !== $type && false === strpos( $type, "'H'" ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded enum migration.
			$wpdb->query( "ALTER TABLE {$fillos_cursos} MODIFY COLUMN aula enum('A','B','C','D','E','F','G','H') NOT NULL" );
		}
	}

	/**
	 * Migration to 1.21.0: add familia_id column to fillos + backfill.
	 *
	 * The familia_id column links each fillo to the family group via the
	 * socios.familia_id (or socios.id when familia_id is NULL/0). The
	 * socio_email column is retained transitionally.
	 *
	 * Idempotent: guarded by column-existence check. Backfill only updates
	 * rows where familia_id is NULL or 0.
	 *
	 * @since  1.21.0
	 * @return void
	 */
	private static function migrate_to_1_21_0(): void {
		global $wpdb;

		$fillos = self::tabela_fillos();
		$socios = self::tabela_socios();

		if ( ! self::tem_columna( $fillos, 'familia_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$fillos} ADD COLUMN familia_id bigint(20) unsigned NULL DEFAULT NULL AFTER socio_email, ADD KEY familia_id (familia_id)" );
		}

		// Backfill: resolve each fillo's familia_id from the owning socio.
		// COALESCE(NULLIF(s.familia_id,0), s.id) mirrors the runtime resolver.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query(
			"UPDATE {$fillos} f
			 INNER JOIN {$socios} s ON s.email = f.socio_email
			 SET f.familia_id = COALESCE(NULLIF(s.familia_id, 0), s.id)
			 WHERE f.familia_id IS NULL OR f.familia_id = 0"
		);
	}

	/**
	 * 1.22.0 — Add `rol_familia` enum to socios + idempotent backfill.
	 *
	 * Business rule:
	 * - 'principal' when familia_id IS NULL / 0 / equals own id (head of family).
	 * - 'secundario' for any other family member (linked to another's id).
	 *
	 * The legacy `rol` column is NOT dropped (rollback safety).
	 *
	 * @since  1.35.2
	 * @return void
	 */
	private static function migrate_to_1_22_0(): void {
		global $wpdb;

		$socios = self::tabela_socios();

		if ( ! self::tem_columna( $socios, 'rol_familia' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query(
				"ALTER TABLE {$socios} ADD COLUMN rol_familia enum('principal','secundario') NOT NULL DEFAULT 'principal' AFTER familia_id"
			);
		}

		// Idempotent backfill: mark secundario members (familia_id != own id AND familia_id > 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query(
			"UPDATE {$socios}
			 SET rol_familia = 'secundario'
			 WHERE familia_id IS NOT NULL
			   AND familia_id <> 0
			   AND familia_id <> id
			   AND rol_familia <> 'secundario'"
		);

		// Ensure head/unlinked members are marked principal (defensive, covers edge cases).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$wpdb->query(
			"UPDATE {$socios}
			 SET rol_familia = 'principal'
			 WHERE (familia_id IS NULL OR familia_id = 0 OR familia_id = id)
			   AND rol_familia <> 'principal'"
		);
	}

	/**
	 * 1.23.0 — Allow socios.email NULL for 2nd-parent contact-without-login.
	 *
	 * The UNIQUE index on email stays — MySQL/MariaDB allows multiple NULLs
	 * in a UNIQUE column (they are not considered equal). A 2nd parent
	 * inserted without an email address can exist as a family contact
	 * without login capability; if an email is later added they gain login.
	 *
	 * Idempotent: guarded by inspecting the current column Null attribute.
	 *
	 * @since  1.35.2
	 * @return void
	 */
	private static function migrate_to_1_23_0(): void {
		global $wpdb;

		$socios = self::tabela_socios();
		$col    = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$socios} LIKE %s", 'email' ),
			ARRAY_A
		);
		// If already nullable, nothing to do.
		$is_nullable = is_array( $col ) && 'YES' === strtoupper( (string) ( $col['Null'] ?? 'NO' ) );
		if ( $is_nullable ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
		$wpdb->query( "ALTER TABLE {$socios} MODIFY COLUMN email varchar(100) NULL DEFAULT NULL" );
	}

	/**
	 * 1.24.0 — Rename idade_min/idade_max → curso_min/curso_max in actividades.
	 *
	 * The columns store an informative numeric grade range (not age). No data
	 * exists in production so the rename is safe. Idempotent: guarded by
	 * column-existence checks.
	 *
	 * @since  1.24.0
	 * @return void
	 */
	private static function migrate_to_1_24_0(): void {
		global $wpdb;

		$actividades = self::tabela_actividades();

		// If the new column already exists, nothing to do.
		if ( self::tem_columna( $actividades, 'curso_min' ) ) {
			return;
		}

		// If the old column exists, rename it.
		if ( self::tem_columna( $actividades, 'idade_min' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} CHANGE COLUMN idade_min curso_min tinyint(3) unsigned NULL DEFAULT NULL" );
		} else {
			// Fresh install or already dropped: add the new column.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN curso_min tinyint(3) unsigned NULL DEFAULT NULL AFTER dias" );
		}

		if ( self::tem_columna( $actividades, 'curso_max' ) ) {
			return;
		}

		if ( self::tem_columna( $actividades, 'idade_max' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} CHANGE COLUMN idade_max curso_max tinyint(3) unsigned NULL DEFAULT NULL" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			$wpdb->query( "ALTER TABLE {$actividades} ADD COLUMN curso_max tinyint(3) unsigned NULL DEFAULT NULL AFTER curso_min" );
		}
	}

	/**
	 * Migration 1.24.0 → 1.25.0: add `baixa_en` datetime NULL to matriculas.
	 *
	 * Tracks the exact date/time a matrícula is set to estado='baixa', enabling
	 * computed trimester ranges (tri_alta..tri_baixa).
	 *
	 * Idempotent: checks column existence before ALTER.
	 *
	 * @since  1.25.0
	 * @return void
	 */
	private static function migrate_to_1_25_0(): void {
		global $wpdb;

		$matriculas = self::tabela_matriculas();

		if ( self::tem_columna( $matriculas, 'baixa_en' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
		$wpdb->query( "ALTER TABLE {$matriculas} ADD COLUMN baixa_en datetime NULL DEFAULT NULL AFTER estado" );
	}

	/**
	 * Repairs restored/legacy data containing more than one active course.
	 * Keeps the most recently updated course and closes every other one.
	 *
	 * @since  1.26.0
	 * @return bool Whether the repair transaction completed.
	 */
	private static function migrate_to_1_26_0(): bool {
		global $wpdb;

		$cursos = self::tabela_cursos();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$wpdb->last_error = '';
		$active_ids       = $wpdb->get_col(
			"SELECT id FROM {$cursos} WHERE estado = 'activo' ORDER BY actualizado_en DESC, curso_escolar DESC FOR UPDATE"
		);
		if ( '' !== $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( count( $active_ids ) > 1 ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$cursos} SET estado = 'pechado', matriculas_abertas = 0, actualizado_en = %s WHERE estado = 'activo' AND id <> %d",
					current_time( 'mysql' ),
					(int) $active_ids[0]
				)
			);
			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Returns the full anpa_audit_log table name.
	 *
	 * @since  1.3.0
	 * @return string
	 */
	public static function tabela_audit_log(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_audit_log';
	}

	/**
	 * Idempotent creation of the five 1.3.0 tables.
	 *
	 * dbDelta is smart enough to add new columns to an existing table,
	 * so the same call works for fresh installs and for upgrades from
	 * 1.2.0.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	private static function create_1_3_0_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$fillos_sql = 'CREATE TABLE ' . self::tabela_fillos() . " (
			id bigint(20) unsigned not null auto_increment,
			socio_email varchar(190) not null,
			familia_id bigint(20) unsigned null default null,
			nome varchar(50) not null,
			apelidos varchar(100) not null,
			data_nacemento date not null,
			curso varchar(30) not null,
			aula varchar(10) not null,
			estado enum('activo','baixa') not null default 'activo',
			image_consent tinyint(1) not null default 0,
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			key socio_email (socio_email),
			key familia_id (familia_id),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$empresas_sql = 'CREATE TABLE ' . self::tabela_empresas() . " (
			id bigint(20) unsigned not null auto_increment,
			nome varchar(120) not null,
			email varchar(190) not null,
			responsable varchar(60) not null default '',
			telefono varchar(60) not null default '',
			url_web varchar(512) not null default '',
			estado enum('activo','inactivo') not null default 'activo',
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			unique key email (email),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$actividades_sql = 'CREATE TABLE ' . self::tabela_actividades() . " (
			id bigint(20) unsigned not null auto_increment,
			empresa_id bigint(20) unsigned not null,
			nome varchar(120) not null,
			descripcion varchar(500) not null default '',
			curso_escolar varchar(20) not null default '',
			min_pupilos smallint(5) unsigned not null default 10,
			max_pupilos smallint(5) unsigned not null default 15,
			curso_min tinyint(3) unsigned null,
			curso_max tinyint(3) unsigned null,
			custo decimal(8,2) not null default 0.00,
			estado enum('activo','inactivo') not null default 'activo',
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			key empresa_id (empresa_id),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$matriculas_sql = 'CREATE TABLE ' . self::tabela_matriculas() . " (
			id bigint(20) unsigned not null auto_increment,
			fillo_id bigint(20) unsigned not null,
			activitad_id bigint(20) unsigned not null,
			estado enum('activo','baixa') not null default 'activo',
			comedor tinyint(1) not null default 0,
			tarde tinyint(1) not null default 0,
			observaciones varchar(500) not null default '',
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			unique key fillo_actividad (fillo_id, activitad_id),
			key activitad_id (activitad_id),
			key estado (estado),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$audit_sql = 'CREATE TABLE ' . self::tabela_audit_log() . " (
			id bigint(20) unsigned not null auto_increment,
			actor_email varchar(190) not null,
			actor_tipo varchar(20) not null,
			target_tipo varchar(20) not null,
			target_id varchar(40) not null default '',
			accion varchar(20) not null,
			timestamp datetime not null default CURRENT_TIMESTAMP,
			key actor_email (actor_email),
			key timestamp (timestamp),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema creation guarded by dbDelta which is the documented path.
		dbDelta( $fillos_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		dbDelta( $empresas_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		dbDelta( $actividades_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		dbDelta( $matriculas_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		dbDelta( $audit_sql );
	}

	/**
	 * Idempotent creation of the 1.4.0 empresa sessions table.
	 *
	 * Mirrors wp_anpa_area_sessions with empresa_email instead of email.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	private static function create_1_4_0_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$empresa_sessions_sql = 'CREATE TABLE ' . self::tabela_sesions_empresas() . " (
			id bigint(20) unsigned not null auto_increment,
			token_digest char(64) not null,
			empresa_email varchar(190) not null,
			ua_hash char(64) not null,
			ip_hash char(64) not null,
			usage_count smallint(5) unsigned not null default 0,
			max_uses smallint(5) unsigned not null default 100,
			expires_at datetime not null,
			created_at datetime not null default CURRENT_TIMESTAMP,
			unique key token_digest (token_digest),
			key empresa_email (empresa_email),
			key expires_at (expires_at),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema creation via dbDelta.
		dbDelta( $empresa_sessions_sql );
	}

	/**
	 * Normalizes existing fillos curso/aula values to canonical enums.
	 *
	 * curso: strips trailing non-digit characters (e.g. '3º' → '3', '3º EP' → '3'),
	 * trims whitespace, then checks if the result is in ['1'..'6'].
	 *
	 * aula: uppercases and trims, then checks if the result is in ['A'..'D'].
	 *
	 * Rows that cannot be unambiguously mapped are left untouched (not corrupted).
	 * This method is idempotent: already-canonical values are not modified.
	 *
	 * @since  1.5.0
	 * @return void
	 */
	private static function normalize_1_5_0_fillos_curso_aula(): void {
		global $wpdb;

		$table = self::tabela_fillos();

		// Normalize curso: '3º' → '3', ' 3º EP ' → '3', '03' → left alone (not in enum).
		// Strategy: UPDATE only rows where we can extract a single-digit 1–6.
		// Pattern: trim, strip ordinal suffix (º, °), strip trailing alpha, check result.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time idempotent data normalization.
		$wpdb->query(
			"UPDATE {$table}
			SET curso = CAST(
				SUBSTRING(TRIM(curso), 1, 1) AS CHAR(1)
			)
			WHERE TRIM(curso) REGEXP '^[1-6][º°]'
			AND curso NOT IN ('1','2','3','4','5','6')"
		);

		// Normalize aula: lowercase → uppercase for single-letter A–D values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time idempotent data normalization.
		$wpdb->query(
			"UPDATE {$table}
			SET aula = UPPER(TRIM(aula))
			WHERE UPPER(TRIM(aula)) IN ('A','B','C','D')
			AND aula NOT IN ('A','B','C','D')"
		);
	}

	/**
	 * Returns whether the given column exists on wp_anpa_socios.
	 *
	 * @since  1.6.0
	 * @param  string $column Column name.
	 * @return bool
	 */
	private static function socios_tem_columna( string $column ): bool {
		global $wpdb;

		$table = self::tabela_socios();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				$column
			)
		);

		return null !== $found;
	}

	/**
	 * Migration 1.5.0 -> 1.6.0: add nif, telefono and familia_id to socios.
	 *
	 * - `nif`         titular/parent fiscal id (validated NIF/NIE).
	 * - `telefono`    contact phone.
	 * - `familia_id`  links a second parent row to the same family unit;
	 *                 nullable, indexed. Fillos stay keyed by socio_email
	 *                 (re-keying deferred per design decision).
	 *
	 * Idempotent: each column is checked before it is added.
	 *
	 * @since  1.6.0
	 * @return void
	 */
	private static function migrate_1_5_0_to_1_6_0(): void {
		global $wpdb;

		$table = self::tabela_socios();

		if ( ! self::socios_tem_columna( 'nif' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by column check.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN nif varchar(15) NULL DEFAULT NULL AFTER apelidos" );
		}

		if ( ! self::socios_tem_columna( 'telefono' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by column check.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN telefono varchar(20) NULL DEFAULT NULL AFTER nif" );
		}

		if ( ! self::socios_tem_columna( 'familia_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by column check.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN familia_id bigint(20) unsigned NULL DEFAULT NULL, ADD KEY familia_id (familia_id)" );
		}
	}

	/**
	 * Returns the full anpa_domiciliacions table name.
	 *
	 * @since  1.7.0
	 * @return string
	 */
	public static function tabela_domiciliacions(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_domiciliacions';
	}

	/**
	 * Idempotent creation of the 1.7.0 encrypted SEPA banking table.
	 *
	 * One row per family (familia_id unique). IBAN and titular NIF are
	 * stored encrypted (ciphertext + nonce); only iban_last4 is kept in
	 * clear for masked admin lists. This table is NEVER joined in any
	 * export.
	 *
	 * @since  1.7.0
	 * @return void
	 */
	private static function create_1_7_0_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = 'CREATE TABLE ' . self::tabela_domiciliacions() . " (
			id bigint(20) unsigned not null auto_increment,
			familia_id bigint(20) unsigned not null,
			titular_nome varchar(50) not null default '',
			titular_apelidos varchar(100) not null default '',
			titular_nif_cifrado text null,
			titular_nif_nonce varchar(64) null,
			titular_nif_mask varchar(20) not null default '',
			enderezo varchar(190) not null default '',
			poboacion varchar(100) not null default '',
			codigo_postal varchar(10) not null default '',
			entidade_bancaria varchar(120) not null default '',
			iban_cifrado text null,
			iban_nonce varchar(64) null,
			iban_last4 char(4) not null default '',
			autorizacion tinyint(1) not null default 0,
			lugar_data varchar(120) not null default '',
			creado_en datetime not null default CURRENT_TIMESTAMP,
			actualizado_en datetime not null default CURRENT_TIMESTAMP,
			unique key familia_id (familia_id),
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema creation via dbDelta.
		dbDelta( $sql );
	}

	/**
	 * Migration 1.7.0 -> 1.8.0: add the socio baixa workflow columns.
	 *
	 * - `baixa_estado`        enum('none','solicitada') — pending baixa request flag.
	 * - `baixa_solicitada_en` datetime — when the socio requested baixa.
	 *
	 * The baixa only becomes effective (estado='baixa') after an admin
	 * confirms it. Idempotent: each column is checked before it is added.
	 *
	 * @since  1.8.0
	 * @return void
	 */
	private static function migrate_1_7_0_to_1_8_0(): void {
		global $wpdb;

		$table = self::tabela_socios();

		if ( ! self::socios_tem_columna( 'baixa_estado' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by column check.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN baixa_estado enum('none','solicitada') NOT NULL DEFAULT 'none' AFTER estado" );
		}

		if ( ! self::socios_tem_columna( 'baixa_solicitada_en' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration guarded by column check.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN baixa_solicitada_en datetime NULL DEFAULT NULL AFTER baixa_estado" );
		}
	}
}
