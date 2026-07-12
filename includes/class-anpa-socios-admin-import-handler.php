<?php
/**
 * Admin REST handler for CSV import (all entities).
 *
 * Receives raw CSV text, delegates parsing/analysis to the pure
 * ANPA_Socios_Csv_Import core, and optionally commits rows to DB.
 *
 * @since  1.34.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for POST /admin/import/{entity}.
 *
 * @since 1.34.0
 */
final class ANPA_Socios_Admin_Import_Handler {

	/**
	 * Entities that can be imported.
	 *
	 * @since 1.34.0
	 * @var string[]
	 */
	const VALID_ENTITIES = array( 'socios', 'fillos', 'empresas', 'actividades', 'matriculas' );

	/**
	 * Maximum preview rows returned in dry-run.
	 *
	 * @since 1.34.0
	 * @var int
	 */
	const PREVIEW_LIMIT = 20;

	/**
	 * Registers the import route.
	 *
	 * @since  1.34.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			ANPA_Socios_Admin_REST::REST_NAMESPACE,
			'/import/(?P<entity>socios|fillos|empresas|actividades|matriculas)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_import' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			)
		);
	}

	/**
	 * POST /admin/import/{entity}
	 *
	 * Body: { csv: "<raw text>", commit: 0|1 }
	 *
	 * @since  1.34.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_import( WP_REST_Request $request ) {
		$entity = (string) $request->get_param( 'entity' );
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$csv    = isset( $body['csv'] ) ? (string) $body['csv'] : '';
		$commit = ! empty( $body['commit'] );

		if ( '' === $csv ) {
			return new WP_Error( 'anpa_import_empty', 'CSV baleiro', array( 'status' => 400 ) );
		}

		// Parse CSV.
		$rows = ANPA_Socios_Csv_Import::parse( $csv );
		if ( empty( $rows ) ) {
			return new WP_Error( 'anpa_import_no_rows', 'O CSV non contén filas válidas', array( 'status' => 400 ) );
		}

		// Build existing keys from DB.
		$existing_keys = self::build_existing_keys( $entity );

		// Analyze (normalize + validate + dedup).
		$report = ANPA_Socios_Csv_Import::analyze( $entity, $rows, $existing_keys );

		if ( ! $commit ) {
			// Dry-run response.
			$preview = array_slice( $report['to_insert'], 0, self::PREVIEW_LIMIT );
			return new WP_REST_Response( array(
				'total'            => count( $rows ),
				'to_insert_count'  => count( $report['to_insert'] ),
				'duplicates_count' => count( $report['duplicates'] ),
				'errors'           => $report['errors'],
				'preview'          => $preview,
			), 200 );
		}

		// Commit mode.
		$result = self::commit_rows( $entity, $report['to_insert'], $request );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Builds existing natural keys for the given entity from the DB.
	 *
	 * @since  1.34.0
	 * @param  string $entity Entity name.
	 * @return string[]
	 */
	private static function build_existing_keys( string $entity ): array {
		global $wpdb;
		$keys = array();

		switch ( $entity ) {
			case 'socios':
				$table = ANPA_Socios_DB::tabela_socios();
				$rows  = $wpdb->get_results(
					"SELECT nome, apelidos FROM {$table} WHERE rol <> 'master'",
					ARRAY_A
				);
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$n = mb_strtolower( $r['nome'] ?? '', 'UTF-8' );
						$a = mb_strtolower( $r['apelidos'] ?? '', 'UTF-8' );
						if ( '' !== $n && '' !== $a ) {
							$keys[] = "socios:{$n}|{$a}";
						}
					}
				}
				break;

			case 'empresas':
				$table = ANPA_Socios_DB::tabela_empresas();
				$rows  = $wpdb->get_results( "SELECT email FROM {$table}", ARRAY_A );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$e = $r['email'] ?? '';
						if ( '' !== $e ) {
							$keys[] = "empresas:{$e}";
						}
					}
				}
				break;

			case 'fillos':
				$table = ANPA_Socios_DB::tabela_fillos();
				$rows  = $wpdb->get_results(
					"SELECT nome, apelidos, socio_email FROM {$table}",
					ARRAY_A
				);
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$n  = mb_strtolower( $r['nome'] ?? '', 'UTF-8' );
						$a  = mb_strtolower( $r['apelidos'] ?? '', 'UTF-8' );
						$pe = $r['socio_email'] ?? '';
						if ( '' !== $n && '' !== $a && '' !== $pe ) {
							$keys[] = "fillos:{$pe}|{$n}|{$a}";
						}
					}
				}
				break;

			case 'actividades':
				$act_t = ANPA_Socios_DB::tabela_actividades();
				$emp_t = ANPA_Socios_DB::tabela_empresas();
				$rows  = $wpdb->get_results(
					"SELECT a.nome, e.email AS empresa_email, a.curso_escolar
					 FROM {$act_t} a
					 LEFT JOIN {$emp_t} e ON e.id = a.empresa_id",
					ARRAY_A
				);
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$ee = $r['empresa_email'] ?? '';
						$n  = mb_strtolower( $r['nome'] ?? '', 'UTF-8' );
						$c  = $r['curso_escolar'] ?? '';
						if ( '' !== $ee && '' !== $n && '' !== $c ) {
							$keys[] = "actividades:{$ee}|{$n}|{$c}";
						}
					}
				}
				break;

			case 'matriculas':
				$mat_t = ANPA_Socios_DB::tabela_matriculas();
				$fil_t = ANPA_Socios_DB::tabela_fillos();
				$act_t = ANPA_Socios_DB::tabela_actividades();
				$emp_t = ANPA_Socios_DB::tabela_empresas();
				$soc_t = ANPA_Socios_DB::tabela_socios();
				$rows  = $wpdb->get_results(
					"SELECT f.socio_email AS proxenitor_email, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
					        e.email AS empresa_email, a.nome AS actividade_nome, a.curso_escolar
					 FROM {$mat_t} m
					 JOIN {$fil_t} f ON f.id = m.fillo_id
					 JOIN {$act_t} a ON a.id = m.activitad_id
					 LEFT JOIN {$emp_t} e ON e.id = a.empresa_id",
					ARRAY_A
				);
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$pe  = $r['proxenitor_email'] ?? '';
						$fn  = mb_strtolower( $r['fillo_nome'] ?? '', 'UTF-8' );
						$fa  = mb_strtolower( $r['fillo_apelidos'] ?? '', 'UTF-8' );
						$ee  = $r['empresa_email'] ?? '';
						$an  = mb_strtolower( $r['actividade_nome'] ?? '', 'UTF-8' );
						$ce  = $r['curso_escolar'] ?? '';
						if ( '' !== $pe && '' !== $fn && '' !== $ee && '' !== $an && '' !== $ce ) {
							$keys[] = "matriculas:{$pe}|{$fn}|{$fa}|{$ee}|{$an}|{$ce}";
						}
					}
				}
				break;
		}

		return $keys;
	}

	/**
	 * Commits validated rows to the database.
	 *
	 * @since  1.34.0
	 * @param  string          $entity  Entity name.
	 * @param  array           $rows    Rows to insert (already normalized/deduped).
	 * @param  WP_REST_Request $request For audit.
	 * @return array{inserted:int,skipped:int,errors:array}
	 */
	private static function commit_rows( string $entity, array $rows, WP_REST_Request $request ): array {
		switch ( $entity ) {
			case 'socios':
				return self::commit_socios( $rows, $request );
			case 'fillos':
				return self::commit_fillos( $rows, $request );
			case 'empresas':
				return self::commit_empresas( $rows, $request );
			case 'actividades':
				return self::commit_actividades( $rows, $request );
			case 'matriculas':
				return self::commit_matriculas( $rows, $request );
			default:
				return array( 'inserted' => 0, 'skipped' => 0, 'errors' => array() );
		}
	}

	/**
	 * Commits socios with logical→real familia_id mapping.
	 *
	 * For each logical id_familia, the FIRST inserted socio's DB id
	 * becomes the family's real familia_id. Subsequent socios with the
	 * same logical id_familia share that real familia_id.
	 *
	 * @since  1.34.0
	 * @param  array           $rows    Rows to insert.
	 * @param  WP_REST_Request $request For audit.
	 * @return array{inserted:int,skipped:int,errors:array}
	 */
	private static function commit_socios( array $rows, WP_REST_Request $request ): array {
		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_socios();
		$inserted = 0;
		$skipped  = 0;
		$errors   = array();

		// logical_id_familia => real DB familia_id.
		$familia_map = array();

		foreach ( $rows as $idx => $row ) {
			$email      = $row['email'] ?? '';
			$nome       = $row['nome'] ?? '';
			$apelidos   = $row['apelidos'] ?? '';
			$nif        = $row['nif'] ?? '';
			$telefono   = $row['telefono'] ?? '';
			$estado     = $row['estado'] ?? 'activo';
			$rol        = $row['rol_familia'] ?? 'principal';
			$logical_fam = $row['id_familia'] ?? '';

			// Compute real_familia_id early (needed for downstream upsert).
			$real_familia_id = null;
			if ( '' !== $logical_fam && isset( $familia_map[ $logical_fam ] ) ) {
				$real_familia_id = $familia_map[ $logical_fam ];
			}

			// Idempotent: skip if socio already exists by nome+apelidos.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE LOWER(nome) = %s AND LOWER(apelidos) = %s LIMIT 1",
				mb_strtolower( $nome, 'UTF-8' ),
				mb_strtolower( $apelidos, 'UTF-8' )
			) );
			if ( $exists ) {
				// Still map their familia_id for subsequent rows.
				if ( '' !== $logical_fam && ! isset( $familia_map[ $logical_fam ] ) ) {
					$fam_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT COALESCE(NULLIF(familia_id, 0), id) FROM {$table} WHERE id = %d",
						(int) $exists
					) );
					$familia_map[ $logical_fam ] = (int) $fam_id;
					$real_familia_id = (int) $fam_id;
				}
				self::upsert_segundo_proxenitor( $table, $row, $real_familia_id, $wpdb, $idx, $errors, $inserted );
				$skipped++;
				continue;
			}

			// Also skip if email already exists (UNIQUE constraint).
			if ( '' !== $email ) {
				$email_exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE email = %s LIMIT 1",
					$email
				) );
				if ( $email_exists ) {
					if ( '' !== $logical_fam && ! isset( $familia_map[ $logical_fam ] ) ) {
						$fam_id = $wpdb->get_var( $wpdb->prepare(
							"SELECT COALESCE(NULLIF(familia_id, 0), id) FROM {$table} WHERE id = %d",
							(int) $email_exists
						) );
						$familia_map[ $logical_fam ] = (int) $fam_id;
						$real_familia_id = (int) $fam_id;
					}
					self::upsert_segundo_proxenitor( $table, $row, $real_familia_id, $wpdb, $idx, $errors, $inserted );
					$skipped++;
					continue;
				}
			}

			// Map rol_familia to DB rol column.
			$db_rol = 'socio';
			if ( 'secundario' === strtolower( $rol ) ) {
				$db_rol = 'socio';
			}

			$insert_data = array(
				'email'    => $email,
				'nome'     => $nome,
				'apelidos' => $apelidos,
				'nif'      => $nif,
				'telefono' => $telefono,
				'estado'   => in_array( $estado, array( 'activo', 'baixa' ), true ) ? $estado : 'activo',
				'rol'      => $db_rol,
			);
			$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

			if ( null !== $real_familia_id ) {
				$insert_data['familia_id'] = $real_familia_id;
				$formats[] = '%d';
			}

			$ok = $wpdb->insert( $table, $insert_data, $formats );
			if ( false === $ok ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'DB insert failed' );
				continue;
			}

			$new_id = (int) $wpdb->insert_id;
			$inserted++;

			// Logical→real familia mapping: first socio in this family
			// sets familia_id = own id.
			if ( '' !== $logical_fam && ! isset( $familia_map[ $logical_fam ] ) ) {
				$familia_map[ $logical_fam ] = $new_id;
				// Set this socio's familia_id to its own id.
				$wpdb->update( $table, array( 'familia_id' => $new_id ), array( 'id' => $new_id ), array( '%d' ), array( '%d' ) );
				$real_familia_id = $new_id;
			} elseif ( '' !== $logical_fam && null === $real_familia_id ) {
				// Shouldn't reach here but guard: update to resolved value.
				$wpdb->update( $table, array( 'familia_id' => $familia_map[ $logical_fam ] ), array( 'id' => $new_id ), array( '%d' ), array( '%d' ) );
				$real_familia_id = $familia_map[ $logical_fam ];
			}

			// Upsert segundo proxenitor after principal is established.
			self::upsert_segundo_proxenitor( $table, $row, $real_familia_id, $wpdb, $idx, $errors, $inserted );
		}

		if ( $inserted > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'socios', 'import_commit' );
		}

		// Task 7: merge duplicate fillos under each familia that was joined.
		$merge_stats = array();
		foreach ( $familia_map as $logical => $real_fam_id ) {
			$ms = self::merge_duplicate_fillos( $real_fam_id );
			if ( $ms['merged'] > 0 ) {
				$merge_stats[] = array( 'familia_id' => $real_fam_id, 'merged' => $ms['merged'] );
			}
		}

		return array( 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors, 'familia_map' => $familia_map, 'fillo_merges' => $merge_stats );
	}

	/**
	 * Commits fillos. Resolves real familia_id from the parent email.
	 *
	 * The CSV provides `proxenitor_email` which maps to a socio's email.
	 * From the socio row we resolve the familia_id.
	 *
	 * @since  1.34.0
	 * @param  array           $rows    Fillos rows.
	 * @param  WP_REST_Request $request For audit.
	 * @return array{inserted:int,skipped:int,errors:array}
	 */
	private static function commit_fillos( array $rows, WP_REST_Request $request ): array {
		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_fillos();
		$soc_t    = ANPA_Socios_DB::tabela_socios();
		$inserted = 0;
		$skipped  = 0;
		$errors   = array();

		// Cache email → familia_id resolution.
		$email_familia_cache = array();

		foreach ( $rows as $idx => $row ) {
			$proxenitor_email = (string) ( $row['proxenitor_email'] ?? '' );
			$nome             = $row['nome'] ?? '';
			$apelidos         = $row['apelidos'] ?? '';
			$nacemento        = $row['data_nacemento'] ?? '';
			$curso            = $row['curso'] ?? '';
			$aula             = $row['aula'] ?? '';
			$consent          = $row['image_consent'] ?? '0';
			$estado           = $row['estado'] ?? 'activo';

			// Resolve real familia_id from parent email.
			$real_fam = null;
			if ( '' !== $proxenitor_email ) {
				if ( isset( $email_familia_cache[ $proxenitor_email ] ) ) {
					$real_fam = $email_familia_cache[ $proxenitor_email ];
				} else {
					$soc_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, familia_id FROM {$soc_t} WHERE email = %s LIMIT 1",
						$proxenitor_email
					), ARRAY_A );
					if ( is_array( $soc_row ) ) {
						$fam_id = ( null !== $soc_row['familia_id'] && (int) $soc_row['familia_id'] > 0 )
							? (int) $soc_row['familia_id']
							: (int) $soc_row['id'];
						$real_fam = $fam_id;
						$email_familia_cache[ $proxenitor_email ] = $real_fam;
					}
				}
			}

			if ( null === $real_fam ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Cannot resolve familia for proxenitor_email={$proxenitor_email}" );
				continue;
			}

			// Idempotent: skip if fillo already exists by (familia_id + nome + apelidos).
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE familia_id = %d AND LOWER(nome) = %s AND LOWER(apelidos) = %s LIMIT 1",
				$real_fam,
				mb_strtolower( $nome, 'UTF-8' ),
				mb_strtolower( $apelidos, 'UTF-8' )
			) );
			if ( $exists ) {
				$skipped++;
				continue;
			}

			$ok = $wpdb->insert( $table, array(
				'socio_email'    => $proxenitor_email,
				'familia_id'     => $real_fam,
				'nome'           => $nome,
				'apelidos'       => $apelidos,
				'data_nacemento' => $nacemento,
				'curso'          => $curso,
				'aula'           => strtoupper( $aula ),
				'image_consent'  => ( '1' === $consent ) ? '1' : '0',
				'estado'         => in_array( $estado, array( 'activo', 'baixa' ), true ) ? $estado : 'activo',
			), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

			if ( false === $ok ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'DB insert failed' );
				continue;
			}
			$inserted++;
		}

		if ( $inserted > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'fillos', 'import_commit' );
		}

		return array( 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors );
	}

	/**
	 * Commits empresas rows.
	 *
	 * @since  1.34.0
	 * @param  array           $rows    Rows.
	 * @param  WP_REST_Request $request For audit.
	 * @return array{inserted:int,skipped:int,errors:array}
	 */
	private static function commit_empresas( array $rows, WP_REST_Request $request ): array {
		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_empresas();
		$inserted = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $rows as $idx => $row ) {
			$email = $row['email'] ?? '';
			if ( '' === $email ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'Email required for empresa' );
				continue;
			}

			// Idempotent: skip if email exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE email = %s LIMIT 1",
				$email
			) );
			if ( $exists ) {
				$skipped++;
				continue;
			}

			$ok = $wpdb->insert( $table, array(
				'nome'        => $row['nome'] ?? '',
				'email'       => $email,
				'responsable' => $row['responsable'] ?? '',
				'telefono'    => $row['telefono'] ?? '',
				'url_web'     => $row['url_web'] ?? '',
				'estado'      => in_array( $row['estado'] ?? '', array( 'activo', 'inactivo' ), true ) ? $row['estado'] : 'activo',
			), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );

			if ( false === $ok ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'DB insert failed' );
				continue;
			}
			$inserted++;
		}

		if ( $inserted > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'empresas', 'import_commit' );
		}

		return array( 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors );
	}

	/**
	 * Commits actividades rows. Resolves empresa_id from empresa_email.
	 *
	 * @since  1.34.0
	 * @param  array           $rows    Rows.
	 * @param  WP_REST_Request $request For audit.
	 * @return array{inserted:int,skipped:int,errors:array}
	 */
	private static function commit_actividades( array $rows, WP_REST_Request $request ): array {
		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_actividades();
		$emp_t    = ANPA_Socios_DB::tabela_empresas();
		$inserted = 0;
		$skipped  = 0;
		$errors   = array();

		// Cache empresa email→id.
		$empresa_cache = array();
		$emp_rows = $wpdb->get_results( "SELECT id, email FROM {$emp_t}", ARRAY_A );
		if ( is_array( $emp_rows ) ) {
			foreach ( $emp_rows as $e ) {
				$empresa_cache[ $e['email'] ] = (int) $e['id'];
			}
		}

		foreach ( $rows as $idx => $row ) {
			$empresa_email = $row['empresa_email'] ?? '';
			$nome          = $row['nome'] ?? '';
			$curso_escolar = $row['curso_escolar'] ?? '';

			if ( ! isset( $empresa_cache[ $empresa_email ] ) ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Empresa non atopada: {$empresa_email}" );
				continue;
			}
			$empresa_id = $empresa_cache[ $empresa_email ];

			// Idempotent: skip if (empresa_id + nome + curso_escolar) exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE empresa_id = %d AND LOWER(nome) = %s AND curso_escolar = %s LIMIT 1",
				$empresa_id,
				mb_strtolower( $nome, 'UTF-8' ),
				$curso_escolar
			) );
			if ( $exists ) {
				$skipped++;
				continue;
			}

			$ok = $wpdb->insert( $table, array(
				'empresa_id'    => $empresa_id,
				'nome'          => $nome,
				'descripcion'   => $row['descripcion'] ?? '',
				'curso_escolar' => $curso_escolar,
				'min_pupilos'   => (int) ( $row['min_pupilos'] ?? 10 ),
				'max_pupilos'   => (int) ( $row['max_pupilos'] ?? 15 ),
				'idade_min'     => (int) ( $row['idade_min'] ?? 0 ),
				'idade_max'     => (int) ( $row['idade_max'] ?? 0 ),
				'custo'         => (float) ( $row['custo'] ?? 0 ),
				'estado'        => in_array( $row['estado'] ?? '', array( 'activo', 'inactivo' ), true ) ? $row['estado'] : 'inactivo',
			), array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s' ) );

			if ( false === $ok ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'DB insert failed' );
				continue;
			}
			$inserted++;
		}

		if ( $inserted > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'actividades', 'import_commit' );
		}

		return array( 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors );
	}

	/**
	 * Commits matriculas. Resolves fillo and actividade by natural keys.
	 *
	 * Uses proxenitor_email to find the family, then looks up the fillo
	 * by (familia_id + nome + apelidos). Never creates/updates fillos or
	 * actividades — lookup only.
	 *
	 * @since  1.34.0
	 * @param  array           $rows    Rows.
	 * @param  WP_REST_Request $request For audit.
	 * @return array{inserted:int,skipped:int,errors:array}
	 */
	private static function commit_matriculas( array $rows, WP_REST_Request $request ): array {
		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_matriculas();
		$fil_t    = ANPA_Socios_DB::tabela_fillos();
		$act_t    = ANPA_Socios_DB::tabela_actividades();
		$emp_t    = ANPA_Socios_DB::tabela_empresas();
		$soc_t    = ANPA_Socios_DB::tabela_socios();
		$inserted = 0;
		$skipped  = 0;
		$errors   = array();

		// Cache empresa email→id.
		$empresa_cache = array();
		$emp_rows = $wpdb->get_results( "SELECT id, email FROM {$emp_t}", ARRAY_A );
		if ( is_array( $emp_rows ) ) {
			foreach ( $emp_rows as $e ) {
				$empresa_cache[ $e['email'] ] = (int) $e['id'];
			}
		}

		// Cache proxenitor_email → familia_id.
		$email_familia_cache = array();

		foreach ( $rows as $idx => $row ) {
			$proxenitor_email = (string) ( $row['proxenitor_email'] ?? '' );
			$fillo_nome       = $row['fillo_nome'] ?? '';
			$fillo_apelidos   = $row['fillo_apelidos'] ?? '';
			$empresa_email    = $row['empresa_email'] ?? '';
			$act_nome         = $row['actividade_nome'] ?? '';
			$curso_escolar    = $row['curso_escolar'] ?? '';

			// Resolve real familia_id from proxenitor_email.
			$real_fam = null;
			if ( '' !== $proxenitor_email ) {
				if ( isset( $email_familia_cache[ $proxenitor_email ] ) ) {
					$real_fam = $email_familia_cache[ $proxenitor_email ];
				} else {
					$soc_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, familia_id FROM {$soc_t} WHERE email = %s LIMIT 1",
						$proxenitor_email
					), ARRAY_A );
					if ( is_array( $soc_row ) ) {
						$fam_id = ( null !== $soc_row['familia_id'] && (int) $soc_row['familia_id'] > 0 )
							? (int) $soc_row['familia_id']
							: (int) $soc_row['id'];
						$real_fam = $fam_id;
						$email_familia_cache[ $proxenitor_email ] = $real_fam;
					}
				}
			}

			if ( null === $real_fam ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Cannot resolve familia for proxenitor_email={$proxenitor_email}" );
				continue;
			}

			// Resolve fillo_id by familia_id + nome + apelidos (lookup only, never creates).
			$fillo_id = null;
			if ( '' !== $fillo_nome ) {
				$fillo_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$fil_t} WHERE familia_id = %d AND LOWER(nome) = %s AND LOWER(apelidos) = %s LIMIT 1",
					$real_fam,
					mb_strtolower( $fillo_nome, 'UTF-8' ),
					mb_strtolower( $fillo_apelidos, 'UTF-8' )
				) );
			}
			if ( ! $fillo_id ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Fillo non atopado: {$fillo_nome} {$fillo_apelidos}" );
				continue;
			}

			// Resolve actividade_id by empresa_email + nome + curso_escolar (lookup only, never creates).
			$empresa_id = $empresa_cache[ $empresa_email ] ?? null;
			if ( ! $empresa_id ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Empresa non atopada: {$empresa_email}" );
				continue;
			}
			$actividade_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$act_t} WHERE empresa_id = %d AND LOWER(nome) = %s AND curso_escolar = %s LIMIT 1",
				$empresa_id,
				mb_strtolower( $act_nome, 'UTF-8' ),
				$curso_escolar
			) );
			if ( ! $actividade_id ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Actividade non atopada: {$act_nome} ({$curso_escolar})" );
				continue;
			}

			// Idempotent: skip if (fillo_id, activitad_id) already exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE fillo_id = %d AND activitad_id = %d LIMIT 1",
				(int) $fillo_id,
				(int) $actividade_id
			) );
			if ( $exists ) {
				$skipped++;
				continue;
			}

			$ok = $wpdb->insert( $table, array(
				'fillo_id'      => (int) $fillo_id,
				'activitad_id'  => (int) $actividade_id,
				'trimestre'     => ANPA_Socios_Trimestre::actual( (int) current_time( 'n' ) ),
				'estado'        => in_array( $row['estado'] ?? '', array( 'activo', 'baixa' ), true ) ? $row['estado'] : 'activo',
				'comedor'       => ( '1' === ( $row['comedor'] ?? '0' ) ) ? 1 : 0,
				'tarde'         => ( '1' === ( $row['tarde'] ?? '0' ) ) ? 1 : 0,
				'observaciones' => $row['observaciones'] ?? '',
			), array( '%d', '%d', '%d', '%s', '%d', '%d', '%s' ) );

			if ( false === $ok ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'DB insert failed' );
				continue;
			}
			$inserted++;
		}

		if ( $inserted > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'matriculas', 'import_commit' );
		}

		return array( 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors );
	}

	/**
	 * Merges duplicate fillos when two parents join into one familia_id.
	 *
	 * When importing socios that share a logical id_familia, fillos
	 * with the same normalized (nome+apelidos+data_nacemento) under that
	 * familia_id are deduplicated: keeps the earliest row active, marks
	 * later duplicates as baixa, and re-points their matriculas to the
	 * surviving fillo. Idempotent.
	 *
	 * Called after commit_socios + commit_fillos when familia grouping
	 * may have produced duplicates.
	 *
	 * @since  1.34.0
	 * @param  int $familia_id The real familia_id to check.
	 * @return array{merged:int,kept:int}
	 */
	public static function merge_duplicate_fillos( int $familia_id ): array {
		global $wpdb;
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$merged = 0;
		$kept   = 0;

		// Find all fillos for this family grouped by dedup key.
		$fillos = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, nome, apelidos, data_nacemento, estado
			 FROM {$fil_t}
			 WHERE familia_id = %d
			 ORDER BY id ASC",
			$familia_id
		), ARRAY_A );

		if ( ! is_array( $fillos ) || count( $fillos ) < 2 ) {
			return array( 'merged' => 0, 'kept' => count( $fillos ?: array() ) );
		}

		// Group by normalized dedup key: nome|apelidos|data_nacemento.
		$groups = array();
		foreach ( $fillos as $f ) {
			$key = mb_strtolower( $f['nome'], 'UTF-8' ) . '|'
				. mb_strtolower( $f['apelidos'], 'UTF-8' ) . '|'
				. ( $f['data_nacemento'] ?? '' );
			$groups[ $key ][] = $f;
		}

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				$kept++;
				continue;
			}
			// Keep the first (earliest id); merge the rest into it.
			$survivor = $group[0];
			$kept++;
			for ( $i = 1, $c = count( $group ); $i < $c; $i++ ) {
				$dup_id = (int) $group[ $i ]['id'];
				// Re-point matriculas from duplicate to survivor.
				$wpdb->update(
					$mat_t,
					array( 'fillo_id' => (int) $survivor['id'] ),
					array( 'fillo_id' => $dup_id ),
					array( '%d' ),
					array( '%d' )
				);
				// Mark duplicate as baixa.
				$wpdb->update(
					$fil_t,
					array( 'estado' => 'baixa' ),
					array( 'id' => $dup_id ),
					array( '%s' ),
					array( '%d' )
				);
				$merged++;
			}
		}

		return array( 'merged' => $merged, 'kept' => $kept );
	}

	/**
	 * Upserts a secundario socio row from segundo_proxenitor_* CSV fields.
	 *
	 * When a CSV row for a principal includes segundo_proxenitor data, this
	 * method creates or updates the corresponding secundario row in the same
	 * family. If a secundario already exists (by familia_id + rol_familia), its
	 * fields are updated. Otherwise a new row with rol_familia='secundario' is
	 * inserted. Empty segundo_proxenitor fields are silently ignored (UPDATE
	 * only touches non-empty fields; INSERT fills empties as '').
	 *
	 * @since  1.37.0
	 * @param  string   $table           Socios table name.
	 * @param  array    $row             CSV row (may contain segundo_proxenitor_*).
	 * @param  int|null $real_familia_id Real DB familia_id of the family.
	 * @param  wpdb     $wpdb            WordPress database object.
	 * @param  int      $idx             Row index (0-based) for error reporting.
	 * @param  array    &$errors         Error accumulator (by reference).
	 * @param  int      &$inserted       Inserted count accumulator (by reference).
	 * @return void
	 */
	private static function upsert_segundo_proxenitor(
		string $table,
		array $row,
		?int $real_familia_id,
		$wpdb,
		int $idx,
		array &$errors,
		int &$inserted
	): void {
		// Check if any segundo_proxenitor field is populated.
		$has_data = false;
		foreach ( array( 'segundo_proxenitor_nome', 'segundo_proxenitor_apelidos', 'segundo_proxenitor_email', 'segundo_proxenitor_nif', 'segundo_proxenitor_telefono' ) as $f ) {
			if ( '' !== ( $row[ $f ] ?? '' ) ) {
				$has_data = true;
				break;
			}
		}
		if ( ! $has_data || null === $real_familia_id ) {
			return;
		}

		// Check if a secundario already exists for this family.
		$sec_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE familia_id = %d AND rol_familia = 'secundario' LIMIT 1",
			$real_familia_id
		) );

		$nome     = $row['segundo_proxenitor_nome'] ?? '';
		$apelidos = $row['segundo_proxenitor_apelidos'] ?? '';
		$email    = $row['segundo_proxenitor_email'] ?? '';
		$nif      = $row['segundo_proxenitor_nif'] ?? '';
		$telefono = $row['segundo_proxenitor_telefono'] ?? '';

		if ( $sec_exists ) {
			// UPDATE existing secundario — only non-empty fields.
			$update_data = array();
			$formats     = array();
			if ( '' !== $nome ) { $update_data['nome'] = $nome; $formats[] = '%s'; }
			if ( '' !== $apelidos ) { $update_data['apelidos'] = $apelidos; $formats[] = '%s'; }
			if ( '' !== $email ) { $update_data['email'] = $email; $formats[] = '%s'; }
			if ( '' !== $nif ) { $update_data['nif'] = $nif; $formats[] = '%s'; }
			if ( '' !== $telefono ) { $update_data['telefono'] = $telefono; $formats[] = '%s'; }

			if ( ! empty( $update_data ) ) {
				$wpdb->update( $table, $update_data, array( 'id' => $sec_exists ), $formats, array( '%d' ) );
			}
		} else {
			// INSERT new secundario.
			$ok = $wpdb->insert( $table, array(
				'familia_id'  => $real_familia_id,
				'rol_familia' => 'secundario',
				'rol'         => 'socio',
				'estado'      => 'activo',
				'nome'        => $nome,
				'apelidos'    => $apelidos,
				'email'       => $email,
				'nif'         => $nif,
				'telefono'    => $telefono,
			), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

			if ( false === $ok ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'DB insert failed for segundo proxenitor' );
				return;
			}
			$inserted++;
		}
	}
}
