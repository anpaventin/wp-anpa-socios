<?php
/**
 * Pure CSV import core: parse, validate, and analyze CSV data.
 *
 * No WordPress dependency, no wpdb, no I/O, no global state.
 * May use ANPA_Socios_Normalize for field normalization.
 * Unit-testable with PHPUnit and a require_once-only bootstrap.
 *
 * @since  1.34.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * CSV import parser and analyzer.
 *
 * Parses raw CSV text into associative arrays and validates/deduplicates
 * rows for a given entity without touching the database.
 *
 * @since 1.34.0
 */
final class ANPA_Socios_Csv_Import {

	/**
	 * Required fields per entity.
	 *
	 * @since 1.34.0
	 * @var array<string,string[]>
	 */
	const REQUIRED_FIELDS = array(
		'socios'      => array( 'id_familia', 'rol_familia', 'nome', 'apelidos' ),
		'fillos'      => array( 'proxenitor_email', 'nome', 'apelidos', 'data_nacemento', 'curso', 'aula' ),
		'empresas'    => array( 'nome', 'email' ),
		'actividades' => array( 'empresa_email', 'nome', 'curso_escolar' ),
		'matriculas'  => array( 'proxenitor_email', 'fillo_nome', 'fillo_apelidos', 'empresa_email', 'actividade_nome', 'curso_escolar' ),
		'socios_iban' => array( 'id_familia', 'titular_nome', 'titular_apelidos', 'titular_nif', 'iban' ),
	);

	/**
	 * All known headers per entity (for validation/trimming).
	 *
	 * @since 1.34.0
	 * @var array<string,string[]>
	 */
	const ENTITY_HEADERS = array(
		'socios'      => array( 'id_familia', 'rol_familia', 'email', 'nome', 'apelidos', 'nif', 'telefono', 'estado' ),
		'fillos'      => array( 'proxenitor_email', 'nome', 'apelidos', 'data_nacemento', 'curso', 'aula', 'image_consent', 'estado' ),
		'empresas'    => array( 'nome', 'email', 'responsable', 'telefono', 'url_web', 'estado' ),
		'actividades' => array( 'empresa_email', 'nome', 'descripcion', 'curso_escolar', 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max', 'custo', 'estado' ),
		'matriculas'  => array( 'proxenitor_email', 'fillo_nome', 'fillo_apelidos', 'empresa_email', 'actividade_nome', 'curso_escolar', 'comedor', 'tarde', 'observaciones', 'estado' ),
		'socios_iban' => array( 'id_familia', 'titular_nome', 'titular_apelidos', 'titular_nif', 'iban', 'entidade_bancaria', 'autorizacion' ),
	);

	/**
	 * Parse raw CSV text into an array of associative arrays.
	 *
	 * Handles UTF-8 BOM, comma separator, quoted fields, trims headers
	 * and values. First row is treated as header.
	 *
	 * @since  1.34.0
	 * @param  string $csv_text Raw CSV content.
	 * @return array Array of associative arrays keyed by header columns.
	 */
	public static function parse( string $csv_text ): array {
		// Strip UTF-8 BOM if present.
		if ( 0 === strpos( $csv_text, "\xEF\xBB\xBF" ) ) {
			$csv_text = substr( $csv_text, 3 );
		}

		$csv_text = str_replace( "\r\n", "\n", $csv_text );
		$csv_text = str_replace( "\r", "\n", $csv_text );

		$lines = self::parse_lines( $csv_text );

		if ( empty( $lines ) ) {
			return array();
		}

		$headers = array_map( 'trim', $lines[0] );
		$rows    = array();

		for ( $i = 1, $count = count( $lines ); $i < $count; $i++ ) {
			$line = $lines[ $i ];
			// Skip completely empty rows.
			if ( empty( $line ) || ( 1 === count( $line ) && '' === trim( (string) $line[0] ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $headers as $idx => $header ) {
				$row[ $header ] = isset( $line[ $idx ] ) ? trim( $line[ $idx ] ) : '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Analyze parsed rows for a given entity.
	 *
	 * Normalizes fields, validates required fields, detects duplicates
	 * within the CSV and against existing keys. Returns a structured
	 * report without touching any database.
	 *
	 * @since  1.34.0
	 * @param  string $entity        Entity name (socios|fillos|empresas|actividades|matriculas).
	 * @param  array  $rows          Parsed rows (from parse()).
	 * @param  array  $existing_keys Array of existing natural keys for dedup.
	 * @return array{rows:array,errors:array,duplicates:array,to_insert:array,valid:array}
	 */
	public static function analyze( string $entity, array $rows, array $existing_keys = array() ): array {
		$errors     = array();
		$duplicates = array();
		$to_insert  = array();
		$valid      = array();
		$seen_keys  = array();

		$required = self::REQUIRED_FIELDS[ $entity ] ?? array();

		foreach ( $rows as $idx => $row ) {
			// Normalize fields.
			$row = self::normalize_row( $entity, $row );
			$rows[ $idx ] = $row;

			// Validate required fields.
			$row_errors = self::validate_required( $entity, $row, $idx );

			// Additional validation for socios: principal must have email and nif.
			if ( 'socios' === $entity ) {
				$rol = strtolower( trim( $row['rol_familia'] ?? '' ) );
				if ( 'principal' === $rol ) {
					if ( '' === ( $row['email'] ?? '' ) ) {
						$row_errors[] = array( 'row' => $idx, 'field' => 'email', 'msg' => 'Email required for principal' );
					}
					if ( '' === ( $row['nif'] ?? '' ) ) {
						$row_errors[] = array( 'row' => $idx, 'field' => 'nif', 'msg' => 'NIF required for principal' );
					}
				} elseif ( 'secundario' === $rol ) {
					// Secondary without email/nif: leave empty + report error (RF-1).
					if ( '' === ( $row['email'] ?? '' ) ) {
						$row_errors[] = array( 'row' => $idx, 'field' => 'email', 'msg' => 'Email missing for secundario (not invented)' );
					}
					if ( '' === ( $row['nif'] ?? '' ) ) {
						$row_errors[] = array( 'row' => $idx, 'field' => 'nif', 'msg' => 'NIF missing for secundario (not invented)' );
					}
				}
			}

			// Additional validation for socios_iban: IBAN format, NIF non-empty.
			if ( 'socios_iban' === $entity ) {
				$iban_val = $row['iban'] ?? '';
				if ( '' !== $iban_val && strlen( $iban_val ) < 15 ) {
					$row_errors[] = array( 'row' => $idx, 'field' => 'iban', 'msg' => 'IBAN too short (invalid format)' );
				}
				$nif_val = $row['titular_nif'] ?? '';
				if ( '' === $nif_val ) {
					$row_errors[] = array( 'row' => $idx, 'field' => 'titular_nif', 'msg' => 'Titular NIF is required for IBAN import' );
				}
			}

			if ( ! empty( $row_errors ) ) {
				$errors = array_merge( $errors, $row_errors );
			}

			// Compute natural key for dedup.
			$key = self::compute_natural_key( $entity, $row );

			if ( null !== $key ) {
				// Check against existing keys.
				if ( in_array( $key, $existing_keys, true ) ) {
					$duplicates[] = $idx;
					continue;
				}
				// Check within CSV itself.
				if ( isset( $seen_keys[ $key ] ) ) {
					$duplicates[] = $idx;
					continue;
				}
				$seen_keys[ $key ] = true;
			}

			$to_insert[] = $row;

			// Strict set: rows with NO validation errors AND not duplicate.
			// Sensitive commits (e.g. IBAN sealing) MUST use `valid`, never
			// `to_insert` (which keeps error rows for the report).
			if ( empty( $row_errors ) ) {
				$valid[] = $row;
			}
		}

		return array(
			'rows'       => $rows,
			'errors'     => $errors,
			'duplicates' => $duplicates,
			'to_insert'  => $to_insert,
			'valid'      => $valid,
		);
	}

	/**
	 * Normalize a row's fields using ANPA_Socios_Normalize.
	 *
	 * @since  1.34.0
	 * @param  string $entity Entity name.
	 * @param  array  $row    Row data.
	 * @return array Normalized row.
	 */
	private static function normalize_row( string $entity, array $row ): array {
		// Name fields: title_case.
		$name_fields = array( 'nome', 'apelidos', 'responsable', 'fillo_nome', 'fillo_apelidos', 'titular_nome', 'titular_apelidos' );
		foreach ( $name_fields as $field ) {
			if ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$row[ $field ] = ANPA_Socios_Normalize::title_case( $row[ $field ] );
			}
		}

		// Email fields: email normalization.
		$email_fields = array( 'email', 'empresa_email', 'proxenitor_email' );
		foreach ( $email_fields as $field ) {
			if ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$normalized = ANPA_Socios_Normalize::email( $row[ $field ] );
				$row[ $field ] = $normalized ?? '';
			}
		}

		// NIF field.
		if ( isset( $row['nif'] ) && '' !== $row['nif'] ) {
			$normalized = ANPA_Socios_Normalize::nif( $row['nif'] );
			$row['nif'] = $normalized ?? '';
		}

		// Titular NIF field (socios_iban entity).
		if ( isset( $row['titular_nif'] ) && '' !== $row['titular_nif'] ) {
			$normalized = ANPA_Socios_Normalize::nif( $row['titular_nif'] );
			$row['titular_nif'] = $normalized ?? '';
		}

		// Telefono field.
		if ( isset( $row['telefono'] ) && '' !== $row['telefono'] ) {
			$normalized = ANPA_Socios_Normalize::telefono( $row['telefono'] );
			$row['telefono'] = $normalized ?? '';
		}

		// IBAN field (if present).
		if ( isset( $row['iban'] ) && '' !== $row['iban'] ) {
			$row['iban'] = ANPA_Socios_Normalize::iban( $row['iban'] );
		}

		// Curso escolar field.
		if ( isset( $row['curso_escolar'] ) && '' !== $row['curso_escolar'] ) {
			$normalized = ANPA_Socios_Normalize::curso_escolar( $row['curso_escolar'] );
			$row['curso_escolar'] = $normalized ?? '';
		}

		return $row;
	}

	/**
	 * Validate required fields for a row.
	 *
	 * @since  1.34.0
	 * @param  string $entity Entity name.
	 * @param  array  $row    Row data (already normalized).
	 * @param  int    $idx    Row index (0-based).
	 * @return array Array of error entries.
	 */
	private static function validate_required( string $entity, array $row, int $idx ): array {
		$errors   = array();
		$required = self::REQUIRED_FIELDS[ $entity ] ?? array();

		foreach ( $required as $field ) {
			if ( ! isset( $row[ $field ] ) || '' === $row[ $field ] ) {
				$errors[] = array(
					'row'   => $idx,
					'field' => $field,
					'msg'   => "Required field '{$field}' is empty",
				);
			}
		}

		return $errors;
	}

	/**
	 * Compute the natural key for dedup.
	 *
	 * Returns a normalized string key or null if the key cannot be computed.
	 *
	 * @since  1.34.0
	 * @param  string $entity Entity name.
	 * @param  array  $row    Normalized row data.
	 * @return string|null Natural key string.
	 */
	public static function compute_natural_key( string $entity, array $row ): ?string {
		switch ( $entity ) {
			case 'socios':
				$nome     = mb_strtolower( $row['nome'] ?? '', 'UTF-8' );
				$apelidos = mb_strtolower( $row['apelidos'] ?? '', 'UTF-8' );
				if ( '' === $nome || '' === $apelidos ) {
					return null;
				}
				return "socios:{$nome}|{$apelidos}";

			case 'empresas':
				$email = $row['email'] ?? '';
				if ( '' === $email ) {
					return null;
				}
				return "empresas:{$email}";

			case 'fillos':
				$nome             = mb_strtolower( $row['nome'] ?? '', 'UTF-8' );
				$apelidos         = mb_strtolower( $row['apelidos'] ?? '', 'UTF-8' );
				$proxenitor_email = $row['proxenitor_email'] ?? '';
				if ( '' === $nome || '' === $apelidos || '' === $proxenitor_email ) {
					return null;
				}
				return "fillos:{$proxenitor_email}|{$nome}|{$apelidos}";

			case 'matriculas':
				$proxenitor_email = $row['proxenitor_email'] ?? '';
				$fillo_nome      = mb_strtolower( $row['fillo_nome'] ?? '', 'UTF-8' );
				$fillo_apelidos  = mb_strtolower( $row['fillo_apelidos'] ?? '', 'UTF-8' );
				$empresa_email   = $row['empresa_email'] ?? '';
				$actividade_nome = mb_strtolower( $row['actividade_nome'] ?? '', 'UTF-8' );
				$curso_escolar   = $row['curso_escolar'] ?? '';
				if ( '' === $proxenitor_email || '' === $fillo_nome || '' === $empresa_email || '' === $actividade_nome || '' === $curso_escolar ) {
					return null;
				}
				return "matriculas:{$proxenitor_email}|{$fillo_nome}|{$fillo_apelidos}|{$empresa_email}|{$actividade_nome}|{$curso_escolar}";

			case 'actividades':
				$empresa_email = $row['empresa_email'] ?? '';
				$nome          = mb_strtolower( $row['nome'] ?? '', 'UTF-8' );
				$curso         = $row['curso_escolar'] ?? '';
				if ( '' === $empresa_email || '' === $nome || '' === $curso ) {
					return null;
				}
				return "actividades:{$empresa_email}|{$nome}|{$curso}";

			case 'socios_iban':
				$id_familia = $row['id_familia'] ?? '';
				if ( '' === $id_familia ) {
					return null;
				}
				return "socios_iban:{$id_familia}";

			default:
				return null;
		}
	}

	/**
	 * Computes the fillo dedup key used for merge on family join.
	 *
	 * Two fillos with the same (nome + apelidos + data_nacemento) under
	 * the same familia_id are considered duplicates. This method produces
	 * the normalized key string for comparison.
	 *
	 * @since  1.34.0
	 * @param  string $nome           Fillo nome.
	 * @param  string $apelidos       Fillo apelidos.
	 * @param  string $data_nacemento Date of birth (YYYY-MM-DD or similar).
	 * @return string Normalized dedup key (lowercase).
	 */
	public static function fillo_dedup_key( string $nome, string $apelidos, string $data_nacemento ): string {
		return mb_strtolower( trim( $nome ), 'UTF-8' ) . '|'
			. mb_strtolower( trim( $apelidos ), 'UTF-8' ) . '|'
			. trim( $data_nacemento );
	}

	/**
	 * Masks an IBAN for safe dry-run display (shows only last 4 digits).
	 *
	 * NEVER returns the full IBAN. Use this in any response or log that
	 * references IBAN data from the socios_iban import.
	 *
	 * @since  1.35.0
	 * @param  string $iban Normalized IBAN.
	 * @return string Masked value, e.g. "****1234".
	 */
	public static function mask_iban_for_report( string $iban ): string {
		$clean = strtoupper( (string) preg_replace( '/\s+/', '', $iban ) );
		if ( strlen( $clean ) < 4 ) {
			return '****';
		}
		return '****' . substr( $clean, -4 );
	}

	/**
	 * Masks a NIF for safe dry-run display (shows only last 4+letter).
	 *
	 * @since  1.35.0
	 * @param  string $nif Normalized NIF.
	 * @return string Masked value, e.g. "****5678Z".
	 */
	public static function mask_nif_for_report( string $nif ): string {
		$nif = strtoupper( trim( $nif ) );
		if ( '' === $nif ) {
			return '';
		}
		if ( strlen( $nif ) <= 5 ) {
			return '****' . $nif;
		}
		return '****' . substr( $nif, -5 );
	}

	/**
	 * Parses CSV text into an array of arrays (one per line).
	 *
	 * Uses fgetcsv for RFC 4180 handling (quoted fields, embedded commas
	 * and newlines). Private to avoid polluting the global namespace in a
	 * public plugin.
	 *
	 * @since  1.34.0
	 * @param  string $text CSV text (newlines normalized to \n).
	 * @return array<int,array<int,string>>
	 */
	private static function parse_lines( string $text ): array {
		$lines  = array();
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return array();
		}
		fwrite( $stream, $text );
		rewind( $stream );

		while ( ( $row = fgetcsv( $stream, 0, ',' ) ) !== false ) {
			$lines[] = $row;
		}

		fclose( $stream );
		return $lines;
	}
}
