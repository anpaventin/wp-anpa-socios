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
	 * Human labels for canonical curso ranges.
	 *
	 * @since 1.9.0
	 * @var array<string,string>
	 */
	const GRUPO_LABELS = array(
		'1-2-3' => '1º-2º-3º',
		'4-5-6' => '4º-5º-6º',
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
			$grupos = ANPA_Socios_Actividade_Options::parse( (string) ( $act['grupos'] ?? '' ), ANPA_Socios_Actividade_Options::GRUPOS );

			$grupo_labels = array();
			foreach ( $grupos as $g ) {
				$grupo_labels[] = self::GRUPO_LABELS[ $g ] ?? $g;
			}

			$entry = array(
				'nome'   => (string) ( $act['nome'] ?? '' ),
				'grupos' => $grupo_labels,
			);

			foreach ( $dias as $dia ) {
				$by_franxa[ $franxa ]['dias'][ $dia ][] = $entry;
			}
		}

		uksort( $by_franxa, array( __CLASS__, 'compare_franxas' ) );

		$grid = array_values( $by_franxa );
		foreach ( $grid as &$row ) {
			foreach ( ANPA_Socios_Actividade_Options::DIAS as $dia ) {
				usort( $row['dias'][ $dia ], static function ( $a, $b ) {
					return strcasecmp( $a['nome'], $b['nome'] );
				} );
			}
		}
		unset( $row );

		return $grid;
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
