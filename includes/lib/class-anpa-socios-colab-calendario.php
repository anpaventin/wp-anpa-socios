<?php
/**
 * Collaborators network (1.105.0): calendar of the weekly offers post.
 *
 * Pure rules (no WordPress): which seasonal key a date belongs to (the 12
 * months as fallback plus Galician festivities and school moments, the
 * Easter-dependent ones computed with the Gregorian computus), the title and
 * intro of the post for that key (2–3 variants picked from the week so
 * consecutive posts differ), and the weekly schedule of the batch.
 *
 * @since   1.105.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seasonal calendar and weekly schedule.
 *
 * @since 1.105.0
 */
final class ANPA_Socios_Colab_Calendario {

	/** Folder of the illustrations, relative to the plugin root. */
	const DIR_IMAXES = 'assets/img/colab/';

	/** Month keys, 1-based. */
	const MESES = array( 1 => 'xaneiro', 'febreiro', 'marzo', 'abril', 'maio', 'xuno', 'xullo', 'agosto', 'setembro', 'outubro', 'novembro', 'decembro' );

	/** Month names in Galician, 1-based. */
	const NOMES_MES = array( 1 => 'xaneiro', 'febreiro', 'marzo', 'abril', 'maio', 'xuño', 'xullo', 'agosto', 'setembro', 'outubro', 'novembro', 'decembro' );

	/** Weekday names in Galician, ISO-8601 numbering (1 = Monday). */
	const NOMES_DIA = array( 1 => 'luns', 'martes', 'mércores', 'xoves', 'venres', 'sábado', 'domingo' );

	/**
	 * Every key with its label (settings screen, image overrides).
	 *
	 * @return array<string,string>
	 */
	public static function claves(): array {
		$out = array();
		foreach ( self::MESES as $n => $clave ) {
			$out[ $clave ] = ucfirst( self::NOMES_MES[ $n ] );
		}
		return $out + array(
			'aninovo-reis'   => 'Aninovo e Reis',
			'entroido'       => 'Entroido',
			'dia-do-pai'     => 'Día do Pai',
			'pascua'         => 'Semana Santa (vacacións de primavera)',
			'dia-do-libro'   => 'Día do Libro',
			'dia-da-nai'     => 'Día da Nai',
			'letras-galegas' => 'Letras Galegas',
			'fin-de-curso'   => 'Fin de curso',
			'san-xoan'       => 'San Xoán',
			'veran'          => 'Verán',
			'dia-de-galicia' => 'Día de Galicia',
			'volta-ao-cole'  => 'Volta ao cole',
			'samain'         => 'Samaín',
			'outono-magosto' => 'Magosto',
			'ponte-decembro' => 'Ponte de decembro',
			'nadal'          => 'Nadal',
		);
	}

	/**
	 * Title, intro and image of the post for a date.
	 *
	 * @param  string $ymd Date 'Y-m-d' (the batch day, site time).
	 * @return array{clave:string,titulo:string,intro:string,imaxe:string}
	 */
	public static function para( string $ymd ): array {
		$d = self::data( $ymd );
		if ( null === $d ) {
			$d = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}
		$clave     = self::clave( $d->format( 'Y-m-d' ) );
		$variantes = self::textos()[ $clave ];
		// Whole weeks since the epoch: consecutive weeks always get a different variant.
		$semana = intdiv( (int) floor( $d->setTime( 12, 0 )->getTimestamp() / 86400 ) + 3, 7 );
		$v      = $variantes[ $semana % count( $variantes ) ];

		return array(
			'clave'  => $clave,
			'titulo' => $v[0],
			'intro'  => $v[1],
			'imaxe'  => self::DIR_IMAXES . $clave . '.png',
		);
	}

	/**
	 * Seasonal key of a date: the festivity window that contains it (the one
	 * that ends first when two overlap), otherwise the month.
	 *
	 * @param  string $ymd Date 'Y-m-d'.
	 * @return string
	 */
	public static function clave( string $ymd ): string {
		$d = self::data( $ymd );
		if ( null === $d ) {
			return self::MESES[1];
		}
		$ymd   = $d->format( 'Y-m-d' );
		$ano   = (int) $d->format( 'Y' );
		$mellor = null;
		foreach ( array( $ano - 1, $ano, $ano + 1 ) as $a ) {
			foreach ( self::xanelas( $a ) as $x ) {
				if ( $ymd >= $x['dende'] && $ymd <= $x['ata'] && ( null === $mellor || $x['ata'] < $mellor['ata'] ) ) {
					$mellor = $x;
				}
			}
		}

		return null !== $mellor ? $mellor['clave'] : self::MESES[ (int) $d->format( 'n' ) ];
	}

	/**
	 * Festivity windows of one year (the date range in which a batch uses
	 * that key). 'ata' is the festivity itself (or the end of the period).
	 *
	 * @param  int $ano Year.
	 * @return array<int,array{clave:string,dende:string,ata:string}>
	 */
	public static function xanelas( int $ano ): array {
		$pascua  = new DateTimeImmutable( self::pascua( $ano ), new DateTimeZone( 'UTC' ) );
		$entroido = $pascua->modify( '-47 days' ); // Shrove Tuesday.
		$nai      = new DateTimeImmutable( sprintf( '%04d-05-01', $ano ), new DateTimeZone( 'UTC' ) );
		if ( '7' !== $nai->format( 'N' ) ) {
			$nai = $nai->modify( 'next sunday' );
		}
		$f = static function ( int $m, int $d ) use ( $ano ): string {
			return sprintf( '%04d-%02d-%02d', $ano, $m, $d );
		};
		$antes = static function ( DateTimeImmutable $x, int $dias ): string {
			return $x->modify( '-' . $dias . ' days' )->format( 'Y-m-d' );
		};

		return array(
			array( 'clave' => 'entroido', 'dende' => $antes( $entroido, 9 ), 'ata' => $entroido->format( 'Y-m-d' ) ),
			array( 'clave' => 'dia-do-pai', 'dende' => $f( 3, 10 ), 'ata' => $f( 3, 19 ) ),
			array( 'clave' => 'pascua', 'dende' => $antes( $pascua, 10 ), 'ata' => $pascua->format( 'Y-m-d' ) ),
			array( 'clave' => 'dia-do-libro', 'dende' => $f( 4, 14 ), 'ata' => $f( 4, 23 ) ),
			array( 'clave' => 'dia-da-nai', 'dende' => $antes( $nai, 9 ), 'ata' => $nai->format( 'Y-m-d' ) ),
			array( 'clave' => 'letras-galegas', 'dende' => $f( 5, 8 ), 'ata' => $f( 5, 17 ) ),
			array( 'clave' => 'fin-de-curso', 'dende' => $f( 6, 7 ), 'ata' => $f( 6, 16 ) ),
			array( 'clave' => 'san-xoan', 'dende' => $f( 6, 17 ), 'ata' => $f( 6, 24 ) ),
			array( 'clave' => 'veran', 'dende' => $f( 6, 25 ), 'ata' => $f( 7, 15 ) ),
			array( 'clave' => 'dia-de-galicia', 'dende' => $f( 7, 16 ), 'ata' => $f( 7, 25 ) ),
			array( 'clave' => 'volta-ao-cole', 'dende' => $f( 9, 1 ), 'ata' => $f( 9, 15 ) ),
			array( 'clave' => 'samain', 'dende' => $f( 10, 22 ), 'ata' => $f( 10, 31 ) ),
			array( 'clave' => 'outono-magosto', 'dende' => $f( 11, 1 ), 'ata' => $f( 11, 11 ) ),
			array( 'clave' => 'ponte-decembro', 'dende' => $f( 11, 29 ), 'ata' => $f( 12, 8 ) ),
			array( 'clave' => 'nadal', 'dende' => $f( 12, 12 ), 'ata' => $f( 12, 26 ) ),
			array( 'clave' => 'aninovo-reis', 'dende' => $f( 12, 27 ), 'ata' => sprintf( '%04d-01-06', $ano + 1 ) ),
		);
	}

	/**
	 * Easter Sunday (Gregorian calendar; anonymous Meeus/Jones/Butcher computus).
	 *
	 * @param  int $ano Year (1583 or later).
	 * @return string 'Y-m-d'
	 */
	public static function pascua( int $ano ): string {
		$a = $ano % 19;
		$b = intdiv( $ano, 100 );
		$c = $ano % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$mes = intdiv( $h + $l - 7 * $m + 114, 31 );
		$dia = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		return sprintf( '%04d-%02d-%02d', $ano, $mes, $dia );
	}

	/**
	 * The first scheduled batch moment strictly after $agora.
	 *
	 * @param  int          $agora      Unix time.
	 * @param  int          $dia_semana ISO weekday (1 = Monday … 7 = Sunday).
	 * @param  int          $hora       Hour 0–23 (site time).
	 * @param  int          $minuto     Minute 0–59.
	 * @param  DateTimeZone $tz         Site timezone.
	 * @return int Unix time.
	 */
	public static function seguinte_saida( int $agora, int $dia_semana, int $hora, int $minuto, DateTimeZone $tz ): int {
		$m = self::momento_da_semana( $agora, $dia_semana, $hora, $minuto, $tz );
		if ( $m->getTimestamp() <= $agora ) {
			$m = self::en_hora( $m->modify( '+7 days' ), $hora, $minuto );
		}
		return $m->getTimestamp();
	}

	/**
	 * The latest scheduled batch moment at or before $agora.
	 *
	 * @param  int          $agora      Unix time.
	 * @param  int          $dia_semana ISO weekday.
	 * @param  int          $hora       Hour.
	 * @param  int          $minuto     Minute.
	 * @param  DateTimeZone $tz         Site timezone.
	 * @return int Unix time.
	 */
	public static function ultima_saida( int $agora, int $dia_semana, int $hora, int $minuto, DateTimeZone $tz ): int {
		$m = self::momento_da_semana( $agora, $dia_semana, $hora, $minuto, $tz );
		if ( $m->getTimestamp() > $agora ) {
			$m = self::en_hora( $m->modify( '-7 days' ), $hora, $minuto );
		}
		return $m->getTimestamp();
	}

	/**
	 * Whether this week's batch is due and not yet run. $feito is the
	 * scheduled moment of the last batch that ran (stored before running it),
	 * so once it is recorded the same week never fires again; a brand new
	 * site ($feito = 0) is primed by the caller rather than firing at once.
	 *
	 * @param  int          $agora      Unix time.
	 * @param  int          $feito      Scheduled moment of the last batch run (0 = none).
	 * @param  int          $dia_semana ISO weekday.
	 * @param  int          $hora       Hour.
	 * @param  int          $minuto     Minute.
	 * @param  DateTimeZone $tz         Site timezone.
	 * @return int The scheduled moment to run now, or 0 when nothing is due.
	 */
	public static function lote_pendente( int $agora, int $feito, int $dia_semana, int $hora, int $minuto, DateTimeZone $tz ): int {
		$ultima = self::ultima_saida( $agora, $dia_semana, $hora, $minuto, $tz );
		// Anything recorded within the last week (even under another weekday setting) counts as this week's batch.
		if ( $feito > 0 && $feito > $ultima - 6 * 86400 ) {
			return 0;
		}
		return $ultima;
	}

	/**
	 * «o xoves 3 ás 16:00» for a batch moment.
	 *
	 * @param  int          $ts Unix time.
	 * @param  DateTimeZone $tz Site timezone.
	 * @return string
	 */
	public static function texto_saida( int $ts, DateTimeZone $tz ): string {
		$d = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
		return sprintf( 'o %s %d ás %s', self::NOMES_DIA[ (int) $d->format( 'N' ) ], (int) $d->format( 'j' ), $d->format( 'H:i' ) );
	}

	/**
	 * «30 de xuño», adding the year only when it is not $ano_ref.
	 *
	 * @param  string $ymd     Date 'Y-m-d'.
	 * @param  int    $ano_ref Year of the post.
	 * @return string '' for an empty or invalid date.
	 */
	public static function data_en_palabras( string $ymd, int $ano_ref ): string {
		$d = self::data( $ymd );
		if ( null === $d ) {
			return '';
		}
		$txt = (int) $d->format( 'j' ) . ' de ' . self::NOMES_MES[ (int) $d->format( 'n' ) ];
		return (int) $d->format( 'Y' ) === $ano_ref ? $txt : $txt . ' de ' . $d->format( 'Y' );
	}

	/**
	 * Validity of an offer in force, in words.
	 *
	 * @param  string $ata     End date 'Y-m-d' ('' or 0000-00-00 = no end).
	 * @param  int    $ano_ref Year of the post.
	 * @return string
	 */
	public static function vixencia( string $ata, int $ano_ref ): string {
		$fin = self::data_en_palabras( $ata, $ano_ref );
		return '' === $fin ? 'Sen data de fin (ata que o establecemento a retire)' : 'Válida ata o ' . $fin;
	}

	/**
	 * Uses per family in words (0 = unlimited).
	 *
	 * @param  int $usos Uses per family.
	 * @return string
	 */
	public static function usos_texto( int $usos ): string {
		if ( $usos <= 0 ) {
			return 'Sen límite de usos por familia';
		}
		return 1 === $usos ? 'Un uso por familia' : $usos . ' usos por familia';
	}

	/**
	 * Strict 'Y-m-d' parse (UTC midnight) or null.
	 *
	 * @param  string $ymd Date.
	 * @return DateTimeImmutable|null
	 */
	private static function data( string $ymd ): ?DateTimeImmutable {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return new DateTimeImmutable( $ymd . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The configured weekday/time inside the ISO week of $agora (site time).
	 *
	 * @param  int          $agora      Unix time.
	 * @param  int          $dia_semana ISO weekday.
	 * @param  int          $hora       Hour.
	 * @param  int          $minuto     Minute.
	 * @param  DateTimeZone $tz         Site timezone.
	 * @return DateTimeImmutable
	 */
	private static function momento_da_semana( int $agora, int $dia_semana, int $hora, int $minuto, DateTimeZone $tz ): DateTimeImmutable {
		$dia_semana = max( 1, min( 7, $dia_semana ) );
		$local      = ( new DateTimeImmutable( '@' . $agora ) )->setTimezone( $tz );
		$dia        = $local->setTime( 12, 0 )->modify( ( $dia_semana - (int) $local->format( 'N' ) ) . ' days' );
		return self::en_hora( $dia, $hora, $minuto );
	}

	/**
	 * Same local day at hour:minute (DST-safe: set on the local calendar day).
	 *
	 * @param  DateTimeImmutable $d      Any moment of the day.
	 * @param  int               $hora   Hour.
	 * @param  int               $minuto Minute.
	 * @return DateTimeImmutable
	 */
	private static function en_hora( DateTimeImmutable $d, int $hora, int $minuto ): DateTimeImmutable {
		return $d->setTime( max( 0, min( 23, $hora ) ), max( 0, min( 59, $minuto ) ) );
	}

	/**
	 * Title and intro variants per key. Never a year, a price, a date of an
	 * event or a shop name: the post lists whatever offers are in force.
	 *
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	public static function textos(): array {
		return array(
			'xaneiro'        => array(
				array( 'Comezamos o ano con ofertas para as familias socias', 'Xaneiro é un bo momento para organizarse e aforrar un pouco. Estas son as ofertas en vigor dos establecementos colaboradores, exclusivas para as familias socias da ANPA.' ),
				array( 'Ofertas de xaneiro para as familias socias', 'Volvemos á rutina despois das vacacións e os establecementos colaboradores seguen ao noso carón. Repasa as ofertas en vigor e aproveita as que che interesen.' ),
			),
			'febreiro'       => array(
				array( 'Ofertas de febreiro dos establecementos colaboradores', 'Febreiro chega curtiño, pero cargado de plans. Aquí tes as ofertas en vigor que os establecementos colaboradores reservan para as familias socias da ANPA.' ),
				array( 'Febreiro con vantaxes para as familias socias', 'Seguimos co inverno e coas ofertas dos comercios que colaboran coa ANPA. Bótalles unha ollada e aproveita as que mellor vos veñan.' ),
			),
			'marzo'          => array(
				array( 'Ofertas de marzo para as familias socias', 'Os días alónganse e a primavera está á volta da esquina. Estas son as ofertas en vigor dos establecementos colaboradores para as familias socias da ANPA.' ),
				array( 'A primavera achégase con ofertas para as familias socias', 'Marzo é mes de cambio de estación e de máis tempo na rúa. Consulta as ofertas en vigor que os comercios colaboradores ofrecen ás familias socias.' ),
			),
			'abril'          => array(
				array( 'Ofertas de abril para as familias socias', 'Abril chega con choiva e con sol a partes iguais. Mentres, os establecementos colaboradores manteñen as súas ofertas para as familias socias da ANPA.' ),
				array( 'Abril con vantaxes nos establecementos colaboradores', 'A primavera xa está aquí e convida a saír. Repasa as ofertas en vigor e aproveita as que che resulten útiles.' ),
			),
			'maio'           => array(
				array( 'Ofertas de maio para as familias socias', 'Maio enche os días de luz e de actividade. Estas son as ofertas en vigor dos establecementos colaboradores, só para as familias socias da ANPA.' ),
				array( 'Maio florido, maio con ofertas para as familias socias', 'Seguimos na primavera e os comercios colaboradores seguen ao noso lado. Bótalle unha ollada á lista e aproveita o que che interese.' ),
			),
			'xuno'           => array(
				array( 'Ofertas de xuño para as familias socias', 'Xuño é mes de despedidas e de plans novos. Aquí tes as ofertas en vigor que os establecementos colaboradores reservan para as familias socias da ANPA.' ),
				array( 'Xuño chega con vantaxes para as familias socias', 'O bo tempo xa se nota e os días son máis longos ca nunca. Consulta as ofertas en vigor e aproveita as que mellor vos veñan.' ),
			),
			'xullo'          => array(
				array( 'Ofertas de xullo para as familias socias', 'En xullo hai máis tempo para estar en familia. Os establecementos colaboradores manteñen as súas ofertas para as familias socias da ANPA tamén no verán.' ),
				array( 'Xullo con vantaxes para as familias socias', 'Praia, monte ou casa: calquera plan vale. Repasa as ofertas en vigor dos comercios colaboradores e aproveita as que che interesen.' ),
			),
			'agosto'         => array(
				array( 'Ofertas de agosto para as familias socias', 'Agosto é mes de descanso e de días longos. Os establecementos colaboradores seguen ofrecendo vantaxes ás familias socias da ANPA.' ),
				array( 'Agosto con ofertas para as familias socias', 'Aínda queda verán por diante e tamén ofertas para aproveitar. Bótalle unha ollada á lista dos comercios colaboradores.' ),
			),
			'setembro'       => array(
				array( 'Ofertas de setembro para as familias socias', 'Setembro trae rutinas novas e moitas cousas por organizar. Estas son as ofertas en vigor dos establecementos colaboradores para as familias socias da ANPA.' ),
				array( 'Setembro con vantaxes nos establecementos colaboradores', 'O curso xa está en marcha e os comercios colaboradores acompáñanvos. Consulta as ofertas en vigor e aproveita as que vos sexan útiles.' ),
			),
			'outubro'        => array(
				array( 'Ofertas de outubro para as familias socias', 'O outono xa pinta as follas de cores. Aquí tes as ofertas en vigor que os establecementos colaboradores reservan para as familias socias da ANPA.' ),
				array( 'Outubro con vantaxes para as familias socias', 'Chegan as tardes máis curtas e os plans baixo teito. Repasa as ofertas dos comercios colaboradores e aproveita as que che interesen.' ),
			),
			'novembro'       => array(
				array( 'Ofertas de novembro para as familias socias', 'Novembro trae frío, choiva e tardes na casa. Os establecementos colaboradores seguen ofrecendo vantaxes ás familias socias da ANPA.' ),
				array( 'Novembro con ofertas para as familias socias', 'O outono avanza e o curso tamén. Bótalle unha ollada ás ofertas en vigor e aproveita as que mellor vos veñan.' ),
			),
			'decembro'       => array(
				array( 'Ofertas de decembro para as familias socias', 'Decembro é mes de preparativos e de encontros. Estas son as ofertas en vigor dos establecementos colaboradores para as familias socias da ANPA.' ),
				array( 'Decembro con vantaxes para as familias socias', 'Remata o trimestre e achéganse as festas. Repasa as ofertas dos comercios colaboradores e aproveita as que che interesen.' ),
			),
			'aninovo-reis'   => array(
				array( 'Ano novo, ofertas para as familias socias', 'Comeza un ano novo e queremos empezalo xuntos. Estas son as ofertas en vigor dos establecementos colaboradores, exclusivas para as familias socias da ANPA.' ),
				array( 'Ofertas para despedir as festas e recibir o ano', 'Entre o aninovo e os Reis, as festas aínda non remataron. Os comercios colaboradores manteñen as súas ofertas para as familias socias da ANPA.' ),
			),
			'entroido'       => array(
				array( 'Chega o Entroido: ofertas para as familias socias', 'Máscaras, cores e moita festa: o Entroido xa está aquí. Aproveita as ofertas en vigor dos establecementos colaboradores, só para as familias socias da ANPA.' ),
				array( 'Ofertas de Entroido para as familias socias', 'O Entroido enche de cor o cole e as rúas. Bótalle unha ollada ás ofertas dos comercios colaboradores e aproveita as que che interesen.' ),
			),
			'dia-do-pai'     => array(
				array( 'Ofertas para o Día do Pai', 'Achégase o Día do Pai, unha boa ocasión para un detalle en familia. Consulta as ofertas en vigor dos establecementos colaboradores para as familias socias da ANPA.' ),
				array( 'O Día do Pai, con vantaxes para as familias socias', 'Se estades a pensar nun agasallo ou nun plan especial, os comercios colaboradores poden botarvos unha man. Aquí tes as ofertas en vigor.' ),
			),
			'pascua'         => array(
				array( 'Vacacións de primavera con ofertas para as familias socias', 'Chegan uns días de descanso para o alumnado e máis tempo para estar en familia. Estas son as ofertas en vigor dos establecementos colaboradores.' ),
				array( 'Ofertas para a Semana Santa', 'Coas vacacións de Semana Santa á volta da esquina, repasa as vantaxes que os comercios colaboradores reservan para as familias socias da ANPA.' ),
			),
			'dia-do-libro'   => array(
				array( 'Día do Libro: ofertas para as familias socias', 'O 23 de abril celebramos o Día do Libro, unha festa para ler e compartir historias. Aproveita as ofertas en vigor dos establecementos colaboradores.' ),
				array( 'Ofertas para celebrar o Día do Libro', 'Non hai mellor plan ca unha boa lectura en familia. Bótalle unha ollada ás ofertas dos comercios colaboradores para as familias socias da ANPA.' ),
			),
			'dia-da-nai'     => array(
				array( 'Ofertas para o Día da Nai', 'Achégase o Día da Nai, unha boa ocasión para un detalle en familia. Consulta as ofertas en vigor dos establecementos colaboradores para as familias socias da ANPA.' ),
				array( 'O Día da Nai, con vantaxes para as familias socias', 'Se estades a preparar unha sorpresa, os comercios colaboradores poden botarvos unha man. Aquí tes as ofertas en vigor.' ),
			),
			'letras-galegas' => array(
				array( 'Letras Galegas: ofertas para as familias socias', 'O 17 de maio celebramos as Letras Galegas e a nosa lingua enche o cole e as rúas. Aproveita as ofertas en vigor dos establecementos colaboradores.' ),
				array( 'Ofertas para celebrar as Letras Galegas', 'Chega a festa da lingua e da literatura galega. Bótalle unha ollada ás vantaxes que os comercios colaboradores reservan para as familias socias da ANPA.' ),
			),
			'fin-de-curso'   => array(
				array( 'Fin de curso con ofertas para as familias socias', 'O curso está a piques de rematar e o verán xa se albisca. Estas son as ofertas en vigor dos establecementos colaboradores, só para as familias socias da ANPA.' ),
				array( 'Ofertas para despedir o curso', 'Últimas semanas de clase, festas e despedidas. Repasa as ofertas dos comercios colaboradores e aproveita as que vos veñan ben.' ),
			),
			'san-xoan'       => array(
				array( 'San Xoán: ofertas para as familias socias', 'Achégase a noite máis curta do ano, con cacharelas, sardiñas e herbas de San Xoán. Consulta as ofertas en vigor dos establecementos colaboradores.' ),
				array( 'Ofertas para a noite de San Xoán', 'Chega unha das festas máis queridas do verán. Bótalle unha ollada ás vantaxes que os comercios colaboradores reservan para as familias socias da ANPA.' ),
			),
			'veran'          => array(
				array( 'Verán con ofertas para as familias socias', 'Remataron as clases e comezan os días longos. Os establecementos colaboradores seguen ofrecendo vantaxes ás familias socias da ANPA tamén no verán.' ),
				array( 'Ofertas para comezar o verán', 'Praia, piscina, excursións ou tardes tranquilas: o verán dá para moito. Repasa as ofertas en vigor e aproveita as que che interesen.' ),
				array( 'O verán chega con vantaxes para as familias socias', 'Máis tempo en familia e plans ao aire libre. Estas son as ofertas en vigor dos comercios colaboradores da ANPA.' ),
			),
			'dia-de-galicia' => array(
				array( 'Día de Galicia: ofertas para as familias socias', 'O 25 de xullo celebramos o Día de Galicia. Aproveita as ofertas en vigor dos establecementos colaboradores, exclusivas para as familias socias da ANPA.' ),
				array( 'Ofertas para celebrar o Día de Galicia', 'Chega a festa de todo o país en pleno verán. Bótalle unha ollada ás vantaxes que os comercios colaboradores reservan para as familias socias.' ),
			),
			'volta-ao-cole'  => array(
				array( 'Volta ao cole con ofertas para as familias socias', 'Mochilas, horarios e reencontros: a volta ao cole xa está aquí. Estas son as ofertas en vigor dos establecementos colaboradores para as familias socias da ANPA.' ),
				array( 'Ofertas para a volta ao cole', 'Comeza un curso novo e hai moito que preparar. Repasa as vantaxes dos comercios colaboradores e aproveita as que vos sexan útiles.' ),
			),
			'samain'         => array(
				array( 'Samaín: ofertas para as familias socias', 'Cabazas iluminadas e contos de medo: chega o Samaín. Aproveita as ofertas en vigor dos establecementos colaboradores, só para as familias socias da ANPA.' ),
				array( 'Ofertas para unha noite de Samaín', 'As noites alónganse e o outono chega con festa. Bótalle unha ollada ás vantaxes que os comercios colaboradores reservan para as familias socias da ANPA.' ),
			),
			'outono-magosto' => array(
				array( 'Magosto: ofertas para as familias socias', 'Castañas asadas, fume e tardes de outono: chega o tempo do magosto. Consulta as ofertas en vigor dos establecementos colaboradores.' ),
				array( 'Ofertas de outono para as familias socias', 'O outono enche os montes de castañas e de cores. Repasa as vantaxes dos comercios colaboradores e aproveita as que che interesen.' ),
			),
			'ponte-decembro' => array(
				array( 'Ofertas para a ponte de decembro', 'Coa ponte de decembro chegan uns días de descanso antes das festas. Estas son as ofertas en vigor dos establecementos colaboradores para as familias socias.' ),
				array( 'Uns días de descanso con vantaxes para as familias socias', 'Decembro trae festivos e tempo para estar xuntos. Bótalle unha ollada ás ofertas dos comercios colaboradores e aproveita as que vos veñan ben.' ),
			),
			'nadal'          => array(
				array( 'Ofertas de Nadal para as familias socias', 'O Nadal xa está aquí, con luces, agasallos e tempo en familia. Aproveita as ofertas en vigor dos establecementos colaboradores, só para as familias socias da ANPA.' ),
				array( 'Este Nadal, aproveita as vantaxes dos comercios colaboradores', 'Se andas a preparar os agasallos, os comercios colaboradores poden axudar. Repasa as ofertas en vigor para as familias socias da ANPA.' ),
				array( 'Bo Nadal con ofertas para as familias socias', 'Remata o trimestre e chegan as vacacións de Nadal. Bótalle unha ollada ás vantaxes que os establecementos colaboradores reservan para vós.' ),
			),
		);
	}
}
