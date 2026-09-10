<?php
/**
 * Pure annual level-promotion calculations.
 *
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Nivel_Promotion {

	/**
	 * Calculates the age reached during the final calendar year of a school year.
	 *
	 * @param  string $birth_date  Birth date in Y-m-d format.
	 * @param  string $school_year School year in YYYY/YYYY+1 format.
	 * @return int|null Age, or null for invalid input.
	 */
	public static function age_for_course( string $birth_date, string $school_year ): ?int {
		if ( ! preg_match( '/^(\d{4})\/(\d{4})$/', $school_year, $course_match ) ) {
			return null;
		}
		$start_year = (int) $course_match[1];
		$end_year   = (int) $course_match[2];
		if ( $end_year !== $start_year + 1 ) {
			return null;
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birth_date, $birth_match ) ) {
			return null;
		}
		$birth_year  = (int) $birth_match[1];
		$birth_month = (int) $birth_match[2];
		$birth_day   = (int) $birth_match[3];
		if ( ! checkdate( $birth_month, $birth_day, $birth_year ) || $birth_year >= $end_year ) {
			return null;
		}

		return $end_year - $birth_year;
	}

	/**
	 * Resolves the active level whose order equals the calculated age.
	 *
	 * @param  int   $age    Calculated age.
	 * @param  array $levels Active levels.
	 * @return array Promotion target.
	 */
	public static function target_for_age( int $age, array $levels ): array {
		$max_age   = 0;
		$top_level = null;
		$seen      = array();
		$target    = null;
		foreach ( $levels as $level ) {
			$level_age = (int) ( $level['orde'] ?? 0 );
			if ( isset( $seen[ $level_age ] ) ) {
				return array( 'status' => 'error', 'code' => 'duplicate_age', 'age' => $level_age );
			}
			$seen[ $level_age ] = true;
			if ( $level_age > $max_age ) {
				$max_age   = $level_age;
				$top_level = $level;
			}
			if ( $level_age === $age ) {
				$target = $level;
			}
		}
		if ( null !== $target ) {
			return array( 'status' => 'assigned', 'level' => $target );
		}
		// Older than the highest configured level (e.g. would be beyond 6º):
		// keep the child in the last level instead of removing the course. The
		// family (or the board) decides when the child actually leaves.
		if ( $max_age > 0 && $age > $max_age ) {
			return array( 'status' => 'capped', 'level' => $top_level, 'max_age' => $max_age );
		}

		return array( 'status' => 'error', 'code' => 'missing_age' );
	}

	/**
	 * Summarises a ready plan for the admin preview (counts + change list).
	 *
	 * @param  array $plan Plan returned by build_plan().
	 * @return array{actualizados:int,sen_cambios:int,no_ultimo_nivel:int,por_curso:array<string,int>,cambios:array<int,array<string,mixed>>}
	 */
	public static function summarize_plan( array $plan ): array {
		$summary = array( 'actualizados' => 0, 'sen_cambios' => 0, 'no_ultimo_nivel' => 0, 'por_curso' => array(), 'cambios' => array() );
		foreach ( (array) ( $plan['items'] ?? array() ) as $item ) {
			$action = (string) ( $item['action'] ?? '' );
			$course = (string) ( $item['curso'] ?? '' );
			$summary['por_curso'][ $course ] = ( $summary['por_curso'][ $course ] ?? 0 ) + 1;
			if ( in_array( $action, array( 'capped', 'unchanged_capped' ), true ) ) {
				++$summary['no_ultimo_nivel'];
			}
			if ( in_array( $action, array( 'unchanged', 'unchanged_capped' ), true ) ) {
				++$summary['sen_cambios'];
				continue;
			}
			++$summary['actualizados'];
			$summary['cambios'][] = array(
				'fillo_id'       => (int) $item['fillo_id'],
				'idade'          => (int) ( $item['age'] ?? 0 ),
				'curso_anterior' => (string) ( $item['curso_anterior'] ?? '' ),
				'curso_novo'     => $course,
				'aula'           => (string) ( $item['aula'] ?? '' ),
				'accion'         => $action,
			);
		}
		ksort( $summary['por_curso'], SORT_NATURAL );
		return $summary;
	}

	/**
	 * Builds a deterministic, side-effect-free promotion plan.
	 *
	 * @param  string $school_year Operational school year.
	 * @param  array  $levels      Active level rows.
	 * @param  array  $children    Active child rows with annual state.
	 * @return array Ready plan or validation error.
	 */
	public static function build_plan( string $school_year, array $levels, array $children ): array {
		if ( array() === $levels ) {
			return array( 'status' => 'error', 'code' => 'no_levels' );
		}
		$seen_ages = array();
		foreach ( $levels as $level ) {
			$level_age = (int) ( $level['orde'] ?? 0 );
			if ( $level_age < 1 || (int) ( $level['id'] ?? 0 ) < 1 || '' === trim( (string) ( $level['codigo'] ?? '' ) ) ) {
				return array( 'status' => 'error', 'code' => 'invalid_level', 'age' => $level_age );
			}
			if ( isset( $seen_ages[ $level_age ] ) ) {
				return array( 'status' => 'error', 'code' => 'duplicate_age', 'age' => $level_age );
			}
			$seen_ages[ $level_age ] = true;
		}

		$items      = array();
		$emails_cco = array();
		foreach ( $children as $child ) {
			$fillo_id       = (int) ( $child['fillo_id'] ?? 0 );
			$principal_count = (int) ( $child['principal_count'] ?? 1 );
			$age            = self::age_for_course( (string) ( $child['data_nacemento'] ?? '' ), $school_year );
			$aula           = trim( (string) ( $child['aula'] ?? '' ) );
			$email          = strtolower( trim( (string) ( $child['principal_email'] ?? '' ) ) );
			if ( 1 !== $principal_count ) {
				return array( 'status' => 'error', 'code' => 'invalid_principal_count', 'fillo_id' => $fillo_id );
			}
			if ( $fillo_id < 1 || null === $age ) {
				return array( 'status' => 'error', 'code' => 'invalid_birth_date', 'fillo_id' => $fillo_id );
			}
			if ( '' === $aula ) {
				return array( 'status' => 'error', 'code' => 'missing_classroom', 'fillo_id' => $fillo_id );
			}
			if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				return array( 'status' => 'error', 'code' => 'invalid_principal_email', 'fillo_id' => $fillo_id );
			}

			$target = self::target_for_age( $age, $levels );
			if ( 'error' === $target['status'] ) {
				$target['fillo_id'] = $fillo_id;
				$target['age']      = $target['age'] ?? $age;
				return $target;
			}

			$current_level  = (int) ( $child['nivel_id'] ?? 0 );
			$current_course = (string) ( $child['curso'] ?? '' );
			$level          = $target['level'];
			$course         = (string) ( $level['codigo'] ?? '' );
			$level_id       = (int) ( $level['id'] ?? 0 );
			$same           = $level_id === $current_level && $course === $current_course;
			if ( 'capped' === $target['status'] ) {
				// Beyond the last level by age: stays in the last level (6º).
				$action               = $same ? 'unchanged_capped' : 'capped';
				$emails_cco[ $email ] = true;
			} else {
				$action = $same ? 'unchanged' : 'update';
			}
			$items[] = array( 'fillo_id' => $fillo_id, 'age' => $age, 'nivel_id' => $level_id, 'curso' => $course, 'curso_anterior' => $current_course, 'aula' => $aula, 'action' => $action );
		}

		$emails = array_keys( $emails_cco );
		sort( $emails, SORT_STRING );
		return array( 'status' => 'ready', 'items' => $items, 'emails_cco' => $emails );
	}
}
