<?php
/**
 * Pure admin payload validation helpers for the ANPA Socios plugin.
 *
 * No WordPress, no I/O, no global state. Unit-testable with PHPUnit.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Validates and canonicalises admin payloads.
 *
 * The functions are pure: they take arrays, return arrays or null, and
 * never touch the database. The integration layer (`ANPA_Socios_Admin_REST`)
 * reads from the database, passes the resulting rows through these
 * helpers, and persists the result.
 *
 * @since 1.2.0
 */
final class ANPA_Socios_Admin_Payload {

	/**
	 * Maximum length for a socio/fillo nome.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const NOME_MAX_LEN = 50;

	/**
	 * Maximum length for a socio/fillo apelidos.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const APELIDOS_MAX_LEN = 100;

	/**
	 * Maximum length for empresa nome.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const EMPRESA_NOME_MAX_LEN = 120;

	/**
	 * Maximum length for actividad nome.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const ACTIVIDAD_NOME_MAX_LEN = 120;

	/**
	 * Maximum length for actividad descripcion.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const ACTIVIDAD_DESC_MAX_LEN = 500;

	/**
	 * Maximum length for responsable and telefono fields.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const CONTACTO_MAX_LEN = 60;

	/**
	 * Valid curso values for fillos (Educación Primaria 1–6).
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const CURSO_VALIDOS = array( '1', '2', '3', '4', '5', '6' );

	/**
	 * Valid grupo/aula values for fillos (A–D). Case-sensitive.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const GRUPO_VALIDOS = array( 'A', 'B', 'C', 'D' );

	/**
	 * Allowed socio/fillo estado values written by the admin REST.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const FILLOS_ESTADO = array( 'activo', 'baixa' );

	/**
	 * Allowed empresa estado values.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const EMPRESA_ESTADO = array( 'activo', 'inactivo' );

	/**
	 * Allowed matricula estado values.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const MATRICULA_ESTADO = array( 'activo', 'baixa' );

	// ──────────────────────────────────────────────
	// Fillos
	// ──────────────────────────────────────────────

	/**
	 * Validates and returns a canonical fillo payload.
	 *
	 * Required fields: nome, apelidos, data_nacemento, curso, aula.
	 * Returns null on missing or invalid data.
	 *
	 * @since  1.2.0
	 * @param  array<string,mixed> $input Raw input.
	 * @return array<string,string>|null
	 */
	public static function validar_fillo( array $input ): ?array {
		$nome           = self::sanitise_optional_string( $input['nome'] ?? null, self::NOME_MAX_LEN );
		$apelidos       = self::sanitise_optional_string( $input['apelidos'] ?? null, self::APELIDOS_MAX_LEN );
		$data_nacemento = self::sanitise_optional_string( $input['data_nacemento'] ?? null, 10 );
		$curso          = self::sanitise_optional_string( $input['curso'] ?? null, 10 );
		$aula           = self::sanitise_optional_string( $input['aula'] ?? null, 10 );
		$estado         = isset( $input['estado'] ) ? (string) $input['estado'] : 'activo';
		if ( ! in_array( $estado, self::FILLOS_ESTADO, true ) ) {
			return null;
		}

		if ( null === $nome || null === $apelidos || null === $data_nacemento || null === $curso || null === $aula ) {
			return null;
		}
		if ( '' === $nome || '' === $apelidos || '' === $curso || '' === $aula ) {
			return null;
		}
		if ( strlen( $nome ) < 1 || strlen( $apelidos ) < 1 ) {
			return null;
		}
		if ( ! self::data_nacemento_valida( $data_nacemento ) ) {
			return null;
		}

		// Enforce canonical curso enum (case-sensitive).
		if ( ! in_array( $curso, self::CURSO_VALIDOS, true ) ) {
			return null;
		}

		// Enforce canonical grupo/aula enum (case-sensitive).
		if ( ! in_array( $aula, self::GRUPO_VALIDOS, true ) ) {
			return null;
		}

		return array(
			'nome'           => $nome,
			'apelidos'       => $apelidos,
			'data_nacemento' => $data_nacemento,
			'curso'          => $curso,
			'aula'           => $aula,
			'estado'         => $estado,
		);
	}

	// ──────────────────────────────────────────────
	// Empresas
	// ──────────────────────────────────────────────

	/**
	 * Validates and returns a canonical empresa payload.
	 *
	 * Required fields: nome, email, responsable, telefono.
	 * Optional: estado.
	 *
	 * @since  1.2.0
	 * @param  array<string,mixed> $input Raw input.
	 * @return array<string,string>|null
	 */
	public static function validar_empresa( array $input ): ?array {
		$nome         = self::sanitise_optional_string( $input['nome'] ?? null, self::EMPRESA_NOME_MAX_LEN );
		$email        = isset( $input['email'] ) ? self::sanitise_email( (string) $input['email'] ) : null;
		$responsable  = self::sanitise_optional_string( $input['responsable'] ?? null, self::CONTACTO_MAX_LEN );
		$telefono     = self::sanitise_optional_string( $input['telefono'] ?? null, self::CONTACTO_MAX_LEN );
		$url_web      = self::sanitise_optional_string( $input['url_web'] ?? null, 512 );
		$estado        = isset( $input['estado'] ) ? (string) $input['estado'] : 'activo';
		if ( ! in_array( $estado, self::EMPRESA_ESTADO, true ) ) {
			return null;
		}

		if ( null === $nome || null === $responsable || null === $telefono || null === $email ) {
			return null;
		}
		if ( '' === $email ) {
			return null;
		}
		if ( '' === $nome || '' === $responsable || '' === $telefono ) {
			return null;
		}

		return array(
			'nome'        => $nome,
			'email'       => $email,
			'responsable' => $responsable,
			'telefono'    => $telefono,
			'url_web'     => null === $url_web ? '' : $url_web,
			'estado'      => $estado,
		);
	}

	// ──────────────────────────────────────────────
	// Actividades
	// ──────────────────────────────────────────────

	/**
	 * Validates and returns a canonical actividad payload.
	 *
	 * Required: empresa_id, nome, descripcion, curso_escolar.
	 * Optional: idade_min, idade_max, custo, estado.
	 *
	 * @since  1.2.0
	 * @param  array<string,mixed> $input Raw input.
	 * @return array<string,mixed>|null
	 */
	public static function validar_actividad( array $input ): ?array {
		$empresa_id    = isset( $input['empresa_id'] ) ? (int) $input['empresa_id'] : 0;
		$nome          = self::sanitise_optional_string( $input['nome'] ?? null, self::ACTIVIDAD_NOME_MAX_LEN );
		$icono         = self::sanitise_optional_string( $input['icono'] ?? null, 20 );
		$descripcion   = self::sanitise_optional_string( $input['descripcion'] ?? null, self::ACTIVIDAD_DESC_MAX_LEN );
		$curso_escolar = self::sanitise_optional_string( $input['curso_escolar'] ?? null, 20 );
		$idade_min     = isset( $input['idade_min'] ) && '' !== $input['idade_min'] ? (int) $input['idade_min'] : null;
		$idade_max     = isset( $input['idade_max'] ) && '' !== $input['idade_max'] ? (int) $input['idade_max'] : null;
		$custo         = self::parse_custo( $input['custo'] ?? null );
		$franxa        = ANPA_Socios_Actividade_Options::normalize_franxa( $input['franxa'] ?? null );
		$estado        = isset( $input['estado'] ) ? (string) $input['estado'] : 'activo';
		if ( ! in_array( $estado, self::EMPRESA_ESTADO, true ) ) {
			return null;
		}

		// fase7: an activity defines option sets (horario / grupo curricular /
		// días) instead of a numeric age range. At least one of each is required.
		$horarios = $input['horarios'] ?? null;
		$grupos   = $input['grupos'] ?? null;
		$dias     = $input['dias'] ?? null;
		if ( ! ANPA_Socios_Actividade_Options::validate( $horarios, $grupos, $dias ) ) {
			return null;
		}

		if ( $empresa_id <= 0 ) {
			return null;
		}
		if ( null === $nome || null === $descripcion || null === $curso_escolar ) {
			return null;
		}
		if ( '' === $nome || '' === $descripcion || '' === $curso_escolar ) {
			return null;
		}
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return null;
		}
		if ( null === $custo ) {
			return null;
		}
		if ( null !== $idade_min && null !== $idade_max && $idade_min > $idade_max ) {
			return null;
		}

		// 1.16.0: per-activity place capacity, parsed with defaults.
		$min_pupilos = isset( $input['min_pupilos'] ) ? (int) $input['min_pupilos'] : 10;
		$max_pupilos = isset( $input['max_pupilos'] ) ? (int) $input['max_pupilos'] : 15;
		if ( $min_pupilos < 1 ) { $min_pupilos = 10; }
		if ( $max_pupilos < 1 || $max_pupilos < $min_pupilos ) { $max_pupilos = 15; }

		return array(
			'empresa_id'    => $empresa_id,
			'nome'          => $nome,
			'icono'         => ( null === $icono || '' === $icono ) ? '🎒' : $icono,
			'descripcion'   => $descripcion,
			'curso_escolar' => $curso_escolar,
			'franxa'        => null === $franxa ? '' : $franxa,
			'horarios'      => ANPA_Socios_Actividade_Options::serialize( $horarios, ANPA_Socios_Actividade_Options::HORARIOS ),
			'grupos'        => ANPA_Socios_Actividade_Options::serialize( $grupos, ANPA_Socios_Actividade_Options::GRUPOS ),
			'dias'          => ANPA_Socios_Actividade_Options::serialize( $dias, ANPA_Socios_Actividade_Options::DIAS ),
			'idade_min'     => $idade_min,
			'idade_max'     => $idade_max,
			'min_pupilos'   => $min_pupilos,
			'max_pupilos'   => $max_pupilos,
			'custo'         => $custo,
			'estado'        => $estado,
		);
	}

	// ──────────────────────────────────────────────
	// Grupos (fase7)
	// ──────────────────────────────────────────────

	/**
	 * Allowed grupo estado values.
	 *
	 * @since 1.9.0
	 * @var string[]
	 */
	const GRUPO_ESTADO = array( 'aberto', 'pechado' );

	/**
	 * Validates and returns a canonical grupo payload (standalone shape).
	 *
	 * Cross-checks against the parent activity's option sets (curso_range ⊆
	 * activity.grupos, días ⊆ activity.días) are the caller's responsibility,
	 * since they need the activity row. Here we validate the shape:
	 * curso_range is canonical, días is a non-empty valid set, max ≥ min and
	 * max > 0, estado is valid.
	 *
	 * @since  1.9.0
	 * @param  array<string,mixed> $input Raw input.
	 * @return array<string,mixed>|null
	 */
	public static function validar_grupo( array $input ): ?array {
		$curso_escolar = self::sanitise_optional_string( $input['curso_escolar'] ?? null, 20 );
		if ( null === $curso_escolar || '' === $curso_escolar || ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return null;
		}

		$curso_range = isset( $input['curso_range'] ) ? (string) $input['curso_range'] : '';
		if ( ! ANPA_Socios_Curso_Fit::is_range( $curso_range ) ) {
			return null;
		}

		$franxa = ANPA_Socios_Actividade_Options::normalize_franxa( $input['franxa'] ?? null );
		if ( null === $franxa ) {
			return null;
		}

		$dias = ANPA_Socios_Actividade_Options::normalize(
			$input['dias'] ?? null,
			ANPA_Socios_Actividade_Options::DIAS
		);
		if ( array() === $dias ) {
			return null;
		}

		$min = isset( $input['min_pupilos'] ) ? (int) $input['min_pupilos'] : 0;
		$max = isset( $input['max_pupilos'] ) ? (int) $input['max_pupilos'] : 0;
		if ( $min < 0 || $max <= 0 || $max < $min ) {
			return null;
		}

		$estado = isset( $input['estado'] ) ? (string) $input['estado'] : 'aberto';
		if ( ! in_array( $estado, self::GRUPO_ESTADO, true ) ) {
			return null;
		}

		return array(
			'curso_escolar' => $curso_escolar,
			'curso_range' => $curso_range,
			'franxa'      => $franxa,
			'dias'        => implode( ',', $dias ),
			'min_pupilos' => $min,
			'max_pupilos' => $max,
			'estado'      => $estado,
		);
	}

	// ──────────────────────────────────────────────
	// Matriculas
	// ──────────────────────────────────────────────
	/**
	 * Validates and returns a canonical matricula payload.
	 *
	 * @since  1.2.0
	 * @param  array<string,mixed> $input Raw input.
	 * @return array<string,mixed>|null
	 */
	public static function validar_matricula( array $input ): ?array {
		$fillo_id     = isset( $input['fillo_id'] ) ? (int) $input['fillo_id'] : 0;
		$activitad_id = isset( $input['activitad_id'] ) ? (int) $input['activitad_id'] : 0;
		$comedor      = ! empty( $input['comedor'] );
		$tarde        = ! empty( $input['tarde'] );
		$observaciones = self::sanitise_optional_string( $input['observaciones'] ?? null, 500 );
		$estado        = isset( $input['estado'] ) ? (string) $input['estado'] : 'activo';
		if ( ! in_array( $estado, self::MATRICULA_ESTADO, true ) ) {
			return null;
		}

		if ( $fillo_id <= 0 || $activitad_id <= 0 ) {
			return null;
		}

		return array(
			'fillo_id'      => $fillo_id,
			'activitad_id'  => $activitad_id,
			'comedor'       => $comedor,
			'tarde'         => $tarde,
			'observaciones' => $observaciones,
			'estado'        => $estado,
		);
	}

	// ──────────────────────────────────────────────
	// Audit
	// ──────────────────────────────────────────────

	/**
	 * Returns a canonical audit row shape.
	 *
	 * @since  1.2.0
	 * @param  string $actor_email Acting user email.
	 * @param  string $actor_tipo  Type of acting user (master/empresa/system).
	 * @param  string $target_tipo Type of target (socio/empresa/actividad/matricula/fillo).
	 * @param  string $target_id   Target identifier.
	 * @param  string $accion      Action verb (create/update/delete/list/...).
	 * @param  string $timestamp   Optional ISO-8601 timestamp; defaults to now.
	 * @return array<string,string>
	 */
	public static function audit_row(
		string $actor_email,
		string $actor_tipo,
		string $target_tipo,
		string $target_id,
		string $accion,
		string $timestamp = ''
	): array {
		return array(
			'actor_email' => '' === $actor_email ? 'unknown' : strtolower( trim( $actor_email ) ),
			'actor_tipo'  => '' === $actor_tipo ? 'system' : strtolower( trim( $actor_tipo ) ),
			'target_tipo' => strtolower( trim( $target_tipo ) ),
			'target_id'   => trim( $target_id ),
			'accion'      => strtolower( trim( $accion ) ),
			'timestamp'   => '' === $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z' ) : $timestamp,
		);
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Sanitises a possibly-null string, validates length without truncating.
	 *
	 * Pure implementation (no WordPress dependency) so the helper
	 * stays testable without WP bootstrap. The integration layer can
	 * add further escaping when echoing values.
	 *
	 * Returns null when the value is missing, empty, contains control
	 * characters, or exceeds the maximum length.
	 *
	 * @since  1.2.0
	 * @param  mixed  $value Raw value.
	 * @param  int    $max_len Maximum length.
	 * @return string|null
	 */
	public static function sanitise_optional_string( $value, int $max_len ): ?string {
		if ( null === $value ) {
			return null;
		}
		$str = trim( (string) $value );
		$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str );
		if ( null === $str || '' === $str ) {
			return null;
		}
		if ( strlen( $str ) > $max_len ) {
			return null;
		}

		return $str;
	}

	/**
	 * Puro email validation. Returns the trimmed email or null.
	 *
	 * @since  1.2.0
	 * @param  string $email Raw email.
	 * @return string|null
	 */
	public static function sanitise_email( string $email ): ?string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}

		return $email;
	}

	/**
	 * Validates an ISO-8601 date (YYYY-MM-DD).
	 *
	 * @since  1.2.0
	 * @param  string $date Date string.
	 * @return bool
	 */
	public static function data_nacemento_valida( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( false === $d ) {
			return false;
		}

		return $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Parses a custo string into a float or returns null.
	 *
	 * Accepts comma or dot as decimal separator. Returns null on
	 * invalid input.
	 *
	 * @since  1.2.0
	 * @param  mixed $value Raw value.
	 * @return float|null
	 */
	private static function parse_custo( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return 0.0;
		}
		$str = trim( (string) $value );
		$str = str_replace( ',', '.', $str );
		if ( ! is_numeric( $str ) ) {
			return null;
		}

		return (float) $str;
	}
}
