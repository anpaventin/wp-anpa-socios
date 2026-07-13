<?php
/**
 * Shared database resolver for the operational school year.
 *
 * @since  1.38.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Curso_Activo {

	/**
	 * Returns the single active course, or null when none is configured.
	 */
	public static function get(): ?string {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_cursos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- shared read-only lifecycle resolver.
		$curso = $wpdb->get_var( "SELECT curso_escolar FROM {$table} WHERE estado = 'activo' ORDER BY actualizado_en DESC, curso_escolar DESC LIMIT 1" );

		return is_string( $curso ) && ANPA_Socios_Curso_Escolar::is_valid( $curso ) ? $curso : null;
	}
}
