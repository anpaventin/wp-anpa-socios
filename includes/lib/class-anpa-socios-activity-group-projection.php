<?php
/**
 * Pure admin projection of activities and their annual groups.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Activity_Group_Projection {

	/**
	 * Adds derived annual-presence fields to base activity rows.
	 *
	 * @param array  $activities Base activity rows.
	 * @param array  $groups     Annual group rows.
	 * @param string $active     Canonical active school year.
	 * @return array
	 */
	public static function build( array $activities, array $groups, string $active ): array {
		$by_activity = array();
		foreach ( $groups as $group ) {
			$activity_id = (int) ( $group['actividad_id'] ?? 0 );
			$course      = trim( (string) ( $group['curso_escolar'] ?? '' ) );
			if ( $activity_id <= 0 || '' === $course ) {
				continue;
			}
			if ( ! isset( $by_activity[ $activity_id ] ) ) {
				$by_activity[ $activity_id ] = array();
			}
			$by_activity[ $activity_id ][] = $group;
		}

		$out = array();
		foreach ( $activities as $activity ) {
			$id            = (int) ( $activity['id'] ?? 0 );
			$annual_groups = $by_activity[ $id ] ?? array();
			$courses       = array();
			$current_count = 0;
			$open_count    = 0;
			foreach ( $annual_groups as $group ) {
				$course             = (string) $group['curso_escolar'];
				$courses[ $course ] = true;
				if ( $course === $active ) {
					++$current_count;
					if ( 'aberto' === (string) ( $group['estado'] ?? '' ) ) {
						++$open_count;
					}
				}
			}
			$course_list = array_keys( $courses );
			sort( $course_list, SORT_STRING );

			if ( 'inactivo' === (string) ( $activity['estado'] ?? '' ) ) {
				$current_state = 'inactiva';
			} elseif ( $open_count > 0 ) {
				$current_state = 'con_grupos_abertos';
			} elseif ( $current_count > 0 ) {
				$current_state = 'con_grupos_pechados';
			} else {
				$current_state = 'sen_grupos_no_curso_activo';
			}

			$activity['cursos_con_grupos']              = $course_list;
			$activity['ten_grupo_curso_activo']         = $current_count > 0;
			$activity['ten_grupo_aberto_curso_activo']  = $open_count > 0;
			$activity['grupos_curso_activo']             = $current_count;
			$activity['grupos_abertos_curso_activo']     = $open_count;
			$activity['estado_curso_activo']             = $current_state;
			$out[] = $activity;
		}

		return $out;
	}
}
