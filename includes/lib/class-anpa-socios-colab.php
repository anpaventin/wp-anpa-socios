<?php
/**
 * Collaborators network (1.104.0): pure rules for shops, their offers and the
 * member card. No WordPress dependency, no I/O. Unit-testable with PHPUnit.
 *
 * The module is self-contained: its own tables, its own shop sessions and
 * login codes, and it never reads or writes the activity companies
 * (anpa_empresas). A shop only ever learns «valid» or «not valid».
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure rules of the collaborators module.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab {

	const PENDENTE  = 'pendente';
	const APROBADA  = 'aprobada';
	const REXEITADA = 'rexeitada';
	const RETIRADA  = 'retirada';

	/** Offer states. */
	const ESTADOS = array( self::PENDENTE, self::APROBADA, self::REXEITADA, self::RETIRADA );

	/** Hours after which a pending offer approves itself (0 = only by hand). */
	const HORAS_APROBACION = 24;

	/** Offers a shop may keep that are not withdrawn. */
	const MAX_OFERTAS = 10;

	/** Life of a member card code, in seconds. */
	const CARNE_TTL = 600;

	/** Characters of a card code (Crockford base32: no I, L, O, U). */
	const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** Length of a card code (8 × 5 bits = 40 bits). */
	const CARNE_LONXITUDE = 8;

	/**
	 * One line of plain text: no tags, no control characters, single spaces.
	 *
	 * @param  mixed $v   Raw value.
	 * @param  int   $max Maximum length in characters.
	 * @return string
	 */
	public static function texto( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = strip_tags( $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s );
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * Several lines of plain text: no tags, line breaks kept (at most two in a row).
	 *
	 * @param  mixed $v   Raw value.
	 * @param  int   $max Maximum length in characters.
	 * @return string
	 */
	public static function parrafo( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = strip_tags( $s );
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $s );
		$s = (string) preg_replace( '/[ \t]+/u', ' ', $s );
		$s = trim( (string) preg_replace( '/\n{3,}/', "\n\n", $s ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * A date typed as YYYY-MM-DD, or '' when empty or not a real date.
	 *
	 * @param  mixed $v Raw value.
	 * @return string
	 */
	public static function data( $v ): string {
		$s = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return '';
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $s : '';
	}

	/**
	 * Cleans and checks an offer sent by a shop.
	 *
	 * @param  array<string,mixed> $in Raw fields.
	 * @return array{datos:array<string,string>,erros:array<string,string>}
	 */
	public static function limpar_oferta( array $in ): array {
		$d = array(
			'titulo'       => self::texto( $in['titulo'] ?? '', 80 ),
			'desconto'     => self::texto( $in['desconto'] ?? '', 60 ),
			'descricion'   => self::parrafo( $in['descricion'] ?? '', 600 ),
			'condicions'   => self::parrafo( $in['condicions'] ?? '', 400 ),
			'valida_dende' => self::data( $in['valida_dende'] ?? '' ),
			'valida_ata'   => self::data( $in['valida_ata'] ?? '' ),
		);
		$e = array();
		if ( self::lonxitude( $d['titulo'] ) < 5 ) {
			$e['titulo'] = 'Escribe un título de polo menos 5 caracteres.';
		}
		if ( '' === $d['desconto'] ) {
			$e['desconto'] = 'Indica o desconto ou a vantaxe (por exemplo «10 %» ou «2x1»).';
		}
		if ( self::lonxitude( $d['descricion'] ) < 10 ) {
			$e['descricion'] = 'Describe a oferta (polo menos 10 caracteres).';
		}
		foreach ( array( 'valida_dende', 'valida_ata' ) as $campo ) {
			$cru = is_scalar( $in[ $campo ] ?? '' ) ? trim( (string) ( $in[ $campo ] ?? '' ) ) : '';
			if ( '' !== $cru && '' === $d[ $campo ] ) {
				$e[ $campo ] = 'A data non é válida.';
			}
		}
		if ( '' !== $d['valida_dende'] && '' !== $d['valida_ata'] && $d['valida_ata'] < $d['valida_dende'] ) {
			$e['valida_ata'] = 'A data final non pode ser anterior á inicial.';
		}

		return array( 'datos' => $d, 'erros' => $e );
	}

	/**
	 * Cleans and checks a shop typed by the junta.
	 *
	 * @param  array<string,mixed> $in Raw fields.
	 * @return array{datos:array<string,string>,erros:array<string,string>}
	 */
	public static function limpar_comercio( array $in ): array {
		$email = strtolower( self::texto( $in['email'] ?? '', 190 ) );
		$web   = self::texto( $in['url_web'] ?? '', 512 );
		$d     = array(
			'nome'        => self::texto( $in['nome'] ?? '', 120 ),
			'email'       => $email,
			'responsable' => self::texto( $in['responsable'] ?? '', 60 ),
			'telefono'    => self::texto( $in['telefono'] ?? '', 30 ),
			'url_web'     => $web,
			'enderezo'    => self::texto( $in['enderezo'] ?? '', 200 ),
			'descricion'  => self::parrafo( $in['descricion'] ?? '', 400 ),
		);
		$e     = array();
		if ( self::lonxitude( $d['nome'] ) < 2 ) {
			$e['nome'] = 'Escribe o nome do comercio.';
		}
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$e['email'] = 'O correo non é válido.';
		}
		if ( '' !== $web && 1 !== preg_match( '#^https?://[^\s]+$#i', $web ) ) {
			$e['url_web'] = 'A web debe comezar por http:// ou https://.';
		}

		return array( 'datos' => $d, 'erros' => $e );
	}

	/**
	 * Whether a pending offer has waited long enough to approve itself.
	 *
	 * @param  int $enviada_ts Unix time the offer was sent (or last edited).
	 * @param  int $horas      Configured hours (0 or less = never by itself).
	 * @param  int $agora      Reference time.
	 * @return bool
	 */
	public static function aprobacion_vencida( int $enviada_ts, int $horas, int $agora ): bool {
		if ( $horas <= 0 || $enviada_ts <= 0 ) {
			return false;
		}

		return $agora >= $enviada_ts + $horas * 3600;
	}

	/**
	 * Whether an offer is inside its validity dates today.
	 *
	 * @param  string $dende YYYY-MM-DD or ''.
	 * @param  string $ata   YYYY-MM-DD or ''.
	 * @param  string $hoxe  YYYY-MM-DD.
	 * @return bool
	 */
	public static function vixente( string $dende, string $ata, string $hoxe ): bool {
		if ( '' !== $dende && '0000-00-00' !== $dende && $hoxe < $dende ) {
			return false;
		}

		return '' === $ata || '0000-00-00' === $ata || $hoxe <= $ata;
	}

	/**
	 * A new card code: 8 random characters of the alphabet (40 bits).
	 *
	 * @return string
	 */
	public static function xerar_codigo(): string {
		$c = '';
		for ( $i = 0; $i < self::CARNE_LONXITUDE; $i++ ) {
			$c .= self::ALFABETO[ random_int( 0, 31 ) ];
		}

		return $c;
	}

	/**
	 * A typed or scanned card code in its stored form, or '' when it cannot be
	 * one. Case, spaces and hyphens do not matter; O reads as 0, I and L as 1.
	 *
	 * @param  mixed $v Raw value.
	 * @return string
	 */
	public static function normalizar_codigo( $v ): string {
		$s = is_scalar( $v ) ? strtoupper( (string) $v ) : '';
		$s = (string) preg_replace( '/[\s\-]+/', '', $s );
		$s = strtr( $s, array( 'O' => '0', 'I' => '1', 'L' => '1' ) );
		if ( strlen( $s ) !== self::CARNE_LONXITUDE || strlen( $s ) !== strspn( $s, self::ALFABETO ) ) {
			return '';
		}

		return $s;
	}

	/**
	 * A card code as shown to people: XXXX-XXXX.
	 *
	 * @param  string $codigo Stored form.
	 * @return string
	 */
	public static function formatar_codigo( string $codigo ): string {
		return substr( $codigo, 0, 4 ) . '-' . substr( $codigo, 4 );
	}

	/**
	 * Label of an offer state for people.
	 *
	 * @param  string $estado State.
	 * @return string
	 */
	public static function etiqueta_estado( string $estado ): string {
		$m = array(
			self::PENDENTE  => 'Pendente de aprobación',
			self::APROBADA  => 'Publicada',
			self::REXEITADA => 'Non aprobada',
			self::RETIRADA  => 'Retirada',
		);

		return $m[ $estado ] ?? $estado;
	}

	/**
	 * @param  string $s Text.
	 * @return int Length in characters.
	 */
	private static function lonxitude( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
	}
}
