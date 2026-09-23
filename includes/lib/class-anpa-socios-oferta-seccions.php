<?php
/**
 * Splits the public offer cards into time-of-day sections (1.68.2).
 *
 * Families read the offer in two blocks — «comedor» (lunchtime) and «tarde»
 * (afternoon) — so the public page shows one titled section per block, with a
 * divider between them, instead of one long grid. An activity goes to the
 * section of its earliest group (the chunks come ordered by franxa); its card
 * still lists every group with its own time label.
 *
 * Pure PHP: works on the `horarios_grupos` string produced by the offer query
 * (chunks `id|nome|horario|franxa|dias` joined by `;;`, same layout as
 * ANPA_Socios_Extraescolares_Page::schedule_detail_html()).
 *
 * @since  1.68.2
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Oferta_Seccions {

	/** Section order on the page. */
	const ORDE = array( 'maña', 'manha', 'tarde', 'outros' );

	/**
	 * Time-of-day key of an activity: the horario of its first group chunk.
	 *
	 * @param  array<string,mixed> $act Activity row with `horarios_grupos`.
	 * @return string maña|manha|tarde|outros
	 */
	public static function horario_de( array $act ): string {
		$raw    = (string) ( $act['horarios_grupos'] ?? '' );
		$chunks = array_values( array_filter( explode( ';;', $raw ) ) );
		if ( array() === $chunks ) {
			return 'outros';
		}
		$segs    = explode( '|', $chunks[0] );
		$horario = count( $segs ) >= 4 ? (string) $segs[ count( $segs ) - 3 ] : '';
		return in_array( $horario, array( 'maña', 'manha', 'tarde' ), true ) ? $horario : 'outros';
	}

	/**
	 * Groups the offer rows into ordered, non-empty sections.
	 *
	 * @param  array<int,array<string,mixed>> $rows Offer rows (already sorted for display).
	 * @return array<int,array{horario:string,titulo:string,rows:array<int,array<string,mixed>>}>
	 */
	public static function agrupar( array $rows ): array {
		$buckets = array_fill_keys( self::ORDE, array() );
		foreach ( $rows as $row ) {
			$buckets[ self::horario_de( $row ) ][] = $row;
		}
		$out = array();
		foreach ( self::ORDE as $key ) {
			if ( array() === $buckets[ $key ] ) {
				continue;
			}
			$out[] = array( 'horario' => $key, 'titulo' => self::titulo( $key ), 'rows' => $buckets[ $key ] );
		}
		return $out;
	}

	/**
	 * ASCII class suffix for the section element.
	 *
	 * @param  string $horario Section key.
	 * @return string mana|comedor|tarde|outros
	 */
	public static function clase( string $horario ): string {
		$map = array( 'maña' => 'mana', 'manha' => 'comedor', 'tarde' => 'tarde' );
		return $map[ $horario ] ?? 'outros';
	}

	/**
	 * @param  string $horario Section key.
	 * @return string Galician title.
	 */
	public static function titulo( string $horario ): string {
		switch ( $horario ) {
			case 'maña':
				return __( 'Actividades de mañá', 'anpa-socios' );
			case 'manha':
				return __( 'Actividades no horario de comedor (mediodía)', 'anpa-socios' );
			case 'tarde':
				return __( 'Actividades de tarde', 'anpa-socios' );
			default:
				return __( 'Outras actividades', 'anpa-socios' );
		}
	}
}
