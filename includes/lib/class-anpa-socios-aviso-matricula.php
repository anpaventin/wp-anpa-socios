<?php
/**
 * Per-pupil notices to the company and the canteen (1.71.0, rule changed in
 * 1.72.0): once a group has been CREATED (the «grupo creado» mass notice was
 * sent or marked), every change in it is notified — a pupil joins (approval
 * with a place, accepted offer, admin enrolment or move from the waiting list),
 * leaves (confirmed baixa, admin removal) or changes group. Before the group is
 * created nothing is sent: the creation itself goes out as a mass notice. Pure.
 *
 * @since   1.71.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure rules and template context for the per-pupil company/canteen notice.
 *
 * @since 1.71.0
 */
final class ANPA_Socios_Aviso_Matricula {

	/** Template: pupil joins a group (company + canteen). */
	const PLANTILLA_ALTA = 'matricula_alta_aviso';

	/** Template: pupil leaves a group (company + canteen). */
	const PLANTILLA_BAIXA = 'matricula_baixa_aviso';

	/** Template: the family accepted a waitlist offer. */
	const PLANTILLA_OFERTA_ACEPTADA = 'oferta_aceptada';

	/** Template (1.72.0): a pupil moves to another group of the activity. */
	const PLANTILLA_CAMBIO = 'matricula_cambio_grupo_aviso';

	/**
	 * The group was created (mass «grupo creado» notice sent or marked) in an active course.
	 *
	 * @since  1.72.0
	 * @param  string      $estado_curso Course state.
	 * @param  string|null $aviso_en     grupos.aviso_comezo_en (null = not created yet).
	 * @return bool
	 */
	public static function grupo_creado( string $estado_curso, ?string $aviso_en ): bool {
		return 'activo' === $estado_curso && null !== $aviso_en && '' !== trim( $aviso_en );
	}

	/**
	 * Whether the enrolment state means the pupil was in the group (a baixa matters to the company).
	 *
	 * @since  1.72.0
	 * @param  string $estado Enrolment state before the change.
	 * @return bool
	 */
	public static function estaba_no_grupo( string $estado ): bool {
		return in_array( $estado, array( 'activo', 'baixa_solicitada' ), true );
	}

	/**
	 * Notice for an admin move between groups: 'cambio' (the pupil was in the
	 * origin group and either group was created), 'alta' (from the waiting list
	 * or a pending request into a created group) or '' (nothing to send).
	 *
	 * @since  1.72.0
	 * @param  string $estado_previo  Enrolment state before the move.
	 * @param  bool   $orixe_creado   Origin group created.
	 * @param  bool   $destino_creado Destination group created.
	 * @return string
	 */
	public static function tipo_movemento( string $estado_previo, bool $orixe_creado, bool $destino_creado ): string {
		if ( self::estaba_no_grupo( $estado_previo ) ) {
			return ( $orixe_creado || $destino_creado ) ? 'cambio' : '';
		}
		return $destino_creado ? 'alta' : '';
	}

	/**
	 * «Nome · franxa días» label of a group (same shape as contexto()['grupo']).
	 *
	 * @since  1.72.0
	 * @param  string $nome   Group name.
	 * @param  string $franxa Time slot.
	 * @param  string $dias   Days.
	 * @return string
	 */
	public static function grupo_label( string $nome, string $franxa, string $dias ): string {
		return trim( implode( ' · ', array_filter( array( trim( $nome ), trim( trim( $franxa ) . ' ' . trim( $dias ) ) ) ) ) );
	}

	/**
	 * Company and canteen addresses, lower-cased, valid and without duplicates.
	 *
	 * @param  string $empresa Company email.
	 * @param  string $comedor Canteen email.
	 * @return array<int,string>
	 */
	public static function destinatarios( string $empresa, string $comedor ): array {
		$out = array();
		foreach ( array( $empresa, $comedor ) as $e ) {
			$e = strtolower( trim( $e ) );
			if ( '' !== $e && false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * Variables for the templates from one company-listing row
	 * (ANPA_Socios_Alumnos_Export::row_panel_matricula()).
	 *
	 * @param  array<string,mixed> $r Row.
	 * @return array<string,string>
	 */
	public static function contexto( array $r ): array {
		$v = static function ( string $k ) use ( $r ): string {
			return trim( (string) ( $r[ $k ] ?? '' ) );
		};
		$grupo = self::grupo_label( $v( 'grupo_nome' ), $v( 'franxa' ), $v( 'dias' ) );
		return array(
			'alumno'      => trim( $v( 'nome' ) . ' ' . $v( 'apelidos' ) ),
			'curso'       => trim( $v( 'curso' ) . ' ' . $v( 'aula' ) ),
			'actividade'  => $v( 'actividade_nome' ),
			'grupo'       => $grupo,
			'empresa'     => '' !== $v( 'empresa_nome' ) ? $v( 'empresa_nome' ) : '—',
			'trimestre'   => $v( 'trimestre' ),
			'proxenitor1' => self::proxenitor( $v( 'proxenitor1_nome' ), $v( 'proxenitor1_telefono' ), $v( 'proxenitor1_email' ) ),
			'proxenitor2' => self::proxenitor( $v( 'proxenitor2_nome' ), $v( 'proxenitor2_telefono' ), $v( 'proxenitor2_email' ) ),
			'opcions'     => self::opcions( $r ),
		);
	}

	/**
	 * «Nome · teléfono · correo», or «—».
	 */
	private static function proxenitor( string $nome, string $tel, string $email ): string {
		$parts = array_values( array_filter( array( $nome, $tel, $email ), static function ( string $s ): bool { return '' !== $s; } ) );
		return array() === $parts ? '—' : implode( ' · ', $parts );
	}

	/**
	 * Same summary as the company panel («Opcións e autorizacións»).
	 *
	 * @param  array<string,mixed> $r Row.
	 * @return string
	 */
	private static function opcions( array $r ): string {
		$parts = array();
		$aut   = (string) ( $r['autorizacion_comedor'] ?? '' );
		if ( 'si' === $aut ) {
			$parts[] = __( 'Comedor: autoriza ao persoal', 'anpa-socios' );
		} elseif ( 'non' === $aut ) {
			$parts[] = __( 'Comedor: NON autoriza ao persoal', 'anpa-socios' );
		}
		$tr = (string) ( $r['tarde_transicion'] ?? '' );
		if ( 'comedor' === $tr ) {
			$parts[] = __( 'Tras o comedor pasa á actividade', 'anpa-socios' );
		} elseif ( 'familia' === $tr ) {
			$parts[] = __( 'A familia lévao á actividade', 'anpa-socios' );
		}
		if ( ! empty( $r['tardes_divertidas_continua'] ) && '0' !== (string) $r['tardes_divertidas_continua'] ) {
			$parts[] = __( 'Continúa en Tardes divertidas', 'anpa-socios' );
		}
		if ( ! empty( $r['recollida_autorizada'] ) && '0' !== (string) $r['recollida_autorizada'] ) {
			$parts[] = __( 'Recollida por persoa autorizada', 'anpa-socios' );
		}
		return array() === $parts ? '—' : implode( ' · ', $parts );
	}
}
