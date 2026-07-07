<?php
/**
 * Pure waitlist ordering helpers (fase7).
 *
 * No WordPress dependency: position arithmetic, renumbering and
 * next-offer selection are pure functions over plain arrays.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Waitlist position logic for group enrolments.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Waitlist {

	/**
	 * Returns the next waitlist position (1-based, contiguous-friendly).
	 *
	 * @since  1.9.0
	 * @param  array<int,int|string> $positions Existing positions.
	 * @return int
	 */
	public static function next_position( array $positions ): int {
		$max = 0;
		foreach ( $positions as $p ) {
			$p = (int) $p;
			if ( $p > $max ) {
				$max = $p;
			}
		}

		return $max + 1;
	}

	/**
	 * Renumbers an ordered list of ids to contiguous 1..N positions.
	 *
	 * @since  1.9.0
	 * @param  array<int,int|string> $ordered_ids Ids ordered by current position.
	 * @return array<int,int> Map of id => new 1-based position.
	 */
	public static function renumber( array $ordered_ids ): array {
		$out = array();
		$pos = 1;
		foreach ( $ordered_ids as $id ) {
			$out[ (int) $id ] = $pos;
			$pos++;
		}

		return $out;
	}

	/**
	 * Returns the row with the smallest position (the next to be offered),
	 * or null when the list is empty.
	 *
	 * @since  1.9.0
	 * @param  array<int,array<string,mixed>> $rows Rows with a 'posicion' key.
	 * @return array<string,mixed>|null
	 */
	public static function first_offerable( array $rows ): ?array {
		$best = null;
		foreach ( $rows as $row ) {
			if ( ! isset( $row['posicion'] ) ) {
				continue;
			}
			if ( null === $best || (int) $row['posicion'] < (int) $best['posicion'] ) {
				$best = $row;
			}
		}

		return $best;
	}
}
