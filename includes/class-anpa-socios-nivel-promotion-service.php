<?php
/**
 * Transactional annual level-promotion service.
 *
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Nivel_Promotion_Service {

	/**
	 * Recalculates active children's levels for the operational school year.
	 *
	 * @return array|WP_Error Result summary or an operational error.
	 */
	public static function run() {
		global $wpdb;

		$school_year = ANPA_Socios_Curso_Activo::get();
		if ( null === $school_year ) {
			return new WP_Error( 'anpa_nivel_promotion_no_active_course', __( 'Non hai un curso escolar activo. Non se modificou ningún fillo.', 'anpa-socios' ) );
		}

		$preflight = self::snapshot( $school_year );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_nivel_promotion_transaction', __( 'Non se puido iniciar a actualización. Non se modificou ningún fillo.', 'anpa-socios' ) );
		}

		if ( ! self::lock_rows( $school_year ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_nivel_promotion_lock', __( 'Non se puideron bloquear os datos para unha actualización segura. Téntao de novo.', 'anpa-socios' ) );
		}
		if ( ANPA_Socios_Curso_Activo::get() !== $school_year ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_nivel_promotion_active_course_changed', __( 'O curso activo cambiou durante a comprobación. Non se modificou ningún fillo; repite a operación.', 'anpa-socios' ) );
		}

		$locked = self::snapshot( $school_year );
		if ( is_wp_error( $locked ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $locked;
		}
		if ( self::fingerprint( $preflight ) !== self::fingerprint( $locked ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_nivel_promotion_changed', __( 'Os niveis ou os fillos cambiaron durante a comprobación. Non se aplicou ningún cambio; repite a operación.', 'anpa-socios' ) );
		}

		foreach ( $locked['items'] as $item ) {
			if ( in_array( $item['action'], array( 'unchanged', 'unchanged_completed' ), true ) ) {
				continue;
			}
			if ( ! ANPA_Socios_DB::upsert_fillo_curso_assignment(
				(int) $item['fillo_id'],
				$school_year,
				(string) $item['curso'],
				(string) $item['aula']
			) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_nivel_promotion_write', __( 'Produciuse un erro ao gardar. Reverteuse toda a operación e non quedou unha actualización parcial.', 'anpa-socios' ) );
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_nivel_promotion_commit', __( 'Non se puido completar a actualización. Non se confirmou ningún cambio.', 'anpa-socios' ) );
		}

		$summary = array(
			'curso_escolar' => $school_year,
			'procesados'    => count( $locked['items'] ),
			'actualizados'  => 0,
			'sen_cambios'   => 0,
			'finalizados'   => 0,
			'emails_cco'    => $locked['emails_cco'],
		);
		foreach ( $locked['items'] as $item ) {
			if ( 'update' === $item['action'] ) {
				++$summary['actualizados'];
			} elseif ( 'unchanged' === $item['action'] ) {
				++$summary['sen_cambios'];
			} else {
				++$summary['finalizados'];
			}
		}

		return $summary;
	}

	/**
	 * Reads and validates a complete deterministic snapshot.
	 *
	 * @param  string $school_year Operational school year.
	 * @return array|WP_Error
	 */
	private static function snapshot( string $school_year ) {
		$levels = self::load_levels( $school_year );
		if ( is_wp_error( $levels ) ) {
			return $levels;
		}
		$children = self::load_children( $school_year );
		if ( is_wp_error( $children ) ) {
			return $children;
		}

		$plan = ANPA_Socios_Nivel_Promotion::build_plan( $school_year, $levels, $children );
		if ( 'ready' !== ( $plan['status'] ?? '' ) ) {
			return self::plan_error( $school_year, $plan );
		}

		$compatibility = self::check_enrolment_compatibility( $school_year, $plan['items'] );
		if ( is_wp_error( $compatibility ) ) {
			return $compatibility;
		}

		return $plan;
	}

	/** @return array|WP_Error */
	private static function load_levels( string $school_year ) {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_niveis();
		$wpdb->last_error = '';
		// Since 1.35.0 niveis are global (no curso_escolar column).
		$rows = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde FROM {$table} WHERE estado = 'activo' ORDER BY orde ASC, id ASC",
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'anpa_nivel_promotion_read_levels', __( 'Non se puideron ler os niveis do curso activo.', 'anpa-socios' ) );
		}
		return $rows;
	}

	/** @return array|WP_Error */
	private static function load_children( string $school_year ) {
		global $wpdb;
		$fillos  = ANPA_Socios_DB::tabela_fillos();
		$socios  = ANPA_Socios_DB::tabela_socios();
		$annual  = ANPA_Socios_DB::tabela_fillos_cursos();
		$wpdb->last_error = '';
		$sql = $wpdb->prepare(
			"SELECT f.id AS fillo_id, f.data_nacemento,
			        COALESCE(NULLIF(fc.aula, ''),
			          NULLIF((SELECT previous.aula FROM {$annual} previous WHERE previous.fillo_id = f.id AND previous.aula <> '' ORDER BY previous.curso_escolar DESC, previous.id DESC LIMIT 1), ''),
			          NULLIF(f.aula, '')) AS aula,
			        principals.principal_email,
			        COALESCE(principals.principal_count, 0) AS principal_count,
			        COALESCE(fc.nivel_id, 0) AS nivel_id, COALESCE(fc.curso, '') AS curso
			 FROM {$fillos} f
			 LEFT JOIN (
			   SELECT familia_id, MIN(email) AS principal_email, COUNT(*) AS principal_count
			   FROM {$socios}
			   WHERE estado = 'activo' AND rol_familia = 'principal'
			   GROUP BY familia_id
			 ) principals ON principals.familia_id = f.familia_id
			 LEFT JOIN {$annual} fc ON fc.fillo_id = f.id AND fc.curso_escolar = %s
			 WHERE f.estado = 'activo'
			 ORDER BY f.id ASC",
			$school_year
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'anpa_nivel_promotion_read_children', __( 'Non se puideron ler os fillos das familias activas.', 'anpa-socios' ) );
		}
		return $rows;
	}

	/**
	 * Ensures current-year operational enrolments accept the calculated level.
	 *
	 * @param  string $school_year Operational school year.
	 * @param  array  $items       Promotion items.
	 * @return true|WP_Error
	 */
	private static function check_enrolment_compatibility( string $school_year, array $items ) {
		global $wpdb;
		$matriculas = ANPA_Socios_DB::tabela_matriculas();
		$grupos     = ANPA_Socios_DB::tabela_grupos();
		$relations  = ANPA_Socios_DB::tabela_grupos_niveis();
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.fillo_id, m.grupo_id, gn.nivel_id
				 FROM {$matriculas} m
				 INNER JOIN {$grupos} g ON g.id = m.grupo_id AND g.curso_escolar = %s
				 LEFT JOIN {$relations} gn ON gn.grupo_id = m.grupo_id
				 WHERE m.estado IN ('activo', 'lista_espera', 'oferta', 'baixa_solicitada')
				 ORDER BY m.id ASC, gn.nivel_id ASC",
				$school_year
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'anpa_nivel_promotion_read_enrolments', __( 'Non se puideron comprobar as matrículas do curso activo.', 'anpa-socios' ) );
		}

		$enrolments = array();
		foreach ( $rows as $row ) {
			$key = (int) $row['id'];
			if ( ! isset( $enrolments[ $key ] ) ) {
				$enrolments[ $key ] = array( 'fillo_id' => (int) $row['fillo_id'], 'grupo_id' => (int) $row['grupo_id'], 'levels' => array() );
			}
			if ( null !== $row['nivel_id'] ) {
				$enrolments[ $key ]['levels'][] = (int) $row['nivel_id'];
			}
		}
		$targets = array();
		foreach ( $items as $item ) {
			$targets[ (int) $item['fillo_id'] ] = (int) $item['nivel_id'];
		}
		foreach ( $enrolments as $enrolment ) {
			$fillo_id = $enrolment['fillo_id'];
			if ( ! array_key_exists( $fillo_id, $targets ) ) {
				continue;
			}
			if ( 0 === $targets[ $fillo_id ] || ! in_array( $targets[ $fillo_id ], $enrolment['levels'], true ) ) {
				return new WP_Error(
					'anpa_nivel_promotion_enrolment_conflict',
					sprintf( __( 'O fillo ID %1$d ten unha matrícula no grupo ID %2$d que non admite o nivel calculado. Non se modificou ningún fillo; revisa esa matrícula ou o grupo.', 'anpa-socios' ), $fillo_id, $enrolment['grupo_id'] )
				);
			}
		}
		return true;
	}

	/** Locks all rows that can affect the snapshot in deterministic order. */
	private static function lock_rows( string $school_year ): bool {
		global $wpdb;
		$courses     = ANPA_Socios_DB::tabela_cursos();
		$levels      = ANPA_Socios_DB::tabela_niveis();
		$socios      = ANPA_Socios_DB::tabela_socios();
		$fillos      = ANPA_Socios_DB::tabela_fillos();
		$annual      = ANPA_Socios_DB::tabela_fillos_cursos();
		$grupos      = ANPA_Socios_DB::tabela_grupos();
		$matriculas  = ANPA_Socios_DB::tabela_matriculas();
		$relations   = ANPA_Socios_DB::tabela_grupos_niveis();
		$queries = array(
			"SELECT id FROM {$courses} WHERE estado = 'activo' ORDER BY id FOR UPDATE",
			// Since 1.35.0 niveis are global (no curso_escolar column).
			"SELECT id FROM {$levels} WHERE estado = 'activo' ORDER BY id FOR UPDATE",
			"SELECT id FROM {$socios} WHERE estado = 'activo' AND rol_familia = 'principal' ORDER BY id FOR UPDATE",
			"SELECT id FROM {$fillos} WHERE estado = 'activo' ORDER BY id FOR UPDATE",
			$wpdb->prepare( "SELECT id FROM {$annual} WHERE curso_escolar = %s ORDER BY id FOR UPDATE", $school_year ),
			$wpdb->prepare( "SELECT id FROM {$grupos} WHERE curso_escolar = %s ORDER BY id FOR UPDATE", $school_year ),
			$wpdb->prepare( "SELECT m.id FROM {$matriculas} m INNER JOIN {$grupos} g ON g.id = m.grupo_id WHERE g.curso_escolar = %s ORDER BY m.id FOR UPDATE", $school_year ),
			$wpdb->prepare( "SELECT gn.grupo_id, gn.nivel_id FROM {$relations} gn INNER JOIN {$grupos} g ON g.id = gn.grupo_id WHERE g.curso_escolar = %s ORDER BY gn.grupo_id, gn.nivel_id FOR UPDATE", $school_year ),
		);
		foreach ( $queries as $query ) {
			$wpdb->last_error = '';
			$wpdb->get_results( $query, ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) {
				return false;
			}
		}
		return true;
	}

	/** Converts a pure-plan validation result to a user-facing WP_Error. */
	private static function plan_error( string $school_year, array $plan ): WP_Error {
		$code = (string) ( $plan['code'] ?? 'invalid_plan' );
		$age  = (int) ( $plan['age'] ?? 0 );
		$id   = (int) ( $plan['fillo_id'] ?? 0 );
		$messages = array(
			'no_levels'              => sprintf( __( 'O curso %s non ten niveis activos creados. Non se modificou ningún fillo.', 'anpa-socios' ), $school_year ),
			'duplicate_age'           => sprintf( __( 'Hai máis dun nivel configurado para alumnado de %d anos. Corrixe a estrutura antes de repetir.', 'anpa-socios' ), $age ),
			'invalid_level'           => __( 'Hai un nivel cunha idade, identificador ou código inválido. Corrixe a estrutura antes de repetir.', 'anpa-socios' ),
			'missing_age'             => sprintf( __( 'O curso %1$s non ten creado un nivel para alumnado de %2$d anos. Non se modificou ningún fillo.', 'anpa-socios' ), $school_year, $age ),
			'invalid_birth_date'      => sprintf( __( 'O fillo ID %d non ten unha data de nacemento válida. Non se modificou ningún fillo.', 'anpa-socios' ), $id ),
			'missing_classroom'       => sprintf( __( 'O fillo ID %d non ten unha aula/letra que se poida conservar. Non se modificou ningún fillo.', 'anpa-socios' ), $id ),
			'invalid_principal_email' => sprintf( __( 'A familia do fillo ID %d non ten un email principal válido. Non se modificou ningún fillo.', 'anpa-socios' ), $id ),
			'invalid_principal_count' => sprintf( __( 'A familia do fillo ID %d non ten exactamente un proxenitor principal activo. Non se modificou ningún fillo.', 'anpa-socios' ), $id ),
		);
		return new WP_Error( 'anpa_nivel_promotion_' . $code, $messages[ $code ] ?? __( 'Os datos non permiten calcular os niveis con seguridade. Non se modificou ningún fillo.', 'anpa-socios' ) );
	}

	/** Stable snapshot fingerprint used for the concurrent-change gate. */
	private static function fingerprint( array $plan ): string {
		return hash( 'sha256', wp_json_encode( $plan ) );
	}
}
