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
	 * 1.27.0 adds parametrizable school structure: `niveis`, `aulas`,
	 *        `grupos_niveis` tables; fills `fillos_cursos` varchar columns
	 *        and level/classroom foreign ids; converts `grupos.curso_range`
	 *        to varchar; adds `actividades_cursos.nivel_min/max_id`;
	 *        backfills legacy data into new structure.
	 * 1.28.0 (fase24) adds curricular groups: `grupos_curriculares`,
	 *        `grupos_curriculares_niveis`,
	 *        `actividades_cursos_grupos_curriculares` tables; adds
	 *        `actividades_cursos.horario` (manha/tarde, exclusive) and
	 *        `grupos.grupo_curricular_id`; backfills curricular groups from
	 *        legacy `grupos.curso_range` + `actividades.horarios`. Non-
	 *        destructive: legacy columns are removed only in a later
	 *        migration, gated on a verified backup.
	 * 1.29.0 (fase24 revised) adds activity-owned group series: `serie_uid`,
	 *        `nome` and exclusive `horario` on annual groups. It backfills one
	 *        independent series per legacy annual group. Destructive removal of
	 *        duplicated fields remains rollout-gated until 1.31.0.
	 * 1.30.0 widens grupos.horario to accept the three UI periods:
	 *        `maña` (Mañá), `manha` (Comedor) and `tarde` (Tarde).
	 * 1.31.0 retires the temporary curricular tables plus legacy activity
	 *        columns once their data has been promoted into the yearly tables.
	 * 1.32.0 adds nullable annual meal-window fields to each school level.
	 * 1.33.0 normalizes reusable annual meal schedules, links levels to them,
	 *        backfills 1.32.0 windows and retires the global aula_max option.
	 * 1.34.0 retires actividades_cursos after verifying every legacy annual
	 *        offer has an annual group with at least one assigned level.
	 * 1.35.0 makes niveis global (drops curso_escolar) with a habilitado toggle.
	 * 1.36.0 (fase31) adds wp_anpa_niveis_curso pivot so a global level can carry
	 *        a per-course comedor schedule; backfills from the legacy global
	 *        niveis.horario_comedor_id column, which is kept as a compatibility
	 *        bridge until the destructive 1.37.0 migration retires it.
	 * 1.37.0 (fase31) drops the now-unused legacy comedor columns from niveis
	 *        (horario_comedor_id, comedor_inicio, comedor_fin) after the pivot
	 *        cutover; the wp_anpa_niveis_curso pivot is the sole authority.
	 * 1.38.0 (fase34) adds the academic calendar: operative trimester close
	 *        dates on cursos (t1_peche_operativo, t2_peche_operativo), the
	 *        wp_anpa_curso_trimestres table (separate trimester + application
	 *        window states per course) and the append-only wp_anpa_transicions
	 *        log. Additive and gated; seeds trimesters for the active course.
	 * 1.38.1 (fase34 close-out) extends wp_anpa_transicions with a correlation/
	 *        idempotency id and an optional reason. Additive and idempotent.
	 * 1.39.0 (fase35) adds the email queue tables: wp_anpa_email_campaigns,
	 *        wp_anpa_email_recipients (one row per recipient; dedup via
	 *        UNIQUE(idempotency_key)) and wp_anpa_email_attempts (one row per
	 *        attempt). Additive; creates no campaign and sends no email.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DB_VERSION = '1.39.0';

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

		// ── Migration chain with failure-state detection ───────────────
		// Each step is idempotent so a re-run on next admin_init is safe.
		// If any step leaves a real error in $wpdb->last_error, we halt
		// immediately and do NOT advance VERSION_OPTION — the next page
		// load will retry from the same stored version.
		$migration_steps = array(
			'1.2.0'  => 'migrate_1_1_0_to_1_2_0',
			'1.3.0'  => 'create_1_3_0_tables',
			'1.4.0'  => 'create_1_4_0_tables',
			'1.5.0'  => 'normalize_1_5_0_fillos_curso_aula',
			'1.6.0'  => 'migrate_1_5_0_to_1_6_0',
			'1.7.0'  => 'create_1_7_0_tables',
			'1.8.0'  => 'migrate_1_7_0_to_1_8_0',
			'1.9.0'  => 'migrate_to_1_9_0',
			'1.10.0' => 'migrate_to_1_10_0',
			'1.11.0' => 'migrate_to_1_11_0',
			'1.12.0' => 'migrate_to_1_12_0',
			'1.13.0' => 'migrate_to_1_13_0',
			'1.14.0' => 'migrate_to_1_14_0',
			'1.15.0' => 'migrate_to_1_15_0',
			'1.16.0' => 'migrate_to_1_16_0',
			'1.17.0' => 'migrate_to_1_17_0',
			'1.18.0' => 'migrate_to_1_18_0',
			'1.19.0' => 'migrate_to_1_19_0',
			'1.20.0' => 'migrate_to_1_20_0',
			'1.21.0' => 'migrate_to_1_21_0',
			'1.22.0' => 'migrate_to_1_22_0',
			'1.23.0' => 'migrate_to_1_23_0',
			'1.24.0' => 'migrate_to_1_24_0',
			'1.25.0' => 'migrate_to_1_25_0',
		);

		foreach ( $migration_steps as $step_version => $method ) {
			if ( version_compare( $installed_version, $step_version, '>=' ) ) {
				continue;
			}
			// Clear any stale error before the step so we only detect NEW failures.
			$wpdb->last_error = '';
			self::$method();
			if ( '' !== (string) $wpdb->last_error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional migration failure logging.
				error_log( sprintf(
					'[anpa-socios] Migration halted at step %s (%s): %s',
					$step_version,
					$method,
					$wpdb->last_error
				) );
				return; // Do NOT advance VERSION_OPTION.
			}
		}

		// 1.26.0: enforce one active course in restored/legacy data.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.26.0', '<' ) && ! self::migrate_to_1_26_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.26.0 (migrate_to_1_26_0): ' . $wpdb->last_error );
			return;
		}

		// 1.27.0: parametrizable school structure (tables + backfill).
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.27.0', '<' ) && ! self::migrate_to_1_27_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.27.0 (migrate_to_1_27_0): ' . $wpdb->last_error );
			return;
		}

		// 1.28.0: curricular groups (tables + columns + best-effort backfill).
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.28.0', '<' ) && ! self::migrate_to_1_28_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.28.0 (migrate_to_1_28_0): ' . $wpdb->last_error );
			return;
		}

		// 1.29.0: activity-owned multi-year group series.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.29.0', '<' ) && ! self::migrate_to_1_29_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.29.0 (migrate_to_1_29_0): ' . $wpdb->last_error );
			return;
		}

		// 1.30.0: align the group horario enum with the three UI values.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.30.0', '<' ) && ! self::migrate_to_1_30_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.30.0 (migrate_to_1_30_0): ' . $wpdb->last_error );
			return;
		}

		// 1.31.0: retire the temporary curricular tables and legacy fields.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.31.0', '<' ) && ! self::migrate_to_1_31_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.31.0 (migrate_to_1_31_0): ' . $wpdb->last_error );
			return;
		}

		// 1.32.0: annual meal availability per school level.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.32.0', '<' ) && ! self::migrate_to_1_32_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.32.0 (migrate_to_1_32_0): ' . $wpdb->last_error );
			return;
		}

		// 1.33.0: reusable annual meal schedules + global aula option retirement.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.33.0', '<' ) && ! self::migrate_to_1_33_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.33.0 (migrate_to_1_33_0): ' . $wpdb->last_error );
			return;
		}

		// 1.34.0: annual groups become the only activity-offer authority.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.34.0', '<' ) && ! self::migrate_to_1_34_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.34.0 (migrate_to_1_34_0): ' . $wpdb->last_error );
			return;
		}

		// 1.35.0: niveis become global (no curso_escolar), with habilitado toggle.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.35.0', '<' ) && ! self::migrate_to_1_35_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.35.0 (migrate_to_1_35_0): ' . $wpdb->last_error );
			return;
		}

		// 1.36.0: per-course comedor pivot for global levels (additive).
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.36.0', '<' ) && ! self::migrate_to_1_36_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.36.0 (migrate_to_1_36_0): ' . $wpdb->last_error );
			return;
		}

		// 1.37.0: drop the legacy comedor columns from niveis (pivot is authority).
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.37.0', '<' ) && ! self::migrate_to_1_37_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.37.0 (migrate_to_1_37_0): ' . $wpdb->last_error );
			return;
		}

		// 1.38.0: academic calendar — operative trimester close dates on cursos
		// + curso_trimestres (separate trimester/window states) + transicions log.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.38.0', '<' ) && ! self::migrate_to_1_38_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.38.0 (migrate_to_1_38_0): ' . $wpdb->last_error );
			return;
		}

		// 1.38.1: extend the transicions audit log (correlation id + reason).
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.38.1', '<' ) && ! self::migrate_to_1_38_1() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.38.1 (migrate_to_1_38_1): ' . $wpdb->last_error );
			return;
		}

		// 1.39.0: email queue tables (campaigns + recipients + attempts). Additive.
		$wpdb->last_error = '';
		if ( version_compare( $installed_version, '1.39.0', '<' ) && ! self::migrate_to_1_39_0() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Migration halted at step 1.39.0 (migrate_to_1_39_0): ' . $wpdb->last_error );
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
	 * Returns the full anpa_curso_trimestres table name (fase34).
	 *
	 * Persists the SEPARATE trimester state and application-window state per
	 * course. See ANPA_Socios_Trimestre_Estado / ANPA_Socios_Ventana_Estado.
	 *
	 * @since  1.38.0
	 * @return string
	 */
	public static function tabela_curso_trimestres(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_curso_trimestres';
	}

	/**
	 * Returns the full anpa_transicions table name (fase34).
	 *
	 * Append-only log of admin/cron state transitions (trimester, window, and
	 * later group/matrícula) with actor, origin and timestamps.
	 *
	 * @since  1.38.0
	 * @return string
	 */
	public static function tabela_transicions(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_transicions';
	}

	/**
	 * Returns the full wp_anpa_email_campaigns table name (fase35).
	 *
	 * @since  1.39.0
	 * @return string
	 */
	public static function tabela_email_campaigns(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_email_campaigns';
	}

	/**
	 * Returns the full wp_anpa_email_recipients table name (fase35).
	 *
	 * @since  1.39.0
	 * @return string
	 */
	public static function tabela_email_recipients(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_email_recipients';
	}

	/**
	 * Returns the full wp_anpa_email_attempts table name (fase35).
	 *
	 * @since  1.39.0
	 * @return string
	 */
	public static function tabela_email_attempts(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_email_attempts';
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
	 * Upserts a fillo's annual course assignment (fillos_cursos) and updates the
	 * legacy mirror columns on anpa_fillos when the target year is the
	 * OPERATIONAL active course.
	 *
	 * This is the SINGLE write point for the pair fillos_cursos + fillos.curso/aula.
	 * Callers MUST manage their own transaction — this helper participates in the
	 * caller's transaction and never starts its own.
	 *
	 * The legacy-mirror gate uses `ANPA_Socios_Curso_Activo::get()` — the
	 * fase22 operational resolver backed by `anpa_cursos.estado = 'activo'`
	 * — NOT `ANPA_Socios_Curso_Escolar::current()` (a pure date calculation).
	 * The two normally agree but can diverge (e.g. the junta delays opening
	 * the next year past September 1st, or opens it early); using the wrong
	 * one would silently stop refreshing the legacy mirror whenever they
	 * disagree, which is exactly the kind of "old check doesn't know about
	 * the newer concept" bug already found elsewhere in this session.
	 * `Curso_Activo::get()` can return null (no active course configured);
	 * in that case the mirror is intentionally left untouched.
	 *
	 * `nivel_id`/`aula_id` on the row are also resolved here (from the
	 * `curso`/`aula` text codes) before the write, via
	 * `resolve_nivel_aula_ids()`. This is the SINGLE write point for
	 * fillos_cursos, so it is the only place that can keep those FK columns
	 * populated for every write made after the 1.27.0 backfill — without
	 * this, `nivel_id`/`aula_id` stay NULL on any post-migration write,
	 * silently undercounting the reference-check in
	 * `ANPA_Socios_Admin_Estrutura_Handler::delete_nivel()` and letting an
	 * in-use nivel/aula be hard-deleted instead of deactivated. If the
	 * codes do not resolve to an existing nivel/aula for that curso_escolar
	 * (e.g. a stale or foreign code), the FK columns are left NULL rather
	 * than failing the whole upsert — the text codes remain authoritative.
	 *
	 * @since  1.39.0
	 * @param  int    $fillo_id      Fillo id.
	 * @param  string $curso_escolar School year (e.g. "2025/2026").
	 * @param  string $curso         Grade code (e.g. "3") or an explicit empty
	 *                               value when the child completed the last level.
	 * @param  string $aula          Classroom code (e.g. "B").
	 * @return bool True on success, false on DB write failure.
	 */
	public static function upsert_fillo_curso_assignment( int $fillo_id, string $curso_escolar, string $curso, string $aula ): bool {
		if ( $fillo_id <= 0 || '' === $curso_escolar || '' === $aula ) {
			return false;
		}

		global $wpdb;

		$fillos_table = self::tabela_fillos();
		$fc_table     = self::tabela_fillos_cursos();
		$now      = current_time( 'mysql' );
		$wpdb->last_error = '';
		// Canonical lock order: fillos -> fillos_cursos. This matches callers
		// that update the child before its annual assignment and prevents the
		// promotion batch from taking the same pair in the opposite order.
		$locked_fillo = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$fillos_table} WHERE id = %d FOR UPDATE", $fillo_id )
		);
		if ( '' !== (string) $wpdb->last_error || $fillo_id !== (int) $locked_fillo ) {
			return false;
		}

		if ( '' === $curso ) {
			$nivel_id = null;
			$aula_id  = null;
		} else {
			list( $nivel_id, $aula_id ) = self::resolve_nivel_aula_ids( $curso_escolar, $curso, $aula );
		}

		// %d would coerce an unresolved null to 0 (a real, wrong value), so
		// the nivel_id/aula_id placeholders are literal NULL or %d chosen
		// per-value instead of always going through wpdb->prepare's %d.
		$nivel_placeholder = null === $nivel_id ? 'NULL' : '%d';
		$aula_placeholder  = null === $aula_id ? 'NULL' : '%d';
		$fk_params         = array_filter(
			array( $nivel_id, $aula_id ),
			static function ( $v ) {
				return null !== $v;
			}
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic upsert participating in caller's transaction.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$fc_table} (fillo_id, curso_escolar, curso, aula, nivel_id, aula_id)
				VALUES (%d, %s, %s, %s, {$nivel_placeholder}, {$aula_placeholder})
				ON DUPLICATE KEY UPDATE curso = VALUES(curso), aula = VALUES(aula), nivel_id = VALUES(nivel_id), aula_id = VALUES(aula_id), actualizado_en = %s",
				array_merge(
					array( $fillo_id, $curso_escolar, $curso, $aula ),
					array_values( $fk_params ),
					array( $now )
				)
			)
		);

		if ( false === $result ) {
			return false;
		}

		// Legacy mirror: only reflect the OPERATIONAL active year on
		// anpa_fillos.curso/aula (design.md §7.1 — mirror is transitional,
		// never fallback for other years).
		if ( $curso_escolar === self::resolve_operational_curso_activo() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- legacy mirror update within caller transaction.
			$mirror = $wpdb->update(
				$fillos_table,
				array( 'curso' => $curso, 'aula' => $aula ),
				array( 'id' => $fillo_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $mirror ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolves the operational active curso for the legacy-mirror gate.
	 *
	 * Prefers `ANPA_Socios_Curso_Activo::get()` (fase22 DB-backed resolver);
	 * falls back to `ANPA_Socios_Curso_Escolar::current()` (date-based) only
	 * when no active course is configured yet, so the mirror keeps working
	 * on a fresh install before the junta has set an explicit active course.
	 *
	 * @since  1.39.0
	 * @return string
	 */
	private static function resolve_operational_curso_activo(): string {
		if ( class_exists( 'ANPA_Socios_Curso_Activo' ) ) {
			$activo = ANPA_Socios_Curso_Activo::get();
			if ( null !== $activo ) {
				return $activo;
			}
		}

		return ANPA_Socios_Curso_Escolar::current();
	}

	/**
	 * Returns the full anpa_niveis table name.
	 *
	 * @since  1.27.0
	 * @return string
	 */
	public static function tabela_niveis(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_niveis';
	}

	/**
	 * Returns the full anpa_aulas table name.
	 *
	 * @since  1.27.0
	 * @return string
	 */
	public static function tabela_aulas(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_aulas';
	}

	/**
	 * Returns the full annual meal-schedules table name.
	 *
	 * @since  1.44.0
	 * @return string
	 */
	public static function tabela_horarios_comedor(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_horarios_comedor';
	}

	/**
	 * Returns the per-course level→comedor pivot table name (fase31).
	 *
	 * A global level (wp_anpa_niveis) can carry a different comedor schedule per
	 * curso_escolar through this pivot, since 1.36.0.
	 *
	 * @since  1.46.0
	 * @return string
	 */
	public static function tabela_niveis_curso(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_niveis_curso';
	}

	/**
	 * Returns the full anpa_grupos_niveis table name.
	 *
	 * @since  1.27.0
	 * @return string
	 */
	public static function tabela_grupos_niveis(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_grupos_niveis';
	}

	/**
	 * Returns the full anpa_grupos_curriculares table name (fase24).
	 *
	 * @since  1.28.0
	 * @return string
	 */
	public static function tabela_grupos_curriculares(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_grupos_curriculares';
	}

	/**
	 * Returns the full anpa_grupos_curriculares_niveis table name (fase24).
	 *
	 * @since  1.28.0
	 * @return string
	 */
	public static function tabela_grupos_curriculares_niveis(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_grupos_curriculares_niveis';
	}

	/**
	 * Returns the full anpa_actividades_cursos_grupos_curriculares table name (fase24).
	 *
	 * @since  1.28.0
	 * @return string
	 */
	public static function tabela_actividades_cursos_grupos_curriculares(): string {
		global $wpdb;

		return $wpdb->prefix . 'anpa_actividades_cursos_grupos_curriculares';
	}

	/**
	 * Lists curricular groups for a curso_escolar, with their nivel ids.
	 *
	 * @since  1.28.0
	 * @param  string $curso_escolar   Course school year.
	 * @param  bool   $include_inactive Include inactive groups.
	 * @return array[] Rows with keys id, curso_escolar, etiqueta, orde,
	 *                 franxa_manha, franxa_tarde, estado, nivel_ids (int[]).
	 */
	public static function get_grupos_curriculares( string $curso_escolar, bool $include_inactive = false ): array {
		global $wpdb;

		$gc     = self::tabela_grupos_curriculares();
		$gc_niv = self::tabela_grupos_curriculares_niveis();
		$estado = $include_inactive ? '' : " AND estado = 'activo'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $estado is a constant literal, curso is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, curso_escolar, etiqueta, orde, franxa_manha, franxa_tarde, estado
			 FROM {$gc} WHERE curso_escolar = %s{$estado} ORDER BY orde ASC, etiqueta ASC",
			$curso_escolar
		), ARRAY_A );
		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		$ids = array_map( static function ( $r ) { return (int) $r['id']; }, $rows );
		$in  = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints from the trusted query above.
		$niv_rows = $wpdb->get_results(
			"SELECT grupo_curricular_id, nivel_id FROM {$gc_niv} WHERE grupo_curricular_id IN ({$in})",
			ARRAY_A
		);
		$niveis_by_gc = array();
		if ( is_array( $niv_rows ) ) {
			foreach ( $niv_rows as $nr ) {
				$niveis_by_gc[ (int) $nr['grupo_curricular_id'] ][] = (int) $nr['nivel_id'];
			}
		}

		foreach ( $rows as &$row ) {
			$row['nivel_ids'] = $niveis_by_gc[ (int) $row['id'] ] ?? array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Returns a single curricular group with its nivel ids, or null.
	 *
	 * @since  1.28.0
	 * @param  int $id Curricular group id.
	 * @return array|null
	 */
	public static function get_grupo_curricular( int $id ): ?array {
		global $wpdb;

		$gc = self::tabela_grupos_curriculares();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit read helper.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, curso_escolar, etiqueta, orde, franxa_manha, franxa_tarde, estado FROM {$gc} WHERE id = %d",
			$id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$gc_niv = self::tabela_grupos_curriculares_niveis();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit read helper.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT nivel_id FROM {$gc_niv} WHERE grupo_curricular_id = %d",
			$id
		) );
		$row['nivel_ids'] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

		return $row;
	}

	/**
	 * Whether a curricular group is referenced by a yearly offer or a group.
	 *
	 * @since  1.28.0
	 * @param  int $id Curricular group id.
	 * @return bool
	 */
	public static function grupo_curricular_in_use( int $id ): bool {
		global $wpdb;

		$acy_gc = self::tabela_actividades_cursos_grupos_curriculares();
		$grupos = self::tabela_grupos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit read helper.
		$in_offers = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$acy_gc} WHERE grupo_curricular_id = %d",
			$id
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit read helper.
		$in_groups = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$grupos} WHERE grupo_curricular_id = %d",
			$id
		) );

		return ( $in_offers + $in_groups ) > 0;
	}

	/**
	 * Inserts a single grupo↔nivel relationship. Idempotent (INSERT IGNORE).
	 *
	 * @since  1.27.0
	 * @param  int $grupo_id Grupo id.
	 * @param  int $nivel_id Nivel id.
	 * @return bool True on success.
	 */
	public static function insert_grupo_nivel( int $grupo_id, int $nivel_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}anpa_grupos_niveis (grupo_id, nivel_id) VALUES (%d, %d)",
			$grupo_id,
			$nivel_id
		) );

		return false !== $result;
	}

	/**
	 * Gets all active global niveis, ordered by orde.
	 *
	 * @since  1.28.0
	 * @param  string $curso_escolar Deprecated — ignored (levels are global since 1.35.0).
	 * @return array[] Array of nivel rows (id, codigo, etiqueta, orde, estado).
	 */
	public static function get_niveis_for_curso( string $curso_escolar = '' ): array {
		global $wpdb;
		$table = self::tabela_niveis();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read helper.
		$results = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde, estado FROM {$table} WHERE estado = 'activo' ORDER BY orde ASC",
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Gets all global niveis (active + inactive), ordered by orde.
	 *
	 * @since  1.45.0
	 * @return array[] Array of nivel rows (id, codigo, etiqueta, orde, estado, habilitado).
	 */
	public static function get_niveis(): array {
		global $wpdb;
		$table = self::tabela_niveis();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read helper.
		$results = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde, estado, habilitado FROM {$table} ORDER BY orde ASC",
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Gets only active and enabled global niveis (for dropdowns/selectors).
	 *
	 * @since  1.45.0
	 * @return array[] Array of nivel rows (id, codigo, etiqueta, orde).
	 */
	public static function get_niveis_habilitados(): array {
		global $wpdb;
		$table = self::tabela_niveis();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read helper.
		$results = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde FROM {$table} WHERE estado = 'activo' AND habilitado = 1 ORDER BY orde ASC",
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Checks if any fillo has this nivel assigned in any curso_escolar.
	 *
	 * @since  1.45.0
	 * @param  int $nivel_id Nivel id to check.
	 * @return int Number of children assigned (0 = safe to disable).
	 */
	public static function nivel_has_alumnos( int $nivel_id ): int {
		global $wpdb;
		$fc_t = self::tabela_fillos_cursos();

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$fc_t} WHERE nivel_id = %d",
			$nivel_id
		) );

		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Toggles the habilitado flag of a nivel.
	 *
	 * @since  1.45.0
	 * @param  int  $nivel_id  Nivel id.
	 * @param  bool $habilitado New value.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function toggle_nivel( int $nivel_id, bool $habilitado ) {
		global $wpdb;
		$table = self::tabela_niveis();

		if ( $nivel_id < 1 ) {
			return new \WP_Error( 'anpa_nivel_invalid', __( 'ID de nivel inválido.', 'anpa-socios' ) );
		}

		// Cannot disable if has children.
		if ( ! $habilitado && self::nivel_has_alumnos( $nivel_id ) > 0 ) {
			return new \WP_Error(
				'anpa_nivel_has_children',
				__( 'Non se pode deshabilitar un nivel que ten alumnos asociados.', 'anpa-socios' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$result = $wpdb->update(
			$table,
			array( 'habilitado' => $habilitado ? 1 : 0 ),
			array( 'id' => $nivel_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Renames a nivel (changes etiqueta).
	 *
	 * @since  1.45.0
	 * @param  int    $nivel_id Nivel id.
	 * @param  string $etiqueta New label.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function rename_nivel( int $nivel_id, string $etiqueta ) {
		global $wpdb;
		$table = self::tabela_niveis();

		$etiqueta = trim( $etiqueta );
		if ( $nivel_id < 1 || '' === $etiqueta ) {
			return new \WP_Error( 'anpa_nivel_invalid', __( 'Datos inválidos.', 'anpa-socios' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$result = $wpdb->update(
			$table,
			array( 'etiqueta' => $etiqueta ),
			array( 'id' => $nivel_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Resolves a nivel/aula text-code pair to their (id) foreign keys.
	 *
	 * Since 1.35.0 niveis are global (no curso_escolar). The lookup is by
	 * codigo only. Returns null|null if not found.
	 *
	 * @since  1.41.0
	 * @param  string $curso_escolar Deprecated — ignored.
	 * @param  string $curso         Nivel codigo, e.g. "3".
	 * @param  string $aula          Aula codigo, e.g. "B".
	 * @return array{0: int|null, 1: int|null} [nivel_id, aula_id].
	 */
	private static function resolve_nivel_aula_ids( string $curso_escolar, string $curso, string $aula ): array {
		global $wpdb;

		$niveis_t = self::tabela_niveis();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- scoped read helper.
		$nivel_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$niveis_t} WHERE codigo = %s LIMIT 1",
			$curso
		) );

		if ( null === $nivel_id ) {
			return array( null, null );
		}

		$nivel_id = (int) $nivel_id;
		$aulas_t  = self::tabela_aulas();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- scoped read helper.
		$aula_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$aulas_t} WHERE nivel_id = %d AND codigo = %s LIMIT 1",
			$nivel_id,
			$aula
		) );

		return array( $nivel_id, null === $aula_id ? null : (int) $aula_id );
	}

	/**
	 * Gets all aulas for given nivel IDs, ordered by `orde`.
	 *
	 * @since  1.28.0
	 * @param  int[] $nivel_ids Array of nivel IDs.
	 * @return array[] Array of aula rows (id, codigo, etiqueta, orde, nivel_id, estado).
	 */
	public static function get_aulas_for_niveis( array $nivel_ids ): array {
		global $wpdb;

		if ( empty( $nivel_ids ) ) {
			return array();
		}

		$table = self::tabela_aulas();
		$ids   = implode( ',', array_map( 'intval', $nivel_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read helper.
		$results = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde, nivel_id, estado FROM {$table} WHERE nivel_id IN ({$ids}) AND estado = 'activo' ORDER BY orde ASC",
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Inserts multiple grupo↔nivel relationships in one batch. Idempotent.
	 *
	 * @since  1.27.0
	 * @param  int   $grupo_id  Grupo id.
	 * @param  int[] $nivel_ids Array of nivel ids.
	 * @return bool True on success.
	 */
	public static function insert_grupo_niveis( int $grupo_id, array $nivel_ids ): bool {
		global $wpdb;

		if ( array() === $nivel_ids ) {
			return true;
		}

		$values = array();
		$params = array();
		foreach ( $nivel_ids as $nid ) {
			$nid = (int) $nid;
			if ( $nid < 1 ) {
				continue;
			}
			$values[] = '(%d, %d)';
			$params[] = $grupo_id;
			$params[] = $nid;
		}

		if ( array() === $values ) {
			return true;
		}

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}anpa_grupos_niveis (grupo_id, nivel_id) VALUES " . implode( ',', $values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		return false !== $wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Deletes all grupo↔nivel relationships for a given grupo.
	 *
	 * @since  1.27.0
	 * @param  int $grupo_id Grupo id.
	 * @return bool True on success.
	 */
	public static function delete_grupo_niveis( int $grupo_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$result = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}anpa_grupos_niveis WHERE grupo_id = %d",
			$grupo_id
		) );

		return false !== $result;
	}

	/**
	 * Returns nivel_ids for a given grupo.
	 *
	 * @since  1.27.0
	 * @param  int $grupo_id Grupo id.
	 * @return int[]
	 */
	public static function get_niveis_for_grupo( int $grupo_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT nivel_id FROM {$wpdb->prefix}anpa_grupos_niveis WHERE grupo_id = %d",
			$grupo_id
		) );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Whether every given nivel id exists and is active.
	 *
	 * Since 1.35.0 niveis are global. This checks existence only.
	 *
	 * @since  1.39.1
	 * @param  int[]  $nivel_ids     Candidate nivel ids.
	 * @param  string $curso_escolar Deprecated — ignored.
	 * @return bool
	 */
	public static function niveis_belong_to_curso( array $nivel_ids, string $curso_escolar = '' ): bool {
		global $wpdb;

		$nivel_ids = array_values( array_unique( array_filter( array_map( 'intval', $nivel_ids ), static function ( $v ) {
			return $v > 0;
		} ) ) );
		if ( array() === $nivel_ids ) {
			return false;
		}

		$table        = self::tabela_niveis();
		$placeholders = implode( ',', array_fill( 0, count( $nivel_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$matched = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders}) AND estado = 'activo'",
			$nivel_ids
		) );

		return $matched === count( $nivel_ids );
	}

	/**
	 * Returns the `orde` value for each given nivel id.
	 *
	 * Used to compare a grupo's niveis against an activity+year's configured
	 * nivel_min_id/nivel_max_id range: the comparison must be done by `orde`
	 * (not by id or codigo), since nivel codigos are arbitrary strings and
	 * ids are not guaranteed to be sequential across curso_escolar rows.
	 *
	 * @since  1.40.0
	 * @param  int[] $nivel_ids Candidate nivel ids.
	 * @return array<int,int> Map of nivel_id => orde, for ids that exist.
	 */
	public static function get_niveis_ordes( array $nivel_ids ): array {
		global $wpdb;

		$nivel_ids = array_values( array_unique( array_filter( array_map( 'intval', $nivel_ids ), static function ( $v ) {
			return $v > 0;
		} ) ) );
		if ( array() === $nivel_ids ) {
			return array();
		}

		$table        = self::tabela_niveis();
		$placeholders = implode( ',', array_fill( 0, count( $nivel_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit CRUD helper.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, orde FROM {$table} WHERE id IN ({$placeholders})",
			$nivel_ids
		), ARRAY_A );

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$map[ (int) $r['id'] ] = (int) $r['orde'];
			}
		}

		return $map;
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
	 * Returns whether the given index exists on the given (full) table name.
	 *
	 * @since  1.31.0
	 * @param  string $table Full table name.
	 * @param  string $index Index name.
	 * @return bool
	 */
	private static function tem_indice( string $table, string $index ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = %s",
				$wpdb->dbname,
				$table,
				$index
			)
		);

		return (int) $found > 0;
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
	 * Migration to 1.27.0: parametrizable school structure.
	 *
	 * Creates {niveis, aulas, grupos_niveis} tables, widens legacy columns,
	 * and backfills existing data into the new structure. Idempotent via
	 * INSERT IGNORE, guarded ALTER TABLE, and a transaction that rolls back
	 * any partial state on failure.
	 *
	 * @since  1.27.0
	 * @return bool Whether the migration completed.
	 */
	private static function migrate_to_1_27_0(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── Step 1: Create new tables (idempotent via dbDelta) ─────────
		$niveis = self::tabela_niveis();
		dbDelta( "CREATE TABLE {$niveis} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			curso_escolar varchar(9) NOT NULL,
			codigo varchar(30) NOT NULL,
			etiqueta varchar(60) NOT NULL,
			orde smallint(5) unsigned NOT NULL,
			comedor_inicio char(5) NULL DEFAULT NULL,
			comedor_fin char(5) NULL DEFAULT NULL,
			horario_comedor_id bigint(20) unsigned NULL DEFAULT NULL,
			estado enum('activo','inactivo') NOT NULL DEFAULT 'activo',
			creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY curso_nivel (curso_escolar, codigo),
			INDEX curso_estado_orde (curso_escolar, estado, orde),
			INDEX horario_comedor_id (horario_comedor_id),
			PRIMARY KEY  (id)
		) {$charset_collate};" );

		$aulas = self::tabela_aulas();
		dbDelta( "CREATE TABLE {$aulas} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nivel_id bigint(20) unsigned NOT NULL,
			codigo varchar(20) NOT NULL,
			etiqueta varchar(60) NOT NULL,
			orde smallint(5) unsigned NOT NULL,
			estado enum('activo','inactivo') NOT NULL DEFAULT 'activo',
			creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY nivel_aula (nivel_id, codigo),
			INDEX nivel_estado_orde (nivel_id, estado, orde),
			PRIMARY KEY  (id)
		) {$charset_collate};" );

		$grupos_niveis = self::tabela_grupos_niveis();
		dbDelta( "CREATE TABLE {$grupos_niveis} (
			grupo_id bigint(20) unsigned NOT NULL,
			nivel_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (grupo_id, nivel_id),
			INDEX nivel_id (nivel_id)
		) {$charset_collate};" );

		// ── Step 2: ALTER existing tables (guarded, idempotent) ────────
		$fillos_cursos = self::tabela_fillos_cursos();

		if ( ! self::tem_columna( $fillos_cursos, 'nivel_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$fillos_cursos} ADD COLUMN nivel_id bigint(20) unsigned NULL DEFAULT NULL AFTER aula, ADD KEY nivel_id (nivel_id)" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $fillos_cursos, 'aula_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$fillos_cursos} ADD COLUMN aula_id bigint(20) unsigned NULL DEFAULT NULL AFTER nivel_id, ADD KEY aula_id (aula_id)" ) ) {
				return false;
			}
		}

		// Widen curso enum → varchar(30).
		$col_info = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$fillos_cursos} LIKE %s", 'curso' ), ARRAY_A );
		$col_type = is_array( $col_info ) ? (string) ( $col_info['Type'] ?? '' ) : '';
		if ( false !== strpos( $col_type, 'enum' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$fillos_cursos} MODIFY COLUMN curso varchar(30) NOT NULL DEFAULT ''" ) ) {
				return false;
			}
		}

		// Widen aula enum → varchar(20).
		$col_info = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$fillos_cursos} LIKE %s", 'aula' ), ARRAY_A );
		$col_type = is_array( $col_info ) ? (string) ( $col_info['Type'] ?? '' ) : '';
		if ( false !== strpos( $col_type, 'enum' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$fillos_cursos} MODIFY COLUMN aula varchar(20) NOT NULL DEFAULT ''" ) ) {
				return false;
			}
		}

		// Convert grupos.curso_range enum → varchar(20).
		$grupos = self::tabela_grupos();
		$col_info = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$grupos} LIKE %s", 'curso_range' ), ARRAY_A );
		$col_type = is_array( $col_info ) ? (string) ( $col_info['Type'] ?? '' ) : '';
		if ( false !== strpos( $col_type, 'enum' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} MODIFY COLUMN curso_range varchar(20) NOT NULL DEFAULT ''" ) ) {
				return false;
			}
		}

		// Add nivel_min_id / nivel_max_id to actividades_cursos.
		$act_cursos = self::tabela_actividades_cursos();
		if ( ! self::tem_columna( $act_cursos, 'nivel_min_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$act_cursos} ADD COLUMN nivel_min_id bigint(20) unsigned NULL DEFAULT NULL AFTER grupos, ADD KEY nivel_min_id (nivel_min_id)" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $act_cursos, 'nivel_max_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$act_cursos} ADD COLUMN nivel_max_id bigint(20) unsigned NULL DEFAULT NULL AFTER nivel_min_id, ADD KEY nivel_max_id (nivel_max_id)" ) ) {
				return false;
			}
		}

		// ── Step 3: Backfill structure from existing cursos ────────────
		// Create levels 1..6 and classrooms A..aula_max for every existing
		// curso_escolar in anpa_cursos. INSERT IGNORE guarantees idempotence.
		$cursos_t = self::tabela_cursos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
		$existing = $wpdb->get_col( "SELECT DISTINCT curso_escolar FROM {$cursos_t} ORDER BY curso_escolar" );
		// Historical direct-upgrade bridge only. Runtime configuration no longer
		// exposes a global classroom limit once annual structure exists.
		$aula_max = strtoupper( trim( (string) get_option( 'anpa_socios_aula_max', 'D' ) ) );
		if ( 1 !== strlen( $aula_max ) || $aula_max < 'A' || $aula_max > 'H' ) {
			$aula_max = 'D';
		}

		if ( is_array( $existing ) ) {
			foreach ( $existing as $curso_escolar ) {
				// Levels 1..6.
				for ( $n = 1; $n <= 6; $n++ ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
					$wpdb->query( $wpdb->prepare(
						"INSERT IGNORE INTO {$niveis} (curso_escolar, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
						 VALUES (%s, %s, %s, %d, 'activo', NOW(), NOW())",
						$curso_escolar,
						(string) $n,
						$n . 'º',
						$n * 10
					) );
				}

				// Classrooms A..aula_max for each level.
				for ( $n = 1; $n <= 6; $n++ ) {
					$nivel_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$niveis} WHERE curso_escolar = %s AND codigo = %s",
						$curso_escolar,
						(string) $n
					) );
					if ( null === $nivel_id || ! $nivel_id ) {
						continue;
					}
					$letters = range( 'A', $aula_max );
					foreach ( $letters as $letter ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
						$wpdb->query( $wpdb->prepare(
							"INSERT IGNORE INTO {$aulas} (nivel_id, codigo, etiqueta, orde, estado, creado_en, actualizado_en)
							 VALUES (%d, %s, %s, %d, 'activo', NOW(), NOW())",
							(int) $nivel_id,
							$letter,
							$letter,
							( ord( $letter ) - 64 ) * 10
						) );
					}
				}
			}
		}

		// ── Step 4: Map existing fillos_cursos to nivel_id/aula_id ─────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- safe read for backfill.
		$fc_rows = $wpdb->get_results( "SELECT id, curso_escolar, curso, aula FROM {$fillos_cursos}", ARRAY_A );
		if ( is_array( $fc_rows ) ) {
			foreach ( $fc_rows as $fc ) {
				if ( ! empty( $fc['curso'] ) ) {
					$nivel_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT n.id FROM {$niveis} n WHERE n.curso_escolar = %s AND n.codigo = %s LIMIT 1",
						$fc['curso_escolar'],
						trim( $fc['curso'] )
					) );
					if ( $nivel_id && ! empty( $fc['aula'] ) ) {
						$aula_id = $wpdb->get_var( $wpdb->prepare(
							"SELECT a.id FROM {$aulas} a INNER JOIN {$niveis} n ON n.id = a.nivel_id
							 WHERE n.curso_escolar = %s AND n.codigo = %s AND a.codigo = %s LIMIT 1",
							$fc['curso_escolar'],
							trim( $fc['curso'] ),
							trim( $fc['aula'] )
						) );
						if ( $aula_id ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
							$wpdb->query( $wpdb->prepare(
								"UPDATE {$fillos_cursos} SET nivel_id = %d, aula_id = %d WHERE id = %d",
								(int) $nivel_id,
								(int) $aula_id,
								(int) $fc['id']
							) );
						}
					}
				}
			}
		}

		// ── Step 5: Map legacy grupos.curso_range to grupos_niveis ─────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- safe read for backfill.
		$legacy_grupos = $wpdb->get_results( "SELECT id, curso_range FROM {$grupos}", ARRAY_A );
		if ( is_array( $legacy_grupos ) ) {
			foreach ( $legacy_grupos as $g ) {
				$range = trim( (string) $g['curso_range'] );
				$codes = array();
				if ( '1-2-3' === $range || '4-5-6' === $range ) {
					$codes = explode( '-', $range );
				}
				if ( array() === $codes ) {
					continue;
				}
				// Find any nivel with matching code (cross-course — best-effort).
				foreach ( $codes as $code ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
					$wpdb->query( $wpdb->prepare(
						"INSERT IGNORE INTO {$grupos_niveis} (grupo_id, nivel_id)
						 SELECT %d, id FROM {$niveis} WHERE codigo = %s",
						(int) $g['id'],
						$code
					) );
				}
			}
		}

		return true;
	}

	/**
	 * Migration to 1.28.0 (fase24): curricular groups.
	 *
	 * Creates {grupos_curriculares, grupos_curriculares_niveis,
	 * actividades_cursos_grupos_curriculares} tables, adds the exclusive
	 * `actividades_cursos.horario` column and `grupos.grupo_curricular_id`,
	 * and backfills curricular groups from legacy `grupos.curso_range` plus
	 * `actividades.horarios` franxas.
	 *
	 * NON-DESTRUCTIVE by design: legacy columns (`curso_range`, `franxa`,
	 * `curso_min/max`, `grupos`, `nivel_min/max_id`) and the `grupos_niveis`
	 * table are NOT removed here — that physical retirement happens in a later
	 * migration gated on a verified backup (fase24 PR-GC7). The backfill is
	 * best-effort and never halts the migration: rows whose horario cannot be
	 * unambiguously inferred keep `horario = NULL` and are resolved by the
	 * admin on the next edit (the write-path validation requires a non-null
	 * horario). This avoids bricking the migration chain on ambiguous legacy
	 * data.
	 *
	 * Idempotent via INSERT IGNORE and guarded ALTER TABLE.
	 *
	 * @since  1.28.0
	 * @return bool Whether the migration completed without a hard DB error.
	 */
	private static function migrate_to_1_28_0(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$gc      = self::tabela_grupos_curriculares();
		$gc_niv  = self::tabela_grupos_curriculares_niveis();
		$acy_gc  = self::tabela_actividades_cursos_grupos_curriculares();
		$grupos  = self::tabela_grupos();
		$act_cur = self::tabela_actividades_cursos();
		$niveis  = self::tabela_niveis();

		// ── Step 1: Create new tables ──────────────────────────────────
		dbDelta( "CREATE TABLE {$gc} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			curso_escolar varchar(9) NOT NULL,
			etiqueta varchar(60) NOT NULL,
			orde smallint(5) unsigned NOT NULL DEFAULT 10,
			franxa_manha varchar(20) NOT NULL DEFAULT '',
			franxa_tarde varchar(20) NOT NULL DEFAULT '',
			estado enum('activo','inactivo') NOT NULL DEFAULT 'activo',
			creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY curso_etiqueta (curso_escolar, etiqueta),
			INDEX curso_estado_orde (curso_escolar, estado, orde),
			PRIMARY KEY  (id)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$gc_niv} (
			grupo_curricular_id bigint(20) unsigned NOT NULL,
			nivel_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (grupo_curricular_id, nivel_id),
			INDEX nivel_id (nivel_id)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$acy_gc} (
			actividad_curso_id bigint(20) unsigned NOT NULL,
			grupo_curricular_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (actividad_curso_id, grupo_curricular_id),
			INDEX grupo_curricular_id (grupo_curricular_id)
		) {$charset_collate};" );

		// ── Step 2: Guarded ALTER of existing tables ───────────────────
		if ( ! self::tem_columna( $act_cur, 'horario' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$act_cur} ADD COLUMN horario enum('manha','tarde') NULL DEFAULT NULL AFTER curso_escolar" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $grupos, 'grupo_curricular_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} ADD COLUMN grupo_curricular_id bigint(20) unsigned NULL DEFAULT NULL AFTER curso_escolar, ADD KEY grupo_curricular_id (grupo_curricular_id)" ) ) {
				return false;
			}
		}

		// ── Step 3: Backfill actividades_cursos.horario (best-effort) ──
		// manha-only → 'manha'; tarde-only → 'tarde'; both/neither → leave NULL
		// (admin resolves on next edit). horarios is a CSV token set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent best-effort backfill.
		$wpdb->query(
			"UPDATE {$act_cur} SET horario = 'manha'
			 WHERE horario IS NULL AND horarios LIKE '%manha%' AND horarios NOT LIKE '%tarde%'"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent best-effort backfill.
		$wpdb->query(
			"UPDATE {$act_cur} SET horario = 'tarde'
			 WHERE horario IS NULL AND horarios LIKE '%tarde%' AND horarios NOT LIKE '%manha%'"
		);

		// ── Step 4: Backfill curricular groups from legacy curso_range ─
		// For each (curso_escolar, curso_range) present in grupos, create one
		// curricular group, link its niveis, and set franxa_manha/tarde from a
		// representative group's franxa according to the parent activity's
		// horario.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- safe read for backfill.
		$combos = $wpdb->get_results(
			"SELECT DISTINCT g.curso_escolar AS curso_escolar, g.curso_range AS curso_range
			 FROM {$grupos} g
			 WHERE g.curso_range IN ('1-2-3','4-5-6') AND g.curso_escolar <> ''",
			ARRAY_A
		);
		if ( is_array( $combos ) ) {
			foreach ( $combos as $combo ) {
				$curso_escolar = (string) $combo['curso_escolar'];
				$range         = (string) $combo['curso_range'];
				$codes         = explode( '-', $range );
				$etiqueta      = implode( 'º-', $codes ) . 'º'; // '1-2-3' → '1º-2º-3º'

				// Representative franxa per horario for this combo.
				$franxa_manha = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT g.franxa FROM {$grupos} g
					 INNER JOIN " . self::tabela_actividades() . " a ON a.id = g.actividad_id
					 WHERE g.curso_escolar = %s AND g.curso_range = %s AND g.franxa <> ''
					   AND a.horarios LIKE '%manha%'
					 ORDER BY g.id ASC LIMIT 1",
					$curso_escolar,
					$range
				) );
				$franxa_tarde = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT g.franxa FROM {$grupos} g
					 INNER JOIN " . self::tabela_actividades() . " a ON a.id = g.actividad_id
					 WHERE g.curso_escolar = %s AND g.curso_range = %s AND g.franxa <> ''
					   AND a.horarios LIKE '%tarde%'
					 ORDER BY g.id ASC LIMIT 1",
					$curso_escolar,
					$range
				) );

				// A curricular group needs at least one franxa; if neither
				// resolved, fall back to any non-empty franxa as tarde so the
				// group is still selectable and the admin can correct it.
				if ( '' === $franxa_manha && '' === $franxa_tarde ) {
					$franxa_tarde = (string) $wpdb->get_var( $wpdb->prepare(
						"SELECT franxa FROM {$grupos} WHERE curso_escolar = %s AND curso_range = %s AND franxa <> '' ORDER BY id ASC LIMIT 1",
						$curso_escolar,
						$range
					) );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$gc} (curso_escolar, etiqueta, orde, franxa_manha, franxa_tarde, estado, creado_en, actualizado_en)
					 VALUES (%s, %s, %d, %s, %s, 'activo', NOW(), NOW())",
					$curso_escolar,
					$etiqueta,
					'1-2-3' === $range ? 10 : 20,
					$franxa_manha,
					$franxa_tarde
				) );

				$gc_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$gc} WHERE curso_escolar = %s AND etiqueta = %s LIMIT 1",
					$curso_escolar,
					$etiqueta
				) );
				if ( $gc_id <= 0 ) {
					continue;
				}

				// Link niveis of this curso_escolar matching the range codes.
				foreach ( $codes as $code ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
					$wpdb->query( $wpdb->prepare(
						"INSERT IGNORE INTO {$gc_niv} (grupo_curricular_id, nivel_id)
						 SELECT %d, id FROM {$niveis} WHERE curso_escolar = %s AND codigo = %s",
						$gc_id,
						$curso_escolar,
						$code
					) );
				}

				// Point every legacy group of this combo at the curricular group.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$grupos} SET grupo_curricular_id = %d
					 WHERE curso_escolar = %s AND curso_range = %s AND grupo_curricular_id IS NULL",
					$gc_id,
					$curso_escolar,
					$range
				) );

				// Link the curricular group to every actividades_cursos offer
				// of the activities that own those legacy groups.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data backfill.
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$acy_gc} (actividad_curso_id, grupo_curricular_id)
					 SELECT DISTINCT ac.id, %d
					 FROM {$act_cur} ac
					 INNER JOIN {$grupos} g ON g.actividad_id = ac.actividad_id AND g.curso_escolar = ac.curso_escolar
					 WHERE ac.curso_escolar = %s AND g.curso_range = %s",
					$gc_id,
					$curso_escolar,
					$range
				) );
			}
		}

		return true;
	}

	/**
	 * Migration 1.29.0: activity-owned multi-year group series.
	 *
	 * Additive by design. Destructive removal of fase24-global and duplicated
	 * activity columns is deferred to the migration rollout gate (1.31.0).
	 *
	 * @since  1.42.0
	 * @return bool
	 */
	private static function migrate_to_1_29_0(): bool {
		global $wpdb;

		$grupos = self::tabela_grupos();
		$acts   = self::tabela_actividades();

		if ( ! self::tem_columna( $grupos, 'serie_uid' ) ) {
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} ADD COLUMN serie_uid char(36) NULL DEFAULT NULL AFTER curso_escolar, ADD KEY serie_uid (serie_uid)" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $grupos, 'nome' ) ) {
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} ADD COLUMN nome varchar(80) NOT NULL DEFAULT '' AFTER serie_uid" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $grupos, 'horario' ) ) {
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} ADD COLUMN horario enum('manha','tarde') NULL DEFAULT NULL AFTER nome" ) ) {
				return false;
			}
		}

		// One independent series per legacy annual row. Never merge by similar
		// labels/time slots: that could silently combine unrelated enrolments.
		if ( false === $wpdb->query( "UPDATE {$grupos} SET serie_uid = UUID() WHERE serie_uid IS NULL OR serie_uid = ''" ) ) {
			return false;
		}
		if ( false === $wpdb->query(
			"UPDATE {$grupos}
			 SET nome = CASE
			   WHEN curso_range <> '' THEN CONCAT('Grupo ', curso_range)
			   ELSE CONCAT('Grupo ', id)
			 END
			 WHERE nome = ''"
		) ) {
			return false;
		}

		// Infer only an unambiguous legacy horario. Ambiguous rows remain NULL
		// and must be resolved explicitly through the group editor.
		if ( false === $wpdb->query(
			"UPDATE {$grupos} g
			 INNER JOIN {$acts} a ON a.id = g.actividad_id
			 SET g.horario = CASE
			   WHEN FIND_IN_SET('manha', a.horarios) > 0 AND FIND_IN_SET('tarde', a.horarios) = 0 THEN 'manha'
			   WHEN FIND_IN_SET('tarde', a.horarios) > 0 AND FIND_IN_SET('manha', a.horarios) = 0 THEN 'tarde'
			   ELSE g.horario
			 END
			 WHERE g.horario IS NULL"
		) ) {
			return false;
		}

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Migration 1.30.0: support Mañá, Comedor and Tarde on annual groups.
	 *
	 * The previous enum only accepted `manha` and `tarde`; saving `maña`
	 * therefore produced an empty value in non-strict MySQL installations.
	 * The guarded ALTER is idempotent and preserves all existing values.
	 *
	 * @since  1.43.0
	 * @return bool Whether the schema accepts all three horario values.
	 */
	private static function migrate_to_1_30_0(): bool {
		global $wpdb;

		$grupos = self::tabela_grupos();
		$column = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$grupos} LIKE %s", 'horario' ),
			ARRAY_A
		);
		$type = is_array( $column ) ? (string) ( $column['Type'] ?? '' ) : '';
		if ( false !== strpos( $type, "'maña'" ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded schema migration.
		return false !== $wpdb->query(
			"ALTER TABLE {$grupos} MODIFY COLUMN horario enum('maña','manha','tarde') NULL DEFAULT NULL"
		);
	}

	/**
	 * Migration 1.31.0: retire phase24 curricular globals and legacy activity
	 * columns once their data has been promoted to the yearly tables.
	 *
	 * Idempotent: each destructive step is guarded before it runs, and the
	 * method only returns true after the postconditions confirm the tables and
	 * columns are actually gone.
	 *
	 * @since  1.31.0
	 * @return bool Whether the retirement completed cleanly.
	 */
	private static function migrate_to_1_31_0(): bool {
		global $wpdb;

		$gc         = self::tabela_grupos_curriculares();
		$gc_niv     = self::tabela_grupos_curriculares_niveis();
		$acy_gc     = self::tabela_actividades_cursos_grupos_curriculares();
		$grupos     = self::tabela_grupos();
		$act_cursos = self::tabela_actividades_cursos();
		$actividades = self::tabela_actividades();

		foreach ( array( $acy_gc, $gc_niv, $gc ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded retirement DDL.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			if ( '' !== (string) $wpdb->last_error ) {
				return false;
			}
		}

		if ( self::tem_indice( $grupos, 'grupo_curricular_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded retirement DDL.
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} DROP INDEX grupo_curricular_id" ) ) {
				return false;
			}
		}
		if ( self::tem_columna( $grupos, 'grupo_curricular_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded retirement DDL.
			if ( false === $wpdb->query( "ALTER TABLE {$grupos} DROP COLUMN grupo_curricular_id" ) ) {
				return false;
			}
		}

		if ( self::tem_columna( $act_cursos, 'horario' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded retirement DDL.
			if ( false === $wpdb->query( "ALTER TABLE {$act_cursos} DROP COLUMN horario" ) ) {
				return false;
			}
		}

		foreach ( array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ) as $column ) {
			if ( ! self::tem_columna( $actividades, $column ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded retirement DDL.
			if ( false === $wpdb->query( "ALTER TABLE {$actividades} DROP COLUMN {$column}" ) ) {
				return false;
			}
		}

		$postconditions = array(
			self::table_missing( $gc ),
			self::table_missing( $gc_niv ),
			self::table_missing( $acy_gc ),
			! self::tem_columna( $grupos, 'grupo_curricular_id' ),
			! self::tem_columna( $act_cursos, 'horario' ),
			! self::tem_columna( $actividades, 'min_pupilos' ),
			! self::tem_columna( $actividades, 'max_pupilos' ),
			! self::tem_columna( $actividades, 'curso_min' ),
			! self::tem_columna( $actividades, 'curso_max' ),
			self::tem_columna( $actividades, 'curso_escolar' ),
		);
		foreach ( $postconditions as $ok ) {
			if ( ! $ok ) {
				$wpdb->last_error = '1.31.0 retirement postcondition failed';
				return false;
			}
		}

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Migration 1.32.0: annual meal window per school level.
	 *
	 * Additive and retry-safe. Both fields remain NULL when no meal block is
	 * configured; validation of complete ordered pairs lives in the write path.
	 *
	 * @since  1.32.0
	 * @return bool Whether both columns exist after the migration.
	 */
	private static function migrate_to_1_32_0(): bool {
		global $wpdb;

		$niveis = self::tabela_niveis();
		if ( ! self::tem_columna( $niveis, 'comedor_inicio' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded additive migration.
			if ( false === $wpdb->query( "ALTER TABLE {$niveis} ADD COLUMN comedor_inicio char(5) NULL DEFAULT NULL AFTER orde" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $niveis, 'comedor_fin' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded additive migration.
			if ( false === $wpdb->query( "ALTER TABLE {$niveis} ADD COLUMN comedor_fin char(5) NULL DEFAULT NULL AFTER comedor_inicio" ) ) {
				return false;
			}
		}

		if ( ! self::tem_columna( $niveis, 'comedor_inicio' ) || ! self::tem_columna( $niveis, 'comedor_fin' ) ) {
			$wpdb->last_error = '1.32.0 comedor postcondition failed';
			return false;
		}

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Migration 1.33.0: reusable annual meal schedules.
	 *
	 * Creates the annual schedule catalogue, links each level to at most one
	 * schedule and promotes complete 1.32.0 level windows without deleting the
	 * rollback bridge columns. The obsolete global classroom option is removed
	 * only after schema and backfill postconditions pass.
	 *
	 * @since  1.44.0
	 * @return bool Whether the migration completed and passed postconditions.
	 */
	private static function migrate_to_1_33_0(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$niveis         = self::tabela_niveis();
		$horarios       = self::tabela_horarios_comedor();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$horarios} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			curso_escolar varchar(9) NOT NULL,
			nome varchar(80) NOT NULL,
			inicio char(5) NOT NULL,
			fin char(5) NOT NULL,
			orde smallint(5) unsigned NOT NULL DEFAULT 10,
			estado enum('activo','inactivo') NOT NULL DEFAULT 'activo',
			creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY curso_franxa (curso_escolar, inicio, fin),
			INDEX curso_estado_orde (curso_escolar, estado, orde),
			PRIMARY KEY  (id)
		) {$charset_collate};" );
		if ( '' !== (string) $wpdb->last_error || self::table_missing( $horarios ) ) {
			return false;
		}

		if ( ! self::tem_columna( $niveis, 'horario_comedor_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded additive migration.
			if ( false === $wpdb->query( "ALTER TABLE {$niveis} ADD COLUMN horario_comedor_id bigint(20) unsigned NULL DEFAULT NULL AFTER comedor_fin, ADD KEY horario_comedor_id (horario_comedor_id)" ) ) {
				return false;
			}
		}

		if ( ! self::backfill_legacy_horarios_comedor() ) {
			return false;
		}

		if ( self::table_missing( $horarios ) || ! self::tem_columna( $niveis, 'horario_comedor_id' ) ) {
			$wpdb->last_error = '1.33.0 comedor postcondition failed';
			return false;
		}

		if ( false !== get_option( 'anpa_socios_aula_max', false ) && ! delete_option( 'anpa_socios_aula_max' ) ) {
			$wpdb->last_error = '1.33.0 aula_max option retirement failed';
			return false;
		}

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Migration 1.34.0: retire the redundant activity/course offer table.
	 *
	 * Retry-safe and fail-closed: the destructive DROP runs only when every
	 * legacy offer has a corresponding annual group with at least one level.
	 * If the table is already absent, the postcondition is already satisfied.
	 */
	private static function migrate_to_1_34_0(): bool {
		global $wpdb;

		$offers     = self::tabela_actividades_cursos();
		$activities = self::tabela_actividades();
		$grupos     = self::tabela_grupos();
		$relations  = self::tabela_grupos_niveis();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- destructive migration table preflight.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $offers ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( $offers !== $found ) {
			return true;
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- destructive migration semantic preflight.
		$conflicts = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$offers} ac
			 LEFT JOIN {$activities} a ON a.id = ac.actividad_id
			 WHERE a.id IS NULL OR ac.custo <> a.custo OR ac.estado <> a.estado"
		);
		if ( null === $conflicts || '' !== (string) $wpdb->last_error ) {
			$wpdb->last_error = '' !== (string) $wpdb->last_error ? $wpdb->last_error : '1.34.0 cost/state preflight failed';
			return false;
		}
		if ( (int) $conflicts > 0 ) {
			$wpdb->last_error = '1.34.0 blocked: divergent legacy activity cost or state';
			return false;
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- destructive migration representation preflight.
		$missing = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$offers} ac
			 WHERE NOT EXISTS (
			   SELECT 1
			   FROM {$grupos} g
			   INNER JOIN {$relations} gn ON gn.grupo_id = g.id
			   WHERE g.actividad_id = ac.actividad_id
			     AND g.curso_escolar = ac.curso_escolar
			 )
			 AND NOT (
			   COALESCE(ac.franxa, '') = ''
			   AND COALESCE(ac.horarios, '') = ''
			   AND COALESCE(ac.grupos, '') = ''
			   AND COALESCE(ac.dias, '') = ''
			   AND COALESCE(ac.min_pupilos, 0) = 0
			   AND COALESCE(ac.max_pupilos, 0) = 0
			   AND ac.nivel_min_id IS NULL
			   AND ac.nivel_max_id IS NULL
			 )"
		);
		if ( null === $missing || '' !== (string) $wpdb->last_error ) {
			$wpdb->last_error = '' !== (string) $wpdb->last_error ? $wpdb->last_error : '1.34.0 offer equivalence preflight failed';
			return false;
		}
		if ( (int) $missing > 0 ) {
			$wpdb->last_error = '1.34.0 blocked: material legacy activity offers without equivalent annual groups';
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded destructive migration.
		if ( false === $wpdb->query( "DROP TABLE {$offers}" ) ) {
			return false;
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- destructive migration postcondition.
		$remaining = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $offers ) ) );
		return '' === (string) $wpdb->last_error && $offers !== $remaining;
	}

	/**
	 * Promotes complete legacy per-level meal windows into the shared catalogue.
	 *
	 * Public for backup v1-v3 restore compatibility; idempotent and intended
	 * only after the 1.33.0 table and relation column exist.
	 *
	 * @since  1.44.0
	 * @return bool
	 */
	public static function backfill_legacy_horarios_comedor(): bool {
		global $wpdb;

		$niveis   = self::tabela_niveis();
		$horarios = self::tabela_horarios_comedor();

		// Post-1.37.0 the legacy per-level meal columns (comedor_inicio/fin,
		// horario_comedor_id) no longer exist; there is nothing to promote.
		// Guarding here keeps both the 1.33.0 migration re-runs and old-backup
		// restore safe once the columns are gone.
		if ( ! self::tem_columna( $niveis, 'comedor_inicio' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data promotion.
		if ( false === $wpdb->query(
			"INSERT INTO {$horarios} (curso_escolar, nome, inicio, fin, orde, estado, creado_en, actualizado_en)
			 SELECT curso_escolar,
			        CONCAT('Horario comedor ', comedor_inicio, '-', comedor_fin),
			        comedor_inicio, comedor_fin, MIN(orde), 'activo', NOW(), NOW()
			 FROM {$niveis}
			 WHERE comedor_inicio REGEXP '^[0-2][0-9]:[0-5][0-9]$'
			   AND comedor_fin REGEXP '^[0-2][0-9]:[0-5][0-9]$'
			   AND comedor_inicio < comedor_fin
			 GROUP BY curso_escolar, comedor_inicio, comedor_fin
			 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
		) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent data promotion.
		return false !== $wpdb->query(
			"UPDATE {$niveis} n
			 INNER JOIN {$horarios} h
			   ON h.curso_escolar = n.curso_escolar
			  AND h.inicio = n.comedor_inicio
			  AND h.fin = n.comedor_fin
			 SET n.horario_comedor_id = h.id
			 WHERE n.horario_comedor_id IS NULL"
		);
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

	/**
	 * Migrates niveis from per-curso-escolar to global (no curso_escolar).
	 *
	 * Strategy:
	 *  1. For each unique orde, keep the nivel from the most recent curso.
	 *  2. Remap fillos_cursos.nivel_id to the surviving global nivel.
	 *  3. Drop curso_escolar column and update indexes.
	 *  4. Add habilitado column.
	 *
	 * @since  1.45.0
	 * @return bool Whether the migration completed.
	 */
	private static function migrate_to_1_35_0(): bool {
		global $wpdb;

		$niveis_t = self::tabela_niveis();
		$fc_t     = self::tabela_fillos_cursos();

		// Guard: if curso_escolar column doesn't exist, migration already done.
		$wpdb->last_error = '';
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$niveis_t} LIKE 'curso_escolar'" );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( empty( $cols ) ) {
			// Already migrated (no curso_escolar column).
			return true;
		}

		// Step 1: Build mapping old_nivel_id → new_nivel_id by orde.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration read.
		$all = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde, curso_escolar
			   FROM {$niveis_t}
			  ORDER BY curso_escolar DESC, orde ASC, id ASC",
			ARRAY_A
		);
		if ( ! is_array( $all ) ) {
			return false;
		}

		// Group by orde, keep the first (most recent curso).
		$by_orde  = array();
		$to_keep  = array(); // orde => row.
		$old_ids  = array(); // nivel_ids to remove.
		foreach ( $all as $row ) {
			$orde = (int) $row['orde'];
			if ( ! isset( $to_keep[ $orde ] ) ) {
				$to_keep[ $orde ] = $row;
			} else {
				// Duplicate orde from older curso — will be remapped.
				$old_ids[] = (int) $row['id'];
			}
		}

		// Also collect the "keep" ids that will survive.
		$keep_ids = array();
		foreach ( $to_keep as $row ) {
			$keep_ids[] = (int) $row['id'];
		}

		// Build mapping: old_id → keep_id (by matching orde).
		$keep_by_orde = array();
		foreach ( $to_keep as $orde => $row ) {
			$keep_by_orde[ $orde ] = (int) $row['id'];
		}
		$mapping = array(); // old_id => new_id.
		foreach ( $all as $row ) {
			$old_id = (int) $row['id'];
			$orde   = (int) $row['orde'];
			if ( in_array( $old_id, $keep_ids, true ) ) {
				$mapping[ $old_id ] = $old_id; // maps to itself.
			} elseif ( isset( $keep_by_orde[ $orde ] ) ) {
				$mapping[ $old_id ] = $keep_by_orde[ $orde ];
			}
		}

		// Step 2: Remap fillos_cursos.nivel_id.
		$remapped = 0;
		foreach ( $mapping as $old_id => $new_id ) {
			if ( $old_id === $new_id ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration write.
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$fc_t} SET nivel_id = %d WHERE nivel_id = %d",
				$new_id,
				$old_id
			) );
			if ( false === $result ) {
				return false;
			}
			$remapped += (int) $result;
		}

		// Step 2b: Remap grupos_niveis.nivel_id (same mapping).
		$gn_t     = self::tabela_grupos_niveis();
		$gn_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gn_t ) );
		if ( $gn_table_exists ) {
			foreach ( $mapping as $old_id => $new_id ) {
				if ( $old_id === $new_id ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration write.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$gn_t} SET nivel_id = %d WHERE nivel_id = %d",
					$new_id,
					$old_id
				) );
			}
		}

		// Step 2c: Remap aulas.nivel_id (same mapping).
		$aulas_t  = self::tabela_aulas();
		$aulas_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aulas_t ) );
		if ( $aulas_exists ) {
			foreach ( $mapping as $old_id => $new_id ) {
				if ( $old_id === $new_id ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration write.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$aulas_t} SET nivel_id = %d WHERE nivel_id = %d",
					$new_id,
					$old_id
				) );
			}
		}

		// Step 3: Delete the duplicate nivel rows (the ones NOT in keep_ids).
		if ( ! empty( $old_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration delete.
			if ( false === $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$niveis_t} WHERE id IN ({$placeholders})",
				$old_ids
			) ) ) {
				return false;
			}
		}

		// Step 4: Drop curso_escolar column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
		$wpdb->query( "ALTER TABLE {$niveis_t} DROP COLUMN curso_escolar" );

		// Step 5: Add habilitado column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
		$wpdb->query( "ALTER TABLE {$niveis_t} ADD COLUMN habilitado tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER estado" );

		// Step 6: Update unique key — from (curso_escolar, codigo) to just (codigo).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
		$wpdb->query( "ALTER TABLE {$niveis_t} DROP INDEX curso_nivel" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
		$wpdb->query( "ALTER TABLE {$niveis_t} ADD UNIQUE KEY codigo_unico (codigo)" );

		// Step 7: Drop curso_escolar index, add a simple estado+orde index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
		$wpdb->query( "ALTER TABLE {$niveis_t} DROP INDEX curso_estado_orde" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
		$wpdb->query( "ALTER TABLE {$niveis_t} ADD INDEX estado_orde (estado, orde)" );

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Migration 1.36.0 (fase31): per-course comedor pivot for global levels.
	 *
	 * ADDITIVE ONLY. Creates wp_anpa_niveis_curso and backfills it from the
	 * legacy global column niveis.horario_comedor_id (resolving each schedule's
	 * curso_escolar via horarios_comedor). The global column is kept as a
	 * compatibility bridge; its destructive removal happens later in 1.37.0 once
	 * every reader/writer is cut over. Idempotent and fail-closed.
	 *
	 * @since  1.46.0
	 * @return bool Whether the migration completed.
	 */
	private static function migrate_to_1_36_0(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$pivot    = self::tabela_niveis_curso();
		$niveis   = self::tabela_niveis();
		$horarios = self::tabela_horarios_comedor();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$pivot} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nivel_id bigint(20) unsigned NOT NULL,
			curso_escolar varchar(9) NOT NULL,
			horario_comedor_id bigint(20) unsigned NULL DEFAULT NULL,
			creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY nivel_curso (nivel_id, curso_escolar),
			KEY curso_escolar (curso_escolar),
			KEY horario_comedor_id (horario_comedor_id),
			PRIMARY KEY  (id)
		) {$charset_collate};" );
		if ( '' !== (string) $wpdb->last_error || self::table_missing( $pivot ) ) {
			return false;
		}

		// Backfill from the legacy global column while it still exists.
		if ( self::tem_columna( $niveis, 'horario_comedor_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration backfill.
			$result = $wpdb->query(
				"INSERT INTO {$pivot} (nivel_id, curso_escolar, horario_comedor_id)
				 SELECT n.id, h.curso_escolar, n.horario_comedor_id
				   FROM {$niveis} n
				   INNER JOIN {$horarios} h ON h.id = n.horario_comedor_id
				  WHERE n.horario_comedor_id IS NOT NULL
				 ON DUPLICATE KEY UPDATE horario_comedor_id = VALUES(horario_comedor_id), actualizado_en = NOW()"
			);
			if ( false === $result ) {
				return false;
			}

			// Postcondition: every resolvable global assignment is in the pivot.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- migration verification.
			$missing = $wpdb->get_var(
				"SELECT COUNT(*)
				   FROM {$niveis} n
				   INNER JOIN {$horarios} h ON h.id = n.horario_comedor_id
				   LEFT JOIN {$pivot} p ON p.nivel_id = n.id AND p.curso_escolar = h.curso_escolar
				  WHERE n.horario_comedor_id IS NOT NULL AND p.id IS NULL"
			);
			if ( null === $missing || (int) $missing > 0 ) {
				if ( '' === (string) $wpdb->last_error ) {
					$wpdb->last_error = '1.36.0 comedor pivot backfill postcondition failed';
				}
				return false;
			}
		}

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Migration 1.37.0 (fase31): retire the legacy comedor columns from niveis.
	 *
	 * DESTRUCTIVE but gated: runs only after the pivot cutover (1.36.0 + code)
	 * so no reader/writer depends on niveis.horario_comedor_id/comedor_inicio/
	 * comedor_fin anymore. Each DROP is guarded by a column-existence check, so
	 * the step is idempotent and fail-closed. Dropping horario_comedor_id also
	 * removes its single-column index automatically.
	 *
	 * @since  1.46.0
	 * @return bool Whether the migration completed.
	 */
	/**
	 * 1.38.0 — academic calendar (fase34).
	 *
	 * Adds the operative trimester close dates to anpa_cursos, and creates the
	 * curso_trimestres (separate trimester/window states) and transicions
	 * (transition log) tables. Idempotent and retry-safe; additive only (no
	 * DROP), so a rollback keeps existing data intact. Does NOT touch
	 * matriculas.trimestre.
	 *
	 * @since  1.38.0
	 * @return bool
	 */
	private static function migrate_to_1_38_0(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$cursos          = self::tabela_cursos();

		// 1. Operative trimester close dates on anpa_cursos (idempotent).
		if ( ! self::tem_columna( $cursos, 't1_peche_operativo' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$cursos} ADD COLUMN t1_peche_operativo DATE NULL AFTER data_peche" ) ) {
				return false;
			}
		}
		if ( ! self::tem_columna( $cursos, 't2_peche_operativo' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$cursos} ADD COLUMN t2_peche_operativo DATE NULL AFTER t1_peche_operativo" ) ) {
				return false;
			}
		}

		// 2. curso_trimestres — separate trimester + window states per course.
		$ct = self::tabela_curso_trimestres();
		dbDelta(
			"CREATE TABLE {$ct} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				curso_escolar varchar(9) NOT NULL,
				trimestre tinyint(1) NOT NULL,
				estado varchar(20) NOT NULL DEFAULT 'pendente',
				ventana_estado varchar(20) NOT NULL DEFAULT 'pechada',
				creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY curso_trimestre (curso_escolar, trimestre)
			) {$charset_collate};"
		);

		// 3. transicions — append-only transition log.
		$tr = self::tabela_transicions();
		dbDelta(
			"CREATE TABLE {$tr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				curso_escolar varchar(9) NOT NULL,
				ambito varchar(20) NOT NULL DEFAULT '',
				referencia varchar(40) NOT NULL DEFAULT '',
				de_estado varchar(20) NOT NULL DEFAULT '',
				a_estado varchar(20) NOT NULL DEFAULT '',
				actor_email varchar(100) NOT NULL DEFAULT '',
				orixe varchar(12) NOT NULL DEFAULT 'manual',
				correlacion varchar(64) NOT NULL DEFAULT '',
				motivo varchar(255) NOT NULL DEFAULT '',
				creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY curso_escolar (curso_escolar),
				KEY creado_en (creado_en),
				KEY correlacion (correlacion)
			) {$charset_collate};"
		);

		// Postconditions: tables exist and columns present.
		if ( self::table_missing( $ct ) || self::table_missing( $tr ) ) {
			$wpdb->last_error = '1.38.0 table creation postcondition failed';
			return false;
		}
		if ( ! self::tem_columna( $cursos, 't1_peche_operativo' ) || ! self::tem_columna( $cursos, 't2_peche_operativo' ) ) {
			$wpdb->last_error = '1.38.0 cursos column postcondition failed';
			return false;
		}

		// 4. Seed the active course's trimesters (idempotent): T1 activo, T2/T3
		//    pendente, windows pechada. Best-effort — seeding never blocks.
		$active = ANPA_Socios_Curso_Activo::get();
		if ( null !== $active && ANPA_Socios_Curso_Escolar::is_valid( (string) $active ) ) {
			$seed = array( 1 => 'activo', 2 => 'pendente', 3 => 'pendente' );
			foreach ( $seed as $tri => $estado ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent seed (unique key guards).
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$ct} (curso_escolar, trimestre, estado, ventana_estado) VALUES (%s, %d, %s, 'pechada')",
						$active,
						$tri,
						$estado
					)
				);
			}
			$wpdb->last_error = '';
		}

		return true;
	}

	/**
	 * Migration 1.38.1 (fase34 close-out): extends the transition audit log with
	 * a correlation/idempotency id and an optional human reason. Additive and
	 * idempotent — safe to retry; guarded by column-existence checks.
	 *
	 * @since  1.38.1
	 * @return bool
	 */
	private static function migrate_to_1_38_1(): bool {
		global $wpdb;

		$tr = self::tabela_transicions();

		// The transicions table may not exist yet if a very old install jumps
		// straight here without 1.38.0 having created it — guard defensively.
		if ( self::table_missing( $tr ) ) {
			return true; // 1.38.0 creates it with the new columns already.
		}

		if ( ! self::tem_columna( $tr, 'correlacion' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$tr} ADD COLUMN correlacion varchar(64) NOT NULL DEFAULT '' AFTER orixe" ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- index for correlation lookups.
			$wpdb->query( "ALTER TABLE {$tr} ADD KEY correlacion (correlacion)" );
		}
		if ( ! self::tem_columna( $tr, 'motivo' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$tr} ADD COLUMN motivo varchar(255) NOT NULL DEFAULT '' AFTER correlacion" ) ) {
				return false;
			}
		}

		// Postcondition: both columns present.
		if ( ! self::tem_columna( $tr, 'correlacion' ) || ! self::tem_columna( $tr, 'motivo' ) ) {
			$wpdb->last_error = '1.38.1 transicions audit columns postcondition failed';
			return false;
		}

		return true;
	}

	/**
	 * Migration 1.39.0 (fase35): create the email queue tables — campaigns,
	 * recipients (one row per recipient) and attempts (one row per attempt).
	 *
	 * Additive and idempotent (dbDelta creates when missing, no-ops otherwise).
	 * Never drops anything. Does NOT create, schedule or send any campaign, and
	 * never sends email. Dedup is enforced by UNIQUE(idempotency_key) on
	 * recipients (a char(64) sha256 of campaign:email), which stays well within
	 * InnoDB index-length limits (unlike a UNIQUE on the varchar email).
	 *
	 * @since  1.39.0
	 * @return bool
	 */
	private static function migrate_to_1_39_0(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$campaigns  = self::tabela_email_campaigns();
		$recipients = self::tabela_email_recipients();
		$attempts   = self::tabela_email_attempts();

		// All datetime columns store UTC (written by the app with gmdate();
		// SQL scheduling/comparisons use UTC_TIMESTAMP()). NO CURRENT_TIMESTAMP
		// defaults, whose value depends on the MySQL session time zone. Plain
		// `datetime` (second precision) is compatible with the minimum supported
		// engines (WordPress 6.0 → MySQL 5.7+ / MariaDB 10.3+).
		//
		// 1. Campaigns. Denormalised counters here are a fast cache, NOT the
		//    source of truth: totals are recalculable from email_recipients and
		//    reconciled by the queue service (see design.md §Contadores).
		dbDelta(
			"CREATE TABLE {$campaigns} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				event_type varchar(40) NOT NULL DEFAULT '',
				state varchar(16) NOT NULL DEFAULT 'pending',
				course_year varchar(9) NULL,
				trimester tinyint(1) NULL,
				entity_type varchar(20) NOT NULL DEFAULT 'general',
				entity_id bigint(20) unsigned NULL,
				template_ref varchar(64) NULL,
				payload_version smallint(5) unsigned NOT NULL DEFAULT 1,
				total int(10) unsigned NOT NULL DEFAULT 0,
				pending_count int(10) unsigned NOT NULL DEFAULT 0,
				processed_count int(10) unsigned NOT NULL DEFAULT 0,
				accepted_count int(10) unsigned NOT NULL DEFAULT 0,
				failed_count int(10) unsigned NOT NULL DEFAULT 0,
				cancelled_count int(10) unsigned NOT NULL DEFAULT 0,
				skipped_count int(10) unsigned NOT NULL DEFAULT 0,
				batch_size smallint(5) unsigned NOT NULL DEFAULT 25,
				max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
				scheduled_at_utc datetime NULL,
				created_at_utc datetime NOT NULL,
				started_at_utc datetime NULL,
				finished_at_utc datetime NULL,
				paused_at_utc datetime NULL,
				cancelled_at_utc datetime NULL,
				purge_after_utc datetime NULL,
				created_by varchar(100) NOT NULL DEFAULT '',
				idempotency_key char(64) NOT NULL,
				meta_json longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY state (state),
				KEY created_at_utc (created_at_utc)
			) {$charset_collate};"
		);

		// 2. Recipients (one row per recipient). Dedup via UNIQUE(idempotency_key),
		//    where the key is sha256 of a canonical JSON of
		//    [version, campaign_uuid, normalized_email, recipient_type, message_key]
		//    (see ANPA_Socios_Email_Recipients). email is varchar(254) (RFC max),
		//    NOT uniquely indexed (the char(64) key handles dedup within index
		//    limits). All datetimes are UTC.
		dbDelta(
			"CREATE TABLE {$recipients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				email varchar(254) NOT NULL,
				recipient_type varchar(20) NOT NULL DEFAULT 'other',
				message_key varchar(190) NOT NULL DEFAULT '',
				entity_type varchar(20) NOT NULL DEFAULT 'general',
				entity_id bigint(20) unsigned NULL,
				state varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at_utc datetime NULL,
				last_attempt_at_utc datetime NULL,
				accepted_at_utc datetime NULL,
				last_error varchar(255) NOT NULL DEFAULT '',
				subject_render varchar(255) NOT NULL DEFAULT '',
				payload_snapshot longtext NULL,
				payload_hash char(64) NOT NULL DEFAULT '',
				lease_token char(36) NOT NULL DEFAULT '',
				locked_at_utc datetime NULL,
				locked_until_utc datetime NULL,
				idempotency_key char(64) NOT NULL,
				correlation_id varchar(64) NOT NULL DEFAULT '',
				created_at_utc datetime NOT NULL,
				updated_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY campaign_state (campaign_id, state),
				KEY claimable (state, next_attempt_at_utc),
				KEY locked_until_utc (locked_until_utc)
			) {$charset_collate};"
		);

		// 3. Attempts (one row per attempt). All datetimes are UTC.
		dbDelta(
			"CREATE TABLE {$attempts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				recipient_id bigint(20) unsigned NOT NULL,
				attempt_no smallint(5) unsigned NOT NULL,
				started_at_utc datetime NOT NULL,
				finished_at_utc datetime NULL,
				result varchar(16) NOT NULL DEFAULT '',
				error_category varchar(40) NOT NULL DEFAULT '',
				error_message varchar(255) NOT NULL DEFAULT '',
				duration_ms int(10) unsigned NULL,
				correlation_id varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY recipient_attempt (recipient_id, attempt_no),
				KEY recipient (recipient_id),
				KEY campaign (campaign_id)
			) {$charset_collate};"
		);

		// Postcondition: the three tables exist.
		if ( self::table_missing( $campaigns ) || self::table_missing( $recipients ) || self::table_missing( $attempts ) ) {
			$wpdb->last_error = '1.39.0 email queue table creation postcondition failed';
			return false;
		}

		// NOTE: this migration intentionally creates NO campaign, schedules NO
		// send and sends NO email. The recurring cron event is registered
		// separately (activation/admin_init) and its tick is a no-op until the
		// queue processor lands (later PR); it never runs during migration.
		return true;
	}

	private static function migrate_to_1_37_0(): bool {
		global $wpdb;

		$niveis  = self::tabela_niveis();
		$columns = array( 'horario_comedor_id', 'comedor_inicio', 'comedor_fin' );

		foreach ( $columns as $column ) {
			if ( ! self::tem_columna( $niveis, $column ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$niveis} DROP COLUMN {$column}" ) ) {
				return false;
			}
		}

		// Postcondition: every legacy comedor column is really gone.
		foreach ( $columns as $column ) {
			if ( self::tem_columna( $niveis, $column ) ) {
				$wpdb->last_error = '1.37.0 comedor column retirement postcondition failed';
				return false;
			}
		}

		return '' === (string) $wpdb->last_error;
	}

	/**
	 * Returns [nivel_id => horario_comedor_id] for a course from the pivot.
	 *
	 * Falls back to the legacy global niveis.horario_comedor_id column for any
	 * level that has no pivot row yet (bridge compatibility during cutover).
	 *
	 * @since  1.46.0
	 * @param  string $curso_escolar Course year.
	 * @return array<int,int> Map of nivel_id → horario_comedor_id.
	 */
	public static function get_niveis_comedor_curso( string $curso_escolar ): array {
		global $wpdb;

		if ( '' === $curso_escolar ) {
			return array();
		}

		$pivot = self::tabela_niveis_curso();
		$map   = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read helper.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT nivel_id, horario_comedor_id FROM {$pivot} WHERE curso_escolar = %s AND horario_comedor_id IS NOT NULL",
			$curso_escolar
		), ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$map[ (int) $r['nivel_id'] ] = (int) $r['horario_comedor_id'];
			}
		}

		return $map;
	}

	/**
	 * Upserts (or clears) a level's comedor schedule for a course in the pivot.
	 *
	 * Participates in the caller's transaction; never starts its own. Passing a
	 * null/0 schedule id deletes the pivot row (no comedor that course).
	 *
	 * @since  1.46.0
	 * @param  int      $nivel_id      Level id.
	 * @param  string   $curso_escolar Course year.
	 * @param  int|null $horario_id    Schedule id, or null to clear.
	 * @return bool
	 */
	public static function set_nivel_comedor( int $nivel_id, string $curso_escolar, ?int $horario_id ): bool {
		global $wpdb;

		if ( $nivel_id <= 0 || '' === $curso_escolar ) {
			return false;
		}

		$pivot = self::tabela_niveis_curso();

		if ( null === $horario_id || $horario_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- pivot delete within caller transaction.
			$res = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$pivot} WHERE nivel_id = %d AND curso_escolar = %s",
				$nivel_id,
				$curso_escolar
			) );
			return false !== $res;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- pivot upsert within caller transaction.
		$res = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$pivot} (nivel_id, curso_escolar, horario_comedor_id)
			 VALUES (%d, %s, %d)
			 ON DUPLICATE KEY UPDATE horario_comedor_id = VALUES(horario_comedor_id), actualizado_en = NOW()",
			$nivel_id,
			$curso_escolar,
			$horario_id
		) );

		return false !== $res;
	}

	/**
	 * Resolves a level's comedor interval [inicio,fin] for a course.
	 *
	 * Joins the pivot to horarios_comedor. Returns null when the level has no
	 * comedor assigned that course.
	 *
	 * @since  1.46.0
	 * @param  int    $nivel_id      Level id.
	 * @param  string $curso_escolar Course year.
	 * @return array{inicio:string,fin:string}|null
	 */
	public static function get_nivel_comedor_interval( int $nivel_id, string $curso_escolar ): ?array {
		global $wpdb;

		if ( $nivel_id <= 0 || '' === $curso_escolar ) {
			return null;
		}

		$pivot    = self::tabela_niveis_curso();
		$horarios = self::tabela_horarios_comedor();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read helper.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT h.inicio, h.fin
			   FROM {$pivot} p
			   INNER JOIN {$horarios} h ON h.id = p.horario_comedor_id
			  WHERE p.nivel_id = %d AND p.curso_escolar = %s
			  LIMIT 1",
			$nivel_id,
			$curso_escolar
		), ARRAY_A );

		if ( ! is_array( $row ) || ! isset( $row['inicio'], $row['fin'] ) ) {
			return null;
		}

		return array( 'inicio' => (string) $row['inicio'], 'fin' => (string) $row['fin'] );
	}
}
