<?php
/**
 * Mid-course notices to the company and the canteen (1.71.0): when a pupil
 * joins a group (approval with a place, accepted waitlist offer) or leaves it
 * (confirmed baixa) while the enrolment window is closed. The start of the
 * course and the trimester changes are covered by the mass notices, so no
 * per-pupil email goes out while the window is open. Pure.
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

	/**
	 * Mid-course = active course with the enrolment window closed.
	 *
	 * @param  array<string,mixed> $gate ANPA_Socios_Matricula_Gate::avaliar() result.
	 * @return bool
	 */
	public static function debe_avisar( array $gate ): bool {
		return 'activo' === (string) ( $gate['estado_curso'] ?? '' ) && empty( $gate['abertas'] );
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
		$grupo = trim( implode( ' · ', array_filter( array( $v( 'grupo_nome' ), trim( $v( 'franxa' ) . ' ' . $v( 'dias' ) ) ) ) ) );
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
