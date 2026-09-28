<?php
/**
 * Company / canteen listing as the families' data is shown in the web panel
 * (1.74.0): spreadsheet rows, descriptive file names and the guide to the
 * CSV columns and codes. Pure.
 *
 * @since   1.74.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure presenters for the company / canteen downloads.
 *
 * @since 1.74.0
 */
final class ANPA_Socios_Listado_Empresa {

	const ESTADOS = array(
		'activo'              => 'Activa',
		'lista_espera'        => 'Lista de espera',
		'oferta'              => 'Oferta de praza',
		'baixa_solicitada'    => 'Baixa solicitada',
		'pendente_aprobacion' => 'Pendente de aprobación',
		'baixa'               => 'Baixa',
	);

	/**
	 * Sheet header, same columns as the web table.
	 *
	 * @param  bool $comedor Canteen account (adds «Empresa»).
	 * @return string[]
	 */
	public static function cabeceira( bool $comedor ): array {
		$cols = array( 'Actividade', 'Grupo', 'Alumno/a', 'Curso', 'Estado', 'Opcións e autorizacións', '1º proxenitor', '2º proxenitor' );
		if ( $comedor ) {
			array_splice( $cols, 1, 0, array( 'Empresa' ) );
		}
		return $cols;
	}

	/**
	 * One sheet row from a rows_panel_empresa() row.
	 *
	 * @param  array<string,mixed> $r       Row.
	 * @param  bool                $comedor Canteen account.
	 * @return string[]
	 */
	public static function fila( array $r, bool $comedor ): array {
		$v = static function ( string $k ) use ( $r ): string {
			return trim( (string) ( $r[ $k ] ?? '' ) );
		};
		$horario = ANPA_Socios_Grupo_Serie::horario_label( $v( 'horario' ) );
		$grupo   = $v( 'grupo_nome' ) . ( '' !== $horario ? ' · ' . trim( $horario . ' ' . $v( 'franxa' ) ) : '' );
		$ctx     = ANPA_Socios_Aviso_Matricula::contexto( $r );
		$out     = array(
			$v( 'actividade_nome' ),
			$grupo,
			trim( $v( 'nome' ) . ' ' . $v( 'apelidos' ) ),
			trim( $v( 'curso' ) . ' ' . $v( 'aula' ) ),
			self::estado_label( $v( 'estado' ) ),
			$ctx['opcions'],
			self::contacto( $v( 'proxenitor1_nome' ), $v( 'proxenitor1_telefono' ), $v( 'proxenitor1_email' ) ),
			self::contacto( $v( 'proxenitor2_nome' ), $v( 'proxenitor2_telefono' ), $v( 'proxenitor2_email' ) ),
		);
		if ( $comedor ) {
			array_splice( $out, 1, 0, array( '' !== $v( 'empresa_nome' ) ? $v( 'empresa_nome' ) : '—' ) );
		}
		return $out;
	}

	/**
	 * @param  string $estado Enrolment state.
	 * @return string Label shown in the panel.
	 */
	public static function estado_label( string $estado ): string {
		return self::ESTADOS[ $estado ] ?? $estado;
	}

	/**
	 * «Parte 1 - Parte 2 - AAAA-MM-DD.ext», safe for every operating system.
	 *
	 * @param  string[] $partes Name parts (company, activity… or «Comedor», «Listado completo»).
	 * @param  string   $data   Export date (Y-m-d).
	 * @param  string   $ext    Extension without the dot.
	 * @return string
	 */
	public static function nome_ficheiro( array $partes, string $data, string $ext ): string {
		$limpas = array();
		foreach ( $partes as $p ) {
			$p = str_replace( array( '/', '\\' ), '-', (string) $p );
			$p = (string) preg_replace( '/[<>:"|?*\x00-\x1F]/u', '', $p );
			$p = trim( (string) preg_replace( '/\s+/u', ' ', $p ), " .-" );
			if ( '' !== $p ) {
				$limpas[] = mb_substr( $p, 0, 60 );
			}
		}
		if ( array() === $limpas ) {
			$limpas[] = 'Listado';
		}
		return implode( ' - ', $limpas ) . ' - ' . $data . '.' . $ext;
	}

	/**
	 * What each CSV column means, with its codes (for the guide next to the CSV button).
	 *
	 * @param  bool $comedor Canteen account (adds empresa_nome).
	 * @return array<string,string>
	 */
	public static function guia_csv( bool $comedor ): array {
		$g = array(
			'actividade_nome'            => 'Nome da actividade.',
			'grupo_nome'                 => 'Nome do grupo.',
			'horario'                    => 'Momento do día: maña = mañá, manha = comedor (mediodía), tarde = tarde.',
			'franxa'                     => 'Hora de inicio e fin (HH:MM-HH:MM).',
			'dias'                       => 'Días da semana do grupo.',
			'nome'                       => 'Nome do alumno/a.',
			'apelidos'                   => 'Apelidos do alumno/a.',
			'curso'                      => 'Curso do alumno/a (1º…6º).',
			'aula'                       => 'Letra da aula.',
			'estado'                     => 'Estado da matrícula: activo = ten praza; lista_espera = en lista de espera; oferta = ofreceuselle unha praza e ten tres días para aceptala; baixa_solicitada = a familia pediu a baixa; pendente_aprobacion = solicitude pendente da directiva; baixa = xa non está.',
			'trimestre'                  => 'Trimestre no que se rexistrou a matrícula (1, 2 ou 3).',
			'comedor'                    => 'Campo antigo, sen uso (sempre 0).',
			'tarde'                      => 'Campo antigo, sen uso (sempre 0).',
			'autorizacion_comedor'       => 'Actividades no horario de comedor: si = a familia autoriza ao persoal de comedor a facilitar a participación; non = non o autoriza; na = non aplica (actividade de tarde).',
			'tarde_transicion'           => 'Actividades de tarde: comedor = usa o comedor e pasa directamente á actividade; familia = non usa o comedor e a familia lévao á actividade; na = non aplica (actividade no horario de comedor).',
			'tardes_divertidas_continua' => '1 = ao rematar a actividade continúa en Tardes Divertidas; 0 = non.',
			'recollida_autorizada'       => '1 = ao rematar a actividade recólleo a familia ou unha persoa autorizada; 0 = non se indicou.',
			'cesion_datos_empresa'       => '1 = a familia autorizou a cesión dos datos necesarios á empresa (obrigatorio para matricular).',
			'proxenitor1_nome'           => 'Nome e apelidos do 1º proxenitor.',
			'proxenitor1_telefono'       => 'Teléfono do 1º proxenitor.',
			'proxenitor1_email'          => 'Correo do 1º proxenitor.',
			'proxenitor2_nome'           => 'Nome e apelidos do 2º proxenitor (baleiro se non hai).',
			'proxenitor2_telefono'       => 'Teléfono do 2º proxenitor.',
			'proxenitor2_email'          => 'Correo do 2º proxenitor.',
		);
		return $comedor ? array( 'empresa_nome' => 'Empresa que imparte a actividade.' ) + $g : $g;
	}

	/**
	 * Name, phone and email on separate lines, or «—».
	 */
	private static function contacto( string $nome, string $tel, string $email ): string {
		$l = array_values( array_filter( array( $nome, $tel, $email ), static function ( string $s ): bool { return '' !== $s; } ) );
		return array() === $l ? '—' : implode( "\n", $l );
	}
}
