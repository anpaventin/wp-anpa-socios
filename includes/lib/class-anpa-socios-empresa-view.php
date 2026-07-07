<?php
/**
 * Pure view/presenter helpers for the empresa surface.
 *
 * Field whitelists that prevent accidental data exposure. No WordPress
 * dependency, no I/O, no global state. Unit-testable with PHPUnit.
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Field-whitelist presenters for empresa REST responses.
 *
 * @since 1.4.0
 */
final class ANPA_Socios_Empresa_View {

	/**
	 * Fields editable by the empresa on an alumno (fillo) record.
	 *
	 * @since 1.4.0
	 * @var array<string>
	 */
	const EDITABLE_ALUMNO_FIELDS = array( 'nome', 'apelidos' );

	/**
	 * Returns a safe public representation of an empresa profile.
	 *
	 * Only exposes fields the empresa is allowed to see about itself.
	 * Deliberately excludes socio_email, child data, and internal IDs
	 * beyond the empresa's own id.
	 *
	 * @since  1.4.0
	 * @param  array<string,mixed> $row Raw DB row from wp_anpa_empresas.
	 * @return array<string,mixed>
	 */
	public static function public_empresa( array $row ): array {
		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'nome'        => isset( $row['nome'] ) ? (string) $row['nome'] : '',
			'email'       => isset( $row['email'] ) ? (string) $row['email'] : '',
			'responsable' => isset( $row['responsable'] ) ? (string) $row['responsable'] : '',
			'telefono'    => isset( $row['telefono'] ) ? (string) $row['telefono'] : '',
			'estado'      => isset( $row['estado'] ) ? (string) $row['estado'] : '',
		);
	}

	/**
	 * Returns a safe representation of an alumno (fillo) for empresa use.
	 *
	 * Includes socio_email (justified by cesión de datos consent) and the
	 * fillo id needed for the PATCH endpoint. Does NOT include comedor,
	 * tarde, or other enrollment-specific flags.
	 *
	 * @since  1.4.0
	 * @param  array<string,mixed> $row Raw DB row from join query.
	 * @return array<string,mixed>
	 */
	public static function alumno_row( array $row ): array {
		return array(
			'id'             => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'nome'           => isset( $row['nome'] ) ? (string) $row['nome'] : '',
			'apelidos'       => isset( $row['apelidos'] ) ? (string) $row['apelidos'] : '',
			'data_nacemento' => isset( $row['data_nacemento'] ) ? (string) $row['data_nacemento'] : '',
			'curso'          => isset( $row['curso'] ) ? (string) $row['curso'] : '',
			'aula'           => isset( $row['aula'] ) ? (string) $row['aula'] : '',
			'socio_email'    => isset( $row['socio_email'] ) ? (string) $row['socio_email'] : '',
		);
	}
}
