<?php
/**
 * Pure read model for the Grupos e horarios admin aggregate.
 *
 * @since  1.44.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a non-PII projection from annual levels and activity-owned groups.
 */
final class ANPA_Socios_Grupos_Horarios {

	private const DIA_LABELS = array(
		'luns'     => 'Luns',
		'martes'   => 'Martes',
		'mercores' => 'Mércores',
		'xoves'    => 'Xoves',
		'venres'   => 'Venres',
	);

	/**
	 * @param string                   $curso_escolar Annual course.
	 * @param array<int,array<string,mixed>> $level_rows Level query rows.
	 * @param array<int,array<string,mixed>> $group_rows Group-level query rows.
	 * @return array<string,mixed>|array{}
	 */
	public static function build( string $curso_escolar, array $level_rows, array $group_rows ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return array();
		}

		$niveis = array();
		$meals  = array();
		foreach ( $level_rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id < 1 ) {
				continue;
			}
			$interval = ANPA_Socios_Disponibilidade_Horaria::normalize_interval(
				$row['comedor_inicio'] ?? null,
				$row['comedor_fin'] ?? null
			);
			$comedor = null;
			if ( is_array( $interval ) && array() !== $interval ) {
				$comedor = array(
					'id'     => (int) ( $row['horario_id'] ?? 0 ),
					'nome'   => (string) ( $row['horario_nome'] ?? '' ),
					'inicio' => $interval['inicio'],
					'fin'    => $interval['fin'],
				);
				$meals[ $id ] = $interval;
			}
			$niveis[] = array(
				'id'       => $id,
				'codigo'   => (string) ( $row['codigo'] ?? '' ),
				'etiqueta' => (string) ( $row['etiqueta'] ?? '' ),
				'orde'     => (int) ( $row['orde'] ?? 0 ),
				'comedor'  => $comedor,
			);
		}

		$slots    = array();
		$franxas  = array();
		foreach ( $group_rows as $row ) {
			$grupo_id      = (int) ( $row['grupo_id'] ?? 0 );
			$actividade_id = (int) ( $row['actividade_id'] ?? 0 );
			$nivel_id      = (int) ( $row['nivel_id'] ?? 0 );
			$franxa        = ANPA_Socios_Actividade_Options::normalize_franxa( $row['franxa'] ?? null );
			$dias          = ANPA_Socios_Actividade_Options::normalize(
				$row['dias'] ?? null,
				ANPA_Socios_Actividade_Options::DIAS
			);
			if ( $grupo_id < 1 || $actividade_id < 1 || $nivel_id < 1 || null === $franxa || array() === $dias ) {
				continue;
			}

			$franxas[ $franxa ] = true;
			$conflicts = ANPA_Socios_Disponibilidade_Horaria::conflicts(
				array( 'horario' => $franxa, 'dias' => $dias ),
				isset( $meals[ $nivel_id ] ) ? array( $nivel_id => $meals[ $nivel_id ] ) : array()
			);
			foreach ( $dias as $dia ) {
				$slots[] = array(
					'slot_key'         => $grupo_id . ':' . $nivel_id . ':' . $dia,
					'dia'              => $dia,
					'nivel_id'         => $nivel_id,
					'grupo_id'         => $grupo_id,
					'actividade_id'    => $actividade_id,
					'serie_uid'        => (string) ( $row['serie_uid'] ?? '' ),
					'actividade_nome'  => (string) ( $row['actividade_nome'] ?? '' ),
					'grupo_nome'       => (string) ( $row['grupo_nome'] ?? '' ),
					'horario'          => (string) ( $row['horario'] ?? '' ),
					'horario_label'    => ANPA_Socios_Grupo_Serie::horario_label( (string) ( $row['horario'] ?? '' ) ),
					'franxa'           => $franxa,
					'estado'           => (string) ( $row['estado'] ?? '' ),
					'conflito_comedor' => array() !== $conflicts,
				);
			}
		}

		$franxas = array_keys( $franxas );
		sort( $franxas, SORT_STRING );

		return array(
			'curso_escolar' => $curso_escolar,
			'dias'          => self::DIA_LABELS,
			'franxas'       => $franxas,
			'niveis'        => $niveis,
			'slots'         => $slots,
		);
	}
}
