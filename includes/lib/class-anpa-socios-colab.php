<?php
/**
 * Collaborators network (1.104.0): pure rules for shops, their offers and the
 * member card. No WordPress dependency, no I/O. Unit-testable with PHPUnit.
 *
 * The module is self-contained: its own tables, its own shop sessions and
 * login codes, and it never reads or writes the activity companies
 * (anpa_empresas). A shop only ever learns «valid» or «not valid».
 *
 * 1.105.0 (v2): one permanent card code per family and one key per shop, both
 * derived from a secret (nothing secret is stored, only keyed digests to find
 * them), offers with uses per family, and shop data sent as proposals.
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

	/** Times a shop may send or edit offers per hour (1.105.0). */
	const GARDADOS_HORA = 20;

	/** Days a rejection keeps a shop's new offers out of the automatic approval. */
	const DIAS_SO_MANUAL = 30;

	/** Offers a shop may keep that are not withdrawn. */
	const MAX_OFERTAS = 10;

	/** Characters of a code (Crockford base32: no I, L, O, U). */
	const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** Length of a family card code (12 × 5 bits = 60 bits). */
	const FAMILIA_LONXITUDE = 12;

	/** Length of a shop key (26 × 5 bits = 130 bits). */
	const CLAVE_LONXITUDE = 26;

	/** Highest number of uses per family an offer may set (0 = unlimited). */
	const MAX_USOS_FAMILIA = 99;

	/** Longest offer description, in characters. */
	const MAX_DESCRICION = 300;

	/** Seconds a shop may undo a use it applied. */
	const DESFACER_SEGUNDOS = 600;

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
	 * A yes/no typed or sent in any usual form.
	 *
	 * @param  mixed $v Raw value.
	 * @return bool
	 */
	public static function si( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( ! is_scalar( $v ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $v ) ), array( '1', 'true', 'si', 'sí', 'yes', 'on' ), true );
	}

	/**
	 * Cleans and checks an offer sent by a shop.
	 *
	 * 1.105.0 (v2): `acumulable` (0/1, default 0) and `usos_familia` (0–99,
	 * 0 = unlimited, default 1). The image is never taken from here: it only
	 * arrives as an upload.
	 *
	 * @param  array<string,mixed> $in Raw fields.
	 * @return array{datos:array<string,string|int>,erros:array<string,string>}
	 */
	public static function limpar_oferta( array $in ): array {
		$usos_cru = $in['usos_familia'] ?? null;
		$usos     = 1;
		$usos_ok  = true;
		if ( null !== $usos_cru && '' !== $usos_cru ) {
			if ( is_int( $usos_cru ) || ( is_string( $usos_cru ) && 1 === preg_match( '/^\s*\d{1,3}\s*$/', $usos_cru ) ) ) {
				$usos    = (int) $usos_cru;
				$usos_ok = $usos >= 0 && $usos <= self::MAX_USOS_FAMILIA;
			} else {
				$usos_ok = false;
			}
		}
		$d = array(
			'titulo'       => self::texto( $in['titulo'] ?? '', 80 ),
			'desconto'     => self::texto( $in['desconto'] ?? '', 60 ),
			'descricion'   => self::parrafo( $in['descricion'] ?? '', self::MAX_DESCRICION ),
			'condicions'   => self::parrafo( $in['condicions'] ?? '', 400 ),
			'valida_dende' => self::data( $in['valida_dende'] ?? '' ),
			'valida_ata'   => self::data( $in['valida_ata'] ?? '' ),
			'acumulable'   => self::si( $in['acumulable'] ?? false ) ? 1 : 0,
			'usos_familia' => $usos_ok ? $usos : 1,
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
		if ( ! $usos_ok ) {
			$e['usos_familia'] = 'Os usos por familia van de 1 a 99 (ou ilimitados).';
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
	 * Fields a shop may propose about itself (never the login email: only the
	 * junta changes it).
	 *
	 * @return array<int,string>
	 */
	public static function campos_perfil(): array {
		return array( 'nome', 'responsable', 'telefono', 'url_web', 'enderezo', 'descricion' );
	}

	/**
	 * Cleans and checks the data a shop proposes about itself.
	 *
	 * @param  array<string,mixed> $in Raw fields.
	 * @return array{datos:array<string,string>,erros:array<string,string>}
	 */
	public static function limpar_perfil( array $in ): array {
		$r = self::limpar_comercio( array_merge( $in, array( 'email' => 'comercio@example.com' ) ) );
		$d = array();
		foreach ( self::campos_perfil() as $k ) {
			$d[ $k ] = (string) $r['datos'][ $k ];
		}
		unset( $r['erros']['email'] );

		return array( 'datos' => $d, 'erros' => $r['erros'] );
	}

	/**
	 * Whether a pending offer (or a shop's data proposal) has waited long
	 * enough to approve itself.
	 *
	 * @param  int $enviada_ts Unix time it was sent (or last edited).
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
	 * Uses left for a family: null when the offer has no limit.
	 *
	 * @param  int $usos_familia Uses per family (0 = unlimited).
	 * @param  int $usados       Uses the family already had (not undone).
	 * @return int|null
	 */
	public static function restan( int $usos_familia, int $usados ): ?int {
		if ( $usos_familia <= 0 ) {
			return null;
		}

		return max( 0, $usos_familia - max( 0, $usados ) );
	}

	/**
	 * The first characters of a keyed hash, in the code alphabet.
	 *
	 * @param  string $mensaxe What is derived (e.g. "familia|12|1").
	 * @param  string $segredo Secret key.
	 * @param  int    $lonx    Characters wanted (at most 51).
	 * @return string
	 */
	public static function derivar( string $mensaxe, string $segredo, int $lonx ): string {
		$bin  = hash_hmac( 'sha256', $mensaxe, $segredo, true );
		$bits = '';
		$n    = strlen( $bin );
		for ( $i = 0; $i < $n; $i++ ) {
			$bits .= str_pad( decbin( ord( $bin[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$lonx = max( 1, min( intdiv( strlen( $bits ), 5 ), $lonx ) );
		$out  = '';
		for ( $i = 0; $i < $lonx; $i++ ) {
			$out .= self::ALFABETO[ (int) bindec( substr( $bits, $i * 5, 5 ) ) ];
		}

		return $out;
	}

	/**
	 * The card code of a family (same family + version = same code).
	 *
	 * @param  int    $familia_id Family id.
	 * @param  int    $version    Card version (renewing bumps it).
	 * @param  string $segredo    Secret key.
	 * @return string
	 */
	public static function codigo_familia( int $familia_id, int $version, string $segredo ): string {
		return self::derivar( 'familia|' . $familia_id . '|' . $version, $segredo, self::FAMILIA_LONXITUDE );
	}

	/**
	 * The key of a shop (same shop + version = same key).
	 *
	 * @param  int    $comercio_id Shop id.
	 * @param  int    $version     QR version (renewing bumps it).
	 * @param  string $segredo     Secret key.
	 * @return string
	 */
	public static function clave_comercio( int $comercio_id, int $version, string $segredo ): string {
		return self::derivar( 'comercio|' . $comercio_id . '|' . $version, $segredo, self::CLAVE_LONXITUDE );
	}

	/**
	 * Keyed digest a code is looked up by (the code itself is never stored).
	 *
	 * @param  string $codigo  Code, stored form.
	 * @param  string $segredo Secret key.
	 * @return string 64 hex characters.
	 */
	public static function digest( string $codigo, string $segredo ): string {
		return hash_hmac( 'sha256', $codigo, $segredo );
	}

	/**
	 * A typed or scanned code in its stored form, or '' when it cannot be one.
	 * Case, spaces and hyphens do not matter; O reads as 0, I and L as 1.
	 *
	 * @param  mixed $v    Raw value.
	 * @param  int   $lonx Expected length (family code by default).
	 * @return string
	 */
	public static function normalizar_codigo( $v, int $lonx = self::FAMILIA_LONXITUDE ): string {
		$s = is_scalar( $v ) ? strtoupper( (string) $v ) : '';
		if ( strlen( $s ) > 80 ) {
			return '';
		}
		$s = (string) preg_replace( '/[\s\-]+/', '', $s );
		$s = strtr( $s, array( 'O' => '0', 'I' => '1', 'L' => '1' ) );
		if ( strlen( $s ) !== $lonx || strlen( $s ) !== strspn( $s, self::ALFABETO ) ) {
			return '';
		}

		return $s;
	}

	/**
	 * A code as shown to people: groups of four (XXXX-XXXX-XXXX).
	 *
	 * @param  string $codigo Stored form.
	 * @return string
	 */
	public static function formatar_codigo( string $codigo ): string {
		return implode( '-', str_split( $codigo, 4 ) );
	}

	/**
	 * Next weekly batch moment at or after a time.
	 *
	 * @param  int          $agora  Reference Unix time.
	 * @param  int          $dia    ISO weekday (1 = Monday … 7 = Sunday).
	 * @param  int          $hora   Hour 0–23.
	 * @param  int          $minuto Minute 0–59.
	 * @param  DateTimeZone $tz     Site timezone.
	 * @return int Unix time.
	 */
	public static function proximo_lote( int $agora, int $dia, int $hora, int $minuto, DateTimeZone $tz ): int {
		$dia    = max( 1, min( 7, $dia ) );
		$hora   = max( 0, min( 23, $hora ) );
		$minuto = max( 0, min( 59, $minuto ) );
		$d      = ( new DateTimeImmutable( '@' . $agora ) )->setTimezone( $tz );
		$diff   = ( $dia - (int) $d->format( 'N' ) + 7 ) % 7;
		$c      = $d->modify( '+' . $diff . ' days' )->setTime( $hora, $minuto, 0 );
		if ( $c->getTimestamp() < $agora ) {
			$c = $c->modify( '+7 days' )->setTime( $hora, $minuto, 0 );
		}

		return $c->getTimestamp();
	}

	/**
	 * Label of an offer state for people.
	 *
	 * @param  string $estado    State.
	 * @param  bool   $publicada For an approved offer: whether it is already out.
	 * @return string
	 */
	public static function etiqueta_estado( string $estado, bool $publicada = true ): string {
		if ( self::APROBADA === $estado && ! $publicada ) {
			return 'Aprobada (sae no próximo envío)';
		}
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
