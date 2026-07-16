<?php
/**
 * Pure builder for the public Extraescolares weekly schedule grid.
 *
 * Composes the timetable dynamically from active activities and their real
 * time-slot (`franxa`) plus selected días. No WordPress dependency, so the
 * layout logic is unit-testable.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Builds an ordered franxa → día → entries structure from activities.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Horario_Builder {

	/**
	 * Human labels for canonical día tokens.
	 *
	 * @since 1.9.0
	 * @var array<string,string>
	 */
	const DIA_LABELS = array(
		'luns'     => 'Luns',
		'martes'   => 'Martes',
		'mercores' => 'Mércores',
		'xoves'    => 'Xoves',
		'venres'   => 'Venres',
	);

	/**
	 * Builds the schedule grid from a list of active activities.
	 *
	 * Each activity is an associative array with at least `nome`, `franxa`,
	 * `grupos` and `dias` (CSV token strings). Rows are franxas sorted by start
	 * time; columns are canonical weekdays. Empty cells are emitted as empty
	 * arrays so the renderer can build a real table.
	 *
	 * @since  1.9.0
	 * @param  array<int,array<string,mixed>> $activities Active activities.
	 * @return array<int,array<string,mixed>> Ordered grid.
	 */
	public static function build( array $activities ): array {
		$by_franxa = array();

		foreach ( $activities as $act ) {
			$franxa = ANPA_Socios_Actividade_Options::normalize_franxa( $act['franxa'] ?? '' );
			if ( null === $franxa ) {
				continue;
			}

			if ( ! isset( $by_franxa[ $franxa ] ) ) {
				$by_franxa[ $franxa ] = self::empty_row( $franxa );
			}

			$dias   = ANPA_Socios_Actividade_Options::parse( (string) ( $act['dias'] ?? '' ), ANPA_Socios_Actividade_Options::DIAS );
			$grupo_nome = trim( (string) ( $act['grupo_nome'] ?? '' ) );
			$horario    = (string) ( $act['horario'] ?? '' );
			$grupo_labels = array();
			if ( '' !== $grupo_nome ) {
				$label = $grupo_nome;
				if ( '' !== $horario ) {
					$label .= ' — ' . ANPA_Socios_Grupo_Serie::horario_label( $horario );
				}
				$grupo_labels[] = $label;
			}

			$entry = array(
				'nome'    => (string) ( $act['nome'] ?? '' ),
				'grupos'  => $grupo_labels,
				'horario' => $horario,
			);

			foreach ( $dias as $dia ) {
				$by_franxa[ $franxa ]['dias'][ $dia ][] = $entry;
			}
		}

		uksort( $by_franxa, array( __CLASS__, 'compare_franxas' ) );

		$grid = array_values( $by_franxa );
		foreach ( $grid as &$row ) {
			$periodo = '';
			foreach ( ANPA_Socios_Actividade_Options::DIAS as $dia ) {
				usort( $row['dias'][ $dia ], static function ( $a, $b ) {
					return strcasecmp( $a['nome'], $b['nome'] );
				} );
				if ( '' === $periodo ) {
					foreach ( $row['dias'][ $dia ] as $entry ) {
						if ( 'tarde' === ( $entry['horario'] ?? '' ) ) {
							$periodo = 'tarde';
							break 2;
						}
						if ( 'manha' === ( $entry['horario'] ?? '' ) ) {
							$periodo = 'manha';
						}
					}
				}
			}
			$row['periodo'] = $periodo;
		}
		unset( $row );

		return $grid;
	}

	/**
	 * Diagnoses why an activity IS or IS NOT included in the public horario.
	 *
	 * Pure helper — no WordPress dependency. Receives pre-fetched data so it
	 * can be tested without a database.
	 *
	 * @since  1.27.0
	 * @param  array $activity_row Row with keys: estado and curso_estado.
	 * @param  array $grupos       All grupos for the (actividad, curso) pair. Each with 'estado' key.
	 * @param  bool  $curso_is_active Whether the curso_escolar is the active one.
	 * @return string One of: incluida_por_grupo, sen_franxa, sen_dias,
	 *               sen_grupo_aberto, estado_inactivo, curso_non_activo.
	 */
	public static function diagnose( array $activity_row, array $grupos, bool $curso_is_active ): string {
		// Gate: curso must be active.
		if ( ! $curso_is_active ) {
			return 'curso_non_activo';
		}

		// Gate: activity must be active.
		if ( 'activo' !== ( $activity_row['estado'] ?? '' ) ) {
			return 'estado_inactivo';
		}

		// Gate: curso_estado from actividades_cursos row must be active.
		if ( 'activo' !== ( $activity_row['curso_estado'] ?? '' ) ) {
			return 'estado_inactivo';
		}

		foreach ( $grupos as $g ) {
			if ( 'aberto' !== ( $g['estado'] ?? '' ) ) {
				continue;
			}
			if ( null === ANPA_Socios_Actividade_Options::normalize_franxa( $g['franxa'] ?? '' ) ) {
				return 'sen_franxa';
			}
			if ( array() === ANPA_Socios_Actividade_Options::parse( (string) ( $g['dias'] ?? '' ), ANPA_Socios_Actividade_Options::DIAS ) ) {
				return 'sen_dias';
			}
			return 'incluida_por_grupo';
		}

		return 'sen_grupo_aberto';
	}

	/**
	 * Creates an empty row with all weekday columns.
	 *
	 * @since  1.10.0
	 * @param  string $franxa Normalised franxa.
	 * @return array<string,mixed>
	 */
	private static function empty_row( string $franxa ): array {
		$dias = array();
		foreach ( ANPA_Socios_Actividade_Options::DIAS as $dia ) {
			$dias[ $dia ] = array();
		}

		return array(
			'franxa' => $franxa,
			'label'  => str_replace( '-', '–', $franxa ),
			'dias'   => $dias,
		);
	}

	/**
	 * Sorts franxas by start time then end time.
	 *
	 * @since  1.10.0
	 * @param  string $a First franxa.
	 * @param  string $b Second franxa.
	 * @return int
	 */
	private static function compare_franxas( string $a, string $b ): int {
		return self::franxa_minutes( $a ) <=> self::franxa_minutes( $b );
	}

	/**
	 * Converts HH:MM-HH:MM to sortable minutes.
	 *
	 * @since  1.10.0
	 * @param  string $franxa Normalised franxa.
	 * @return int
	 */
	private static function franxa_minutes( string $franxa ): int {
		if ( ! preg_match( '/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $franxa, $m ) ) {
			return PHP_INT_MAX;
		}

		return ( (int) $m[1] * 60 + (int) $m[2] ) * 1440 + ( (int) $m[3] * 60 + (int) $m[4] );
	}
}
