<?php
/**
 * Contract tests for the fase34 course-calendar admin UI (PR-34s3).
 *
 * Source-inspection style: the admin-post glue is verified by reading the
 * source (nonce + capability guard, operative-date fields, calendar
 * validation, copy-from-previous, and the trimester/window transition panel),
 * matching the other schema/glue contract tests in this suite.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Trimestre_Admin_Ui extends TestCase {

	private string $settings;
	private string $repo;
	private string $bootstrap;

	protected function setUp(): void {
		$root            = dirname( __DIR__ );
		$this->settings  = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
		$this->repo      = (string) file_get_contents( $root . '/includes/class-anpa-socios-trimestre-repo.php' );
		$this->bootstrap = (string) file_get_contents( $root . '/anpa-socios.php' );
	}

	public function test_new_admin_post_actions_are_registered(): void {
		$this->assertStringContainsString( "admin_post_anpa_socios_copiar_datas_curso", $this->settings );
		$this->assertStringContainsString( "admin_post_anpa_socios_trimestre_transicion", $this->settings );
		$this->assertStringContainsString( 'handle_copiar_datas_curso', $this->settings );
		$this->assertStringContainsString( 'handle_trimestre_transicion', $this->settings );
	}

	public function test_repo_is_wired_in_bootstrap(): void {
		$this->assertStringContainsString( 'class-anpa-socios-trimestre-repo.php', $this->bootstrap );
	}

	public function test_curso_form_exposes_operative_date_fields(): void {
		$this->assertStringContainsString( 'name="t1_peche_operativo"', $this->settings );
		$this->assertStringContainsString( 'name="t2_peche_operativo"', $this->settings );
		// The read query must select the new columns so the form can pre-fill.
		$this->assertStringContainsString( 't1_peche_operativo, t2_peche_operativo', $this->settings );
	}

	public function test_save_handler_validates_calendar_before_persisting(): void {
		$start = strpos( $this->settings, 'public static function handle_save_cursos' );
		$end   = strpos( $this->settings, 'private static function redirect_cursos', $start );
		$body  = substr( $this->settings, $start, $end - $start );

		$this->assertStringContainsString( 'ANPA_Socios_Calendario::validar', $body );
		$this->assertStringContainsString( "curso_datas_error", $body );
		// The operative dates are forwarded to the writer.
		$this->assertStringContainsString( 'upsert_course( $curso, $inicio, $peche, $estado, $t1, $t2 )', $body );
	}

	public function test_copy_handler_guards_and_shifts_one_year(): void {
		$start = strpos( $this->settings, 'public static function handle_copiar_datas_curso' );
		$end   = strpos( $this->settings, 'private static function shift_date_one_year', $start );
		$body  = substr( $this->settings, $start, $end - $start );

		$this->assertStringContainsString( "self::guard( 'anpa_socios_copiar_datas_curso' )", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Escolar::previous', $body );
		$this->assertStringContainsString( 'shift_date_one_year', $body );
		$this->assertStringContainsString( "sen_curso_anterior", $body );
	}

	public function test_transition_handler_guards_and_delegates_to_repo(): void {
		$start = strpos( $this->settings, 'public static function handle_trimestre_transicion' );
		$end   = strpos( $this->settings, 'private static function render_trimestres_panel', $start );
		$body  = substr( $this->settings, $start, $end - $start );

		$this->assertStringContainsString( "self::guard( 'anpa_socios_trimestre_transicion' )", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Trimestre_Repo::transicionar_trimestre', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Trimestre_Repo::transicionar_ventana', $body );
	}

	public function test_transition_buttons_carry_a_nonce(): void {
		$start = strpos( $this->settings, 'private static function render_transicion_button' );
		$body  = substr( $this->settings, $start, 800 );
		$this->assertStringContainsString( "wp_nonce_field( 'anpa_socios_trimestre_transicion' )", $body );
	}

	public function test_repo_delegates_transition_rules_to_value_objects(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Trimestre_Estado::pode_transicionar', $this->repo );
		$this->assertStringContainsString( 'ANPA_Socios_Ventana_Estado::pode_transicionar', $this->repo );
		// Every real transition is logged in the audit table.
		$this->assertStringContainsString( 'tabela_transicions', $this->repo );
		// Idempotent no-op path returns changed=false.
		$this->assertStringContainsString( "'code' => 'noop'", $this->repo );
	}

	public function test_season_cron_detects_trimester_ends_without_changing_state(): void {
		$root   = dirname( __DIR__ );
		$season = (string) file_get_contents( $root . '/includes/class-anpa-socios-season-service.php' );

		// The cron adds a detection pass using the pure calendar helper.
		$this->assertStringContainsString( 'ANPA_Socios_Calendario::trimestres_operativos_alcanzados', $season );
		$this->assertStringContainsString( 'private static function detect_trimestre_ends', $season );
		$this->assertStringContainsString( "'trimestre_avisos'", $season );
		// Detection must NOT mutate trimester/window state (no transition calls).
		$start = strpos( $season, 'private static function detect_trimestre_ends' );
		$end   = strpos( $season, 'public static function get_avisos', $start );
		$body  = substr( $season, $start, $end - $start );
		$this->assertStringNotContainsString( 'transicionar_trimestre', $body );
		$this->assertStringNotContainsString( 'transicionar_ventana', $body );
		// Persistent, idempotent notice store + admin notice + clear-on-manage.
		$this->assertStringContainsString( 'AVISOS_OPTION', $season );
		$this->assertStringContainsString( 'public static function render_admin_notice', $season );
		$this->assertStringContainsString( 'public static function clear_aviso', $season );
	}

	public function test_season_admin_notice_is_wired_in_bootstrap(): void {
		$this->assertStringContainsString( "add_action( 'admin_notices', array( 'ANPA_Socios_Season_Service', 'render_admin_notice' ) )", $this->bootstrap );
	}

	// ── fase34 close-out: fail-closed, audit and explicit-seeding contracts ──

	public function test_for_curso_is_read_only_and_never_seeds_or_fabricates_state(): void {
		$start = strpos( $this->repo, 'public static function for_curso' );
		$end   = strpos( $this->repo, 'public static function esta_inicializado', $start );
		$body  = substr( $this->repo, $start, $end - $start );

		// A read must not write or seed.
		$this->assertStringNotContainsString( 'ensure_seeded', $body );
		$this->assertStringNotContainsString( '$wpdb->insert', $body );
		$this->assertStringNotContainsString( 'INSERT', $body );
		// Missing rows are reported as not-present with a closed window default,
		// never as an "activo" trimester.
		$this->assertStringContainsString( "'presente'       => false", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Ventana_Estado::PECHADA', $body );
	}

	public function test_transitions_fail_closed_when_row_missing(): void {
		$this->assertStringContainsString( "'code' => 'sen_configurar'", $this->repo );
		// Both transition methods check presence before deciding.
		$this->assertSame( 2, substr_count( $this->repo, "empty( \$rows[ \$trimestre ]['presente'] )" ) );
	}

	public function test_seeding_is_explicit_and_logged_with_origin(): void {
		$this->assertStringContainsString( 'public static function ensure_seeded', $this->repo );
		$this->assertStringContainsString( "const ORIXE_MIGRACION", $this->repo );
		$this->assertStringContainsString( "const ORIXE_ACTIVACION", $this->repo );
		$this->assertStringContainsString( "const ORIXE_REPARACION", $this->repo );
		// ensure_seeded never overwrites an existing row (idempotent, per-trimester).
		$start = strpos( $this->repo, 'public static function ensure_seeded' );
		$end   = strpos( $this->repo, 'public static function transicionar_trimestre', $start );
		$body  = substr( $this->repo, $start, $end - $start );
		$this->assertStringContainsString( 'in_array( $tri, $existing, true )', $body );
		$this->assertStringContainsString( 'INSERT IGNORE', $body );
	}

	public function test_audit_log_carries_correlation_and_reason(): void {
		// The log writer persists correlation id + reason + origin + actor.
		$this->assertStringContainsString( "'correlacion'   => \$correlacion", $this->repo );
		$this->assertStringContainsString( "'motivo'        => \$motivo", $this->repo );
		$this->assertStringContainsString( "'orixe'         => \$orixe", $this->repo );
		$this->assertStringContainsString( "'actor_email'   => \$actor", $this->repo );
	}

	public function test_course_activation_seeds_trimesters(): void {
		$root    = dirname( __DIR__ );
		$handler = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-cursos-handler.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Trimestre_Repo::ensure_seeded( $curso, ANPA_Socios_Trimestre_Repo::ORIXE_ACTIVACION', $handler );
		// Seeding runs AFTER commit (never inside the lifecycle transaction).
		$commit = strpos( $handler, "\$wpdb->query( 'COMMIT' )" );
		$seed   = strpos( $handler, 'ensure_seeded( $curso, ANPA_Socios_Trimestre_Repo::ORIXE_ACTIVACION' );
		$this->assertIsInt( $commit );
		$this->assertIsInt( $seed );
		$this->assertLessThan( $seed, $commit );
	}

	public function test_transition_handler_passes_a_correlation_id(): void {
		$start = strpos( $this->settings, 'public static function handle_trimestre_transicion' );
		$end   = strpos( $this->settings, 'public static function handle_inicializar_trimestres', $start );
		$body  = substr( $this->settings, $start, $end - $start );
		$this->assertStringContainsString( 'wp_generate_uuid4', $body );
		$this->assertStringContainsString( 'transicion_sen_config', $body );
	}

	public function test_panel_offers_explicit_repair_when_not_initialised(): void {
		$this->assertStringContainsString( 'admin_post_anpa_socios_inicializar_trimestres', $this->settings );
		$this->assertStringContainsString( 'handle_inicializar_trimestres', $this->settings );
		$this->assertStringContainsString( 'esta_inicializado', $this->settings );
		$this->assertStringContainsString( 'Sen configurar', $this->settings );
	}
}
