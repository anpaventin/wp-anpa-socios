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
		$max_age = 0;
		$seen     = array();
		$target   = null;
		foreach ( $levels as $level ) {
			$level_age = (int) ( $level['orde'] ?? 0 );
			if ( isset( $seen[ $level_age ] ) ) {
				return array( 'status' => 'error', 'code' => 'duplicate_age', 'age' => $level_age );
			}
			$seen[ $level_age ] = true;
			$max_age            = max( $max_age, $level_age );
			if ( $level_age === $age ) {
				$target = $level;
			}
		}
		if ( null !== $target ) {
			return array( 'status' => 'assigned', 'level' => $target );
		}
		if ( $max_age > 0 && $age > $max_age ) {
			return array( 'status' => 'completed', 'max_age' => $max_age );
		}

		return array( 'status' => 'error', 'code' => 'missing_age' );
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

			$current_level = (int) ( $child['nivel_id'] ?? 0 );
			$current_course = (string) ( $child['curso'] ?? '' );
			if ( 'completed' === $target['status'] ) {
				$action                = 0 === $current_level && '' === $current_course ? 'unchanged_completed' : 'completed';
				$items[]               = array( 'fillo_id' => $fillo_id, 'age' => $age, 'nivel_id' => 0, 'curso' => '', 'aula' => $aula, 'action' => $action );
				$emails_cco[ $email ]  = true;
				continue;
			}

			$level  = $target['level'];
			$course = (string) ( $level['codigo'] ?? '' );
			$level_id = (int) ( $level['id'] ?? 0 );
			$action = $level_id === $current_level && $course === $current_course ? 'unchanged' : 'update';
			$items[] = array( 'fillo_id' => $fillo_id, 'age' => $age, 'nivel_id' => $level_id, 'curso' => $course, 'aula' => $aula, 'action' => $action );
		}

		$emails = array_keys( $emails_cco );
		sort( $emails, SORT_STRING );
		return array( 'status' => 'ready', 'items' => $items, 'emails_cco' => $emails );
	}
}
